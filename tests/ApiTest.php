<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\CompletePackage;
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
     * Sets the TUF updater in a TUF-validated repository instance.
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

            public function configurePackageTransportOptions(PackageInterface $package)
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

        $manager = $this->composer->getRepositoryManager();
        $repository = $manager->createRepository('composer', [
            'url' => $url,
            'tuf' => true,
        ]);
        $manager->prependRepository($repository);
        $updater = $this->prophesize('\Tuf\ComposerIntegration\ComposerCompatibleUpdater');
        $updater->verify('packages.json', $stream)->shouldBeCalled();
        $updater->verify('drupal/token/1.9.0.0', $stream)->shouldBeCalled();
        $this->setUpdater($repository, $updater->reveal());

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
        $this->composer->getEventDispatcher()->dispatch($event->getName(), $event);

        $event = new PostFileDownloadEvent(
            PluginEvents::POST_FILE_DOWNLOAD,
            __FILE__,
            null,
            'https://ftp.drupal.org/files/projects/token-8.x-1.9.zip',
            'package',
            $package
        );
        $this->composer->getEventDispatcher()->dispatch($event->getName(), $event);
    }

    /**
     * @covers ::preFileDownload
     */
    public function testPreFileDownload(): void
    {
        $url = 'http://localhost:8080';

        $updater = $this->prophesize('\Tuf\ComposerIntegration\ComposerCompatibleUpdater');
        $updater->getLength('packages.json')
            ->willReturn(1024)
            ->shouldBeCalled();
        $updater->getLength('bogus.json')
            ->willThrow('\Tuf\Exception\NotFoundException')
            ->shouldBeCalled();

        $repository = $this->composer->getRepositoryManager()
            ->createRepository('composer', [
                'url' => $url,
                'tuf' => true,
            ]);
        $this->setUpdater($repository, $updater->reveal());

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
        $this->composer->getEventDispatcher()->dispatch($event->getName(), $event);
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
}
