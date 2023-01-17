<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Config;
use Composer\Util\Filesystem;
use PHPUnit\Framework\TestCase;
use Tuf\ComposerIntegration\ComposerFileStorage;

/**
 * @coversDefaultClass \Tuf\ComposerIntegration\ComposerFileStorage
 */
class ComposerFileStorageTest extends TestCase
{
    /**
     * The vendor directory used for testing.
     *
     * @var string
     */
    private string $vendorDir;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        $this->vendorDir = sys_get_temp_dir() . '/vendor';
        $this->assertTrue(putenv('COMPOSER_VENDOR_DIR=' . $this->vendorDir));
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        (new Filesystem())->removeDirectoryPhp($this->vendorDir);
    }

    /**
     * @covers ::basePath
     */
    public function testBasePath(): void
    {
        $expectedPath = implode(DIRECTORY_SEPARATOR, [
           $this->vendorDir,
           'composer',
           'tuf',
        ]);
        $config = new Config();
        $this->assertSame($expectedPath, ComposerFileStorage::basePath($config));
    }

    /**
     * @covers ::__construct
     * @covers ::create
     *
     * @depends testBasePath
     */
    public function testCreate(): void
    {
        $config = new Config();

        $basePath = implode(DIRECTORY_SEPARATOR, [
           ComposerFileStorage::basePath($config),
            'https---example.net-packages',
        ]);
        $this->assertDirectoryDoesNotExist($basePath);

        ComposerFileStorage::create('https://example.net/packages', $config);
        $this->assertDirectoryExists($basePath);
    }
}
