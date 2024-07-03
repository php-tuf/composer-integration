<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Autoload\ClassLoader;
use Composer\Util\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tuf\Tests\FixtureBuilder\Fixture;

abstract class FunctionalTestBase extends TestCase
{
    /**
     * The built-in PHP server process, if there is one.
     *
     * @see ::startServer()
     * @see ::tearDown()
     *
     * @var \Symfony\Component\Process\Process|null
     */
    private ?Process $server = NULL;

    private Filesystem $fileSystem;

    protected string $workingDir;

    protected const SERVER_ROOT = __DIR__ . '/server_root';

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fileSystem = new Filesystem();

        $this->workingDir = uniqid(sys_get_temp_dir() . '/');
        mkdir($this->workingDir . '/tuf', recursive: true);

        // Make PHP-TUF's fixture builder available.
        $loaders = ClassLoader::getRegisteredLoaders();
        reset($loaders)->addPsr4('Tuf\\Tests\\', key($loaders) . '/php-tuf/php-tuf/tests');

        // Generate the fixture.
        $fixture = new Fixture($this->workingDir);
        $fixture->root->consistentSnapshot = true;
        $fixture->delegate('targets', 'package_metadata', [
          'paths' => ['drupal/*.json'],
        ]);
        $fixture->delegate('targets', 'package', [
          'paths' => ['drupal/*/*'],
        ]);
        $dir = static::SERVER_ROOT;
        $fixture->addTarget("$dir/packages.json");
        $fixture->targets['package_metadata']->add("$dir/drupal/token.json", 'drupal/token.json');
        $fixture->targets['package_metadata']->add("$dir/drupal/pathauto.json", 'drupal/pathauto.json');
        // Add a metapackage so we can test that we don't try to verify packages
        // that don't install any files of their own.
        $fixture->targets['package_metadata']->add("$dir/drupal/core-recommended.json", 'drupal/core-recommended.json');
        $fixture->targets['package']->add("$dir/token-1.9.zip", 'drupal/token/1.9.0.0');
        $fixture->targets['package']->add("$dir/pathauto-1.12.zip", 'drupal/pathauto/1.12.0.0');
        $fixture->publish();
        // Copy the root metadata into the working directory.
        copy($fixture->serverDir . '/root.json', $this->workingDir . '/tuf/localhost.json');
        // Symlink all the other metadata into the root directory of the web server.
        $this->fileSystem->relativeSymlink($fixture->serverDir, "$dir/metadata");

        // Generate `composer.json` with the appropriate configuration.
        $this->composer(['init', '--no-interaction', '--stability=dev']);
        $this->composer(['config', 'prefer-stable', 'true']);
        $this->composer(['config', 'secure-http', 'false']);
        $this->composer(['config', 'allow-plugins.php-tuf/composer-integration', 'true']);
        $this->composer(['config', 'repositories.packagist.org', 'false']);
        $this->composer(['config', 'repositories.plugin', 'path', realpath(__DIR__ . '/..')]);
        $this->composer(['config', 'repositories.fixture', '{"type": "composer", "url": "http://localhost:8080"}']);

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
        $this->composer(['config', 'repo.vendor', 'composer', 'file://' . $destination]);
    }

    protected function startServer(): void
    {
        $this->server = new Process([PHP_BINARY, '-S', 'localhost:8080'], static::SERVER_ROOT);
        $this->server->start();
        $serverStarted = $this->server->waitUntil(function ($outputType, $output): bool {
            return str_contains($output, 'Development Server (http://localhost:8080) started');
        });
        static::assertTrue($serverStarted);
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        // Stop the web server.
        $this->server?->stop();

        // Delete the fake project created for the test.
        $this->fileSystem->remove($this->workingDir);
        $this->fileSystem->unlink(static::SERVER_ROOT . '/metadata');

        parent::tearDown();
    }

    /**
     * Runs Composer in the test project directory.
     *
     * @param string[] $arguments
     *   The arguments to pass to Composer.
     * @param int $expected_exit_code
     *   (optional) The expected status code when the process completes.
     *   Defaults to 0.
     *
     * @return Process
     *   The process object.
     */
    protected function composer(array $arguments, int $expected_exit_code = 0): Process
    {
        // Ensure the current PHP runtime is used to execute Composer.
        array_unshift($arguments, PHP_BINARY, __DIR__ . '/../vendor/composer/composer/bin/composer');
        // Always run in very, very verbose mode.
        $arguments[] = '-vvv';

        $process = new Process($arguments, $this->workingDir);
        $process->run();
        static::assertSame($expected_exit_code, $process->getExitCode(), $process->getErrorOutput());
        // There should not be any deprecation warnings.
        static::assertStringNotContainsStringIgnoringCase('deprecated', $process->getOutput());
        static::assertStringNotContainsStringIgnoringCase('deprecated', $process->getErrorOutput());

        return $process;
    }
}
