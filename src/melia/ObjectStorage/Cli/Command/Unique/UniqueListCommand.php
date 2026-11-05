<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command\Unique;

use melia\ObjectStorage\Cli\Command\BaseCommand;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Unique\Definition;
use melia\ObjectStorage\Unique\Manager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists the unique indexes of the storage.
 */
#[AsCommand(name: 'unique:list')]
final class UniqueListCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('List the unique indexes of the storage');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        return $this->withStorage($input, function (ObjectStorage $storage) use ($input, $output): int {
            $indexes = (new Manager($storage))->list();

            if ($input->getOption('json')) {
                $output->writeln((string)json_encode(array_values(array_map(static fn(Definition $d): array => $d->toArray(), $indexes)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return Command::SUCCESS;
            }
            if ([] === $indexes) {
                return $this->warning('No unique indexes yet: create one with unique:create.');
            }

            $this->io->table(['Name', 'Class', 'Paths', 'Case'], array_map(static fn(Definition $d): array => [
                $d->name,
                $d->className,
                implode(', ', $d->paths),
                $d->caseInsensitive ? 'ignored' : 'exact',
            ], array_values($indexes)));
            return Command::SUCCESS;
        });
    }
}
