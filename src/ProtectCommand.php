<?php

namespace Tuf\ComposerIntegration;

use Composer\Command\BaseCommand;
use Composer\Json\JsonFile;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A command that enables TUF protection for a Composer repository.
 */
class ProtectCommand extends BaseCommand
{
    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        parent::configure();
        $this
          ->setName('tuf:protect')
          ->setDescription('Adds TUF protection to Composer repositories defined in `composer.json`.')
          ->addArgument('repository', InputArgument::REQUIRED, "The key or URL of the repository to protect.");
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->getApplication()->getComposer();

        $file = $composer->getConfig()->getConfigSource()->getName();
        $file = new JsonFile($file);
        $data = $file->read();
        $repositories = $data['repositories'] ?? [];

        $key = $input->getArgument('repository');
        foreach ($repositories as $index => $repository) {
            if ($index === $key || $repository['url'] === $key) {
                if ($repository['type'] !== 'composer') {
                    throw new \RuntimeException("Only Composer repositories can be protected by TUF.");
                }
                $data['repositories'][$index]['tuf'] = TRUE;
                $file->write($data);
                $output->writeln($repository['url'] . ' is now protected by TUF.');
                return 0;
            }
        }
        throw new \LogicException("The '$key' repository is not defined in " . $file->getPath());
    }
}
