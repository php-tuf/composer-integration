<?php

namespace Tuf\ComposerIntegration;

use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Version\VersionParser;
use Composer\Repository\ComposerRepository;

/**
 * Defines a package loader that registers packages as TUF targets.
 */
class PackageLoader extends ArrayLoader
{
    /**
     * The repository which contains the packages.
     *
     * @var \Composer\Repository\ComposerRepository
     */
    private $repository;

    /**
     * The TUF-aware HTTP downloader.
     *
     * @var \Tuf\ComposerIntegration\HttpDownloaderAdapter
     */
    private $downloader;

    /**
     * PackageLoader constructor.
     *
     * @param \Composer\Repository\ComposerRepository $repository
     *   The repository which contains the packages being loaded.
     * @param \Tuf\ComposerIntegration\HttpDownloaderAdapter $downloader
     *   The TUF-aware HTTP downloader.
     * @param \Composer\Package\Version\VersionParser|null $parser
     *   (optional) The version parser.
     * @param false $loadOptions
     *   (optional) I have no idea what this does. Passed to the parent.
     */
    public function __construct(ComposerRepository $repository, HttpDownloaderAdapter $downloader, VersionParser $parser = null, $loadOptions = false)
    {
        parent::__construct($parser, $loadOptions);
        $this->repository = $repository;
        $this->downloader = $downloader;
    }

    /**
     * {@inheritDoc}
     */
    public function loadPackages(array $versions, $class)
    {
        $packages = parent::loadPackages($versions, $class);

        /** @var \Composer\Package\CompletePackage $package */
        foreach ($packages as $package) {
            $this->downloader->registerPackage($package, $this->repository);
        }
        return $packages;
    }
}
