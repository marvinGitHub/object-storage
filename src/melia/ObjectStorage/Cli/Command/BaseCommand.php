<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command;

use melia\ObjectStorage\Cli\DI\Container;
use melia\ObjectStorage\Cli\Style\Styles;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class BaseCommand extends Command
{
    protected SymfonyStyle $io;

    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Storage directory', './.object-storage')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON where applicable');
    }

    protected function initIO(InputInterface $input, OutputInterface $output): void
    {
        $this->io = Styles::io($input, $output);
    }

    protected function withStorage(InputInterface $input, callable $fn)
    {
        $dir = (string)$input->getOption('dir');
        $storage = Container::makeStorage($dir);
        return $fn($storage, $dir);
    }

    protected function success(string $message): int
    {
        $this->io->writeln(sprintf('<ok>✔ %s</ok>', $message));
        return Command::SUCCESS;
    }

    protected function warning(string $message): int
    {
        $this->io->writeln(sprintf('<warn>⚠ %s</warn>', $message));
        return Command::SUCCESS;
    }

    protected function failure(string $message, int $code = Command::FAILURE): int
    {
        $this->io->writeln(sprintf('<err>✖ %s</err>', $message));
        return $code;
    }
}