<?php

namespace Tuf\ComposerIntegration;

use Composer\Composer;
use Composer\Factory;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\RepositoryManager;
use Composer\Util\Loop;
use Tuf\ComposerIntegration\Repository\TufValidatedComposerRepository;

class Plugin implements PluginInterface
{
    /**
     * {@inheritDoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $composer->getLoop()->getHttpDownloader()->wait();
        $this->httpDownloader = new HttpDownloaderAdapter(
          $composer->getLoop()->getHttpDownloader(),
          $composer->getConfig()->get('vendor-dir')
        );
        $loop = new Loop($this->httpDownloader, $composer->getLoop()->getProcessExecutor());
        $composer->setLoop($loop);

        // By the time this plugin is activated, several repositories may have
        // already been instantiated, and we need to convert them to
        // TUF-validated repositories. Unfortunately, the repository manager
        // only allows us to add new repositories, not replace existing ones.
        // So we have to rebuild the repository manager from the ground up to
        // add TUF validation to the existing repositories.
        $newManager = $this->createNewRepositoryManager($composer, $io);
        $this->addTufValidationToRepositories($composer, $newManager, $io);
        $composer->setRepositoryManager($newManager);

        $downloadManager = Factory::createDownloadManager(
          $io,
          $composer->getConfig(),
          $this->httpDownloader,
          $composer->getLoop()->getProcessExecutor(),
          $composer->getEventDispatcher()
        );
        $composer->setDownloadManager($downloadManager);

        $installationManager = Factory::createInstallationManager($loop, $io, $composer->getEventDispatcher());
        $composer->setInstallationManager($installationManager);

        $factory = new Factory();
        $reflector = new \ReflectionObject($factory);
        $method = $reflector->getMethod('createDefaultInstallers');
        $method->setAccessible(true);
        $method->invoke($factory, $installationManager, $composer, $io, $composer->getLoop()->getProcessExecutor());
    }

    private function createNewRepositoryManager(Composer $composer, IOInterface $io): RepositoryManager
    {
        $loop = $composer->getLoop();
        $newManager = RepositoryFactory::manager($io, $composer->getConfig(), $loop->getHttpDownloader(), $composer->getEventDispatcher(), $loop->getProcessExecutor());
        $newManager->setLocalRepository($composer->getRepositoryManager()->getLocalRepository());
        // Ensure that any Composer repositories added to this manager will be
        // validated by TUF if configured accordingly.
        $newManager->setRepositoryClass('composer', TufValidatedComposerRepository::class);

        return $newManager;
    }

    private function addTufValidationToRepositories(Composer $composer, RepositoryManager $manager, IOInterface $io): void
    {
        foreach ($composer->getRepositoryManager()->getRepositories() as $repository) {
            if ($repository instanceof ComposerRepository) {
                $repository = new TufValidatedComposerRepository($repository->getRepoConfig(), $io, $composer->getConfig(), $this->httpDownloader, $composer->getEventDispatcher());
            }
            $manager->addRepository($repository);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
        // TODO: Implement uninstall() method.
    }

    /**
     * {@inheritDoc}
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
        // TODO: Implement deactivate() method.
    }
}
