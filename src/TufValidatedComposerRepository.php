<?php

namespace Tuf\ComposerIntegration;

use Composer\Config;
use Composer\Downloader\FilesystemException;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Repository\ComposerRepository;
use Composer\Util\Filesystem;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use GuzzleHttp\Psr7\Utils;
use Tuf\Client\DurableStorage\FileStorage;
use Tuf\Client\GuzzleFileFetcher;
use Tuf\Exception\NotFoundException;

/**
 * Defines a Composer repository that is protected by TUF.
 */
class TufValidatedComposerRepository extends ComposerRepository
{
    /**
     * The maximum allowable length of a 404 response for metadata.
     *
     * @see ::prepareMetadata()
     *
     * @var int
     */
    public const MAX_404_BYTES = 1024;

    /**
     * The TUF updater, if any, for this repository.
     *
     * @var ComposerCompatibleUpdater
     */
    private $updater;

    /**
     * {@inheritDoc}
     */
    public function __construct(array $repoConfig, IOInterface $io, Config $config, HttpDownloader $httpDownloader, EventDispatcher $eventDispatcher = null)
    {
        $url = $repoConfig['url'];

        if (isset($repoConfig['tuf'])) {
            $repoKey = preg_replace('/[^[:alnum:]\.]+/', '.', $url);

            // @todo: Write a custom implementation of FileStorage that stores repo keys to user's global composer cache?
            $repoPath = Plugin::getStoragePath($config) . DIRECTORY_SEPARATOR . $repoKey;

            $fs = new Filesystem();
            $fs->ensureDirectoryExists($repoPath);

            // We expect the repository to have a root metadata file in a known
            // good state. Copy that file to our persistent storage location if
            // it doesn't already exist.
            $rootFile = $repoPath . '/root.json';
            if (!file_exists($rootFile)) {
                $sourcePath = implode(DIRECTORY_SEPARATOR, [
                    dirname($config->getConfigSource()->getName()),
                    'tuf',
                    $repoKey,
                ]);
                $fs->copy("$sourcePath.json", $rootFile);
            }

            // For unit testing purposes, allow the updater instance to be passed in
            // the plugin configuration.
            if (isset($repoConfig['tuf']['_updater'])) {
                $this->updater = $repoConfig['tuf']['_updater'];
            } else {
                $fetcher = GuzzleFileFetcher::createFromUri($url);
                $this->updater = new ComposerCompatibleUpdater($fetcher, [], new FileStorage($repoPath));
            }

            // The Python tool (which generates the server-side TUF repository) will
            // put all signed files into /targets, so ensure that all downloads are
            // prefixed with that.
            $repoConfig['url'] .= '/targets';
        } else {
            // @todo Usability assessment. Should we output this for other repo types, or not at all?
            $io->warning("Authenticity of packages from $url are not verified by TUF.");
        }
        parent::__construct($repoConfig, $io, $config, $httpDownloader, $eventDispatcher);
    }

    /**
     * {@inheritDoc}
     */
    protected function configurePackageTransportOptions(PackageInterface $package)
    {
        parent::configurePackageTransportOptions($package);

        $options = $package->getTransportOptions();
        $config = $this->getRepoConfig();
        // Store the information identifying this package to TUF in a format
        // that can be safely saved to and loaded from the lock file.
        // @see \Tuf\ComposerIntegration\Plugin::postFileDownload()
        $options['tuf'] = [
            'repository' => $config['url'],
            'target' => $package->getName() . '/' . $package->getVersion(),
        ];
        if ($this->isTufEnabled()) {
            $options['max_file_size'] = $this->updater->getLength($options['tuf']['target']);
        }
        $package->setTransportOptions($options);
    }

    /**
     * Indicates if TUF is enabled for this repository.
     *
     * @return bool
     *   Whether PHP-TUF is enabled for this repository.
     */
    private function isTufEnabled(): bool
    {
        return $this->updater instanceof ComposerCompatibleUpdater;
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
        $target = str_replace($config['url'], null, $url);
        return ltrim($target, '/');
    }

    /**
     * Reacts before metadata is downloaded.
     *
     * @param PreFileDownloadEvent $event
     *   The event object.
     */
    public function prepareMetadata(PreFileDownloadEvent $event): void
    {
        if ($this->isTufEnabled()) {
            $target = $this->getTargetFromUrl($event->getProcessedUrl());
            $options = $event->getTransportOptions();
            try {
                $options['max_file_size'] = $this->updater->getLength($target);
            } catch (NotFoundException $e) {
                // As it compiles information on the available packages, ComposerRepository
                // expects to receive occasional 404 responses from the server, which
                // it treats as a totally normal indication that the repository doesn't have
                // a particular package, or version of a package. Those requests probably
                // don't have a corresponding TUF target, which means Updater::getLength()
                // will throw exceptions. Since we need to allow those requests to happen,
                // a reasonable compromise is to constrain the response size for unknown
                // metadata targets to a small constant value (we don't expect 404 responses
                // to be particularly verbose). This is NOT something we want to do for
                // actual package requests, since it's always an error condition if a package
                // URL returns a 404. That's why we don't do a similar try-catch in
                // ::configurePackageTransportOptions().
                $options['max_file_size'] = static::MAX_404_BYTES;
            }
            $event->setTransportOptions($options);
        }
    }

    /**
     * Validates downloaded metadata with TUF.
     *
     * @param string $url
     *   The URL from which the metadata was downloaded.
     * @param Response $response
     *   The HTTP response for the downloaded metadata.
     */
    public function validateMetadata(string $url, Response $response): void
    {
        if ($this->isTufEnabled()) {
            $target = $this->getTargetFromUrl($url);
            $this->updater->verify($target, Utils::streamFor($response->getBody()));
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
        if ($this->isTufEnabled()) {
            $options = $package->getTransportOptions();
            $resource = Utils::tryFopen($filename, 'r');
            $this->updater->verify($options['tuf']['target'], Utils::streamFor($resource));
        }
    }
}
