<?php

namespace Tuf\ComposerIntegration;

use Composer\Config;
use Composer\Downloader\FilesystemException;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\ComposerRepository;
use Composer\Util\Filesystem;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use GuzzleHttp\Psr7\Utils;
use Tuf\Client\DurableStorage\FileStorage;
use Tuf\Client\GuzzleFileFetcher;
use Tuf\Client\Updater;

/**
 * Defines a Composer repository that is protected by TUF.
 */
class TufValidatedComposerRepository extends ComposerRepository
{
    /**
     * The TUF updater, if any, for this repository.
     *
     * @var Updater
     */
    private $updater;

    /**
     * {@inheritDoc}
     */
    public function __construct(array $repoConfig, IOInterface $io, Config $config, HttpDownloader $httpDownloader, EventDispatcher $eventDispatcher = null)
    {
        $url = $repoConfig['url'];

        if (isset($repoConfig['tuf'])) {
            // @todo: Write a custom implementation of FileStorage that stores repo keys to user's global composer cache?
            // Use the repository URL to derive a path where we can persist the TUF
            // data.
            $repoPath = implode(DIRECTORY_SEPARATOR, [
                Plugin::getStoragePath($config),
                preg_replace('/[^[:alnum:]\.]/', '-', $url),
            ]);

            $fs = new Filesystem();
            $fs->ensureDirectoryExists($repoPath);

            // We expect the repository to have a root metadata file in a known
            // good state. Copy that file to our persistent storage location if
            // it doesn't already exist.
            $rootFile = $repoPath . '/root.json';
            if (!file_exists($rootFile)) {
                $sourcePath = realpath($repoConfig['tuf']['root']);
                if (!$fs->copy($sourcePath, $rootFile)) {
                    throw new FilesystemException("Could not copy '$sourcePath' to '$rootFile");
                }
            }

            $fetcher = GuzzleFileFetcher::createFromUri($url);
            $this->updater = new Updater($fetcher, [], new FileStorage($repoPath));

            // The Python tool (which generates the server-side TUF repository) will
            // put all signed files into /targets, so ensure that all downloads are
            // prefixed with that.
            $repoConfig['url'] .= '/targets';
            // Ensure that metadata downloads are constrained to a maximum size.
            $repoConfig['options']['max_file_size'] = Updater::MAXIMUM_DOWNLOAD_BYTES;
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
        // Constrain the download to a maximum size.
        $options += [
            'max_file_size' => Updater::MAXIMUM_DOWNLOAD_BYTES,
        ];
        $package->setTransportOptions($options);
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
        if ($this->updater) {
            $config = $this->getRepoConfig();
            $target = str_replace($config['url'], null, $url);
            $target = ltrim($target, '/');
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
        if ($this->updater) {
            $options = $package->getTransportOptions();
            $resource = Utils::tryFopen($filename, 'r');
            $this->updater->verify($options['tuf']['target'], Utils::streamFor($resource));
        }
    }
}
