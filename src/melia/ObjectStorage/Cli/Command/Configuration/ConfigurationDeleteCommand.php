<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command\Configuration;

use melia\ObjectStorage\Cli\Command\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deletes a configuration; storages it is assigned to can't be opened until they are assigned another one.
 */
final class ConfigurationDeleteCommand extends BaseCommand
{
    protected static $defaultName = 'configuration:delete';

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Delete a configuration (storages assigned to it can\'t be opened until reassigned)')
            ->addArgument('name', InputArgument::REQUIRED, 'Name of the configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        $name = (string)$input->getArgument('name');
        if (!$this->registry($input)->delete($name)) {
            return $this->failure(sprintf('There is no configuration %s.', $name));
        }

        return $this->success(sprintf('Configuration %s deleted.', $name));
    }
}
