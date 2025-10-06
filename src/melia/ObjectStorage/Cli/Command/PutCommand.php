<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command;

use ReflectionClass;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class PutCommand extends BaseCommand
{
    protected static $defaultName = 'put';

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setDescription('Store/update an object from JSON (file or stdin)')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Path to JSON file (omit to read from stdin)')
            ->addOption('uuid', 'u', InputOption::VALUE_REQUIRED, 'UUID to overwrite or assign')
            ->addOption('class', 'c', InputOption::VALUE_REQUIRED, 'FQCN of the target object (required for new objects)')
            ->addOption('ttl', 't', InputOption::VALUE_REQUIRED, 'TTL in seconds (optional)', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initIO($input, $output);

        $file = $input->getOption('file') ?: null;
        $uuid = $input->getOption('uuid') ?: null;
        $class = $input->getOption('class') ?: null;
        $ttlOpt = $input->getOption('ttl');
        $ttl = $ttlOpt !== null ? (int)$ttlOpt : null;

        $json = $file ? @file_get_contents($file) : stream_get_contents(STDIN);
        if ($json === false || $json === '') {
            return $this->failure('No JSON input provided', 1);
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $this->failure('Invalid JSON payload', 1);
        }

        return $this->withStorage($input, function ($storage) use ($data, $class, $uuid, $ttl) {
            try {
                if ($uuid === null && $class === null) {
                    return $this->failure('--class is required for creating new objects without --uuid', 1);
                }

                if ($class === null) {
                    $class = $storage->getClassName($uuid);
                    if ($class === null) {
                        return $this->failure('Unable to infer class for UUID; specify --class', 1);
                    }
                }

                if (!class_exists($class)) {
                    return $this->failure('Class not found: ' . $class, 1);
                }

                $ref = new ReflectionClass($class);
                $obj = $ref->newInstanceWithoutConstructor();

                foreach ($data as $k => $v) {
                    // naive property assignment; adjust to your reflection helper if needed
                    if ($ref->hasProperty($k)) {
                        $prop = $ref->getProperty($k);
                        $prop->setAccessible(true);
                        $prop->setValue($obj, $v);
                    } else {
                        // ignore unknown fields, or collect warnings
                    }
                }

                $id = $storage->store($obj, $uuid, $ttl);
                return $this->success('Stored object with UUID ' . $id);
            } catch (Throwable $e) {
                return $this->failure('Store failed: ' . $e->getMessage(), 2);
            }
        });
    }
}