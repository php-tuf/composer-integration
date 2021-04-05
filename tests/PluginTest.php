<?php

namespace Tuf\ComposerIntegration\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PluginTest extends TestCase
{
    public function testRequire(): void
    {
        static::composer(['require', 'drupal/paragraphs'])->mustRun();
    }

    private static function composer(array $arguments): Process
    {
        array_unshift($arguments, __DIR__ . '/../vendor/composer/composer/bin/composer');
        return new Process($arguments, __DIR__ . '/../fixtures/test-project');
    }
}
