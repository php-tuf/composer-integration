<?php

namespace Tuf\ComposerIntegration;

use Composer\Config;
use Composer\Util\Filesystem;
use Tuf\Client\DurableStorage\FileStorage;

/**
 * Defines storage for the PHP-TUF metadata of a repository in a Composer project.
 */
class ComposerFileStorage extends FileStorage
{
    /**
     * {@inheritDoc}
     */
    public function __construct(string $basePath)
    {
        (new Filesystem())->ensureDirectoryExists($basePath);
        parent::__construct($basePath);
    }

    /**
     * Returns the base path where data for all repositories is stored.
     *
     * @param Config $config
     *   The current Composer configuration.
     *
     * @return string
     *   The base path where data is stored.
     */
    public static function basePath(Config $config): string
    {
        return implode(DIRECTORY_SEPARATOR, [
            rtrim($config->get('vendor-dir'), DIRECTORY_SEPARATOR),
            'composer',
            'tuf',
        ]);
    }

    /**
     * Creates a storage object for a specific repository.
     *
     * @param string $url
     *   The repository URL.
     * @param Config $config
     *   The current Composer configuration.
     *
     * @return static
     *   The storage object.
     */
    public static function create(string $url, Config $config): self
    {
        $escapedUrl = preg_replace('/[^[:alnum:]\.]/', '-', $url);
        // Append a partial hash of the unescaped URL, to prevent URLs like
        // `https://www.site.coop.info/packages` from colliding with
        // `https://www.site.coop/info/packages`, whilst keeping the directory
        // names easily distinguishable.
        $hashedUrl = hash('sha256', $url);

        $basePath = implode(DIRECTORY_SEPARATOR, [
            static::basePath($config),
            $escapedUrl . '-' . substr($hashedUrl, 0, 8),
        ]);
        return new static($basePath);
    }
}
