<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class GetCommand extends BaseCommand
{
    protected static $defaultName = 'get';

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Get object and/or metadata by UUID')
            ->addArgument('uuid', InputArgument::REQUIRED, 'UUID')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Print object graph only (JSON)')
            ->addOption('meta', null, InputOption::VALUE_NONE, 'Print metadata only (JSON)')
            ->addOption('pretty', null, InputOption::VALUE_NONE, 'Pretty-print JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        $uuid = (string)$input->getArgument('uuid');
        $rawOnly = (bool)$input->getOption('raw');
        $metaOnly = (bool)$input->getOption('meta');
        $pretty = (bool)$input->getOption('pretty');
        $flags = $pretty ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : 0;

        return $this->withStorage($input, function ($storage) use ($uuid, $rawOnly, $metaOnly, $flags) {
            try {
                $meta = $storage->loadMetadata($uuid);
                $obj = $storage->load($uuid);

                if (!$meta && !$obj) {
                    return $this->failure('UUID not found', 1);
                }

                if ($rawOnly) {
                    $json = $obj !== null ? json_encode($obj, $flags) : 'null';
                    $this->io->writeln($json);
                    return 0;
                }

                if ($metaOnly) {
                    $this->io->writeln(json_encode($meta, $flags));
                    return 0;
                }

                $this->io->writeln('<title> Metadata </title>');
                $this->io->writeln(json_encode($meta, $flags));
                $this->io->newLine();
                $this->io->writeln('<title> Object </title>');
                $this->io->writeln(json_encode($obj, $flags));

                return 0;
            } catch (Throwable $e) {
                return $this->failure('Error loading object: ' . $e->getMessage(), 2);
            }
        });
    }
}