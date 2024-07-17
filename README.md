# PHP-TUF Composer Integration Plugin

![build](https://github.com/php-tuf/composer-integration/actions/workflows/build.yml/badge.svg)

Experimental Composer plugin marrying Composer 2.6 and later to [PHP-TUF](https://github.com/php-tuf/php-tuf).

This plugin seeks to demonstrate adding TUF security to
  * Composer's package discovery process when using Composer v2 package repositories.
  * Packages that Composer selects for download.

## IMPORTANT

This plugin, as well as the PHP-TUF library it depends on, is in a pre-release state and is not considered a complete or secure implementation of the TUF framework.

This plugin should currently only be used for testing, development and feedback. *Do NOT use in production for secure downloads!!*

## Overview

The plugin examines `composer` type repositories. For any that contain an additional key `tuf`, it invokes PHP-TUF
during package discovery and download operations, validating that the repository and package are not being tampered
with.

In accordance with the [TUF specification](https://github.com/theupdateframework/specification/blob/v1.0.9/tuf-spec.md#5-detailed-workflows),
projects using this plugin must supply a set of trusted keys for each repository they want to protect with TUF. Each
TUF-protected repository should provide a JSON file with its root keys. The file may be named in one of a few ways,
which will be searched for in this order:

1. A SHA-256 hash of the full repository URL. For example, if the repository URL is `http://repo.example.net/composer`,
   the JSON file can be named `d82cfa7a5a4ba36bd2bcc9d3f7b24bdddbe1209b71ebebaeebc59f6f0ea48792.json`.
2. The host name of the repository. To continue the previous example, the JSON file can be named 
   `repo.example.net.json`.

All root key files must be stored in a directory called `tuf`, adjacent to the project's `composer.json` file.

The TUF repository must track the Composer repository, signing new versions of packages as they are released as well as
the Composer package metadata for them.

## Usage
```
# Configure Composer to allow the plugin to run.
composer config allow-plugins.php-tuf/composer-integration true

# Install the plugin.
composer require php-tuf/composer-integration

# Enable TUF protection for a repository defined in composer.json. For example,
# if you have a Drupal site, the following will probably work.
composer tuf:protect https://packages.drupal.org/8

# Install a package with safety guaranteed by TUF!
composer require drupal/token
```

## Performance
There's no way around it: this plugin affects Composer's performance. This is because, for every file Composer examines
(including package metadata), TUF needs to download other files, to confirm that the file Composer is looking at hasn't
been tampered with.

The performance hit generally isn't extreme, but it may be quite noticeable, depending on how large your project is and
what you're asking Composer to do. Performance can also be affected by the way TUF has been set up on the server, which
may be different for each repository.

To mitigate this, the plugin will try to keep network activity to a minimum; whatever network activity it has to do, it
tries to do in parallel. This is in addition to fairly aggressive caching, while maintaining the ability for TUF to
keep itself up-to-date. That said, **you should generally expect Composer to be approximately 1.5 to 3 times slower when
TUF is enabled.**
