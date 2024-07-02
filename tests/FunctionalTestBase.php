<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Util\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

abstract class FunctionalTestBase extends TestCase
{
    /**
     * The built-in PHP server process.
     *
     * @see ::setUp()
     * @see ::tearDown()
     *
     * @var \Symfony\Component\Process\Process
     */
    private Process $server;

    protected string $workingDir;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->workingDir = uniqid(sys_get_temp_dir() . '/');
        mkdir($this->workingDir . '/tuf', recursive: true);

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

        // Copy the root metadata into the working directory.
        copy(__DIR__ . '/_targets/metadata/root.json', $this->workingDir . '/tuf/localhost.json');

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
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        // Stop the web server.
        $this->server->stop();

        // Delete the fake project created for the test.
        (new Filesystem())->remove($this->workingDir);

        parent::tearDown();
    }

    /**
     * Runs Composer in the test project directory.
     *
     * @param string ...$arguments
     *   The arguments to pass to Composer.
     *
     * @return Process
     *   The process object.
     */
    protected function composer(string ...$arguments): Process
    {
        // Ensure the current PHP runtime is used to execute Composer.
        array_unshift($arguments, PHP_BINARY, __DIR__ . '/../vendor/composer/composer/bin/composer');
        // Always run in very, very verbose mode.
        $arguments[] = '-vvv';

        $process = (new Process($arguments))
            ->setWorkingDirectory($this->workingDir)
            ->mustRun();
        static::assertSame(0, $process->getExitCode());
        // There should not be any deprecation warnings.
        static::assertStringNotContainsStringIgnoringCase('deprecated', $process->getOutput());
        static::assertStringNotContainsStringIgnoringCase('deprecated', $process->getErrorOutput());

        return $process;
    }
}
