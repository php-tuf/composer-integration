<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Util\Filesystem;
use Composer\Util\HttpDownloader;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Tuf\Client\ResponseStream;
use Tuf\Client\Updater;
use Tuf\ComposerIntegration\HttpDownloaderAdapter;
use Tuf\ComposerIntegration\Repository\TufValidatedComposerRepository;
use Tuf\Exception\PotentialAttackException\InvalidHashException;
use Tuf\Exception\RepoFileNotFound;

/**
 * @coversDefaultClass \Tuf\ComposerIntegration\HttpDownloaderAdapter
 */
class HttpDownloaderTest extends TestCase
{
    /**
     * The TUF-aware HTTP downloader under test.
     *
     * @var \Tuf\ComposerIntegration\HttpDownloaderAdapter
     */
    private $downloader;

    /**
     * A mocked TUF-validated Composer repository.
     *
     * @var \Tuf\ComposerIntegration\Repository\TufValidatedComposerRepository
     */
    private $repository;

    /**
     * Directory where TUF data is persisted for the test repository.
     *
     * @var string
     */
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
        $responses = [];
        // A boring, successful response.
        $response = new Response(200, [], Utils::streamFor('My bag of evil tricks has no limit!'));
        $responses[] = new FulfilledPromise(new ResponseStream($response));
        // A 304 (not modified) response with no body, which would normally
        // trigger a TUF exception.
        $error = new InvalidHashException(new ResponseStream(new Response(304)), '', 304);
        $responses[] = new RejectedPromise($error);
        // A RepoFileNotFound (i.e., a 404), which should result in an exception.
        $responses[] = new RejectedPromise(new RepoFileNotFound('Ask moar nicely.'));

        $updater = $this->prophesize(Updater::class);
        $updater->download('packages.json', Argument::cetera())
            ->willReturn(...$responses)
            ->shouldBeCalled();

        $this->downloader->addRepository($this->repository, $updater->reveal());

        $url = 'https://test.net/packages.json';
        $options = [
          'tuf' => [
            'repository' => 'https://test.net',
          ],
        ];
        $response = $this->downloader->get($url, $options);
        // The response should be converted to a native Composer response.
        $this->assertInstanceOf('\Composer\Util\Http\Response', $response);
        $this->assertSame('My bag of evil tricks has no limit!', $response->getBody());

        $response = $this->downloader->get($url, $options);
        // This 304 response would normally be an error, but should be converted
        // to a native Composer response with an empty body.
        $this->assertInstanceOf('\Composer\Util\Http\Response', $response);
        $this->assertSame(304, $response->getStatusCode());
        $this->assertEmpty($response->getBody());

        // A RepoFileNotFound exception should be converted to a native Composer
        // TransportException.
        $this->expectException('\Composer\Downloader\TransportException');
        $this->expectExceptionMessage('Ask moar nicely.');
        $this->downloader->get($url, $options);
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