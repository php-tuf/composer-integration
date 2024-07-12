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
use Composer\Util\Http\Response;
use Psr\Http\Message\StreamInterface;
use Tuf\Client\Repository;
use Tuf\Client\Updater;
use Tuf\ComposerIntegration\ComposerCompatibleUpdater;
use Tuf\ComposerIntegration\Plugin;
use Tuf\ComposerIntegration\TufValidatedComposerRepository;
use Tuf\Exception\NotFoundException;

/**
 * Contains unit test coverage of the Composer plugin class.
 *
 * @coversDefaultClass \Tuf\ComposerIntegration\Plugin
 */
class ApiTest extends FunctionalTestBase
{
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
        $this->composer = $factory->createComposer(new NullIO(), "$this->workingDir/composer.json", false, $this->workingDir);

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
     *   (optional) The TUF updater to use. Defaults to a plain mock.
     * @param array $config
     *   (optional) The repository configuration. By default, will use the
     *   server layout in `tests/server`.
     *
     * @return TufValidatedComposerRepository
     *   The created repository object.
     */
    private function mockRepository(Updater $updater = NULL, array $config = []): TufValidatedComposerRepository
    {
        $manager = $this->composer->getRepositoryManager();

        $config += [
            'url' => 'http://localhost:8080/targets',
            'tuf' => [
                'metadata-url' => 'http://localhost:8080/metadata',
            ],
        ];
        $repository = $manager->createRepository('composer', $config);
        $this->setUpdater($repository, $updater ?? $this->createMock(ComposerCompatibleUpdater::class));
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
        $reflector = new \ReflectionProperty(TufValidatedComposerRepository::class, 'updater');
        $reflector->setValue($repository, $updater);
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

        $package = new CompletePackage('drupal/token', '1.9.0.0', '1.9.0');
        $repository->configurePackageTransportOptions($package);
        $options = $package->getTransportOptions();
        $this->assertArrayHasKey('tuf', $options);
        $this->assertSame('https://packages.drupal.org/8', $options['tuf']['repository']);
        $this->assertSame('drupal/token/1.9.0.0', $options['tuf']['target']);
    }

    /**
     * @covers ::postFileDownload
     */
    public function testPostFileDownload(): void
    {
        $updater = $this->createMock(ComposerCompatibleUpdater::class);
        $updater->expects($this->atLeast(2))
            ->method('verify')
            ->with(
              $this->callback(fn ($target) => $target ==='packages.json' || $target === 'drupal/token/1.9.0.0'),
              $this->isInstanceOf(StreamInterface::class),
            );

        $repository = $this->mockRepository($updater);
        $url = $repository->getRepoConfig()['url'];
        $package = new CompletePackage('drupal/token', '1.9.0.0', '1.9');
        $package->setTransportOptions([
           'tuf' => [
               'repository' => $url,
               'target' => 'drupal/token/1.9.0.0',
           ],
        ]);
        $eventDispatcher = $this->composer->getEventDispatcher();

        $event = new PostFileDownloadEvent(
            PluginEvents::POST_FILE_DOWNLOAD,
            null,
            null,
            "$url/packages.json",
            'metadata',
            [
                'repository' => $repository,
                'response' => $this->createMock(Response::class),
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
     * Data provider for ::testPreFileDownload().
     *
     * @return array[]
     *   The test cases.
     */
    public function providerPreFileDownload(): array {
        return [
            'legitimate target' => [
                'packages.json',
                99,
                99,
            ],
            'unknown target' => [
                'bogus.json',
                NULL,
                TufValidatedComposerRepository::MAX_404_BYTES,
            ],
            'URL-encoded target' => [
                'all$random-hash.json',
                999,
                999,
            ]
        ];
    }

    /**
     * @covers ::preFileDownload
     *
     * @param string $filename
     *   The filename of the target, as known in the processed URL, relative to
     *   the `targets` directory.
     * @param int|null $known_size
     *   Either a file size that will be returned by TUF, or NULL if the target
     *   is not known to TUF.
     * @param int $expected_size
     *   The maximum file size that Composer should end up with.
     *
     * @dataProvider providerPreFileDownload
     */
    public function testPreFileDownload(string $filename, ?int $known_size, int $expected_size): void
    {
        $updater = $this->createMock(ComposerCompatibleUpdater::class);
        $updater->expects($this->atLeastOnce())
            ->method('getLength')
            ->with(urldecode($filename))
            ->willReturnCallback(fn () => $known_size ?? throw new NotFoundException());

        $repository = $this->mockRepository($updater);

        // If the target length is known, it should end up in the transport options.
        $event = new PreFileDownloadEvent(
            PluginEvents::PRE_FILE_DOWNLOAD,
            $this->composer->getLoop()->getHttpDownloader(),
            "http://localhost:8080/targets/" . urlencode($filename),
            'metadata',
            [
                'repository' => $repository,
            ]
        );
        $this->composer->getEventDispatcher()
            ->dispatch($event->getName(), $event);
        $options = $event->getTransportOptions();
        $this->assertSame($expected_size, $options['max_file_size']);
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

    public function testMaxBytesOverride(): void
    {
        $updater = $this->createMock(ComposerCompatibleUpdater::class);
        $this->mockRepository($updater, [
            'url' => 'http://localhost',
            'tuf' => [
                'max-bytes' => 123,
            ],
        ]);
        $this->assertSame(123, Repository::$maxBytes);
    }

    /**
     * Tests that the URLs of Composer metadata files can be mapped to TUF targets.
     * For example, given a repository URL of `https://example.com/packages`:
     *
     * - If the metadata URL starts with the repository URL, the repository URL should
     *   be stripped out to derive the target name. For example, if the metadata URL is
     *   `https://example.com/packages/file.json`, the target name should be `file.json`.
     * - Otherwise, the metadata URL's path component should be used as the target name.
     *   For example, if the metadata URL is `https://example.com/package/info.json`,
     *   the target name should be `package/info.json`.
     *
     * @covers \Tuf\ComposerIntegration\TufValidatedComposerRepository::prepareMetadata
     * @covers \Tuf\ComposerIntegration\TufValidatedComposerRepository::getTargetFromUrl
     */
    public function testTargetFromUrl(): void
    {
        $updater = $this->createMock(ComposerCompatibleUpdater::class);

        $updater->expects($this->atLeast(2))
            ->method('getLength')
            ->willReturnMap([
              ['packages.json', 39],
              ['another/target.json', 59],
            ]);

        $repository = $this->mockRepository($updater, [
            'url' => 'http://localhost/repo',
        ]);
        $event = new PreFileDownloadEvent(
            PluginEvents::PRE_FILE_DOWNLOAD,
            $this->composer->getLoop()->getHttpDownloader(),
            "http://localhost/repo/packages.json",
            'metadata',
            [
                'repository' => $repository,
            ]
        );
        $repository->prepareComposerMetadata($event);
        $this->assertSame(39, $event->getTransportOptions()['max_file_size']);

        // If the URL of the metadata doesn't start with the repository URL,
        // we should fall back to using the URL's path component as the target.
        $event->setProcessedUrl('http://localhost/another/target.json');
        $repository->prepareComposerMetadata($event);
        $this->assertSame(59, $event->getTransportOptions()['max_file_size']);
    }
}
