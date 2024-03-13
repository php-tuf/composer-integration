<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;
use Composer\Repository\FilesystemRepository;
use Composer\Util\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tuf\ComposerIntegration\TufValidatedComposerRepository;
use Tuf\Tests\FixtureBuilder\Fixture;

/**
 * Tests TUF protection when using Composer in an example project.
 */
class ComposerCommandsTest extends TestCase
{
    private const CLIENT_DIR = __DIR__ . '/client';

    /**
     * The built-in PHP server processes.
     *
     * @see ::startServer()
     * @see ::tearDownAfterClass()
     *
     * @var \Symfony\Component\Process\Process[]
     */
    private static array $servers = [];

    private static Filesystem $fileSystem;

    private static function startServer(string $docRoot, int $port): void
    {
        static::assertDirectoryExists($docRoot);
        $url = "localhost:$port";

        $process = new Process([PHP_BINARY, '-S', $url], $docRoot);
        $process->start();
        static::assertTrue(
            $process->waitUntil(fn ($type, $output) => str_contains($output, "Development Server (http://$url) started")),
        );
        static::$servers[] = $process;
    }

    /**
     * {@inheritDoc}
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Ensure PHP-TUF's fixture builder is available to us.
        $path = InstalledVersions::getInstallPath('php-tuf/php-tuf');
        static::assertDirectoryExists($path);
        /** @var ClassLoader[] $loaders */
        $loaders = ClassLoader::getRegisteredLoaders();
        reset($loaders)->addPsr4('Tuf\\Tests\\FixtureBuilder\\', $path . '/tests/FixtureBuilder');

        // Build the fixture in a temporary directory.
        $fixture = new Fixture();
        $fixture->root->consistentSnapshot = true;
        $fixture->delegate('targets', 'package_metadata', [
           'paths' => ['drupal/*.json*'],
        ]);
        $fixture->delegate('targets', 'package', [
            'paths' => ['drupal/*'],
        ]);
        $targetsDir = __DIR__ . '/targets';
        $fixture->targets['targets']->add("$targetsDir/packages.json", 'packages.json');
        $fixture->targets['package_metadata']->add("$targetsDir/drupal/pathauto.json", 'drupal/pathauto.json');
        $fixture->targets['package_metadata']->add("$targetsDir/drupal/token.json", 'drupal/token.json');
        $fixture->targets['package']->add("$targetsDir/pathauto-1.12.0.0.zip", 'drupal/pathauto/1.12.0.0');
        $fixture->targets['package']->add("$targetsDir/token-1.9.0.0.zip", 'drupal/token/1.9.0.0');
        $fixture->publish();

        static::$fileSystem = new Filesystem();
        static::$fileSystem->copy($fixture->serverDir . '/root.json', static::CLIENT_DIR . '/tuf/localhost.json');

        // These ports are statically defined in `client/composer.json`.
        static::startServer($targetsDir, 8086);
        static::startServer($fixture->serverDir, 8088);

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

        // Stop the web servers.
        while (static::$servers) {
            array_pop(static::$servers)->stop();
        }

        // Delete files and directories created during the test.
        foreach (['vendor', 'composer.json', 'composer.lock', 'vendor.json'] as $file) {
            static::$fileSystem->remove(self::CLIENT_DIR . '/' . $file);
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

        $this->assertDirectoryDoesNotExist("$vendorDir/drupal/token");
        $this->assertDirectoryDoesNotExist("$vendorDir/drupal/pathauto");

        // Run Composer in very, very verbose mode so that we can capture and assert the
        // debugging messages generated by the plugin, which will be logged to STDERR.
        $debug = $this->composer('require', 'drupal/pathauto', '--with-all-dependencies', '-vvv')
            ->getErrorOutput();
        $this->assertStringContainsString('TUF integration enabled.', $debug);
        $this->assertStringContainsString('[TUF] Root metadata for http://localhost:8086 loaded from ', $debug);
        $this->assertStringContainsString('[TUF] Packages from http://localhost:8086 are verified by TUF.', $debug);
        $this->assertStringContainsString('[TUF] Metadata source: http://localhost:8088', $debug);
        $this->assertStringContainsString("[TUF] Target 'packages.json' limited to 93 bytes.", $debug);
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
        $this->assertStringContainsString('[TUF] Loading http://localhost:8088/1.package_metadata.json from static cache.', $debug);
        $this->assertStringContainsString('[TUF] Loading http://localhost:8088/1.package.json from static cache.', $debug);
        // The package metadata should only be *downloaded* once, while the dependency tree is
        // being solved. The rest of the time, it should be loaded from static cache, or downloaded
        // only if it's been modified.
        $this->assertStringContainsStringCount("Downloading http://localhost:8088/1.package_metadata.json\n", $debug, 1);
        // The actual targets' metadata should be *downloaded* twice -- once while the dependency
        // tree is being solved, and again when the solved dependencies are actually downloaded
        // (which is done by Composer effectively re-invoking itself, resulting in the static
        // cache being reset).
        // @see \Composer\Command\RequireCommand::doUpdate()
        $this->assertStringContainsStringCount("Downloading http://localhost:8088/1.package.json\n", $debug, 2);

        $this->assertDirectoryExists("$vendorDir/drupal/token");
        $this->assertDirectoryExists("$vendorDir/drupal/pathauto");

        // Load the locked package to ensure that the TUF information was saved.
        // @see \Tuf\ComposerIntegration\TufValidatedComposerRepository::configurePackageTransportOptions()
        $lock = new JsonFile(self::CLIENT_DIR . '/composer.lock');
        $this->assertTrue($lock->exists());
        $lock = new FilesystemRepository($lock);

        $transportOptions = $lock->findPackage('drupal/token', '*')
            ?->getTransportOptions();
        $this->assertIsArray($transportOptions);
        $this->assertSame('http://localhost:8086', $transportOptions['tuf']['repository']);
        $this->assertSame('drupal/token/1.9.0.0', $transportOptions['tuf']['target']);
        $this->assertNotEmpty($transportOptions['max_file_size']);

        $transportOptions = $lock->findPackage('drupal/pathauto', '*')
            ?->getTransportOptions();
        $this->assertIsArray($transportOptions);
        $this->assertSame('http://localhost:8086', $transportOptions['tuf']['repository']);
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
