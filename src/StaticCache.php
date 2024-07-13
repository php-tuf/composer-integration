<?php

namespace Tuf\ComposerIntegration;

use Composer\IO\IOInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\StreamInterface;
use Tuf\Loader\LoaderInterface;

/**
 * Caches all downloaded TUF metadata streams in memory.
 *
 * The internal $cache array needs to be static because certain Composer commands
 * will completely reset Composer in the middle of the process -- for example, the
 * `require` command does it before actually updating the installed packages -- which
 * would blow away a non-static (instance) cache. Making $cache static means it
 * persists for the lifetime of the PHP process, no matter how many times Composer
 * resets itself.
 *
 * Because this is effectively the one static cache for *every* TUF-protected
 * repository, it is internally divided into bins, keyed by the base URL from which
 * the TUF metadata is be downloaded.
 */
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
            ->then(function (StreamInterface $stream) use ($locator, &$cacheBin) {
                return $cacheBin[$locator] = $stream;
            });
    }
}
