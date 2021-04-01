<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Util\HttpDownloader;
use PHPUnit\Framework\TestCase;
use Tuf\ComposerIntegration\HttpDownloaderAdapter;

class HttpDownloaderTest extends TestCase
{
    /**
     * Tests that method of the decorated downloader are called.
     */
    public function testDecoration(): void
    {
        $decorated = $this->prophesize(HttpDownloader::class);
        $downloader = new HttpDownloaderAdapter($decorated->reveal(), sys_get_temp_dir());

        $decorated->enableAsync()->shouldBeCalled();
        $decorated->wait(null)->shouldBeCalled();
        $decorated->countActiveJobs(null)->shouldBeCalled();
        $decorated->markJobDone()->shouldBeCalled();
        $decorated->setOptions([])->shouldBeCalled();
        $decorated->getOptions()->shouldBeCalled();
        $decorated->addCopy('url', 'destination', [])->shouldBeCalled();
        $decorated->copy('url', 'destination', [])->shouldBeCalled();
        $decorated->add('url', [])->shouldBeCalled();
        $decorated->get('url', [])->shouldBeCalled();

        $downloader->enableAsync();
        $downloader->wait();
        $downloader->markJobDone();
        $downloader->setOptions([]);
        $downloader->getOptions();
        $downloader->addCopy('url', 'destination');
        $downloader->copy('url', 'destination');
        $downloader->add('url');
        $downloader->get('url');
    }
}