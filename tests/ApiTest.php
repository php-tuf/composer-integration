<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PostFileDownloadEvent;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Repository\ComposerRepository;
use Composer\Util\Filesystem;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Tuf\Client\Updater;
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

        $dir = __DIR__ . '/../test-project';
        $factory = new Factory();
        $this->composer = $factory->createComposer(new NullIO(), "$dir/composer.json", false, $dir);

        // Composer requires the plugin package to be passed to the plugin manager, so load that from the composer.json
        // at the root of the repository.
        $source_package = __DIR__ . '/../composer.json';
        $this->assertFileIsReadable($source_package);
        $source_package = file_get_contents($source_package);
        $source_package = json_decode($source_package, true);
        // The package loader will throw an exception if no version is defined.
        $source_package += ['version' => '1.0.0'];

        $this->composer->getPluginManager()->addPlugin($this->plugin, false, (new ArrayLoader())->load($source_package));
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
     * Creates a TUF-validated repository object with a mocked TUF updater.
     *
     * @param Updater $updater
     *   The TUF updater to use.
     * @param array $config
     *   The repository configuration.
     *
     * @return TufValidatedComposerRepository
     *   The created repository object.
     */
    private function mockRepository(Updater $updater, array $config): TufValidatedComposerRepository
    {
        $manager = $this->composer->getRepositoryManager();

        $config['tuf'] = true;
        $repository = $manager->createRepository('composer', $config);
        $this->setUpdater($repository, $updater);
        // Prepend the new repository to the list, so that it will be found first
        // if the plugin searches for the repository by its URL.
        // @see \Tuf\ComposerIntegration\Plugin::postFileDownload()
        $manager->prependRepository($repository);

        return $repository;
    }

    /**
     * Sets the TUF updater in a TUF-validated repository object.
     *
     * @param TufValidatedComposerRepository $repository
     *   The TUF-validated repository.
     * @param Updater $updater
     *   The TUF updater to use.
     */
    private function setUpdater(TufValidatedComposerRepository $repository, Updater $updater): void
    {
        $reflector = new \ReflectionClass(TufValidatedComposerRepository::class);
        $property = $reflector->getProperty('updater');
        $property->setAccessible(true);
        $property->setValue($repository, $updater);
    }

    /**
     * Tests that package transport options are configured as expected.
     *
     * @covers \Tuf\ComposerIntegration\TufValidatedComposerRepository::configurePackageTransportOptions
     */
    public function testPackageTransportOptions(): void
    {
        $repository = new class () extends TufValidatedComposerRepository
        {
            public function __construct()
            {
            }

            public function getRepoConfig()
            {
                return [
                    'url' => 'https://packages.drupal.org/8',
                ];
            }

            public function configurePackageTransportOptions(PackageInterface $package): void
            {
                parent::configurePackageTransportOptions($package);
            }
        };

        $updater = $this->prophesize('\Tuf\ComposerIntegration\ComposerCompatibleUpdater');
        $updater->getLength('drupal/token/1.9.0.0')
            ->willReturn(36)
            ->shouldBeCalled();
        $this->setUpdater($repository, $updater->reveal());

        $package = new CompletePackage('drupal/token', '1.9.0.0', '1.9.0');
        $repository->configurePackageTransportOptions($package);
        $options = $package->getTransportOptions();
        $this->assertArrayHasKey('tuf', $options);
        $this->assertSame('https://packages.drupal.org/8', $options['tuf']['repository']);
        $this->assertSame('drupal/token/1.9.0.0', $options['tuf']['target']);
        $this->assertSame(36, $options['max_file_size']);
    }

    /**
     * @covers ::postFileDownload
     */
    public function testPostFileDownload(): void
    {
        $url = 'http://localhost:8080';
        $stream = Argument::type('\Psr\Http\Message\StreamInterface');
        $package = new CompletePackage('drupal/token', '1.9.0.0', '1.9');
        $package->setTransportOptions([
           'tuf' => [
               'repository' => "$url/targets",
               'target' => 'drupal/token/1.9.0.0',
           ],
        ]);
        $eventDispatcher = $this->composer->getEventDispatcher();

        $updater = $this->prophesize('\Tuf\ComposerIntegration\ComposerCompatibleUpdater');
        $repository = $this->mockRepository($updater->reveal(), [
            'url' => $url,
        ]);
        $updater->verify('packages.json', $stream)->shouldBeCalled();
        $updater->verify('drupal/token/1.9.0.0', $stream)->shouldBeCalled();

        $event = new PostFileDownloadEvent(
            PluginEvents::POST_FILE_DOWNLOAD,
            null,
            null,
            "$url/targets/packages.json",
            'metadata',
            [
                'repository' => $repository,
                'response' => $this->prophesize('\Composer\Util\Http\Response')->reveal(),
            ]
        );
        $eventDispatcher->dispatch($event->getName(), $event);

        $event = new PostFileDownloadEvent(
            PluginEvents::POST_FILE_DOWNLOAD,
            __FILE__,
            null,
            'https://ftp.drupal.org/files/projects/token-8.x-1.9.zip',
            'package',
            $package
        );
        $eventDispatcher->dispatch($event->getName(), $event);
    }

    /**
     * @covers ::preFileDownload
     */
    public function testPreFileDownload(): void
    {
        $url = 'http://localhost:8080';
        $eventDispatcher = $this->composer->getEventDispatcher();

        $updater = $this->prophesize('\Tuf\ComposerIntegration\ComposerCompatibleUpdater');
        $repository = $this->mockRepository($updater->reveal(), [
            'url' => $url,
        ]);

        $updater->getLength('packages.json')
            ->willReturn(1024)
            ->shouldBeCalled();
        $updater->getLength('bogus.json')
            ->willThrow('\Tuf\Exception\NotFoundException')
            ->shouldBeCalled();

        // If the target length is known, it should end up in the transport options.
        $event = new PreFileDownloadEvent(
            PluginEvents::PRE_FILE_DOWNLOAD,
            $this->composer->getLoop()->getHttpDownloader(),
            "$url/targets/packages.json",
            'metadata',
            [
                'repository' => $repository,
            ]
        );
        $eventDispatcher->dispatch($event->getName(), $event);
        $options = $event->getTransportOptions();
        $this->assertSame(1024, $options['max_file_size']);

        // If the target is unknown, the default maximum length should end up in
        // the transport options.
        $event = new PreFileDownloadEvent(
            PluginEvents::PRE_FILE_DOWNLOAD,
            $this->composer->getLoop()->getHttpDownloader(),
            "$url/targets/bogus.json",
            'metadata',
            [
                'repository' => $repository,
            ]
        );
        $eventDispatcher->dispatch($event->getName(), $event);
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
}
