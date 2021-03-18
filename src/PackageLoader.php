<?php

namespace Tuf\ComposerIntegration;

use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Version\VersionParser;
use Tuf\ComposerIntegration\Repository\TufValidatedComposerRepository;

class PackageLoader extends ArrayLoader
{
    private $repository;
    public function __construct(
      TufValidatedComposerRepository $repository,
      VersionParser $parser = null,
      $loadOptions = false
    ) {
        parent::__construct($parser, $loadOptions);
        $this->repository = $repository;
    }

    public function loadPackages(array $versions, $class)
    {
        $packages = parent::loadPackages($versions, $class);

        /** @var \Composer\Package\CompletePackage $package */
        foreach ($packages as $package) {
            $options = $package->getTransportOptions();
            $options['tuf'] = [
              'target' => hash('sha256', $package->getDistUrl()),
              'repository' => $this->repository->getRepoConfig()['url'],
            ];
            $package->setTransportOptions($options);
        }
        return $packages;
    }
}
