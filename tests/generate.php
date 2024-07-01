<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Composer\Autoload\ClassLoader;
use Tuf\Tests\FixtureBuilder\Fixture;

/** @var ClassLoader[] $class_loaders */
$class_loaders = ClassLoader::getRegisteredLoaders();
reset($class_loaders)
    ->addPsr4('Tuf\\Tests\\', key($class_loaders) . '/php-tuf/php-tuf/tests');

$fixture = new Fixture(__DIR__);
$fixture->root->consistentSnapshot = true;

// Create delegated roles and add their keys.
$fixture->delegate('targets', 'package_metadata', [
  'paths' => ['files/packages/8/p2/*'],
]);
$fixture->delegate('targets', 'package', [
  'paths' => ['drupal/*'],
]);

// Add more targets here as needed.
$dir = __DIR__ . '/_targets';
$fixture->addTarget("$dir/packages.json");
$fixture->targets['package_metadata']->add("$dir/drupal/token.json", 'drupal/token.json');
$fixture->targets['package_metadata']->add("$dir/drupal/pathauto.json", 'drupal/pathauto.json');
$fixture->targets['package']->add("$dir/token-1.9.zip", 'drupal/token/1.9.0.0');
$fixture->targets['package']->add("$dir/pathauto-1.12.zip", 'drupal/pathauto/1.12.0.0');

$fixture->publish();
