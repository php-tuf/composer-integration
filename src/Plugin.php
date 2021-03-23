<?php

namespace Tuf\ComposerIntegration;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Repository\ComposerRepository;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\RepositoryManager;
use Composer\Util\Filesystem;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use Tuf\ComposerIntegration\Repository\TufValidatedComposerRepository;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return [
          PluginEvents::PRE_FILE_DOWNLOAD => ['preFileDownload', -1000],
        ];
    }

    /**
     * Reacts when a package is about to be downloaded.
     *
     * Note that this event handler is undebuggable due to some inexplicable
     * bananapantsing in Composer's plugin manager. It copies the code of this
     * class, renames it, and evals it into existence...which means it's not
     * debuggable, since the code doesn't concretely exist in a place where
     * Xdebug can find it. Dafuq! (See
     * \Composer\Plugin\PluginManager::registerPackage() if you don't believe
     * me.)
     *
     * @param \Composer\Plugin\PreFileDownloadEvent $event
     *   The event object.
     */
    public function preFileDownload(PreFileDownloadEvent $event): void
    {
        if ($event->getType() === 'package') {
            /** @var \Composer\Package\PackageInterface $package */
            $package = $event->getContext();
            // If the package is protected by TUF, its repository URL and target
            // key should have been set by
            // \Tuf\ComposerIntegration\PackageLoader::loadPackages().
            if (array_key_exists('tuf', $package->getTransportOptions())) {
                $event->getHttpDownloader()->setPackageUrl($package, $event->getProcessedUrl());
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        // Finish any pending transfers, then swap out the HTTP downloader with
        // a TUF-aware one.
        $downloader = $composer->getLoop()->getHttpDownloader();
        $downloader->wait();
        $downloader = new HttpDownloaderAdapter($downloader, static::getStoragePath($composer));
        $this->setHttpDownloader($composer, $io, $downloader);

        // By the time this plugin is activated, several repositories may have
        // already been instantiated, and we need to convert them to
        // TUF-validated repositories. Unfortunately, the repository manager
        // only allows us to add new repositories, not replace existing ones.
        // So we have to rebuild the repository manager from the ground up to
        // add TUF validation to the existing repositories.
        $newManager = $this->createNewRepositoryManager($composer, $io);
        $this->addTufValidationToRepositories($composer, $newManager, $io);
        $composer->setRepositoryManager($newManager);
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
                $config = $repository->getRepoConfig();

                if (isset($config['tuf'])) {
                    $repository = new TufValidatedComposerRepository($config, $io, $composer->getConfig(), $composer->getLoop()->getHttpDownloader(), $composer->getEventDispatcher());
                } else {
                    // @todo Usability assessment. Should we output this for other repo types, or not at all?
                    $io->warning("Authenticity of packages from ${config['url']} are not verified by TUF.");
                }
            }
            $manager->addRepository($repository);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
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
        $downloader = $composer->getLoop()->getHttpDownloader();

        if ($downloader instanceof HttpDownloaderAdapter) {
            $this->setHttpDownloader($composer, $io, $downloader->getDecorated());
        }
    }

    /**
     * Swaps out the HTTP downloader.
     *
     * The HTTP downloader is a low-level service used by a lot of things.
     * Therefore, we need to reinitialize the main event loop, the download
     * manager, and the installation manager for the change to take full effect.
     *
     * @param \Composer\Composer $composer
     *   The Composer instance.
     * @param \Composer\IO\IOInterface $io
     *   The I/O object.
     * @param \Composer\Util\HttpDownloader $newDownloader
     *   The new HTTP downloader to swap in.
     */
    private function setHttpDownloader(Composer $composer, IOInterface $io, HttpDownloader $newDownloader): void
    {
        $loop = new Loop($newDownloader, $composer->getLoop()->getProcessExecutor());
        $composer->setLoop($loop);

        $factory = new Factory();

        $downloadManager = $factory->createDownloadManager($io, $composer->getConfig(), $newDownloader, $loop->getProcessExecutor(), $composer->getEventDispatcher());
        $composer->setDownloadManager($downloadManager);

        $installationManager = $factory->createInstallationManager($loop, $io, $composer->getEventDispatcher());
        $composer->setInstallationManager($installationManager);

        // It sucks to call a protected method, but if we don't do this, package
        // installations and updates will fail hard. Hopefully we can fix this
        // later if Composer makes Factory::createDefaultInstallers() public.
        // @todo Support composer/installers and
        // oomphinc/composer-installers-extender as well.
        $reflector = new \ReflectionObject($factory);
        $method = $reflector->getMethod('createDefaultInstallers');
        $method->setAccessible(true);
        $method->invoke($factory, $installationManager, $composer, $io, $loop->getProcessExecutor());
    }

    /**
     * Gets the path where persistent TUF data should be stored.
     *
     * @param \Composer\Composer $composer
     *   The Composer instance.
     *
     * @return string
     *   The path where persistent TUF data should be stored.
     */
    private static function getStoragePath(Composer $composer): string
    {
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        $vendorDir = rtrim($vendorDir, DIRECTORY_SEPARATOR);
        return implode(DIRECTORY_SEPARATOR, [$vendorDir, 'composer', 'tuf']);
    }
}
