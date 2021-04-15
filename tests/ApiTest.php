<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositorySecurityException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
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

        // Create a new Composer instance, not associated with any project.
        $factory = new Factory();
        $this->composer = $factory->createComposer(new NullIO(), []);

        // Activate the plugin.
        $this->composer->getPluginManager()->addPlugin($this->plugin);
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        $this->composer->getPluginManager()->uninstallPlugin($this->plugin);
        $this->assertDirectoryDoesNotExist(__DIR__ . '/vendor/composer/tuf');
        parent::tearDown();
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
     *
     * @param mixed $data
     *   The root data that will be returned from the server. Must be streamable
     *   (i.e., a string or file resource).
     * @param \Throwable|null $expectedException
     *   (optional) If given, a copy (i.e., same class, message, and error code)
     *   of the exception that should be thrown.
     *
     * @dataProvider providerFetchRootData
     */
    public function testFetchRootData($data, \Throwable $expectedException = null): void
    {
        $rootHash = hash_file('sha256', __DIR__ . '/../metadata/root.json');

        $stream = Utils::streamFor($data);
        $promise = new FulfilledPromise($stream);

        $fetcher = $this->prophesize('\Tuf\Client\RepoFileFetcherInterface');
        $fetcher->fetchMetadata('root.json', Updater::MAXIMUM_DOWNLOAD_BYTES)
            ->willReturn($promise)
            ->shouldBeCalled();

        if ($expectedException) {
            $this->expectException(get_class($expectedException));
            $this->expectExceptionMessage($expectedException->getMessage());
            $this->expectExceptionCode($expectedException->getCode());
        }

        $this->composer->getRepositoryManager()->createRepository('composer', [
            'url' => 'https://example.org',
            'tuf' => [
                'root' => [
                    'sha256' => $rootHash,
                ],
                '_fileFetcher' => $fetcher->reveal(),
            ],
        ]);

        $rootFile = __DIR__ . '/vendor/composer/tuf/https---example.org/root.json';
        $this->assertFileExists($rootFile);
        $this->assertSame($rootHash, hash_file('sha256', $rootFile));
    }

    /**
     * Data provider for ::testFetchRootData().
     *
     * @return array[]
     *   Sets of arguments to pass to the test method.
     */
    public function providerFetchRootData(): array
    {
        return [
            'valid data' => [
                fopen(__DIR__ . '/../metadata/root.json', 'r'),
            ],
            'invalid data' => [
                "Shall I compare thee to a summer's day?",
                new RepositorySecurityException("TUF root data from server did not match expected sha256 hash."),
            ],
        ];
    }
}
