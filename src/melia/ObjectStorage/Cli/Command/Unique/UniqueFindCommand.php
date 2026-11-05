<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command\Unique;

use melia\ObjectStorage\Cli\Command\BaseCommand;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Unique\Exception\UniqueIndexException;
use melia\ObjectStorage\Unique\Manager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the UUID of the object having the values (exit code 1 if none).
 */
#[AsCommand(name: 'unique:find')]
final class UniqueFindCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Print the UUID of the object having the values (exit code 1 if none)')
            ->addArgument('name', InputArgument::REQUIRED, 'Name of the index')
            ->addArgument('values', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'The values, in the order of the paths');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        return $this->withStorage($input, function (ObjectStorage $storage) use ($input, $output): int {
            try {
                $uuid = (new Manager($storage))->findOwner((string)$input->getArgument('name'), ...(array)$input->getArgument('values'));
            } catch (UniqueIndexException $e) {
                return $this->failure($e->getMessage());
            }
            if (null === $uuid) {
                return $this->failure('Not found.');
            }

            $output->writeln($input->getOption('json') ? (string)json_encode(['uuid' => $uuid]) : $uuid);
            return Command::SUCCESS;
        });
    }
}
