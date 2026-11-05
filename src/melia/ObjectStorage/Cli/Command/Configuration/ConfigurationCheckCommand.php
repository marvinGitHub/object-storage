<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command\Configuration;

use melia\ObjectStorage\Cli\Command\BaseCommand;
use melia\ObjectStorage\Configuration\Exception\ConfigurationException;
use melia\ObjectStorage\Configuration\Factory;
use melia\ObjectStorage\Configuration\Registry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks that the backends of a configuration work: takes a lock and uses the cache.
 */
#[AsCommand(name: 'configuration:check')]
final class ConfigurationCheckCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Check a configuration: take a lock and use the cache (default: the one assigned to the storage)')
            ->addArgument('name', InputArgument::OPTIONAL, 'Name of the configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        $dir = (string)$input->getOption('dir');
        $name = $input->getArgument('name');
        $name = is_string($name) && '' !== $name ? $name : (is_dir($dir) ? Registry::getAssignedName($dir) : null);
        if (null === $name) {
            return $this->failure('Pass the name of a configuration; the storage has none assigned.', Command::INVALID);
        }

        try {
            $results = (new Factory())->check($this->registry($input)->get($name), is_dir($dir) ? $dir : sys_get_temp_dir());
        } catch (ConfigurationException $e) {
            return $this->failure(sprintf('%s: %s', $name, $e->getMessage()));
        }

        foreach ($results as $what => $result) {
            $this->io->writeln(sprintf('%-6s %s', $what, $result));
        }
        return $this->success(sprintf('The configuration %s works.', $name));
    }
}
