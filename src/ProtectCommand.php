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
          ->addArgument('repository', InputArgument::REQUIRED);
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->getApplication()->getComposer();

        $repositories = $composer->getPackage()->getRepositories();

        $key = $input->getArgument('repository');
        foreach ($repositories as $index => $repository) {
            if ($index === $key || $repository['url'] === $key) {
                $key = $index;
                break;
            }
        }
        if (array_key_exists($key, $repositories)) {
            if ($repositories[$key]['type'] === 'composer') {
                $repositories[$key]['tuf'] = true;
            } else {
                throw new \RuntimeException("TUF can only protected Composer repositories.");
            }

            $file = $composer->getConfig()->getConfigSource()->getName();
            $file = new JsonFile($file);
            $data = $file->read();
            $data['repositories'] = $repositories;
            $file->write($data);

            $message = sprintf("TUF protection enabled for '%s'.", $repositories[$key]['url']);
            $output->writeln($message);
        } else {
            throw new \LogicException("The '$key' repository is not defined.");
        }
        return 0;
    }
}
