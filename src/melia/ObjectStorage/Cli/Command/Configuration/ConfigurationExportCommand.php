<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command\Configuration;

use melia\ObjectStorage\Cli\Command\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints a configuration as JSON, to edit it or to import it elsewhere with configuration:import.
 */
final class ConfigurationExportCommand extends BaseCommand
{
    protected static $defaultName = 'configuration:export';

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Print a configuration as JSON, e.g. to import it elsewhere')
            ->addArgument('name', InputArgument::REQUIRED, 'Name of the configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        $configuration = $this->registry($input)->get((string)$input->getArgument('name'));
        $output->writeln((string)json_encode($configuration->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
