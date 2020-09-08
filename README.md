# Php-tuf Composer Integration

Experimental Composer plugin created as an exercise to discover any fundamental
difficulties with marrying Composer to `php-tuf`.

This plugin seeks to demonstrate adding TUF security to
  * Composer's package discovery process when using Composer v2 package repositories.
  * Packages that Composer selects for download from distribution archives.
  
## Usage

The plugin examines `composer` type repositories defined in your project's `composer.json`.
For any that contain an additional key `tuf-url`, it invokes `php-tuf` during package
discovery and download operations, validating that the repository and package are not being
tampered with per the TUF repository at the `tuf-url`.

The TUF repository must effectively parallel the Composer metadata repository, signing
new versions of packages as they are released as well as the Composer package discovery
metadata for them.

 