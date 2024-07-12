<?php

namespace Tuf\ComposerIntegration;

use Composer\IO\IOInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Tuf\Loader\LoaderInterface;

class StaticCache implements LoaderInterface
{
    /**
     * @var \Psr\Http\Message\StreamInterface[]
     */
    private array $cache = [];

    public function __construct(
        private readonly LoaderInterface $decorated,
        private readonly IOInterface $io,
    ) {}

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
        return $this->cache[$locator] = $this->decorated->load($locator, $maxBytes);
    }
}
