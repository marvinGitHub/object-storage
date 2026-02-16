<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class PurgeExpiredCommand extends BaseCommand
{
    protected static $defaultName = 'purge-expired';

    protected function configure(): void
    {
        parent::configure();

        $this
            ->setDescription('Delete expired objects from storage (based on metadata TTL)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not delete anything; only report what would be deleted')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of expired objects to delete (0 = unlimited)', '0')
            ->addOption('class', 'c', InputOption::VALUE_REQUIRED, 'Only purge objects of the given FQCN (optional)', null)
            ->addOption('quiet-errors', null, InputOption::VALUE_NONE, 'Suppress per-UUID error output (still returns non-zero on fatal command errors)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        $dryRun = (bool)$input->getOption('dry-run');
        $limitOpt = (string)$input->getOption('limit');
        $limit = (int)$limitOpt;
        $className = $input->getOption('class');
        $quietErrors = (bool)$input->getOption('quiet-errors');

        if ($limit < 0) {
            return $this->failure('--limit must be >= 0', 1);
        }

        if ($className !== null && !is_string($className)) {
            return $this->failure('--class must be a string', 1);
        }

        return $this->withStorage($input, function ($storage) use ($dryRun, $limit, $className, $quietErrors) {
            try {
                $deleted = 0;
                $expiredFound = 0;
                $errors = 0;

                $this->io->writeln(sprintf(
                    '<title> Purge expired objects </title>%s',
                    $dryRun ? ' <comment>(dry-run)</comment>' : ''
                ));

                if ($className) {
                    $this->io->writeln('Class filter: ' . $className);
                }

                $this->io->writeln('Limit: ' . ($limit === 0 ? 'unlimited' : (string)$limit));
                $this->io->newLine();

                foreach ($storage->list($className ?: null) as $uuid) {
                    $uuid = (string)$uuid;

                    try {
                        if (!$storage->expired($uuid)) {
                            continue;
                        }

                        $expiredFound++;

                        if ($dryRun) {
                            $this->io->writeln('Would delete: ' . $uuid);
                            if ($limit !== 0 && $expiredFound >= $limit) {
                                break;
                            }
                            continue;
                        }

                        $storage->delete($uuid);
                        $deleted++;

                        $this->io->writeln('Deleted: ' . $uuid);

                        if ($limit !== 0 && $deleted >= $limit) {
                            break;
                        }
                    } catch (Throwable $e) {
                        $errors++;

                        if (!$quietErrors) {
                            $this->io->writeln(sprintf(
                                '<error>Error for %s: %s</error>',
                                $uuid,
                                $e->getMessage()
                            ));
                        }

                        // Continue purging other objects; per-UUID errors shouldn't abort the whole run.
                        continue;
                    }
                }

                $this->io->newLine();

                if ($dryRun) {
                    $this->io->writeln(sprintf('Expired objects matched: %d', $expiredFound));
                    if ($errors > 0) {
                        $this->io->writeln(sprintf('<comment>Errors encountered: %d</comment>', $errors));
                    }
                    return 0;
                }

                $this->io->writeln(sprintf('Deleted expired objects: %d', $deleted));
                if ($errors > 0) {
                    $this->io->writeln(sprintf('<comment>Errors encountered: %d</comment>', $errors));
                    // Non-fatal: we still purged some (or tried). Return 0 to keep it automation-friendly.
                    // Change to "return 2;" if you prefer strict failure on any per-UUID error.
                }

                return 0;
            } catch (Throwable $e) {
                return $this->failure('Purge failed: ' . $e->getMessage(), 2);
            }
        });
    }
}