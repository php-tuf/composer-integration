<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositorySecurityException;
use Composer\Util\Filesystem;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Tuf\ComposerIntegration\Plugin;
use Tuf\ComposerIntegration\TufValidatedComposerRepository;

/**
 * Contains unit test coverage of the Composer plugin class.
 *
 * @coversDefaultClass \Tuf\ComposerIntegration\Plugin
 */
class ApiTest extends TestCase
{
    use ProphecyTrait;

    /**
     * The Composer instance under test.
     *
     * @var \Composer\Composer
     */
    private $composer;

    /**
     * The plugin instance under test.
     *
     * @var \Tuf\ComposerIntegration\Plugin
     */
    private $plugin;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->plugin = new Plugin();

        $factory = new Factory();
        $this->composer = $factory->createComposer(new NullIO(), [], false, __DIR__);
        $this->composer->getPluginManager()->addPlugin($this->plugin);
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        $this->composer->getPluginManager()->uninstallPlugin($this->plugin);
        (new Filesystem())
            ->removeDirectory(__DIR__ . '/vendor');
        parent::tearDown();
    }

    /**
     * Creates a TUF-protected Composer repository.
     *
     * @param array $config
     *   (optional) The repository configuration.
     * @param mixed $bootstrap
     *   (optional) The data which should be used to bootstrap trust. Must be streamable
     *   (i.e., a string or a file resource). Defaults to ../metadata/root.json.
     *
     * @return TufValidatedComposerRepository
     *   The repository instance.
     */
    private function createRepository(array $config = [], $bootstrap = null): TufValidatedComposerRepository
    {
        if (empty($bootstrap)) {
            $bootstrap = fopen(__DIR__ . '/../metadata/root.json', 'r');
            $this->assertIsResource($bootstrap);
        }
        $stream = Utils::streamFor($bootstrap);
        $promise = new FulfilledPromise($stream);

        $fetcher = $this->prophesize('\Tuf\Client\RepoFileFetcherInterface');
        $fetcher->fetchMetadata('root.json', $stream->getSize())
            ->willReturn($promise)
            ->shouldBeCalled();

        $config['tuf']['root']['hashes']['sha256'] = '6cf95b77cedc832c980b81560704bd2fb9ee32ec4c1a73395a029b76715705cc';
        $config['tuf']['root']['length'] = $stream->getSize();
        $config['tuf']['_fileFetcher'] = $fetcher->reveal();

        return $this->composer->getRepositoryManager()
            ->createRepository('composer', $config);
    }

    /**
     * @covers ::preFileDownload
     */
    public function testPreFileDownload(): void
    {
        $updater = $this->prophesize('\Tuf\ComposerIntegration\ComposerCompatibleUpdater');
        $updater->getLength('packages.json')
            ->willReturn(1024)
            ->shouldBeCalled();
        $updater->getLength('bogus.json')
            ->willThrow('\Tuf\Exception\NotFoundException')
            ->shouldBeCalled();

        $repository = $this->createRepository([
            'url' => 'https://example.com',
            'tuf' => [
                '_updater' => $updater->reveal(),
            ],
        ]);

        // If the target length is known, it should end up in the transport options.
        $event = new PreFileDownloadEvent(
            PluginEvents::PRE_FILE_DOWNLOAD,
            $this->composer->getLoop()->getHttpDownloader(),
            'https://example.com/targets/packages.json',
            'metadata',
            [
                'repository' => $repository,
            ]
        );
        $this->composer->getEventDispatcher()->dispatch($event->getName(), $event);
        $options = $event->getTransportOptions();
        $this->assertSame(1024, $options['max_file_size']);

        // If the target is unknown, the default maximum length should end up in
        // the transport options.
        $event = new PreFileDownloadEvent(
            PluginEvents::PRE_FILE_DOWNLOAD,
            $this->composer->getLoop()->getHttpDownloader(),
            'https://example.com/targets/bogus.json',
            'metadata',
            [
                'repository' => $repository,
            ]
        );
        $this->composer->getEventDispatcher()->dispatch($event->getName(), $event);
        $options = $event->getTransportOptions();
        $this->assertSame(TufValidatedComposerRepository::MAX_404_BYTES, $options['max_file_size']);
    }

    /**
     * @covers ::activate
     */
    public function testActivate(): void
    {
        $manager = $this->composer->getRepositoryManager();

        // At least one Composer repository should be loaded.
        $repositories = array_filter($manager->getRepositories(), function ($repository) {
           return $repository instanceof ComposerRepository;
        });
        $this->assertNotEmpty($repositories);

        // All Composer repositories should be using the TUF driver.
        foreach ($repositories as $repository) {
            $this->assertInstanceOf(TufValidatedComposerRepository::class, $repository);
        }

        // The TUF driver should also be used when creating a new Composer repository.
        $repository = $manager->createRepository('composer', [
           'url' => 'https://packagist.example.net',
        ]);
        $this->assertInstanceOf(TufValidatedComposerRepository::class, $repository);
    }

    /**
     * Tests fetching and validating root TUF data from the server.
     */
    public function testFetchRootData(): void
    {
        $this->createRepository([
            'url' => 'https://example.org',
        ]);

        $rootFile = __DIR__ . '/vendor/composer/tuf/https---example.org/root.json';
        $this->assertFileExists($rootFile);
        $this->assertSame('6cf95b77cedc832c980b81560704bd2fb9ee32ec4c1a73395a029b76715705cc', hash_file('sha256', $rootFile));
    }

    /**
     * Tests fetching invalid root TUF data from the server.
     */
    public function testFetchInvalidRootData(): void
    {
        $this->expectException('\Composer\Repository\RepositorySecurityException');
        $this->expectExceptionMessage("TUF root data from server did not match expected sha256 hash.");

        $this->createRepository([
            'url' => 'https://example.org',
        ], "Shall I compare thee to a summer's day?");
    }
}
