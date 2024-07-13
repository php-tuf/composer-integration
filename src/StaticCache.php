<?php

namespace Tuf\ComposerIntegration;

use Composer\IO\IOInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\StreamInterface;
use Tuf\Loader\LoaderInterface;

class StaticCache implements LoaderInterface
{
    private static array $cache = [];

    public function __construct(
        private readonly LoaderInterface $decorated,
        private readonly IOInterface $io,
        private readonly string $bin,
    ) {
        static::$cache += [
            $bin => [],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function load(string $locator, int $maxBytes): PromiseInterface
    {
        $cacheBin = &static::$cache[$this->bin];

        if (array_key_exists($locator, $cacheBin)) {
            $this->io->debug("[TUF] Loading '$locator' from static cache.");

            $cachedStream = $cacheBin[$locator];
            // The underlying stream should always be seekable.
            assert($cachedStream->isSeekable());
            $cachedStream->rewind();
            return Create::promiseFor($cachedStream);
        }
        return $this->decorated->load($locator, $maxBytes)
            ->then(function (StreamInterface $stream) use ($locator) {
                return static::$cache[$this->bin][$locator] = $stream;
            });
    }
}
