<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command\Configuration;

use melia\ObjectStorage\Cli\Command\BaseCommand;
use melia\ObjectStorage\Configuration\Configuration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates or replaces a configuration from JSON, as configuration:export prints it; missing settings get their
 * defaults.
 */
#[AsCommand(name: 'configuration:import')]
final class ConfigurationImportCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Create a configuration from JSON (a file, or - for STDIN), as configuration:export prints it')
            ->addArgument('file', InputArgument::REQUIRED, 'JSON file, - for STDIN')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Name of the configuration, instead of the one in the JSON')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'Replace a configuration of the name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        $file = (string)$input->getArgument('file');
        $json = '-' === $file ? stream_get_contents(STDIN) : @file_get_contents($file);
        if (false === $json) {
            return $this->failure(sprintf('Unable to read %s', $file));
        }

        $data = json_decode((string)$json, true);
        if (!is_array($data)) {
            return $this->failure(sprintf('%s is no JSON object: %s', $file, json_last_error_msg()), Command::INVALID);
        }
        $name = $input->getOption('name');
        if (is_string($name) && '' !== $name) {
            $data['name'] = $name;
        }

        $configuration = Configuration::fromArray($data);
        $registry = $this->registry($input);
        $replaced = $registry->has($configuration->name);

        if ($replaced && !$input->getOption('replace')) {
            return $this->failure(sprintf('A configuration %s exists already; pass --replace to replace it.', $configuration->name));
        }
        $replaced ? $registry->update($configuration) : $registry->create($configuration);

        return $this->success(sprintf('Configuration %s %s in %s (locks: %s, cache: %s).',
            $configuration->name,
            $replaced ? 'replaced' : 'created',
            $registry->getDirectory(),
            $configuration->lock->describe(),
            $configuration->cache->describe()
        ));
    }
}
