<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command\Transfer;

use melia\ObjectStorage\Cli\Command\BaseCommand;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Transfer\ObjectTransfer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Copies or moves objects of the storage (--dir) into another storage (--to), as stored.
 */
abstract class TransferCommand extends BaseCommand
{
    abstract protected function isMove(): bool;

    protected function configure(): void
    {
        parent::configure();
        $verb = $this->isMove() ? 'Move' : 'Copy';
        $this
            ->setDescription(sprintf('%s objects into another storage, keeping UUID, revision and lifetime', $verb))
            ->addArgument('uuids', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'UUIDs of the objects')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Directory of the target storage')
            ->addOption('to-configuration', null, InputOption::VALUE_REQUIRED, 'Configuration of the target (default: the one assigned to it)')
            ->addOption('create', null, InputOption::VALUE_NONE, 'Create the target storage if it doesn\'t exist')
            ->addOption('class', null, InputOption::VALUE_REQUIRED, 'All objects of the class (FQCN)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'All objects of the storage')
            ->addOption('with-references', 'r', InputOption::VALUE_NONE, 'Copy the referenced objects as well (they stay in the source when moving)')
            ->addOption('on-conflict', null, InputOption::VALUE_REQUIRED, 'An object existing in the target: fail, skip or overwrite', ObjectTransfer::CONFLICT_FAIL);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        $targetDir = (string)$input->getOption('to');
        $uuids = array_values(array_filter((array)$input->getArgument('uuids'), static fn($uuid) => '' !== trim((string)$uuid)));
        $className = $input->getOption('class');
        $all = (bool)$input->getOption('all');
        $onConflict = (string)$input->getOption('on-conflict');

        if ('' === $targetDir) {
            return $this->failure('Pass the target storage with --to.', Command::INVALID);
        }
        if (1 !== count(array_filter([[] !== $uuids, is_string($className) && '' !== $className, $all]))) {
            return $this->failure('Choose the objects: UUIDs, --class or --all (one of them).', Command::INVALID);
        }
        if (!in_array($onConflict, ObjectTransfer::CONFLICTS, true)) {
            return $this->failure(sprintf('--on-conflict must be one of %s.', implode(', ', ObjectTransfer::CONFLICTS)), Command::INVALID);
        }
        if (!is_dir($targetDir) && !$input->getOption('create')) {
            return $this->failure(sprintf('The target storage %s doesn\'t exist; pass --create to create it.', $targetDir));
        }

        $toConfiguration = $input->getOption('to-configuration');
        $target = $this->registry($input)->open($targetDir, is_string($toConfiguration) && '' !== $toConfiguration ? $toConfiguration : null);
        $this->attachUniqueIndexes($target); // copies are checked against the unique indexes of the target

        return $this->withStorage($input, function (ObjectStorage $source) use ($target, $uuids, $className, $all, $onConflict, $input, $output): int {
            $transfer = new ObjectTransfer($source, $target);

            // the list is taken first: moving changes what the source lists
            if ([] === $uuids) {
                $uuids = iterator_to_array($source->list($all ? null : (string)$className), false);
            }
            if ([] === $uuids) {
                return $this->warning('No objects to transfer.');
            }

            $json = (bool)$input->getOption('json');
            $progress = $json ? null : new ProgressBar($output, count($uuids));
            $progress?->setBarWidth(28);
            $progress?->start();

            $arguments = [$uuids, (bool)$input->getOption('with-references'), $onConflict, static fn() => $progress?->advance()];
            $result = $this->isMove() ? $transfer->move(...$arguments) : $transfer->copy(...$arguments);

            $progress?->finish();
            if ($json) {
                $output->writeln((string)json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return [] === $result->failed ? Command::SUCCESS : Command::FAILURE;
            }

            $this->io->newLine(2);
            foreach ($result->skipped as $uuid => $reason) {
                $this->io->writeln(sprintf('<warn>skipped</warn> %s: %s', $uuid, $reason));
            }
            foreach ($result->failed as $uuid => $error) {
                $this->io->writeln(sprintf('<err>failed</err>  %s: %s', $uuid, $error));
            }

            $summary = sprintf(
                '%s %d object(s) to %s%s; %d skipped, %d failed.',
                $this->isMove() ? 'Moved' : 'Copied',
                count($result->transferred),
                $target->getStorageDir(),
                [] === $result->references ? '' : sprintf(' with %d referenced object(s)', count($result->references)),
                count($result->skipped),
                count($result->failed)
            );

            return [] === $result->failed ? $this->success($summary) : $this->failure($summary);
        });
    }
}
