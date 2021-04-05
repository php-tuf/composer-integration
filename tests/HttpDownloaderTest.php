<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Util\Filesystem;
use Composer\Util\HttpDownloader;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Tuf\Client\ResponseStream;
use Tuf\Client\Updater;
use Tuf\ComposerIntegration\HttpDownloaderAdapter;
use Tuf\ComposerIntegration\Repository\TufValidatedComposerRepository;

/**
 * @coversDefaultClass \Tuf\ComposerIntegration\HttpDownloaderAdapter
 */
class HttpDownloaderTest extends TestCase
{
    private $downloader;

    private $repository;

    private $storageDir;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $io =  $this->prophesize('\Composer\IO\IOInterface')->reveal();
        $config = $this->prophesize('\Composer\Config')->reveal();

        $decorated = new HttpDownloader($io, $config);
        $this->storageDir = sys_get_temp_dir() . '/https---test.net';
        $this->downloader = new HttpDownloaderAdapter($decorated, dirname($this->storageDir));

        $repoConfig = [
          'url' => 'https://test.net',
          'tuf' => [
            'root' => __DIR__ . '/../fixtures/test-project/tuf-root.json',
          ],
        ];
        $this->repository = new TufValidatedComposerRepository($repoConfig, $io, $config, $this->downloader);
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        (new Filesystem())->removeDirectoryPhp($this->storageDir);
        parent::tearDown();
    }

    /**
     * @covers ::get
     */
    public function testGet(): void
    {
        $response = new Response(200, [], Utils::streamFor('My bag of evil tricks has no limit!'));
        $updater = $this->prophesize(Updater::class);
        $updater->download('packages.json', Argument::cetera())
            ->willReturn(new FulfilledPromise(new ResponseStream($response)))
            ->shouldBeCalled();

        $this->downloader->addRepository($this->repository, $updater->reveal());

        $response = $this->downloader->get('https://test.net/packages.json', [
          'tuf' => [
            'repository' => 'https://test.net',
          ],
        ]);
        // The response should be converted to a native Composer response.
        $this->assertInstanceOf('\Composer\Util\Http\Response', $response);
        $this->assertSame('My bag of evil tricks has no limit!', $response->getBody());
    }

    /**
     * @covers ::addRepository
     */
    public function testAddRepository(): void
    {
        $this->downloader->addRepository($this->repository);
        $this->assertFileExists("$this->storageDir/root.json");
    }

    /**
     * Tests that methods of the decorated downloader are called.
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