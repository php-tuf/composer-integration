<?php

namespace Tuf\ComposerIntegration;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PostFileDownloadEvent;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\RepositoryManager;
use Composer\Util\Filesystem;
use Tuf\ComposerIntegration\Repository\TufValidatedComposerRepository;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var RepositoryManager
     */
    private $repositoryManager;

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            PluginEvents::POST_FILE_DOWNLOAD => ['postFileDownload', -1000],
        ];
    }

    public function postFileDownload(PostFileDownloadEvent $event): void
    {
        $type = $event->getType();
        /** @var array|PackageInterface $context */
        $context = $event->getContext();

        if ($type === 'metadata') {
            if ($context['repository'] instanceof TufValidatedComposerRepository) {
                $context['repository']->validateMetadata($event->getUrl(), $context['response']);
            }
        } elseif ($type === 'package') {
            $options = $context->getTransportOptions();
            if (array_key_exists('tuf', $options)) {
                foreach ($this->repositoryManager->getRepositories() as $repository) {
                    if ($repository instanceof TufValidatedComposerRepository) {
                        $config = $repository->getRepoConfig();
                        if ($config['url'] === $options['tuf']['repository']) {
                            $repository->validatePackage($context, $event->getFileName());
                        }
                    }
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        // By the time this plugin is activated, several repositories may have
        // already been instantiated, and we need to convert them to
        // TUF-validated repositories. Unfortunately, the repository manager
        // only allows us to add new repositories, not replace existing ones.
        // So we have to rebuild the repository manager from the ground up to
        // add TUF validation to the existing repositories.
        $newManager = $this->createNewRepositoryManager($composer, $io);
        $this->addTufValidationToRepositories($composer, $newManager, $io);
        $composer->setRepositoryManager($newManager);
        $this->repositoryManager = $newManager;
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
                $repository = new TufValidatedComposerRepository($repository->getRepoConfig(), $io, $composer->getConfig(), $composer->getLoop()->getHttpDownloader(), $composer->getEventDispatcher());
            }
            $manager->addRepository($repository);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
        return;
        // @todo Delete all persistent TUF data.
        $path = static::getStoragePath($composer);
        $io->info("Deleting TUF data in $path");

        $fs = new Filesystem();
        $fs->removeDirectoryPhp($path);
    }

    /**
     * {@inheritDoc}
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
    }
}
