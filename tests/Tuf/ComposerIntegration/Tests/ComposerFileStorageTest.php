<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Config;
use Composer\Util\Filesystem;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Tuf\ComposerIntegration\ComposerFileStorage;

/**
 * @coversDefaultClass \Tuf\ComposerIntegration\ComposerFileStorage
 */
class ComposerFileStorageTest extends TestCase
{
    use ProphecyTrait;

    private $vendorDir;

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
        $config = new Config();
        $expectedPath = implode(DIRECTORY_SEPARATOR, [
           $this->vendorDir,
           'composer',
           'tuf',
        ]);
        $this->assertSame($expectedPath, ComposerFileStorage::basePath($config));
    }

    /**
     * @covers ::__construct
     * @covers ::create
     */
    public function testCreate(): void
    {
        $storage = TestComposerFileStorage::create('https://example.net/packages', new Config());
        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            $this->vendorDir,
            'composer',
            'tuf',
            'https---example.net-packages',
        ]);
        $this->assertSame($expectedPath, $storage->basePath);
        $this->assertDirectoryExists($expectedPath);
    }
}

class TestComposerFileStorage extends ComposerFileStorage
{
    public $basePath;
}
