<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command\Configuration;

use melia\ObjectStorage\Cli\Command\BaseCommand;
use melia\ObjectStorage\Configuration\Registry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists the named configurations and which one the storage (--dir) is assigned.
 */
final class ConfigurationListCommand extends BaseCommand
{
    protected static $defaultName = 'configuration:list';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('List the named configurations (locks, cache, strategy) and the one assigned to the storage');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        $registry = $this->registry($input);
        $dir = (string)$input->getOption('dir');
        $assigned = is_dir($dir) ? Registry::getAssignedName($dir) : null;
        $configurations = $registry->list();

        if ($input->getOption('json')) {
            $output->writeln((string)json_encode([
                'directory' => $registry->getDirectory(),
                'assigned' => $assigned,
                'configurations' => array_values(array_map(static fn($configuration) => [
                    'name' => $configuration->name,
                    'description' => $configuration->description,
                    'lock' => $configuration->lock->describe(),
                    'cache' => $configuration->cache->describe(),
                ], $configurations)),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $this->io->writeln(sprintf('Configurations in <info>%s</info>', $registry->getDirectory()));
        if ([] === $configurations) {
            return $this->warning('No configurations yet: import one with configuration:import.');
        }

        $rows = [];
        foreach ($configurations as $configuration) {
            $rows[] = [
                $configuration->name . ($configuration->name === $assigned ? ' *' : ''),
                $configuration->lock->describe(),
                $configuration->cache->describe(),
                $configuration->description,
            ];
        }
        $this->io->table(['Name', 'Locks', 'Cache', 'Description'], $rows);
        $this->io->writeln(null === $assigned
            ? sprintf('%s uses the defaults (file locks, cache in memory).', $dir)
            : sprintf('* assigned to %s', $dir));

        return Command::SUCCESS;
    }
}
