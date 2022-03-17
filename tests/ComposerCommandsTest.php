<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Util\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

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
     * {@inheritDoc}
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Ensure that static::composer() runs in the correct directory.
        static::$projectDir = __DIR__ . '/../test-project';

        // Create a Composer repository with all the installed vendor dependencies,
        // so that the test doesn't need to interact with the internet.
        $lock = __DIR__ . '/../composer.lock';
        static::assertFileExists($lock);
        $lock = file_get_contents($lock);
        $lock = json_decode($lock, true);
        $vendor = [];
        $packages = array_merge($lock['packages'], $lock['packages-dev']);
        foreach ($packages as $package) {
            $name = $package['name'];
            $dir = __DIR__ . '/../vendor';
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
        file_put_contents(static::$projectDir . '/vendor.json', json_encode($vendor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        static::composer('config', 'repo.vendor', 'composer', 'file://' . static::$projectDir . '/vendor.json');

        // Install the plugin.
        static::composer('require', 'php-tuf/composer-integration');
    }

    /**
     * {@inheritDoc}
     */
    public static function tearDownAfterClass(): void
    {
        // Revert changes to composer.json.
        static::composer('remove', 'php-tuf/composer-integration', '--no-update');
        // Delete the vendor directory.
        (new Filesystem())
            ->removeDirectory(static::$projectDir . '/vendor');

        parent::tearDownAfterClass();
    }

    /**
     * Runs Composer in the test project directory.
     *
     * @param string ...$command
     *   The arguments to pass to Composer.
     */
    private static function composer(string ...$command): void
    {
        array_unshift($command, __DIR__ . '/../vendor/composer/composer/bin/composer');
        $process = new Process($command, static::$projectDir);
        static::assertSame(0, $process->mustRun()->getExitCode());
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
        $this->composer('require', $package, '--with-all-dependencies');
        $this->assertPackageInstalled($package);
        $this->composer('remove', $package);
        $this->assertPackageNotInstalled($package);
    }
}
