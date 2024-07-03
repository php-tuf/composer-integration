<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Config;
use Composer\Downloader\MaxFileSizeExceededException;
use Composer\Downloader\TransportException;
use Composer\InstalledVersions;
use Composer\IO\IOInterface;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use DMS\PHPUnitExtensions\ArraySubset\Constraint\ArraySubset;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Tuf\ComposerIntegration\ComposerFileStorage;
use Tuf\ComposerIntegration\Loader;
use Tuf\Exception\DownloadSizeException;
use Tuf\Exception\NotFoundException;

/**
 * @covers \Tuf\ComposerIntegration\Loader
 */
class LoaderTest extends TestCase
{
    public function testLoader(): void
    {
        $loader = function (HttpDownloader $downloader): Loader {
            return new Loader(
              $downloader,
              $this->createMock(ComposerFileStorage::class),
              $this->createMock(IOInterface::class),
              '/metadata/'
            );
        };

        $url = '/metadata/root.json';
        $downloader = $this->createMock(HttpDownloader::class);
        $downloader->expects($this->atLeastOnce())
            ->method('get')
            ->with($url, $this->mockOptions(129))
            ->willReturn(new Response(['url' => $url], 200, [], ''));
        $this->assertInstanceOf(StreamInterface::class, $loader($downloader)->load('root.json', 128)->wait());

        // Any TransportException with a 404 error could should be converted
        // into a NotFoundException.
        $exception = new TransportException();
        $exception->setStatusCode(404);
        $downloader = $this->createMock(HttpDownloader::class);
        $downloader->expects($this->atLeastOnce())
            ->method('get')
            ->with('/metadata/bogus.txt', $this->mockOptions(11))
            ->willThrowException($exception);
        try {
            $loader($downloader)->load('bogus.txt', 10);
            $this->fail('Expected a NotFoundException, but none was thrown.');
        } catch (NotFoundException $e) {
            $this->assertSame('Item not found: bogus.txt', $e->getMessage());
        }

        // A MaxFileSizeExceededException should be converted into a
        // DownloadSizeException.
        $downloader = $this->createMock(HttpDownloader::class);
        $downloader->expects($this->atLeastOnce())
            ->method('get')
            ->with('/metadata/too_big.txt', $this->mockOptions(11))
            ->willThrowException(new MaxFileSizeExceededException());
        try {
            $loader($downloader)->load('too_big.txt', 10);
            $this->fail('Expected a DownloadSizeException, but none was thrown.');
        } catch (DownloadSizeException $e) {
            $this->assertSame('too_big.txt exceeded 10 bytes', $e->getMessage());
        }

        // Any other TransportException should be wrapped in a
        // \RuntimeException.
        $originalException = new TransportException('Whiskey Tango Foxtrot', -32);
        $downloader = $this->createMock(HttpDownloader::class);
        $downloader->expects($this->atLeastOnce())
            ->method('get')
            ->with('/metadata/wtf.txt', $this->mockOptions(11))
            ->willThrowException($originalException);
        try {
            $loader($downloader)->load('wtf.txt', 10);
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
        $modifiedTime = $storage->getModifiedTime('test');

        $downloader = $this->createMock(HttpDownloader::class);
        $url = '2.test.json';
        $response = $this->createMock(Response::class);
        $response->expects($this->atLeastOnce())
            ->method('getStatusCode')
            ->willReturn(304);
        $response->expects($this->never())
            ->method('getBody');
        $downloader->expects($this->atLeastOnce())
            ->method('get')
            ->with($url, $this->mockOptions(1025, $modifiedTime))
            ->willReturn($response);

        $loader = new Loader($downloader, $storage, $this->createMock(IOInterface::class));
        // Since the response has no actual body data, the fact that we get the contents
        // of the file we wrote here is proof that it was ultimately read from persistent
        // storage by the loader.
        $this->assertSame('Some test data.', $loader->load('2.test.json', 1024)->wait()->getContents());
    }

    public function testStaticCache(): void
    {
        $response = $this->createMock(Response::class);
        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects($this->any())
            ->method('getBody')
            ->willReturn('Truly, this is amazing stuff.');

        $downloader = $this->createMock(HttpDownloader::class);
        $downloader->expects($this->once())
            ->method('get')
            ->with('foo.txt', $this->mockOptions(1025))
            ->willReturn($response);

        $loader = new Loader($downloader, $this->createMock(ComposerFileStorage::class), $this->createMock(IOInterface::class));
        $stream = $loader->load('foo.txt', 1024)->wait();

        // We should be at the beginning of the stream.
        $this->assertSame(0, $stream->tell());
        // Skip to the end of the stream, so we can confirm that it is rewound
        // when loaded from the static cache.
        $stream->seek(0, SEEK_END);
        $this->assertGreaterThan(0, $stream->tell());

        $this->assertSame($stream, $loader->load('foo.txt', 1024)->wait());
        $this->assertSame(0, $stream->tell());
    }

    private function mockOptions(int $expectedSize, ?\DateTimeInterface $modifiedTime = null): object
    {
        $options = ['max_file_size' => $expectedSize];
        $options['http']['header'][] = sprintf(
          'X-PHP-TUF: spec=1.0.33; client=%s; plugin=%s',
          InstalledVersions::getVersion('php-tuf/php-tuf'),
          InstalledVersions::getVersion('php-tuf/composer-integration'),
        );
        if ($modifiedTime) {
            $subset['http']['header'][] = "If-Modified-Since: " . $modifiedTime->format('D, d M Y H:i:s') . ' GMT';
        }
        return new ArraySubset($options);
    }
}
