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
        // Ensure that all HTTP requests made by the parent class will identify
        // which TUF repository they're associated with. The TUF-aware HTTP
        // downloader keeps track of all instantiated TUF repositories and
        // identifies them by their URL. We need to do this before calling the
        // parent constructor because the options are stored in a private
        // property.
        // @see \Tuf\ComposerIntegration\HttpDownloaderAdapter::addRepository()
        // @see \Tuf\ComposerIntegration\HttpDownloaderAdapter::createPromise()
        $repoConfig['options']['tuf'] = [
          'repository' => $repoConfig['url'],
        ];
        parent::__construct($repoConfig, $io, $config, $httpDownloader, $eventDispatcher);
        // Make the HTTP downloader aware of this repository.
        $httpDownloader->addRepository($this);
        // The parent constructor sets up a package loader, so we need to
        // override that with our TUF-aware one.
        $this->loader = new PackageLoader($repoConfig['url'], $this->versionParser);
    }
}
