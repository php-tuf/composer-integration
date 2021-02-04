<?php

namespace Tuf\ComposerIntegration;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryFactory;
use Tuf\ComposerIntegration\Repository\TufValidatedComposerRepository;

class Plugin implements PluginInterface
{
    /**
     * {@inheritDoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $loop = $composer->getLoop();
        $httpDownloader = $loop->getHttpDownloader();
        $dispatcher = $composer->getEventDispatcher();
        $config = $composer->getConfig();

        // By the time this plugin is activated, several repositories may have
        // already been instantiated, and we need to convert them to
        // TUF-validated repositories. Unfortunately, the repository manager
        // only allows us to add new repositories, not replace existing ones.
        // So we have to rebuild the repository manager from the ground up to
        // add TUF validation to the existing repositories.
        $oldManager = $composer->getRepositoryManager();
        $newManager = RepositoryFactory::manager($io, $config, $loop->getHttpDownloader(), $dispatcher, $loop->getProcessExecutor());
        $newManager->setLocalRepository($oldManager->getLocalRepository());
        // Ensure that any repositories added later will be validated by TUF if
        // configured accordingly.
        $newManager->setRepositoryClass('composer', TufValidatedComposerRepository::class);

        foreach ($oldManager->getRepositories() as $repository) {
            if ($repository instanceof ComposerRepository) {
                $repository = new TufValidatedComposerRepository($repository->getRepoConfig(), $io, $config, $httpDownloader, $dispatcher);
            }
            $newManager->addRepository($repository);
        }
        $composer->setRepositoryManager($newManager);
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
