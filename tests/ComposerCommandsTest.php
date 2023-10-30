<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;
use Composer\Repository\FilesystemRepository;
use Composer\Util\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tuf\ComposerIntegration\TufValidatedComposerRepository;

/**
 * Tests TUF protection when using Composer in an example project.
 */
class ComposerCommandsTest extends TestCase
{
    private const CLIENT_DIR = __DIR__ . '/client';

    /**
     * The built-in PHP server process.
     *
     * @see ::setUpBeforeClass()
     * @see ::tearDownAfterClass()
     *
     * @var \Symfony\Component\Process\Process
     */
    private static Process $server;

    /**
     * {@inheritDoc}
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$server = new Process([PHP_BINARY, '-S', 'localhost:8080'], __DIR__ . '/server');
        self::$server->start();
        $serverStarted = self::$server->waitUntil(function ($outputType, $output): bool {
            return str_contains($output, 'Development Server (http://localhost:8080) started');
        });
        static::assertTrue($serverStarted);

        // Create a backup of composer.json that we can restore at the end of the test.
        // @see ::tearDownAfterClass()
        copy(self::CLIENT_DIR . '/composer.json', self::CLIENT_DIR . '/composer.json.orig');

        // Create a Composer repository with all the installed vendor
        // dependencies, so that the test project doesn't need to interact
        // with the internet.
        $lock = __DIR__ . '/../composer.lock';
        static::assertFileIsReadable($lock);
        $lock = file_get_contents($lock);
        $lock = json_decode($lock, true);
        $vendor = [];
        $packages = array_merge($lock['packages'], $lock['packages-dev']);
        foreach ($packages as $package) {
            $name = $package['name'];
            $dir = __DIR__ . '/../vendor/' . $name;
            if (is_dir($dir)) {
                $version = $package['version'];
                $vendor['packages'][$name][$version] = [
                    'name' => $name,
                    'version' => $version,
                    'type' => $package['type'],
                    'dist' => [
                        'type' => 'path',
                        'url' => $dir,
                    ],
                ];
            }
        }
        $destination = self::CLIENT_DIR . '/vendor.json';
        file_put_contents($destination, json_encode($vendor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        static::composer('config', 'repo.vendor', 'composer', 'file://' . $destination);

        // Install the plugin.
        static::composer('require', 'php-tuf/composer-integration');
    }

    /**
     * {@inheritDoc}
     */
    public static function tearDownAfterClass(): void
    {
        // Revert changes to composer.json made by ::setUpBeforeClass().
        static::composer('remove', 'php-tuf/composer-integration', '--no-update');
        static::composer('config', '--unset', 'repo.vendor');

        // Stop the web server.
        self::$server->stop();

        // Delete files and directories created during the test.
        $file_system = new Filesystem();
        foreach (['vendor', 'composer.json', 'composer.lock', 'vendor.json'] as $file) {
            $file_system->remove(self::CLIENT_DIR . '/' . $file);
        }

        // Create a backup of composer.json that we can restore at the end of the test.
        // @see ::tearDownAfterClass()
        rename(self::CLIENT_DIR . '/composer.json.orig', self::CLIENT_DIR . '/composer.json');

        parent::tearDownAfterClass();
    }

    /**
     * Runs Composer in the test project directory.
     *
     * @param string ...$command
     *   The arguments to pass to Composer.
     *
     * @return Process
     *   The process object.
     */
    private static function composer(string ...$command): Process
    {
        // Ensure the current PHP runtime is used to execute Composer.
        array_unshift($command, PHP_BINARY, __DIR__ . '/../vendor/composer/composer/bin/composer');
        // Always run in very, very verbose mode.
        $command[] = '-vvv';

        $process = (new Process($command))
            ->setWorkingDirectory(self::CLIENT_DIR)
            ->mustRun();
        static::assertSame(0, $process->getExitCode());
        // There should not be any deprecation warnings.
        static::assertStringNotContainsStringIgnoringCase('deprecated', $process->getOutput());
        static::assertStringNotContainsStringIgnoringCase('deprecated', $process->getErrorOutput());

        return $process;
    }

