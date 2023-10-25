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
    public function __construct(private HttpDownloader $downloader, private ComposerFileStorage $storage, private string $baseUrl = '')
    {
    }

    /**
     * {@inheritDoc}
     */
    public function load(string $locator, int $maxBytes): StreamInterface
    {
        $url = $this->baseUrl . $locator;

        $options = [
            // Add 1 to $maxBytes to work around a bug in Composer.
            // @see \Tuf\ComposerIntegration\ComposerCompatibleUpdater::getLength()
            'max_file_size' => $maxBytes + 1,
        ];

        // The name of the file in persistent storage will differ from $locator.
        $name = basename($locator, '.json');
        $name = ltrim($name, '.0123456789');

        $modifiedTime = $this->storage->getModifiedTime($name);
        if ($modifiedTime) {
            // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/If-Modified-Since.
            $options['http']['header'][] = 'If-Modified-Since: ' . $modifiedTime->format('D, d M Y H:i:s') . ' GMT';
        }

        try {
            $response = $this->downloader->get($url, $options);
            // If we sent an If-Modified-Since header and received a 304 (Not Modified)
            // response, we can just load the file from cache.
            if ($response->getStatusCode() === 304) {
                $content = $this->storage->read($name);
            } else {
                $content = $response->getBody();
            }
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
