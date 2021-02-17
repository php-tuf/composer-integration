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
use Tuf\Client\Updater;
use Tuf\Exception\TufException;

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
        parent::__construct($repoConfig, $io, $config, $httpDownloader, $eventDispatcher);

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
            $tufDurableStorage = new FileStorage($repoPath);
            // Instantiate TUF library.
            $this->tufRepo = new Updater($repoConfig['url'], [
              ['url_prefix' => $tufConfig['url']]
            ], $tufDurableStorage);
        } else {
            // Outputting composer repositories not secured by TUF may create confusion about other
            // not-secured repository types (eg, "vcs").
            // @todo Usability assessment. Should we output this for other repo types, or not at all?
            $io->warning("Authenticity of packages from ${repoConfig['url']} are not verified by TUF.");
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function loadRootServerFile()
    {
        // If we are using TUF, fetch the latest secure metadata for the
        // Composer package metadata.
        if ($this->tufRepo) {
            try {
                $this->tufRepo->refresh();
            } catch (TufException $e) {
                throw new RepositorySecurityException("TUF security error: {$e->getMessage()}", $e->getCode(), $e);
            }
        }
        return parent::loadRootServerFile();
    }

    /**
     * {@inheritDoc}
     */
    protected function fetchFile($filename, $cacheKey = null, $sha256 = null, $storeLastModifiedTime = false)
    {
        if ($this->tufRepo) {
            $tufTarget = ltrim(parse_url($filename, PHP_URL_PATH), '/');
            try {
                $tufTargetInfo = $this->tufRepo->getOneValidTargetInfo($tufTarget);
            } catch (TufException $e) {
                throw new RepositorySecurityException('TUF secure error: ' . $e->getMessage(), $e->getCode(), $e);
            }

            // @todo: Investigate whether all $sha256 hashes, when provided, are trusted. Skip TUF if so.
            if ($sha256 !== null && $sha256 !== $tufTargetInfo['hashes']['sha256']) {
                throw new RepositorySecurityException('TUF secure error: disagreement between TUF and Composer repositories on expected hash of ' . $tufTarget);
            }
            $sha256 = $tufTargetInfo['hashes']['sha256'];
        }
        return parent::fetchFile($filename, $cacheKey, $sha256, $storeLastModifiedTime);
    }
}