    /**
     * Tests requiring and removing a TUF-protected package with a dependency.
     */
    public function testRequireAndRemove(): void
    {
        $vendorDir = self::CLIENT_DIR . '/vendor';

        $this->assertDirectoryDoesNotExist($vendorDir . '/drupal/token');
        $this->assertDirectoryDoesNotExist($vendorDir . '/drupal/pathauto');

        // Run Composer in very, very verbose mode so that we can capture and assert the
        // debugging messages generated by the plugin, which will be logged to STDERR.
        $debug = $this->composer('require', 'drupal/pathauto', '--with-all-dependencies', '-vvv')
            ->getErrorOutput();
        $this->assertStringContainsString('TUF integration enabled.', $debug);
        $this->assertStringContainsString('[TUF] Root metadata for http://localhost:8080/targets loaded from ', $debug);
        $this->assertStringContainsString('[TUF] Packages from http://localhost:8080/targets are verified by TUF.', $debug);
        $this->assertStringContainsString('[TUF] Metadata source: http://localhost:8080/metadata/', $debug);
        $this->assertStringContainsString("[TUF] Target 'packages.json' limited to 120 bytes.", $debug);
        $this->assertStringContainsString("[TUF] Target 'packages.json' validated.", $debug);
        $this->assertStringContainsString("[TUF] Target 'files/packages/8/p2/drupal/pathauto.json' limited to 1610 bytes.", $debug);
        $this->assertStringContainsString("[TUF] Target 'files/packages/8/p2/drupal/pathauto.json' validated.", $debug);
        $this->assertStringContainsString("[TUF] Target 'files/packages/8/p2/drupal/token.json' limited to 1330 bytes.", $debug);
        $this->assertStringContainsString("[TUF] Target 'files/packages/8/p2/drupal/token.json' validated.", $debug);
        // token~dev.json doesn't exist, so the plugin will limit it to a hard-coded maximum
        // size, and there should not be a message saying that it was validated.
        $this->assertStringContainsString("[TUF] Target 'files/packages/8/p2/drupal/token~dev.json' limited to " . TufValidatedComposerRepository::MAX_404_BYTES, $debug);
        $this->assertStringNotContainsStringIgnoringCase("[TUF] Target 'files/packages/8/p2/drupal/token~dev.json' validated.", $debug);
        // The plugin won't report the maximum download size of package targets; instead, that
        // information will be stored in the transport options saved to the lock file.
        $this->assertStringContainsString("[TUF] Target 'drupal/token/1.9.0.0' validated.", $debug);

        // Even though we are searching delegated roles for multiple targets, the TUF metadata should
        // only be downloaded once. This proves that static caching works.
        // @see \Tuf\ComposerIntegration\ComposerCompatibleUpdater::getMetadataForTarget()
        $this->assertStringContainsStringCount('Downloading http://localhost:8080/metadata/1.package_metadata.json', $debug, 1);
        $this->assertStringContainsStringCount('Downloading http://localhost:8080/metadata/1.package.json', $debug, 1);

        $this->assertDirectoryExists($vendorDir . '/drupal/token');
        $this->assertDirectoryExists($vendorDir . '/drupal/pathauto');

        // Load the locked package to ensure that the TUF information was saved.
        // @see \Tuf\ComposerIntegration\TufValidatedComposerRepository::configurePackageTransportOptions()
        $lock = new JsonFile(self::CLIENT_DIR . '/composer.lock');
        $this->assertTrue($lock->exists());
        $lock = new FilesystemRepository($lock);

        $transportOptions = $lock->findPackage('drupal/token', '*')
            ?->getTransportOptions();
        $this->assertIsArray($transportOptions);
        $this->assertSame('http://localhost:8080/targets', $transportOptions['tuf']['repository']);
        $this->assertSame('drupal/token/1.9.0.0', $transportOptions['tuf']['target']);
        $this->assertNotEmpty($transportOptions['max_file_size']);

        $transportOptions = $lock->findPackage('drupal/pathauto', '*')
            ?->getTransportOptions();
        $this->assertIsArray($transportOptions);
        $this->assertSame('http://localhost:8080/targets', $transportOptions['tuf']['repository']);
        $this->assertSame('drupal/pathauto/1.12.0.0', $transportOptions['tuf']['target']);
        $this->assertNotEmpty($transportOptions['max_file_size']);

        $this->composer('remove', 'drupal/pathauto');
        $this->composer('remove', 'drupal/token');

        $this->assertDirectoryDoesNotExist($vendorDir . '/drupal/token');
        $this->assertDirectoryDoesNotExist($vendorDir . '/drupal/pathauto');
    }

    private function assertStringContainsStringCount(string $needle, string $haystack, int $count): void
    {
        $this->assertSame($count, substr_count($haystack, $needle), "Failed asserting that '$needle' appears $count time(s) in '$haystack'.");
    }
}
