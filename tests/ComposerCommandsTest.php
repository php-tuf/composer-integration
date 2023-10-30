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
    /**
     * The path to the test project.
     *
     * @var string
     */
    private static $projectDir;

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

        // Ensure that static::composer() runs in the correct directory.
        static::$projectDir = __DIR__ . '/client';

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
        $destination = static::$projectDir . '/vendor.json';
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
        foreach (['vendor', 'composer.lock', 'vendor.json'] as $file) {
            $file_system->remove(static::$projectDir . '/' . $file);
        }

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

        $process = new Process($command, static::$projectDir);
        $process->mustRun();
        static::assertSame(0, $process->getExitCode());
        // There should not be any deprecation warnings.
        static::assertStringNotContainsStringIgnoringCase('deprecated', $process->getOutput());
        static::assertStringNotContainsStringIgnoringCase('deprecated', $process->getErrorOutput());

        return $process;
    }

    /**
     * Asserts that a package is installed in the test project.
     *
     * @param string $package
     *   The name of the package (e.g., 'drupal/core').
     */
    private function assertPackageInstalled(string $package): void
    {
        $this->assertFileExists(static::$projectDir . "/vendor/$package");
    }

    /**
     * Asserts that a package is not installed in the test project.
     *
     * @param string $package
     *   The name of the package (e.g., 'drupal/core').
     */
    private function assertPackageNotInstalled(string $package): void
    {
        $this->assertFileDoesNotExist(static::$projectDir . "/vendor/$package");
    }

    /**
     * Tests requiring and removing a TUF-protected package.
     */
    public function testRequireAndRemove(): void
    {
        $package = 'drupal/token';
        $this->assertPackageNotInstalled($package);

        // Run Composer in very, very verbose mode so that we can capture and assert the
        // debugging messages generated by the plugin, which will be logged to STDERR.
        $debug = $this->composer('require', $package, '--with-all-dependencies', '-vvv')
            ->getErrorOutput();
        $this->assertStringContainsString('TUF integration enabled.', $debug);
        $this->assertStringContainsString('[TUF] Root metadata for http://localhost:8080/targets loaded from ', $debug);
        $this->assertStringContainsString('[TUF] Packages from http://localhost:8080/targets are verified by TUF.', $debug);
        $this->assertStringContainsString('[TUF] Metadata source: http://localhost:8080/metadata/', $debug);
        $this->assertStringContainsString("[TUF] Target 'packages.json' limited to 120 bytes.", $debug);
        $this->assertStringContainsString("[TUF] Target 'packages.json' validated.", $debug);
        $this->assertStringContainsString("[TUF] Target 'files/packages/8/p2/drupal/token.json' limited to 1330 bytes.", $debug);
        $this->assertStringContainsString("[TUF] Target 'files/packages/8/p2/drupal/token.json' validated.", $debug);
        // token~dev.json doesn't exist, so the plugin will limit it to a hard-coded maximum
        // size, and there should not be a message saying that it was validated.
        $this->assertStringContainsString("[TUF] Target 'files/packages/8/p2/drupal/token~dev.json' limited to " . TufValidatedComposerRepository::MAX_404_BYTES, $debug);
        $this->assertStringNotContainsStringIgnoringCase("[TUF] Target 'files/packages/8/p2/drupal/token~dev.json' validated.", $debug);
        // The plugin won't report the maximum download size of package targets; instead, that
        // information will be stored in the transport options saved to the lock file.
        $this->assertStringContainsString("[TUF] Target 'drupal/token/1.9.0.0' validated.", $debug);

        $this->assertPackageInstalled($package);

        // Load the locked package to ensure that the TUF information was saved.
        // @see \Tuf\ComposerIntegration\TufValidatedComposerRepository::configurePackageTransportOptions()
        $lock = static::$projectDir . '/composer.lock';
        $this->assertFileIsReadable($lock);
        $lock = new JsonFile($lock);
        $lock = new FilesystemRepository($lock);
        $locked_package = $lock->findPackage($package, '*');
        $this->assertInstanceOf(PackageInterface::class, $locked_package);
        $transport_options = $locked_package->getTransportOptions();
        $this->assertSame('http://localhost:8080/targets', $transport_options['tuf']['repository']);
        $this->assertSame('drupal/token/1.9.0.0', $transport_options['tuf']['target']);
        $this->assertNotEmpty($transport_options['max_file_size']);

        $this->composer('remove', $package);
        $this->assertPackageNotInstalled($package);
    }
}
