<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ListCommand extends BaseCommand
{
    protected static $defaultName = 'list';

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('List UUIDs (optionally filter by class)')
            ->addOption('class', 'c', InputOption::VALUE_REQUIRED, 'Filter by fully-qualified class name')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit number of results', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        return $this->withStorage($input, function ($storage) use ($input) {
            $class = $input->getOption('class') ?: null;
            $limit = (int)($input->getOption('limit') ?? 0);
            $asJson = (bool)$input->getOption('json');

            $uuids = [];
            foreach ($storage->list($class) as $uuid) {
                $uuids[] = $uuid;
                if ($limit > 0 && count($uuids) >= $limit) {
                    break;
                }
            }

            if ($asJson) {
                $this->io->writeln(json_encode($uuids, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return 0;
            }

            if (!$uuids) {
                $this->io->writeln('<warn>No objects found.</warn>');
                return 0;
            }

            $this->io->writeln('<title> Objects </title>');
            foreach ($uuids as $i => $id) {
                $this->io->writeln(sprintf(' <muted>%3d.</muted> <key>%s</key>', $i + 1, $id));
            }
            $this->io->writeln(sprintf('<muted>Total:</muted> <ok>%d</ok>', count($uuids)));

            return 0;
        });
    }
}