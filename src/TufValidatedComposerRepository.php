<?php

namespace Tuf\ComposerIntegration;

use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Repository\ComposerRepository;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use GuzzleHttp\Psr7\Utils;
use Tuf\Client\Repository;
use Tuf\Exception\NotFoundException;
use Tuf\Loader\SizeCheckingLoader;
use Tuf\Metadata\RootMetadata;

/**
 * Defines a Composer repository that is protected by TUF.
 */
class TufValidatedComposerRepository extends ComposerRepository
{
    /**
     * The maximum allowable length of a 404 response for Composer metadata.
     *
     * @see ::prepareMetadata()
     *
     * @var int
     */
    public const MAX_404_BYTES = 1024;

    /**
     * The TUF updater, if any, for this repository.
     *
     * @var ComposerCompatibleUpdater|null
     */
    private ?ComposerCompatibleUpdater $updater = NULL;

    /**
     * The I/O wrapper.
     *
     * @var IOInterface
     */
    private IOInterface $io;

    /**
     * {@inheritDoc}
     */
    public function __construct(array $repoConfig, IOInterface $io, Config $config, HttpDownloader $httpDownloader, EventDispatcher $eventDispatcher = null)
    {
        $this->io = $io;
        $url = rtrim($repoConfig['url'], '/');

        if (!empty($repoConfig['tuf'])) {
            // TUF metadata can optionally be loaded from a different place than the Composer package metadata.
            $metadataUrl = $repoConfig['tuf']['metadata-url'] ?? "$url/metadata/";
            if (!str_ends_with($metadataUrl, '/')) {
                $metadataUrl .= '/';
            }

            $maxBytes = $repoConfig['tuf']['max-bytes'] ?? NULL;
            if (is_int($maxBytes)) {
                Repository::$maxBytes = $maxBytes;
            }

            // @todo: Write a custom implementation of FileStorage that stores repo keys to user's global composer cache?
            $storage = $this->initializeStorage($url, $config);
            $loader = new Loader($httpDownloader, $storage, $metadataUrl);
            $loader = new StaticCache($loader, $io);
            $loader = new SizeCheckingLoader($loader);
            $this->updater = new ComposerCompatibleUpdater($loader, $storage);

            $io->debug("[TUF] Packages from $url are verified by TUF.");
            $io->debug("[TUF] Metadata source: $metadataUrl");
        } else {
            // @todo Usability assessment. Should we output this for other repo types, or not at all?
            $io->warning("Authenticity of packages from $url are not verified by TUF.");
        }
        parent::__construct($repoConfig, $io, $config, $httpDownloader, $eventDispatcher);
    }

    /**
     * Initializes the durable storage for this repository's TUF metadata.
     *
     * @param string $url
     *   The repository URL.
     * @param Config $config
     *   The Composer configuration.
     *
     * @return \Tuf\ComposerIntegration\ComposerFileStorage
     *   A durable storage object for this repository's TUF data.
     *
     * @throws \RuntimeException
     *   If no root metadata could be found for this repository.
     */
    private function initializeStorage(string $url, Config $config): ComposerFileStorage
    {
        $storage = ComposerFileStorage::create($url, $config);

        try {
            $storage->getRoot();
            $this->io->debug("[TUF] Root metadata for $url loaded from persistent storage.");
        } catch (\LogicException) {
            // The durable storage doesn't have any root metadata, so copy the
            // initial root metadata into it.
            $initialRootMetadataPath = $this->locateRootMetadata($url, $config);
            if (empty($initialRootMetadataPath)) {
                throw new \RuntimeException("No TUF root metadata was found for repository $url.");
            }
            $rootMetadata = file_get_contents($initialRootMetadataPath);
            $rootMetadata = RootMetadata::createFromJson($rootMetadata)->trust();
            $storage->save($rootMetadata);
            $this->io->debug("[TUF] Root metadata for $url loaded from $initialRootMetadataPath.");
        }
        return $storage;
    }

    /**
     * Determine the location of the initial root TUF metadata for a repository.
     *
     * @param string $url
     *   The repository URL.
     * @param Config $config
     *   The current Composer configuration.
     *
     * @return string|null
     *   The path of the initial root TUF metadata for the repository, or null
     *   if none was found.
     */
    private function locateRootMetadata(string $url, Config $config): ?string
    {
        // The root metadata can either be named with the SHA-256 hash of the URL,
        // or the host name only.
        $candidates = [
            hash('sha256', $url),
            parse_url($url, PHP_URL_HOST),
        ];
        // We expect the root metadata to be in a directory called `tuf`, adjacent
        // to the active composer.json.
        $searchDir = dirname($config->getConfigSource()->getName()) . DIRECTORY_SEPARATOR . 'tuf';

        foreach ($candidates as $candidate) {
            $location = $searchDir . DIRECTORY_SEPARATOR . $candidate . '.json';
            if (file_exists($location)) {
                return $location;
            }
        }
        return null;
    }

