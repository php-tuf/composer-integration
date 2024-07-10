<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Config;
use Composer\IO\NullIO;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Tuf\ComposerIntegration\ComposerFileStorage;
use Tuf\ComposerIntegration\Loader;
use Tuf\Exception\NotFoundException;

/**
 * @covers \Tuf\ComposerIntegration\Loader
 */
class LoaderTest extends TestCase
{
    private readonly MockHandler $responses;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->responses = new MockHandler();
    }

    private function getLoader(?ComposerFileStorage $storage = null): Loader
    {
        return new Loader(
            $storage ?? $this->createMock(ComposerFileStorage::class),
            new NullIO(),
            '/metadata/',
            new Client([
                'handler' => HandlerStack::create($this->responses),
            ]),
        );
    }

    public function testBasicSuccessAndFailure(): void
    {
        $loader = $this->getLoader();

        $this->responses->append(new Response());
        $this->assertInstanceOf(StreamInterface::class, $loader->load('root.json', 128)->wait());
        $this->assertRequestOptions();

        // Any 404 response should raise a NotFoundException.
        $this->responses->append(new Response(404));
        try {
            $loader->load('bogus.txt', 10)->wait();
            $this->fail('Expected a NotFoundException, but none was thrown.');
        } catch (NotFoundException $e) {
            $this->assertSame('Item not found: bogus.txt', $e->getMessage());
            $this->assertRequestOptions();
        }
    }

    public function testNotModifiedResponse(): void
    {
        $storage = ComposerFileStorage::create('https://example.net/packages', new Config());

        $method = new \ReflectionMethod($storage, 'write');
        $method->invoke($storage, 'test', 'Some test data.');

        $this->responses->append(new Response(304));
        // Since the response has no actual body data, the fact that we get the contents
        // of the file we wrote here is proof that it was ultimately read from persistent
        // storage by the loader.
        $this->assertSame('Some test data.', $this->getLoader($storage)->load('2.test.json', 1024)->wait()->getContents());
        $this->assertRequestOptions($storage->getModifiedTime('test'));
    }

    public function testStaticCache(): void
    {
        $loader = $this->getLoader();

        $this->responses->append(
            new Response(body: 'Truly, this is amazing stuff.'),
        );
        $stream = $loader->load('foo.txt', 1024)->wait();
        $this->assertRequestOptions();

        // We should be at the beginning of the stream.
        $this->assertSame(0, $stream->tell());
        // Skip to the end of the stream, so we can confirm that it is rewound
        // when loaded from the static cache.
        $stream->seek(0, SEEK_END);
        $this->assertGreaterThan(0, $stream->tell());

        $this->assertSame($stream, $loader->load('foo.txt', 1024)->wait());
        $this->assertSame(0, $stream->tell());
    }

    private function assertRequestOptions(?\DateTimeInterface $modifiedTime = null): void
    {
        $options = $this->responses->getLastOptions();
        $this->assertIsCallable($options[RequestOptions::PROGRESS]);

        $request = $this->responses->getLastRequest();

        // There's no real reason to expose versionHeader() to the world, so
        // it's okay to use reflection here.
        $method = new \ReflectionMethod(Loader::class, 'versionHeader');
        $this->assertSame($request?->getHeaderLine('X-PHP-TUF'), $method->invoke(null));

        if ($modifiedTime) {
            $this->assertSame($request?->getHeaderLine('If-Modified-Since'), $modifiedTime->format('D, d M Y H:i:s') . ' GMT');
        }
    }
}
