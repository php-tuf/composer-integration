<?php

namespace Tuf\ComposerIntegration;

use Composer\Downloader\MaxFileSizeExceededException;
use Composer\Downloader\TransportException;
use Composer\Util\HttpDownloader;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Tuf\Client\RepoFileFetcherInterface;
use Tuf\Exception\DownloadSizeException;
use Tuf\Exception\RepoFileNotFound;

class FileFetcher implements RepoFileFetcherInterface
{
    public function __construct(private HttpDownloader $downloader, private string $metadataBaseUrl, private string $targetsBaseUrl)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function fetchMetadata(string $fileName, int $maxBytes): PromiseInterface
    {
        return $this->doFetch($this->metadataBaseUrl . '/' . $fileName, $maxBytes);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchTarget(string $fileName, int $maxBytes): PromiseInterface
    {
        return $this->doFetch($this->targetsBaseUrl . '/' . $fileName, $maxBytes);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchMetadataIfExists(string $fileName, int $maxBytes): ?string
    {
        $onFailure = function (\Throwable $e) {
            if ($e instanceof RepoFileNotFound) {
                return null;
            } else {
                throw $e;
            }
        };
        return $this->fetchMetadata($fileName, $maxBytes)
            ->then(null, $onFailure)
            ->wait();
    }

    private function doFetch(string $url, int $maxBytes): PromiseInterface
    {
        $fileName = parse_url($url, PHP_URL_PATH);
        $fileName = basename($fileName);

        try {
            // Add 1 to $maxBytes to work around a bug in Composer.
            // @see \Tuf\ComposerIntegration\ComposerCompatibleUpdater::getLength()
            $content = $this->downloader->get($url, ['max_file_size' => $maxBytes + 1])
                ->getBody();
            $stream = Utils::streamFor($content);
            return Create::promiseFor($stream);
        } catch (TransportException $e) {
            if ($e->getStatusCode() === 404) {
                $error = new RepoFileNotFound("$fileName not found");
            } elseif ($e instanceof MaxFileSizeExceededException) {
                $error = new DownloadSizeException("$fileName exceeded $maxBytes bytes");
            } else {
                $error = new \RuntimeException($e->getMessage(), $e->getCode(), $e);
            }
            return Create::rejectionFor($error);
        }
    }
}