    /**
     * {@inheritDoc}
     */
    protected function configurePackageTransportOptions(PackageInterface $package): void
    {
        parent::configurePackageTransportOptions($package);

        $options = $package->getTransportOptions();
        ['url' => $url] = $this->getRepoConfig();

        // Store the information identifying this package to TUF in a form that can be stored in the lock file.
        // @see \Tuf\ComposerIntegration\Plugin::getRepositoryFromEvent()
        $options['tuf'] = [
            'repository' => $url,
            'target' => $package->getName() . '/' . $package->getVersion(),
        ];
        $package->setTransportOptions($options);
    }

    /**
     * Indicates if TUF is enabled for this repository.
     *
     * @param \Composer\Package\PackageInterface|null $package
     *   A specific package being validated, if any.
     *
     * @return bool
     *   Whether PHP-TUF is enabled for this repository.
     */
    private function isTufEnabled(?PackageInterface $package = null): bool
    {
        // Metapackages are not downloaded into the code base at all, so don't
        // bother validating them.
        // @see https://github.com/composer/composer/blob/11e5237ad9d9e8f29bdc57d946f87c816320d863/doc/07-runtime.md?plain=1#L110
        return $this->updater instanceof ComposerCompatibleUpdater && $package?->getType() !== 'metapackage';
    }

    /**
     * Extracts a TUF target path from a full URL.
     *
     * @param string $url
     *   A URL.
     *
     * @return string
     *   A TUF target path derived from the URL.
     */
    private function getTargetFromUrl(string $url): string
    {
        $config = $this->getRepoConfig();
        if (str_starts_with($url, $config['url'])) {
            $target = str_replace($config['url'], '', $url);
        } else {
            $target = parse_url($url, PHP_URL_PATH);
        }
        return urldecode(ltrim($target, '/'));
    }

    /**
     * Reacts before Composer metadata is downloaded.
     *
     * @param PreFileDownloadEvent $event
     *   The event object.
     */
    public function prepareComposerMetadata(PreFileDownloadEvent $event): void
    {
        if ($this->isTufEnabled()) {
            $target = $this->getTargetFromUrl($event->getProcessedUrl());
            $options = $event->getTransportOptions();
            try {
                $options['max_file_size'] = $this->updater->getLength($target);
            } catch (NotFoundException) {
                // As it compiles information on the available packages, ComposerRepository
                // expects to receive occasional 404 responses from the server, which
                // it treats as a totally normal indication that the repository doesn't have
                // a particular package, or version of a package. Those requests probably
                // don't have a corresponding TUF target, which means Updater::getLength()
                // will throw exceptions. Since we need to allow those requests to happen,
                // a reasonable compromise is to constrain the response size for unknown
                // Composer metadata targets to a small constant value (we don't expect 404
                // responses to be particularly verbose). This is NOT something we want to do
                // for actual package requests, since it's always an error condition if a
                // package URL returns a 404. That's why we don't do a similar try-catch in
                // ::configurePackageTransportOptions().
                $options['max_file_size'] = static::MAX_404_BYTES;
            }
            $event->setTransportOptions($options);

            $this->io->debug("[TUF] Target '$target' limited to " . $options['max_file_size'] . ' bytes.');
        }
    }

    /**
     * Validates downloaded Composer metadata with TUF.
     *
     * @param string $url
     *   The URL from which the Composer metadata was downloaded.
     * @param Response $response
     *   The HTTP response for the downloaded Composer metadata.
     */
    public function validateComposerMetadata(string $url, Response $response): void
    {
        if ($this->isTufEnabled()) {
            $target = $this->getTargetFromUrl($url);
            $this->updater->verify($target, Utils::streamFor($response->getBody()));

            $this->io->debug("[TUF] Target '$target' validated.");
        }
    }

    public function preparePackage(PackageInterface $package): void
    {
        if ($this->isTufEnabled($package)) {
            $options = $package->getTransportOptions();
            // @see ::configurePackageTransportOptions()
            $options['max_file_size'] = $this->updater->getLength($options['tuf']['target']);
            $package->setTransportOptions($options);
        }
    }

    /**
     * Validates a downloaded package with TUF.
     *
     * @param PackageInterface $package
     *   The downloaded package.
     * @param string $filename
     *   The local path of the downloaded file.
     */
    public function validatePackage(PackageInterface $package, string $filename): void
    {
        if ($this->isTufEnabled($package)) {
            $options = $package->getTransportOptions();
            $resource = Utils::tryFopen($filename, 'r');
            $this->updater->verify($options['tuf']['target'], Utils::streamFor($resource));

            $this->io->debug("[TUF] Target '" . $options['tuf']['target'] . "' validated.");
        }
    }
}
