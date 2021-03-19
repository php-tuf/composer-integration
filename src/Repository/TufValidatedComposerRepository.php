<?php

namespace Tuf\ComposerIntegration\Repository;

use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Repository\ComposerRepository;
use Tuf\ComposerIntegration\HttpDownloaderAdapter;
use Tuf\ComposerIntegration\PackageLoader;

/**
 * Defines a Composer repository that is protected by TUF.
 */
class TufValidatedComposerRepository extends ComposerRepository
{
    /**
     * {@inheritDoc}
     */
    public function __construct(array $repoConfig, IOInterface $io, Config $config, HttpDownloaderAdapter $httpDownloader, EventDispatcher $eventDispatcher = null)
    {
        $repoConfig['options']['tuf'] = [
          $repoConfig['url'],
        ];
        parent::__construct($repoConfig, $io, $config, $httpDownloader, $eventDispatcher);
        // The HTTP downloader manages connections to multiple TUF repositories,
        // so it needs to be made aware of this specific one.
        $httpDownloader->register($this);
        $this->loader = new PackageLoader($this, $httpDownloader, $this->versionParser);
    }
}
