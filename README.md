# PHP-TUF Composer Integration Plugin

Experimental Composer plugin marrying Composer to `php-tuf`.

This plugin seeks to demonstrate adding TUF security to
  * Composer's package discovery process when using Composer v2 package repositories.
  * Packages that Composer selects for download.

## Overview

The plugin examines `composer` type repositories. For any that contain an additional key
`tuf`, it invokes `php-tuf` during package discovery and download operations, validating
that the repository and package are not being tampered with.

The TUF repository must track the Composer repository, signing new versions of packages as
they are released as well as the Composer package metadata for them.

## Usage

A sample TUF-protected Composer repository is included for development purposes. To set it up,
first be sure you have `pipenv` installed, as per the instructions at
https://github.com/php-tuf/php-tuf#server-environment-setup-for-the-python-tuf-cli. Then, run
`composer run make-fixture`.

An example Composer project that uses the sample TUF repo above is in `test-project`. To
initialize it, run ``.

To invoke Composer such that this plugin is used with the example project:
```
# Start a web server on localhost:8080.
php -S localhost:8080
cd test-project
# Install the plugin.
composer require php-tuf/composer-integration
# Install a package with TUF protection! For development purposes, we need
# to invoke a specific binary of Composer in order to avoid the autoloader
# getting confused.
../vendor/composer/composer/bin/composer require drupal/token
```
