<?php

namespace Tuf\ComposerIntegration;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Tuf\ComposerIntegration\Repository\TufValidatedComposerRepository;


class Plugin implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        $repos = $composer->getRepositoryManager();
        $repos->setRepositoryClass('composer', TufValidatedComposerRepository::class);
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        // TODO: Implement uninstall() method.
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        // TODO: Implement deactivate() method.
    }
}
