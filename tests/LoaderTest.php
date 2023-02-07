<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Downloader\MaxFileSizeExceededException;
use Composer\Downloader\TransportException;
use Composer\Util\HttpDownloader;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Http\Message\StreamInterface;
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
        $loader = new Loader($downloader->reveal(), '/metadata/');

        $downloader->get('/metadata/root.json', ['max_file_size' => 129])
            ->willReturn(new Response())
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
}
