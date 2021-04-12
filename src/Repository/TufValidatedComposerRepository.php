<?php

namespace Tuf\ComposerIntegration\Repository;

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
               rtrim($config->get('vendor-dir'), DIRECTORY_SEPARATOR),
               'composer',
               'tuf',
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

            $repoConfig['url'] .= '/targets';
        } else {
            // @todo Usability assessment. Should we output this for other repo types, or not at all?
            $io->warning("Authenticity of packages from $url are not verified by TUF.");
        }
        parent::__construct($repoConfig, $io, $config, $httpDownloader, $eventDispatcher);
    }

    protected function configurePackageTransportOptions(PackageInterface $package)
    {
        parent::configurePackageTransportOptions($package);

        $options = $package->getTransportOptions();
        $config = $this->getRepoConfig();
        $options['tuf'] = [
            'repository' => $config['url'],
            'target' => $package->getName() . '/' . $package->getVersion(),
        ];
        $package->setTransportOptions($options);
    }

    public function validateMetadata(string $url, Response $response): void
    {
        if ($this->updater) {
            $config = $this->getRepoConfig();
            $target = str_replace($config['url'], null, $url);
            $target = ltrim($target, '/');
            $stream = Utils::streamFor($response->getBody());
            $this->updater->verify($target, $stream);
        }
    }

    public function validatePackage(PackageInterface $package, string $filename): void
    {
        if ($this->updater) {
            $options = $package->getTransportOptions();
            $data = Utils::tryFopen($filename, 'r');
            $this->updater->verify($options['tuf']['target'], Utils::streamFor($data));
        }
    }
}
