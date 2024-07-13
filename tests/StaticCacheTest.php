<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\IO\IOInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Tuf\ComposerIntegration\StaticCache;
use Tuf\Loader\LoaderInterface;

/**
 * @covers \Tuf\ComposerIntegration\StaticCache
 */
class StaticCacheTest extends TestCase
{
    public function testStaticCache(): void
    {
        $decorated = $this->createMock(LoaderInterface::class);

        $mockedStream = Utils::streamFor('Across the universe');
        $decorated->expects($this->once())
            ->method('load')
            ->with('foo.txt', 60)
            ->willReturn(Create::promiseFor($mockedStream));

        $loader = new StaticCache($decorated, $this->createMock(IOInterface::class), 'picard');
        $stream = $loader->load('foo.txt', 60)->wait();

        // We should be at the beginning of the stream.
        $this->assertSame(0, $stream->tell());
        // Skip to the end of the stream, so we can confirm that it is rewound when loaded from the static cache.
        $stream->seek(0, SEEK_END);
        $this->assertGreaterThan(0, $stream->tell());

        $this->assertSame($stream, $loader->load('foo.txt', 60)->wait());
        $this->assertSame(0, $stream->tell());
    }
}
