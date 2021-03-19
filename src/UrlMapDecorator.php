<?php

namespace Tuf\ComposerIntegration;

use GuzzleHttp\Promise\PromiseInterface;
use Tuf\Client\RepoFileFetcherInterface;

/**
 * Decorates a file fetcher to map targets to remote URLs.
 */
class UrlMapDecorator implements RepoFileFetcherInterface
{
    /**
     * The decorated file fetcher.
     *
     * @var \Tuf\Client\RepoFileFetcherInterface
     */
    private $decorated;

    public $urlMap = [];

    /**
     * UrlMapDecorator constructor.
     *
     * @param \Tuf\Client\RepoFileFetcherInterface $decorated
     *   The decorated file fetcher.
     */
    public function __construct(RepoFileFetcherInterface $decorated)
    {
        $this->decorated = $decorated;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchMetaData(string $fileName, int $maxBytes, ...$extra): PromiseInterface
    {
        return $this->decorated->fetchMetaData($fileName, $maxBytes, ...$extra);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchMetaDataIfExists(string $fileName, int $maxBytes, ...$extra): ?string
    {
        return $this->decorated->fetchMetaDataIfExists($fileName, $maxBytes, ...$extra);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchTarget(string $fileName, int $maxBytes, ...$extra): PromiseInterface
    {
        return $this->decorated->fetchTarget($this->urlMap[$fileName] ?? $fileName, $maxBytes, ...$extra);
    }
}
