<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command\Unique;

use melia\ObjectStorage\Cli\Command\BaseCommand;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Unique\Exception\DuplicateValuesException;
use melia\ObjectStorage\Unique\Exception\UniqueIndexException;
use melia\ObjectStorage\Unique\Manager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Claims the values of all objects again, e.g. after writes without the index; reports duplicates.
 */
#[AsCommand(name: 'unique:rebuild')]
final class UniqueRebuildCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Claim the values of all objects again, e.g. after writes without the index (reports duplicates)')
            ->addArgument('name', InputArgument::REQUIRED, 'Name of the index');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        return $this->withStorage($input, function (ObjectStorage $storage) use ($input): int {
            $name = (string)$input->getArgument('name');
            try {
                $count = (new Manager($storage))->rebuild($name);
            } catch (DuplicateValuesException $e) {
                foreach (array_slice($e->getDuplicates(), 0, 20, true) as $key => $uuids) {
                    $this->io->writeln(sprintf('%s: %s', $key, implode(', ', $uuids)));
                }
                return $this->failure(sprintf('%d value(s) are shared by several objects; the index is left as it was.', count($e->getDuplicates())));
            } catch (UniqueIndexException $e) {
                return $this->failure($e->getMessage());
            }

            return $this->success(sprintf('Unique index %s rebuilt: %d value(s).', $name, $count));
        });
    }
}
