<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class StatsCommand extends BaseCommand
{
    protected static $defaultName = 'stats';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Show store statistics (counts, classes, bytes)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        return $this->withStorage($input, function ($storage) {
            $count = 0;
            $classes = [];
            $bytes = 0;

            foreach ($storage->list() as $uuid) {
                $count++;
                try {
                    $cls = $storage->getClassName($uuid);
                    if ($cls) {
                        $classes[$cls] = ($classes[$cls] ?? 0) + 1;
                    }
                    $bytes += $storage->getMemoryConsumption($uuid);
                } catch (Throwable) {
                    // ignore corrupted entries in stats
                }
            }

            ksort($classes);

            $this->io->writeln('<title> Store Stats </title>');
            $this->io->writeln(sprintf(' <key>Objects:</key> <ok>%d</ok>', $count));
            $this->io->writeln(sprintf(' <key>Total Size:</key> <ok>%s</ok>', self::humanBytes($bytes)));
            $this->io->newLine();
            $this->io->writeln('<title> By Class </title>');
            if (!$classes) {
                $this->io->writeln(' <muted>no classes</muted>');
            } else {
                foreach ($classes as $cls => $cnt) {
                    $this->io->writeln(sprintf(' <key>%s</key>: <ok>%d</ok>', $cls, $cnt));
                }
            }

            return 0;
        });
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return sprintf('%.2f %s', $bytes, $units[$i]);
    }
}