# PHP-TUF Composer Integration Plugin

Experimental Composer plugin marrying Composer 2.1 and later to [PHP-TUF](https://github.com/php-tuf/php-tuf).

This plugin seeks to demonstrate adding TUF security to
  * Composer's package discovery process when using Composer v2 package repositories.
  * Packages that Composer selects for download.

## IMPORTANT

This plugin, as well as the PHP-TUF library it depends on, is in a pre-release state and is not considered a complete or secure implementation of the TUF framework. Additionally, this plugin requires Composer 2.1 or later, which has not yet been released.

This plugin should currently only be used for testing, development and feedback. *Do NOT use in production for secure downloads!!*

## Overview

The plugin examines `composer` type repositories. For any that contain an additional key `tuf`, it invokes PHP-TUF
during package discovery and download operations, validating that the repository and package are not being tampered
with.

In accordance with the [TUF specification](https://github.com/theupdateframework/specification/blob/v1.0.9/tuf-spec.md#5-detailed-workflows),
projects using this plugin must supply a set of trusted keys for each repository they want to protect with TUF. Each
TUF-protected repository should provide a JSON file named for the repository URL, with non-alphanumeric characters
replaced by a single period. For example, if the repository URL is `https://repo.example.net:1234/packages`, then the
key file should be called `https.repo.example.net.1234.packages.json`. All key files must be stored in a directory
called`tuf`, adjacent to the project's `composer.json` file.

The TUF repository must track the Composer repository, signing new versions of packages as they are released as well as
the Composer package metadata for them.

## Usage

A sample TUF-protected Composer repository is included for development purposes. To set it up, first be sure you have
`pipenv` installed, as per [these instructions](https://github.com/php-tuf/php-tuf#server-environment-setup-for-the-python-tuf-cli).
Then, run `composer make-fixture`.

An example Composer project that uses the sample TUF repo above is in `test-project`. To invoke Composer such that this
plugin is used by the example project:
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
