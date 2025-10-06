<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class DeleteCommand extends BaseCommand
{
    protected static $defaultName = 'delete';

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Delete object by UUID')
            ->addArgument('uuid', InputArgument::REQUIRED, 'UUID')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Do not error if not found');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);
        $uuid = (string)$input->getArgument('uuid');
        $force = (bool)$input->getOption('force');

        return $this->withStorage($input, function ($storage) use ($uuid, $force) {
            try {
                $storage->delete($uuid, $force);
                return $this->success('Deleted ' . $uuid);
            } catch (Throwable $e) {
                if ($force) {
                    return $this->warning('Not found or already deleted: ' . $uuid);
                }
                return $this->failure('Delete failed: ' . $e->getMessage(), 2);
            }
        });
    }
}