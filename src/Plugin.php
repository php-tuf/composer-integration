<?php

namespace Tuf\ComposerIntegration;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PostFileDownloadEvent;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\RepositoryManager;
use Composer\Util\Filesystem;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * The repository manager.
     *
     * @var RepositoryManager
     */
    private $repositoryManager;

    /**
     * The I/O wrapper.
     *
     * @var IOInterface
     */
    private $io;

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            PluginEvents::PRE_FILE_DOWNLOAD => ['preFileDownload', -1000],
            PluginEvents::POST_FILE_DOWNLOAD => ['postFileDownload', -1000],
        ];
    }

    /**
     * Reacts before metadata is downloaded.
     *
     * If the metadata is associated with a TUF-aware Composer repository,
     * its maximum length in bytes will be set by TUF.
     *
     * @param PreFileDownloadEvent $event
     *   The event object.
     */
    public function preFileDownload(PreFileDownloadEvent $event): void
    {
        $context = $event->getContext();

        if ($event->getType() === 'metadata' && $context['repository'] instanceof TufValidatedComposerRepository) {
            $context['repository']->prepareMetadata($event, $this->io);
        }
    }

    /**
     * Reacts when a file, or metadata, is downloaded.
     *
     * If the downloaded file or metadata is associated with a TUF-aware Composer
     * repository, then the downloaded data will be validated by TUF.
     *
     * @param PostFileDownloadEvent $event
     *   The event object.
     */
    public function postFileDownload(PostFileDownloadEvent $event): void
    {
        $type = $event->getType();
        /** @var array|PackageInterface $context */
        $context = $event->getContext();

        if ($type === 'metadata') {
            if ($context['repository'] instanceof TufValidatedComposerRepository) {
                $context['repository']->validateMetadata($event->getUrl(), $context['response'], $this->io);
            }
        } elseif ($type === 'package') {
            // The repository URL is saved in the package's transport options so that
            // it will persist even when loaded from the lock file.
            // @see \Tuf\ComposerIntegration\TufValidatedComposerRepository::configurePackageTransportOptions()
            $options = $context->getTransportOptions();
            if (array_key_exists('tuf', $options)) {
                $repository = $this->getRepositoryByUrl($options['tuf']['repository']);
                if ($repository) {
                    $repository->validatePackage($context, $event->getFileName());
                }
            }
        }
    }

    /**
     * Looks up a TUF-validated Composer repository by its URL.
     *
     * @param string $url
     *   The repository URL.
     * @return TufValidatedComposerRepository|null
     *   The TUF-validated Composer repository with the given URL, or NULL if none
     *   is currently registered.
     */
    private function getRepositoryByUrl(string $url): ?TufValidatedComposerRepository
    {
        foreach ($this->repositoryManager->getRepositories() as $repository) {
            if ($repository instanceof TufValidatedComposerRepository) {
                $config = $repository->getRepoConfig();
                if ($config['url'] === $url) {
                    return $repository;
                }
            }
        }
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $io->debug('TUF integration enabled.');
        // Keep a reference to the I/O wrapper so we can set debugging messages
        // at other points.
        $this->io = $io;

        // By the time this plugin is activated, several repositories may have
        // already been instantiated, and we need to convert them to
        // TUF-validated repositories. Unfortunately, the repository manager
        // only allows us to add new repositories, not replace existing ones.
        // So we have to rebuild the repository manager from the ground up to
        // add TUF validation to the existing repositories.
        $newManager = $this->createNewRepositoryManager($composer, $io);
        $this->addTufValidationToRepositories($composer, $newManager, $io);
        $composer->setRepositoryManager($newManager);
        $this->repositoryManager = $composer->getRepositoryManager();
    }

    /**
     * Creates a new repository manager.
     *
     * The new repository manager will allow Composer repositories to opt into
     * TUF protection.
     *
     * @param Composer $composer
     *   The Composer instance.
     * @param IOInterface $io
     *   The I/O service.
     *
     * @return RepositoryManager
     *   The new repository manager.
     */
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

    /**
     * Adds TUF validation to already-instantiated Composer repositories.
     *
     * @param Composer $composer
     *   The Composer instance.
     * @param RepositoryManager $manager
     *   The repository manager.
     * @param IOInterface $io
     *   The I/O service.
     */
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
        $path = ComposerFileStorage::basePath($composer->getConfig());
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
