<?php


namespace Tuf\ComposerIntegration\Repository;


use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositorySecurityException;
use Composer\Util\HttpDownloader;
use Tuf\Client\Updater;
use Tuf\Exception\TufException;

class TufValidatedComposerRepository extends ComposerRepository
{
    /**
     * @var bool
     *   Indicates whether this Composer repository is validated by a parallel
     *   TUF repository.
     */
    protected $isValidated;

    /**
     * @var array
     *   Cache to short-circuit TUF operations when accessing the Composer root metadata.
     *
     *   Serves same purpose as parent::$rootData, but its visibility is private.
     */
    protected $tufValidatedRootData;

    /**
     * @var string
     *   The URL to the composer repository root.
     *
     *   Serves same purpose as parent::$url, but its visibility is private.
     */
    protected $composerRepoUrl;

    /**
     * @var Updater
     */
    protected $tufRepo;

    public function __construct(array $repoConfig, IOInterface $io, Config $config, HttpDownloader $httpDownloader, EventDispatcher $eventDispatcher = null)
    {
        parent::__construct($repoConfig, $io, $config, $httpDownloader, $eventDispatcher);
        $this->isValidated = false;
        if (!empty($repoConfig['tuf-url'])) {
            $this->isValidated = true;
            $this->composerRepoUrl = $repoConfig['url'];
            $this->tufRepo = new Updater($repoConfig['url'], [
              ['url_prefix' => $repoConfig['tuf-url']]
            ], null);
        } else {
            // Outputting composer repositories not secured by TUF may create confusion about other
            // not-secured repository types (eg, "vcs").
            // @todo Usability assessment. Should we output this for other repo types, or not at all?
            $io->warning("Authenticity of packages from ${repoConfig['url']} are not verified by TUF.");
        }
    }

    protected function loadRootServerFile()
    {
        if (! $this->isValidated) {
            return parent::loadRootServerFile();
        }

        if (null !== $this->tufValidatedRootData) {
            return $this->tufValidatedRootData;
        }

        $jsonUrlParts = parse_url($this->composerRepoUrl);
        if (isset($jsonUrlParts['path']) && false !== strpos($jsonUrlParts['path'], '.json')) {
            $jsonUrl = $this->composerRepoUrl;
        } else {
            $jsonUrl = $this->composerRepoUrl . '/packages.json';
        }

        $composerRootFilename = parse_url($jsonUrl, PHP_URL_PATH);

        // Perform tuf repository refresh / composer repository root validation.
        try {
            $this->tufRepo->refresh();
            $composerRootTufTarget = $this->tufRepo->getOneValidTargetInfo($composerRootFilename);
        } catch (TufException $e) {
            throw new RepositorySecurityException('TUF secure error: ' . $e->getMessage(), $e->getCode(), $e);
        }

        $signedComposerRootSha = $composerRootTufTarget['hashes']['sha256'];
        // fetchFile() will throw a RepositorySecurityException if the hash doesn't match.
        $data = $this->fetchFile($jsonUrl, 'packages.json', $signedComposerRootSha);

        // In Composer v1, this would have been about all you need to do to secure the package
        // discovery mechanism; there's a hash chain from the trusted root file down to any leaf
        // package's metadata.
        //
        // In Composer v2 paired w/ a v2-aware repository, package discovery doesn't require
        // preloading a big hash list.
        // We'll need to secure the fetching of individual package info.
        // Force v1 for now by removing v2 elements so this Composer repository "looks" like a v1.
        unset($data['metadata-url']);

        if (!empty($data['providers-url'])) {
            $this->providersUrl = $this->canonicalizeUrl($data['providers-url']);
            $this->hasProviders = true;
        }

        if (!empty($data['list'])) {
            $this->listUrl = $this->canonicalizeUrl($data['list']);
        }

        if (!empty($data['providers']) || !empty($data['providers-includes'])) {
            $this->hasProviders = true;
        }

        if (!empty($data['providers-api'])) {
            $this->providersApiUrl = $this->canonicalizeUrl($data['providers-api']);
        }

        return $this->tufValidatedRootData = $data;
    }

    /**
     * Reimplementation of parent::canonicalizeUrl(), whose visibility is private.
     */
    protected function canonicalizeUrl($url)
    {
        if ('/' === $url[0]) {
            if (preg_match('{^[^:]++://[^/]*+}', $this->composerRepoUrl, $matches)) {
                return $matches[0] . $url;
            }

            return $this->composerRepoUrl;
        }

        return $url;
    }
}