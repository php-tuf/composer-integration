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
     * @covers ::escapeUrl
     */
    public function testEscapeUrl(): void
    {
        // Ensure that two very similar URLs are converted into unique, but
        // readable, directory names.
        $url1 = ComposerFileStorage::escapeUrl('https://site.coop/info/packages');
        $url2 = ComposerFileStorage::escapeUrl('https://site.coop.info/packages');

        $this->assertNotSame($url1, $url2);
        $this->assertMatchesRegularExpression('/^https---site\.coop-info-packages-[a-z0-9]{8}$/', $url1);
        $this->assertMatchesRegularExpression('/^https---site\.coop\.info-packages-[a-z0-9]{8}$/', $url2);
    }

    /**
     * @covers ::__construct
     * @covers ::create
     *
     * @depends testBasePath
     * @depends testEscapeUrl
     */
    public function testCreate(): void
    {
        $url = 'https://example.net/packages';
        $config = new Config();

        $basePath = implode(DIRECTORY_SEPARATOR, [
           ComposerFileStorage::basePath($config),
           ComposerFileStorage::escapeUrl($url),
        ]);
        $this->assertDirectoryDoesNotExist($basePath);

        ComposerFileStorage::create($url, $config);
        $this->assertDirectoryExists($basePath);
    }
}
