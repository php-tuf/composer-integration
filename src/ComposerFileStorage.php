<?php

namespace Tuf\ComposerIntegration;

use Composer\Config;
use Composer\Util\Filesystem;
use Tuf\Client\DurableStorage\FileStorage;

/**
 * Defines storage for the TUF metadata of a repository in a Composer project.
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
        $basePath = implode(DIRECTORY_SEPARATOR, [
            static::basePath($config),
            preg_replace('/[^[:alnum:]\.]/', '-', $url),
        ]);
        return new static($basePath);
    }

    /**
     * Returns the time a stored file was last modified.
     *
     * @param string $name
     *   The name of the file to check, without its `.json` extension.
     *
     * @return \DateTimeImmutable|null
     *   The time the file was last modified, or null if the file doesn't exist.
     *
     * @throws \RuntimeException
     *   If the file exists but its modification time could not be determined.
     */
    public function getModifiedTime(string $name): ?\DateTimeImmutable
    {
        $path = $this->toPath($name);
        if (file_exists($path)) {
            $modifiedTime = filemtime($path);
            if (is_int($modifiedTime)) {
                // The @ prefix tells \DateTimeImmutable that $modifiedTime is
                // a UNIX timestamp.
                return new \DateTimeImmutable("@$modifiedTime");
            }
            throw new \RuntimeException("Could not get the modification time for '$path'.");
        }
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function toPath(string $name): string
    {
        return parent::toPath($name);
    }
}
