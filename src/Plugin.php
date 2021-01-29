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

        $old_manager = $composer->getRepositoryManager();
        $new_manager = RepositoryFactory::manager($io, $config, $loop->getHttpDownloader(), $dispatcher, $loop->getProcessExecutor());
        $new_manager->setLocalRepository($old_manager->getLocalRepository());
        $new_manager->setRepositoryClass('composer', TufValidatedComposerRepository::class);

        foreach ($old_manager->getRepositories() as $repository) {
            if ($repository instanceof ComposerRepository) {
                $repository = new TufValidatedComposerRepository($repository->getRepoConfig(), $io, $config, $httpDownloader, $dispatcher);
            }
            $new_manager->addRepository($repository);
        }
        $composer->setRepositoryManager($new_manager);
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
