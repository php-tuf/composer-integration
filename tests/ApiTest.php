<?php

namespace Tuf\ComposerIntegration\Tests;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Repository\ComposerRepository;
use PHPUnit\Framework\TestCase;
use Tuf\ComposerIntegration\TufValidatedComposerRepository;

/**
 * @coversDefaultClass \Tuf\ComposerIntegration\Plugin
 */
class ApiTest extends TestCase
{
    private $composer;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Instantiate a Composer instance in the example project.
        $dir = __DIR__ . '/../test-project';
        $this->composer = (new Factory())
            ->createComposer(new NullIO(), "$dir/composer.json", false, $dir);
    }

    /**
     * @covers ::activate
     */
    public function testActivate(): void
    {
        $manager = $this->composer->getRepositoryManager();

        // At least one Composer repository should be loaded.
        $repositories = array_filter($manager->getRepositories(), function ($repository) {
           return $repository instanceof ComposerRepository;
        });
        $this->assertNotEmpty($repositories);

        // All Composer repositories should be using the TUF driver.
        foreach ($repositories as $repository) {
            $this->assertInstanceOf(TufValidatedComposerRepository::class, $repository);
        }

        // The TUF driver should also be used when creating a new Composer repository.
        $repository = $manager->createRepository('composer', [
           'url' => 'https://packagist.example.net',
        ]);
        $this->assertInstanceOf(TufValidatedComposerRepository::class, $repository);
    }
}
