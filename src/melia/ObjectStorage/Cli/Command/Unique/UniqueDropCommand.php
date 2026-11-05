<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command\Unique;

use melia\ObjectStorage\Cli\Command\BaseCommand;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Unique\Manager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes a unique index; the objects are not affected.
 */
#[AsCommand(name: 'unique:drop')]
final class UniqueDropCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Remove a unique index (the objects are not affected)')
            ->addArgument('name', InputArgument::REQUIRED, 'Name of the index');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        return $this->withStorage($input, function (ObjectStorage $storage) use ($input): int {
            $name = (string)$input->getArgument('name');
            return (new Manager($storage))->drop($name)
                ? $this->success(sprintf('Unique index %s dropped.', $name))
                : $this->failure(sprintf('There is no unique index %s.', $name));
        });
    }
}
