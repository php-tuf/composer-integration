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
     * The URL of the repository which contains the packages.
     *
     * @var string
     */
    private $url;

    /**
     * PackageLoader constructor.
     *
     * @param string $repository
     *   The URL of the repository which contains the packages being loaded.
     * @param \Composer\Package\Version\VersionParser|null $parser
     *   (optional) The version parser.
     * @param false $loadOptions
     *   (optional) I have no idea what this does. Passed to the parent.
     */
    public function __construct(string $url, VersionParser $parser = null, $loadOptions = false)
    {
        parent::__construct($parser, $loadOptions);
        $this->url = $url;
    }

    /**
     * {@inheritDoc}
     */
    public function loadPackages(array $versions, $class)
    {
        $packages = parent::loadPackages($versions, $class);

        /** @var \Composer\Package\CompletePackage $package */
        foreach ($packages as $package) {
            $options = $package->getTransportOptions();
            $options['tuf'] = [
              $this->url,
              hash('sha256', $package->getDistUrl()),
            ];
            $package->setTransportOptions($options);
        }
        return $packages;
    }
}
