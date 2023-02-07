<?php

namespace Tuf\ComposerIntegration;

use Composer\Downloader\MaxFileSizeExceededException;
use Composer\Downloader\TransportException;
use Composer\Util\HttpDownloader;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use Tuf\Exception\DownloadSizeException;
use Tuf\Exception\RepoFileNotFound;
use Tuf\Loader\LoaderInterface;

/**
 * Defines a data loader that wraps around Composer's HttpDownloader.
 */
class Loader implements LoaderInterface
{
    public function __construct(private HttpDownloader $downloader, private string $baseUrl = '')
    {
    }

    /**
     * {@inheritDoc}
     */
    public function load(string $locator, int $maxBytes): StreamInterface
    {
        $url = $this->baseUrl . $locator;

        try {
            // Add 1 to $maxBytes to work around a bug in Composer.
            // @see \Tuf\ComposerIntegration\ComposerCompatibleUpdater::getLength()
            $content = $this->downloader->get($url, ['max_file_size' => $maxBytes + 1])
                ->getBody();
            return Utils::streamFor($content);
        } catch (TransportException $e) {
            if ($e->getStatusCode() === 404) {
                throw new RepoFileNotFound("$locator not found");
            } elseif ($e instanceof MaxFileSizeExceededException) {
                throw new DownloadSizeException("$locator exceeded $maxBytes bytes");
            } else {
                throw new \RuntimeException($e->getMessage(), $e->getCode(), $e);
            }
        }
    }
}
