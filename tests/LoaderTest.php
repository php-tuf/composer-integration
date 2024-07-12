<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Config;
use Composer\Downloader\MaxFileSizeExceededException;
use Composer\Downloader\TransportException;
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

        $loader = new Loader($downloader, $storage);
        // Since the response has no actual body data, the fact that we get the contents
        // of the file we wrote here is proof that it was ultimately read from persistent
        // storage by the loader.
        $this->assertSame('Some test data.', $loader->load('2.test.json', 1024)->wait()->getContents());
    }

    private function mockOptions(int $expectedSize, ?\DateTimeInterface $modifiedTime = null): object
    {
        $options = ['max_file_size' => $expectedSize];

        // There's no real reason to expose versionHeader() to the world, so
        // it's okay to use reflection here.
        $method = new \ReflectionMethod(Loader::class, 'versionHeader');
        $options['http']['header'][] = $method->invoke(null);

        if ($modifiedTime) {
            $options['http']['header'][] = "If-Modified-Since: " . $modifiedTime->format('D, d M Y H:i:s') . ' GMT';
        }
        return new ArraySubset($options);
    }
}
