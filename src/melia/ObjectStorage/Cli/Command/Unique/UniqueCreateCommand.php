<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command\Unique;

use melia\ObjectStorage\Cli\Command\BaseCommand;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Unique\Definition;
use melia\ObjectStorage\Unique\Exception\DuplicateValuesException;
use melia\ObjectStorage\Unique\Exception\UniqueIndexException;
use melia\ObjectStorage\Unique\Manager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates a unique index; fails, listing them, if objects stored before share values.
 */
#[AsCommand(name: 'unique:create')]
final class UniqueCreateCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Create a unique index: values no two objects of a class may share')
            ->addArgument('name', InputArgument::REQUIRED, 'Name of the index (lowercase letters, digits, "_" and "-")')
            ->addOption('class', null, InputOption::VALUE_REQUIRED, 'FQCN of the objects')
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Path of a value in the stored data, e.g. email; several for a combination')
            ->addOption('case-insensitive', 'i', InputOption::VALUE_NONE, 'Ignore case and surrounding spaces of strings, e.g. for emails');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        $className = (string)$input->getOption('class');
        $paths = (array)$input->getOption('path');
        if ('' === $className || [] === $paths) {
            return $this->failure('Pass the class with --class and at least one --path.', Command::INVALID);
        }

        return $this->withStorage($input, function (ObjectStorage $storage) use ($input, $className, $paths): int {
            $name = (string)$input->getArgument('name');
            try {
                (new Manager($storage))->create(new Definition($name, $className, $paths, (bool)$input->getOption('case-insensitive')));
            } catch (DuplicateValuesException $e) {
                foreach (array_slice($e->getDuplicates(), 0, 20, true) as $key => $uuids) {
                    $this->io->writeln(sprintf('%s: %s', $key, implode(', ', $uuids)));
                }
                return $this->failure(sprintf('%d value(s) are shared by several objects; resolve them first.', count($e->getDuplicates())));
            } catch (UniqueIndexException $e) {
                return $this->failure($e->getMessage());
            }

            return $this->success(sprintf('Unique index %s created: %s of %s.', $name, implode(' + ', $paths), $className));
        });
    }
}
