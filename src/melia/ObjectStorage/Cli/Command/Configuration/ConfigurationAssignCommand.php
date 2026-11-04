<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command\Configuration;

use melia\ObjectStorage\Cli\Command\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Assigns a configuration to the storage (--dir), so it is opened with it; --none removes the assignment.
 */
final class ConfigurationAssignCommand extends BaseCommand
{
    protected static $defaultName = 'configuration:assign';

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Assign a configuration to the storage, or remove the assignment with --none')
            ->addArgument('name', InputArgument::OPTIONAL, 'Name of the configuration')
            ->addOption('none', null, InputOption::VALUE_NONE, 'Remove the assignment: the storage uses the defaults');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        $dir = (string)$input->getOption('dir');
        $name = $input->getArgument('name');
        $none = (bool)$input->getOption('none');

        if ($none === (is_string($name) && '' !== $name)) {
            return $this->failure('Pass either the name of a configuration or --none.', Command::INVALID);
        }
        if (!is_dir($dir)) {
            return $this->failure(sprintf('Storage not found: %s', $dir));
        }

        $this->registry($input)->assign($dir, $none ? null : $name);

        return $this->success($none
            ? sprintf('%s uses the defaults now. All processes using it must do so as well.', $dir)
            : sprintf('%s uses the configuration %s now. All processes using it must do so as well.', $dir, $name));
    }
}
