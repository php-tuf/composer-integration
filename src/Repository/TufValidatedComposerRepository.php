<?php

namespace Tuf\ComposerIntegration\Repository;

use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositorySecurityException;
use Composer\Util\Filesystem;
use Composer\Util\HttpDownloader;
use Tuf\Client\DurableStorage\FileStorage;
use Tuf\Client\GuzzleFileFetcher;
use Tuf\Client\Updater;
use Tuf\ComposerIntegration\HttpDownloaderAdapter;

class TufValidatedComposerRepository extends ComposerRepository
{
    /**
     * @var Updater
     */
    protected $tufRepo;

    /**
     * {@inheritDoc}
     */
    public function __construct(array $repoConfig, IOInterface $io, Config $config, HttpDownloader $httpDownloader, EventDispatcher $eventDispatcher = null)
    {
        if (!empty($repoConfig['tuf'])) {
            $tufConfig = $repoConfig['tuf'];

            // @todo: Write a custom implementation of FileStorage that stores repo keys to user's global composer cache?
            // Convert the repo URL into a string that can be used as a
            // directory name.
            $repoPath = preg_replace('/[^[:alnum:]\.]/', '-', $repoConfig['url']);
            // Harvest the vendor dir from Composer. We'll store TUF state under vendor/composer/tuf.
            $vendorDir = rtrim($config->get('vendor-dir'), '/');
            $repoPath = "$vendorDir/composer/tuf/repo/$repoPath";
            // Ensure directory exists.
            $fs = new Filesystem();
            $fs->ensureDirectoryExists($repoPath);

            $rootFile = $repoPath . '/root.json';
            if (!file_exists($rootFile)) {
                $fs->copy(realpath($tufConfig['root']), $rootFile);
            }

            // Instantiate TUF library.
            $fetcher = GuzzleFileFetcher::createFromUri($repoConfig['url']);
            $repoConfig['options']['tuf'] = new Updater($fetcher, [], new FileStorage($repoPath));

            $httpDownloader = new HttpDownloaderAdapter($httpDownloader);
        } else {
            // Outputting composer repositories not secured by TUF may create confusion about other
            // not-secured repository types (eg, "vcs").
            // @todo Usability assessment. Should we output this for other repo types, or not at all?
            $io->warning("Authenticity of packages from ${repoConfig['url']} are not verified by TUF.");
        }
        parent::__construct($repoConfig, $io, $config, $httpDownloader, $eventDispatcher);
    }
}
