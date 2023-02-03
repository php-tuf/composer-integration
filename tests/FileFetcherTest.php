<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Downloader\MaxFileSizeExceededException;
use Composer\Downloader\TransportException;
use Composer\Util\HttpDownloader;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Http\Message\StreamInterface;
use Tuf\ComposerIntegration\FileFetcher;
use Tuf\Exception\DownloadSizeException;
use Tuf\Exception\RepoFileNotFound;

/**
 * @covers \Tuf\ComposerIntegration\FileFetcher
 */
class FileFetcherTest extends TestCase
{
    use ProphecyTrait;

    public function testFileFetcher(): void
    {
        $downloader = $this->prophesize(HttpDownloader::class);
        $fetcher = new FileFetcher($downloader->reveal(), '/metadata', '/targets');

        $downloader->get('/metadata/root.json', ['max_file_size' => 129])
            ->willReturn(new Response())
            ->shouldBeCalled();
        $this->assertInstanceOf(StreamInterface::class, $fetcher->fetchMetadata('root.json', 128)->wait());

        $downloader->get('/targets/payload.zip', ['max_file_size' => 257])
            ->willReturn(new Response())
            ->shouldBeCalled();
        $this->assertInstanceOf(StreamInterface::class, $fetcher->fetchTarget('payload.zip', 256)->wait());

        // Any TransportException with a 404 error could should be converted
        // into a RepoFileNotFound exception.
        $exception = new TransportException();
        $exception->setStatusCode(404);
        $downloader->get('/metadata/bogus.txt', ['max_file_size' => 11])
            ->willThrow($exception)
            ->shouldBeCalled();
        try {
            $fetcher->fetchMetadata('bogus.txt', 10)->wait();
            $this->fail('Expected a RepoFileNotFound exception, but none was thrown.');
        } catch (RepoFileNotFound $e) {
            $this->assertSame('bogus.txt not found', $e->getMessage());
        }

        // A MaxFileSizeExceededException should be converted into a
        // DownloadSizeException.
        $downloader->get('/targets/too_big.txt', ['max_file_size' => 11])
            ->willThrow(new MaxFileSizeExceededException())
            ->shouldBeCalled();
        try {
            $fetcher->fetchTarget('too_big.txt', 10)->wait();
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
            $fetcher->fetchMetadata('wtf.txt', 10)->wait();
            $this->fail('Expected a RuntimeException, but none was thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame($originalException->getMessage(), $e->getMessage());
            $this->assertSame($originalException->getCode(), $e->getCode());
            $this->assertSame($originalException, $e->getPrevious());
        }
    }
}
