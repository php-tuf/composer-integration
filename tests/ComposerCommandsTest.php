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
     * The built-in PHP server process.
     *
     * @see ::setUpBeforeClass()
     * @see ::tearDownAfterClass()
     *
     * @var \Symfony\Component\Process\Process
     */
    private Process $server;

    private string $workingDir;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->workingDir = __DIR__ . '/_client';

        $this->server = new Process([PHP_BINARY, '-S', 'localhost:8080'], __DIR__ . '/_targets');
        $this->server->start();
        $serverStarted = $this->server->waitUntil(function ($outputType, $output): bool {
            return str_contains($output, 'Development Server (http://localhost:8080) started');
        });
        static::assertTrue($serverStarted);

        // Generate `composer.json` with the appropriate configuration.
        $this->composer('init', '--no-interaction', '--stability=dev');
        $this->composer('config', 'prefer-stable', 'true');
        $this->composer('config', 'secure-http', 'false');
        $this->composer('config', 'allow-plugins.php-tuf/composer-integration', 'true');
        $this->composer('config', 'repositories.packagist.org', 'false');
        $this->composer('config', 'repositories.plugin', 'path', realpath(__DIR__ . '/..'));
        $this->composer('config', 'repositories.fixture', '{"type": "composer", "url": "http://localhost:8080", "tuf": true}');

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
        $destination = $this->workingDir . '/vendor.json';
        file_put_contents($destination, json_encode($vendor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->composer('config', 'repo.vendor', 'composer', 'file://' . $destination);

        // Install the plugin.
        $this->composer('require', 'php-tuf/composer-integration');
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        // Stop the web server.
        $this->server->stop();

        // Delete files and directories created during the test.
        $file_system = new Filesystem();
        foreach (['vendor', 'composer.lock', 'vendor.json'] as $file) {
            $file_system->remove($this->workingDir . '/' . $file);
        }

        parent::tearDown();
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
    private function composer(string ...$command): Process
    {
        // Ensure the current PHP runtime is used to execute Composer.
        array_unshift($command, PHP_BINARY, __DIR__ . '/../vendor/composer/composer/bin/composer');
        // Always run in very, very verbose mode.
        $command[] = '-vvv';

        $process = (new Process($command))
            ->setWorkingDirectory($this->workingDir)
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
        $vendorDir = $this->workingDir . '/vendor';

        $this->assertDirectoryDoesNotExist("$vendorDir/drupal/token");
        $this->assertDirectoryDoesNotExist("$vendorDir/drupal/pathauto");

        // Run Composer in very, very verbose mode so that we can capture and assert the
        // debugging messages generated by the plugin, which will be logged to STDERR.
        $debug = $this->composer('require', 'drupal/pathauto', '--with-all-dependencies', '-vvv')
            ->getErrorOutput();
        $this->assertStringContainsString('TUF integration enabled.', $debug);
        $this->assertStringContainsString('[TUF] Root metadata for http://localhost:8080 loaded from ', $debug);
        $this->assertStringContainsString('[TUF] Packages from http://localhost:8080 are verified by TUF.', $debug);
        $this->assertStringContainsString('[TUF] Metadata source: http://localhost:8080/metadata/', $debug);
        $this->assertStringContainsString("[TUF] Target 'packages.json' limited to 92 bytes.", $debug);
        $this->assertStringContainsString("[TUF] Target 'packages.json' validated.", $debug);
        $this->assertStringContainsString("[TUF] Target 'drupal/pathauto.json' limited to 1610 bytes.", $debug);
        $this->assertStringContainsString("[TUF] Target 'drupal/pathauto.json' validated.", $debug);
        $this->assertStringContainsString("[TUF] Target 'drupal/token.json' limited to 1330 bytes.", $debug);
        $this->assertStringContainsString("[TUF] Target 'drupal/token.json' validated.", $debug);
        // token~dev.json doesn't exist, so the plugin will limit it to a hard-coded maximum
        // size, and there should not be a message saying that it was validated.
        $this->assertStringContainsString("[TUF] Target 'drupal/token~dev.json' limited to " . TufValidatedComposerRepository::MAX_404_BYTES, $debug);
        $this->assertStringNotContainsStringIgnoringCase("[TUF] Target 'drupal/token~dev.json' validated.", $debug);
        // The plugin won't report the maximum download size of package targets; instead, that
        // information will be stored in the transport options saved to the lock file.
        $this->assertStringContainsString("[TUF] Target 'drupal/token/1.9.0.0' validated.", $debug);

        // Even though we are searching delegated roles for multiple targets, we should see the TUF metadata
        // loaded from the static cache.
        $this->assertStringContainsString('[TUF] Loading http://localhost:8080/metadata/1.package_metadata.json from static cache.', $debug);
        $this->assertStringContainsString('[TUF] Loading http://localhost:8080/metadata/1.package.json from static cache.', $debug);
        // The metadata should actually be *downloaded* twice -- once while the dependency tree is
        // being solved by Composer, and again when the solved dependencies are actually downloaded
        // (which is done by Composer effectively re-invoking itself, which results in the static
        // cache being reset).
        // @see \Composer\Command\RequireCommand::doUpdate()
        $this->assertStringContainsStringCount('Downloading http://localhost:8080/metadata/1.package_metadata.json', $debug, 2);
        $this->assertStringContainsStringCount('Downloading http://localhost:8080/metadata/1.package.json', $debug, 2);

        $this->assertDirectoryExists("$vendorDir/drupal/token");
        $this->assertDirectoryExists("$vendorDir/drupal/pathauto");

        // Load the locked package to ensure that the TUF information was saved.
        // @see \Tuf\ComposerIntegration\TufValidatedComposerRepository::configurePackageTransportOptions()
        $lock = new JsonFile($this->workingDir . '/composer.lock');
        $this->assertTrue($lock->exists());
        $lock = new FilesystemRepository($lock);

        $transportOptions = $lock->findPackage('drupal/token', '*')
            ?->getTransportOptions();
        $this->assertIsArray($transportOptions);
        $this->assertSame('http://localhost:8080', $transportOptions['tuf']['repository']);
        $this->assertSame('drupal/token/1.9.0.0', $transportOptions['tuf']['target']);
        $this->assertNotEmpty($transportOptions['max_file_size']);

        $transportOptions = $lock->findPackage('drupal/pathauto', '*')
            ?->getTransportOptions();
        $this->assertIsArray($transportOptions);
        $this->assertSame('http://localhost:8080', $transportOptions['tuf']['repository']);
        $this->assertSame('drupal/pathauto/1.12.0.0', $transportOptions['tuf']['target']);
        $this->assertNotEmpty($transportOptions['max_file_size']);

        $this->composer('remove', 'drupal/pathauto', 'drupal/token');
        $this->assertDirectoryDoesNotExist("$vendorDir/drupal/token");
        $this->assertDirectoryDoesNotExist("$vendorDir/drupal/pathauto");
    }

    private function assertStringContainsStringCount(string $needle, string $haystack, int $count): void
    {
        $this->assertSame($count, substr_count($haystack, $needle), "Failed asserting that '$needle' appears $count time(s) in '$haystack'.");
    }
}
