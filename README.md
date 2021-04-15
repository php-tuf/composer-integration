# PHP-TUF Integration Composer Plugin

Experimental Composer plugin created as an exercise to discover any fundamental
difficulties with marrying Composer to `php-tuf`.

This plugin seeks to demonstrate adding TUF security to
  * Composer's package discovery process when using Composer v2 package repositories.
  * Packages that Composer selects for download from distribution archives.
  
## Overview

The plugin examines `composer` type repositories defined in your project's `composer.json`.
For any that contain an additional key `tuf`, it invokes `php-tuf` during package
discovery and download operations, validating that the repository and package are not being
tampered with per the TUF repository at the `tuf-url`.

The TUF repository must effectively parallel the Composer metadata repository, signing
new versions of packages as they are released as well as the Composer package discovery
metadata for them.

## Usage

A sample TUF repository and parallel Composer repository exist in `fixtures/tuf-repo` and `fixtures/composer-repo`.

An example Composer project that causes Composer to source a package from the above sample repos is in
`fixtures/test-project`. 

To invoke Composer such that this plugin is used with the test-project,
  1. Run `make serve-repos`. This starts up a webserver on localhost:8080.
  1. cd to `fixtures/test-project`
  1. Run `composer install` (Note that Composer 2 is required.).  
     At this point, the plugin is not used, because it is not yet downloaded.
  1. Run `composer update`.
     This update operation will be performed with TUF.
