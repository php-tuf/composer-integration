<?php

namespace Tuf\ComposerIntegration;

use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Version\VersionParser;
use Tuf\ComposerIntegration\Repository\TufValidatedComposerRepository;

class PackageLoader extends ArrayLoader
{
    private $repository;
    private $downloader;
    public function __construct(
      TufValidatedComposerRepository $repository,
      HttpDownloaderAdapter $downloader,
      VersionParser $parser = null,
      $loadOptions = false
    ) {
        parent::__construct($parser, $loadOptions);
        $this->repository = $repository;
        $this->downloader = $downloader;
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

            $this->downloader->fetchers[$options['tuf']['repository']]->urlMap[$options['tuf']['target']] = $package->getDistUrl();
        }
        return $packages;
    }
}
