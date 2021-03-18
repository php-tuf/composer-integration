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
use Tuf\ComposerIntegration\PackageLoader;

class TufValidatedComposerRepository extends ComposerRepository
{
    /**
     * {@inheritDoc}
     */
    public function __construct(array $repoConfig, IOInterface $io, Config $config, HttpDownloader $httpDownloader, EventDispatcher $eventDispatcher = null)
    {
        parent::__construct($repoConfig, $io, $config, $httpDownloader, $eventDispatcher);
        if (!empty($repoConfig['tuf'])) {
            $httpDownloader->register($this);
            $this->loader = new PackageLoader($this, $this->versionParser);
        } else {
            // Outputting composer repositories not secured by TUF may create confusion about other
            // not-secured repository types (eg, "vcs").
            // @todo Usability assessment. Should we output this for other repo types, or not at all?
            $io->warning("Authenticity of packages from ${repoConfig['url']} are not verified by TUF.");
        }

    }
}
