<?php

namespace Tuf\ComposerIntegration;

use Composer\InstalledVersions;
use Composer\IO\IOInterface;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
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

    private readonly ClientInterface $client;

    public function __construct(
        private readonly ComposerFileStorage $storage,
        private readonly IOInterface $io,
        string $baseUrl = ''
    ) {
        $options = [];
        if ($baseUrl) {
            $options['base_uri'] = $baseUrl;
        }
        $this->client = new Client($options);
    }

    /**
     * {@inheritDoc}
     */
    public function load(string $locator, int $maxBytes): PromiseInterface
    {
        if (array_key_exists($locator, $this->cache)) {
            $this->io->debug("[TUF] Loading '$locator' from static cache.");

            $cachedStream = $this->cache[$locator];
            // The underlying stream should always be seekable.
            assert($cachedStream->isSeekable());
            $cachedStream->rewind();
            return Create::promiseFor($cachedStream);
        }

        $options = [];
        // Try to enforce the maximum download size during transport. This will only have an effect
        // if cURL is in use.
        $options[RequestOptions::PROGRESS] = function (int $expectedBytes, int $bytesSoFar) use ($locator, $maxBytes): void
        {
            // Add 1 to $maxBytes to work around a bug in Composer.
            // @see \Tuf\ComposerIntegration\ComposerCompatibleUpdater::getLength()
            if ($bytesSoFar > $maxBytes + 1) {
                throw new DownloadSizeException("$locator exceeded $maxBytes bytes");
            }
        };
        // Always send a X-PHP-TUF header with version information.
        $options[RequestOptions::HEADERS]['X-PHP-TUF'] = self::versionHeader();

        // The name of the file in persistent storage will differ from $locator.
        $name = basename($locator, '.json');
        $name = ltrim($name, '.0123456789');

        $modifiedTime = $this->storage->getModifiedTime($name);
        if ($modifiedTime) {
            // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/If-Modified-Since.
            $options[RequestOptions::HEADERS]['If-Modified-Since'] = $modifiedTime->format('D, d M Y H:i:s') . ' GMT';
        }

        $onSuccess = function (ResponseInterface $response) use ($name, $locator): StreamInterface {
            $status = $response->getStatusCode();
            $this->io->debug("[TUF] $status: '$locator'");

            // If we sent an If-Modified-Since header and received a 304 (Not Modified)
            // response, we can just load the file from cache.
            if ($status === 304) {
                $content = Utils::tryFopen($this->storage->toPath($name), 'r');
                $stream = Utils::streamFor($content);
            } else {
                $stream = $response->getBody();
            }
            $this->cache[$locator] = $stream;
            return $stream;
        };
        $onFailure = function (\Throwable $e) use ($locator): never {
            if ($e instanceof ClientException && $e->getCode() === 404) {
                throw new NotFoundException($locator);
            }
            throw $e;
        };

        return $this->client->getAsync($locator, $options)->then($onSuccess, $onFailure);
    }

    private static function versionHeader(): string
    {
        return sprintf(
          'X-PHP-TUF: client=%s; plugin=%s',
          InstalledVersions::getVersion('php-tuf/php-tuf'),
          InstalledVersions::getVersion('php-tuf/composer-integration'),
        );
    }
}
