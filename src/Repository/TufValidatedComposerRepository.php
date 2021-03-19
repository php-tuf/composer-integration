<?php

namespace Tuf\ComposerIntegration\Repository;

use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Repository\ComposerRepository;
use Tuf\ComposerIntegration\HttpDownloaderAdapter;
use Tuf\ComposerIntegration\PackageLoader;

class TufValidatedComposerRepository extends ComposerRepository
{
    /**
     * {@inheritDoc}
     */
    public function __construct(array $repoConfig, IOInterface $io, Config $config, HttpDownloaderAdapter $httpDownloader, EventDispatcher $eventDispatcher = null)
    {
        if (!empty($repoConfig['tuf'])) {
            $repoConfig['options']['tuf']['repository'] = $repoConfig['url'];
        }
        parent::__construct($repoConfig, $io, $config, $httpDownloader, $eventDispatcher);
        if (!empty($repoConfig['tuf'])) {
            $httpDownloader->register($this);
            $this->loader = new PackageLoader($this, $httpDownloader, $this->versionParser);
        } else {
            // Outputting composer repositories not secured by TUF may create confusion about other
            // not-secured repository types (eg, "vcs").
            // @todo Usability assessment. Should we output this for other repo types, or not at all?
            $io->warning("Authenticity of packages from ${repoConfig['url']} are not verified by TUF.");
        }

    }
}
