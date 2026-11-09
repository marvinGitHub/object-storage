<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command;

use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Like touch on a file: sets when an object was modified, without changing its data (ObjectStorage::touch()).
 */
#[AsCommand(name: 'touch')]
final class TouchCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Set when an object was modified (now, or --at), without changing its data')
            ->addArgument('uuid', InputArgument::REQUIRED, 'UUID')
            ->addOption('at', null, InputOption::VALUE_REQUIRED, 'The point in time, e.g. 2026-11-09T10:00:00+01:00 (UTC without offset)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);
        $uuid = (string)$input->getArgument('uuid');
        $atOption = $input->getOption('at');

        return $this->withStorage($input, function ($storage) use ($input, $uuid, $atOption) {
            try {
                $at = null === $atOption ? null : new DateTimeImmutable((string)$atOption, new \DateTimeZone('UTC'));
                $storage->touch($uuid, $at);
                $modified = $storage->getModifiedAt($uuid)?->format(DateTimeInterface::RFC3339_EXTENDED);
                if ($input->getOption('json')) {
                    $this->io->writeln((string)json_encode([
                        'uuid' => $uuid,
                        'createdAt' => $storage->getCreatedAt($uuid)?->format(DateTimeInterface::RFC3339_EXTENDED),
                        'modifiedAt' => $modified,
                    ], JSON_UNESCAPED_SLASHES));
                    return 0;
                }
                return $this->success(sprintf('Touched %s: modified at %s', $uuid, $modified));
            } catch (Throwable $e) {
                return $this->failure('Touch failed: ' . $e->getMessage(), 2);
            }
        });
    }
}
