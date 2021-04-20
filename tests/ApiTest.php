<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Repository\ComposerRepository;
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
        $this->composer = $factory->createComposer(new NullIO(), []);
        $this->composer->getPluginManager()->addPlugin($this->plugin);
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        $this->composer->getPluginManager()->uninstallPlugin($this->plugin);
        parent::tearDown();
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

        $repository = $this->composer->getRepositoryManager()
            ->createRepository('composer', [
                'url' => 'https://example.com',
                'tuf' => [
                    'root' => __DIR__ . '/../test-project/root.json',
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
}
