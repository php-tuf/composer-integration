<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Config;
use Composer\Downloader\MaxFileSizeExceededException;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Http\Message\StreamInterface;
use Tuf\ComposerIntegration\ComposerFileStorage;
use Tuf\ComposerIntegration\Loader;
use Tuf\Exception\DownloadSizeException;
use Tuf\Exception\RepoFileNotFound;

/**
 * @covers \Tuf\ComposerIntegration\Loader
 */
class LoaderTest extends TestCase
{
    use ProphecyTrait;

    public function testLoader(): void
    {
        $downloader = $this->prophesize(HttpDownloader::class);
        $io = $this->prophesize(IOInterface::class);
        $storage = $this->prophesize(ComposerFileStorage::class);
        $loader = new Loader($downloader->reveal(), $storage->reveal(), $io->reveal(), '/metadata/');

        $url = '/metadata/root.json';
        $downloader->get($url, ['max_file_size' => 129])
            ->willReturn(new Response(['url' => $url], 200, [], null))
            ->shouldBeCalled();
        $this->assertInstanceOf(StreamInterface::class, $loader->load('root.json', 128));

        // Any TransportException with a 404 error could should be converted
        // into a RepoFileNotFound exception.
        $exception = new TransportException();
        $exception->setStatusCode(404);
        $downloader->get('/metadata/bogus.txt', ['max_file_size' => 11])
            ->willThrow($exception)
            ->shouldBeCalled();
        try {
            $loader->load('bogus.txt', 10);
            $this->fail('Expected a RepoFileNotFound exception, but none was thrown.');
        } catch (RepoFileNotFound $e) {
            $this->assertSame('bogus.txt not found', $e->getMessage());
        }

        // A MaxFileSizeExceededException should be converted into a
        // DownloadSizeException.
        $downloader->get('/metadata/too_big.txt', ['max_file_size' => 11])
            ->willThrow(new MaxFileSizeExceededException())
            ->shouldBeCalled();
        try {
            $loader->load('too_big.txt', 10);
            $this->fail('Expected a DownloadSizeException, but none was thrown.');
        } catch (DownloadSizeException $e) {
            $this->assertSame('too_big.txt exceeded 10 bytes', $e->getMessage());
        }

        // Any other TransportException should be wrapped in a
        // \RuntimeException.
        $originalException = new TransportException('Whiskey Tango Foxtrot', -32);
        $downloader->get('/metadata/wtf.txt', ['max_file_size' => 11])
            ->willThrow($originalException)
            ->shouldBeCalled();
        try {
            $loader->load('wtf.txt', 10);
            $this->fail('Expected a RuntimeException, but none was thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame($originalException->getMessage(), $e->getMessage());
            $this->assertSame($originalException->getCode(), $e->getCode());
            $this->assertSame($originalException, $e->getPrevious());
        }
    }

    public function testNotModifiedResponse(): void
    {
        $config = new Config();
        $storage = ComposerFileStorage::create('https://example.net/packages', $config);

        $method = new \ReflectionMethod($storage, 'write');
        $method->setAccessible(true);
        $method->invoke($storage, 'test', 'Some test data.');
        $modifiedTime = $storage->getModifiedTime('test')->format('D, d M Y H:i:s');

        $downloader = $this->prophesize(HttpDownloader::class);
        $options = [
            'max_file_size' => 1025,
            'http' => [
                'header' => [
                    "If-Modified-Since: $modifiedTime GMT",
                ],
            ],
        ];
        $url = '2.test.json';
        $response = $this->prophesize(Response::class);
        $response->getStatusCode()->willReturn(304)->shouldBeCalled();
        $response->getBody()->shouldNotBeCalled();
        $downloader->get($url, $options)
            ->willReturn($response->reveal())
            ->shouldBeCalled();

        $loader = new Loader($downloader->reveal(), $storage, $this->prophesize(IOInterface::class)->reveal());
        // Since the response has no actual body data, the fact that we get the contents
        // of the file we wrote here is proof that it was ultimately read from persistent
        // storage by the loader.
        $this->assertSame('Some test data.', $loader->load('2.test.json', 1024)->getContents());
    }

    public function testStaticCache(): void
    {
        $response = $this->prophesize(Response::class);
        $response->getStatusCode()->willReturn(200);
        $response->getBody()->willReturn('Truly, this is amazing stuff.');

        $downloader = $this->prophesize(HttpDownloader::class);
        $downloader->get('foo.txt', ['max_file_size' => 1025])
            ->willReturn($response->reveal())
            ->shouldBeCalledOnce();

        $loader = new Loader($downloader->reveal(), $this->prophesize(ComposerFileStorage::class)->reveal(), $this->prophesize(IOInterface::class)->reveal());
        $stream = $loader->load('foo.txt', 1024);

        // We should be at the beginning of the stream.
        $this->assertSame(0, $stream->tell());
        // Skip to the end of the stream, so we can confirm that it is rewound
        // when loaded from the static cache.
        $stream->seek(0, SEEK_END);
        $this->assertGreaterThan(0, $stream->tell());

        $this->assertSame($stream, $loader->load('foo.txt', 1024));
        $this->assertSame(0, $stream->tell());
    }
}
