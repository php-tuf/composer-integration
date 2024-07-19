<?php

namespace Tuf\ComposerIntegration;

use Composer\InstalledVersions;
use Composer\IO\IOInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
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
    public function __construct(
        private readonly ComposerFileStorage $storage,
        private readonly IOInterface $io,
        private readonly ClientInterface $client,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function load(string $locator, int $maxBytes): PromiseInterface
    {
        $options = [
            // @see https://docs.guzzlephp.org/en/stable/request-options.html#stream
            RequestOptions::STREAM => true,
        ];
        // Try to enforce the maximum download size during transport. This will only have an effect
        // if cURL is in use.
        // @see https://docs.guzzlephp.org/en/stable/request-options.html#progress
        $options[RequestOptions::PROGRESS] = function (int $expectedBytes, int $bytesSoFar) use ($locator, $maxBytes): void
        {
            // Add 1 to $maxBytes to work around a bug in Composer.
            // @see \Tuf\ComposerIntegration\ComposerCompatibleUpdater::getLength()
            if ($bytesSoFar > $maxBytes + 1) {
                throw new DownloadSizeException("$locator exceeded $maxBytes bytes");
            }
        };
        // Always send a X-PHP-TUF header with version information.
        // @see https://docs.guzzlephp.org/en/stable/request-options.html#headers
        $options[RequestOptions::HEADERS]['X-PHP-TUF'] = self::versionHeader();

        // The name of the file in persistent storage will differ from $locator.
        $name = basename($locator, '.json');
        $name = ltrim($name, '.0123456789');

        $modifiedTime = $this->storage->getModifiedTime($name);
        if ($modifiedTime) {
            // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/If-Modified-Since.
            // @see https://docs.guzzlephp.org/en/stable/request-options.html#headers
            $options[RequestOptions::HEADERS]['If-Modified-Since'] = $modifiedTime->format('D, d M Y H:i:s') . ' GMT';
        }

        $onSuccess = function (ResponseInterface $response) use ($name, $locator): StreamInterface {
            $status = $response->getStatusCode();
            $this->io->debug("[TUF] $status: '$locator'");

            // If we sent an If-Modified-Since header and received a 304 (Not Modified)
            // response, we can just load the file from cache.
            if ($status === 304) {
                $content = Utils::tryFopen($this->storage->toPath($name), 'r');
                return Utils::streamFor($content);
            } else {
                return $response->getBody();
            }
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
          'client=%s; plugin=%s',
          InstalledVersions::getVersion('php-tuf/php-tuf'),
          InstalledVersions::getVersion('php-tuf/composer-integration'),
        );
    }
}
