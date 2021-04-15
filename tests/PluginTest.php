<?php

namespace Tuf\ComposerIntegration\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Tests TUF protection when using Composer in an example project.
 */
class PluginTest extends TestCase
{
    /**
     * Runs Composer in the test project directory.
     *
     * @param string ...$command
     *   The arguments to pass to Composer.
     */
    private function composer(string ...$command): void
    {
        array_unshift($command, __DIR__ . '/../vendor/composer/composer/bin/composer');
        $process = new Process($command, __DIR__ . '/../test-project');
        $this->assertSame(0, $process->mustRun()->getExitCode());
    }

    /**
     * Tests requiring a TUF-protected package.
     */
    public function testRequire(): void
    {
        $this->composer('require', 'drupal/token', '--with-all-dependencies');
    }

    /**
     * {@inheritDoc}
     */
    protected function tearDown(): void
    {
        $this->composer('remove', 'drupal/token');
        parent::tearDown();
    }
}
