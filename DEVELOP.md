Developing PHP-TUF Composer Integration
=======================================

This project uses [DDEV](https://ddev.com) to standardize its local development environment. To get started, make sure that you have the [latest release](https://github.com/ddev/ddev/releases) of DDEV [installed](https://ddev.com/get-started/).

```
ddev start
ddev composer install
ddev composer test
```

To run a single test use PHPUnit's `--filter` option:
```
ddev exec phpunit ./tests --debug --filter=testCannotProtectNonComposerRepository
```
