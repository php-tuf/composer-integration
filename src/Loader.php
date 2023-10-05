<?php

namespace Tuf\ComposerIntegration;

use Composer\IO\IOInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\StreamInterface;
use Tuf\Exception\DownloadSizeException;
use Tuf\Exception\RepoFileNotFound;
use Tuf\Loader\LoaderInterface;

/**
 * Defines a data loader that wraps around Guzzle's HTTP client.
 */
class Loader implements LoaderInterface
{
    public function __construct(private ClientInterface $client, private IOInterface $io)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function load(string $locator, int $maxBytes): StreamInterface
    {
        // If cURL will be used (a decision internally made by Guzzle), use a
        // progress callback to ensure we don't download the maximum number
        // of bytes.
        $progress = function ($expectedBytes, $downloadedBytes) use ($locator, $maxBytes) {
            if ($expectedBytes > $maxBytes || $downloadedBytes > $maxBytes) {
                throw new DownloadSizeException("$locator exceeded $maxBytes bytes");
            }
        };
        $options = [
            RequestOptions::PROGRESS => $progress,
        ];

        try {
            $body = $this->client->get($locator, $options)->getBody();
            $this->io->debug("[TUF] Downloaded $locator");
            return $body;
        } catch (ClientException $e) {
            if ($e->getCode() === 404) {
                throw new RepoFileNotFound("$locator not found");
            } else {
                throw new \RuntimeException($e->getMessage(), $e->getCode(), $e);
            }
        }
    }
}
