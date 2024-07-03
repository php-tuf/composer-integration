<?php

namespace Tuf\ComposerIntegration;

use Composer\Downloader\MaxFileSizeExceededException;
use Composer\Downloader\TransportException;
use Composer\InstalledVersions;
use Composer\IO\IOInterface;
use Composer\Util\HttpDownloader;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Tuf\Exception\DownloadSizeException;
use Tuf\Exception\NotFoundException;
use Tuf\Loader\LoaderInterface;

/**
 * Defines a data loader that wraps around Composer's HttpDownloader.
 */
class Loader implements LoaderInterface
{
    /**
     * @var \Psr\Http\Message\StreamInterface[]
     */
    private array $cache = [];

    public function __construct(
        private HttpDownloader $downloader,
        private ComposerFileStorage $storage,
        private IOInterface $io,
        private string $baseUrl = ''
    ) {}

    /**
     * {@inheritDoc}
     */
    public function load(string $locator, int $maxBytes): PromiseInterface
    {
        $url = $this->baseUrl . $locator;
        if (array_key_exists($url, $this->cache)) {
            $this->io->debug("[TUF] Loading $url from static cache.");

            $cachedStream = $this->cache[$url];
            // The underlying stream should always be seekable.
            assert($cachedStream->isSeekable());
            $cachedStream->rewind();
            return Create::promiseFor($cachedStream);
        }

        $options = [
            // Add 1 to $maxBytes to work around a bug in Composer.
            // @see \Tuf\ComposerIntegration\ComposerCompatibleUpdater::getLength()
            'max_file_size' => $maxBytes + 1,
        ];
        // Always send a X-PHP-TUF header with version information.
        $options['http']['header'][] = self::versionHeader();

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
        } catch (TransportException $e) {
            if ($e->getStatusCode() === 404) {
                throw new NotFoundException($locator);
            } elseif ($e instanceof MaxFileSizeExceededException) {
                throw new DownloadSizeException("$locator exceeded $maxBytes bytes");
            } else {
                throw new \RuntimeException($e->getMessage(), $e->getCode(), $e);
            }
        }

        // If we sent an If-Modified-Since header and received a 304 (Not Modified)
        // response, we can just load the file from cache.
        if ($response->getStatusCode() === 304) {
            $content = Utils::tryFopen($this->storage->toPath($name), 'r');
        } else {
            // To prevent the static cache from running out of memory, write the response
            // contents to a temporary stream (which will turn into a temporary file once
            // once we've written 1024 bytes to it), which will be automatically cleaned
            // up when it is garbage collected.
            $content = Utils::tryFopen('php://temp/maxmemory:1024', 'r+');
            fwrite($content, $response->getBody());
        }

        $stream = $this->cache[$url] = Utils::streamFor($content);
        $stream->rewind();
        return Create::promiseFor($stream);
    }

    private static function versionHeader(): string
    {
        // @todo The spec version should come from a constant in PHP-TUF itself.
        return sprintf(
          'X-PHP-TUF: spec=1.0.33; client=%s; plugin=%s',
          InstalledVersions::getVersion('php-tuf/php-tuf'),
          InstalledVersions::getVersion('php-tuf/composer-integration'),
        );
    }
}
