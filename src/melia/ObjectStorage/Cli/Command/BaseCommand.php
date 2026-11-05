<?php

declare(strict_types=1);

namespace melia\ObjectStorage\Cli\Command;

use melia\ObjectStorage\Cli\Config\CliConfig;
use melia\ObjectStorage\Cli\DI\Container;
use melia\ObjectStorage\Cli\Style\Styles;
use melia\ObjectStorage\Configuration\Configuration;
use melia\ObjectStorage\Configuration\Exception\ConfigurationException;
use melia\ObjectStorage\Configuration\Exception\ConfigurationNotFoundException;
use melia\ObjectStorage\Configuration\Registry;
use melia\ObjectStorage\Exception\InvalidMaxDepthException;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Strategy\Standard;
use melia\ObjectStorage\Unique\Manager as UniqueManager;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class BaseCommand extends Command
{
    protected SymfonyStyle $io;

    /** The environment variable naming the directory of the configurations. */
    public const string ENV_CONFIGURATIONS = 'OBJECT_STORAGE_CONFIGURATIONS';

    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Storage directory', './.object-storage')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON where applicable')
            ->addOption('configuration', null, InputOption::VALUE_REQUIRED, 'Name of the configuration to open the storage with (default: the one assigned to the storage)')
            ->addOption('configurations', null, InputOption::VALUE_REQUIRED, sprintf('Directory of the configurations (default: $%s, else next to the CLI config file)', self::ENV_CONFIGURATIONS));
    }

    /**
     * The directory of the named configurations: --configurations, the environment variable, or the directory
     * of the central CLI config file.
     */
    protected function configurationsDirectory(InputInterface $input): string
    {
        $option = $input->hasOption('configurations') ? $input->getOption('configurations') : null;
        if (is_string($option) && '' !== $option) {
            return $option;
        }

        $env = getenv(self::ENV_CONFIGURATIONS);
        if (is_string($env) && '' !== $env) {
            return $env;
        }

        return dirname((new CliConfig())->getConfigPath()) . DIRECTORY_SEPARATOR . 'configurations';
    }

    protected function registry(InputInterface $input): Registry
    {
        return new Registry($this->configurationsDirectory($input), Container::makeLogger());
    }

    /**
     * The configuration to open the storage with: the one named by --configuration, else the one assigned to the
     * storage, else none. A storage assigned a configuration that isn't found fails instead of falling back to
     * file locks, since all processes of a storage must use the same locks.
     *
     * @throws ConfigurationException
     */
    protected function configurationFor(InputInterface $input, string $dir): ?Configuration
    {
        $name = $input->hasOption('configuration') ? $input->getOption('configuration') : null;
        $name = is_string($name) && '' !== $name ? $name : Registry::getAssignedName($dir);
        if (null === $name) {
            return null;
        }

        $registry = $this->registry($input);
        try {
            return $registry->get($name);
        } catch (ConfigurationNotFoundException $e) {
            throw new ConfigurationNotFoundException(sprintf(
                'The configuration "%s" is not in %s; pass the directory of the configurations with --configurations or $%s.',
                $name,
                $registry->getDirectory(),
                self::ENV_CONFIGURATIONS
            ), previous: $e);
        }
    }

    protected function initIO(InputInterface $input, OutputInterface $output): void
    {
        $this->io = Styles::io($input, $output);
    }

    /**
     * Checks the writes to a storage against its unique indexes, see Unique\Manager.
     */
    protected function attachUniqueIndexes(ObjectStorage $storage): UniqueManager
    {
        $unique = new UniqueManager($storage);
        $unique->attach();
        return $unique;
    }

    /**
     * @throws IOException
     * @throws InvalidMaxDepthException
     */
    protected function withStorage(InputInterface $input, callable $fn)
    {
        $dir = (string)$input->getOption('dir');
        $storage = Container::makeStorage($dir, $this->configurationFor($input, $dir));

        // Apply CLI-only per-dir configuration (central config file, not inside the storage dir)
        $config = new CliConfig();
        $configuredDepth = $config->getShardDepthForDir($dir);

        if ($configuredDepth !== null) {
            $strategy = $storage->getStrategy();

            if ($strategy instanceof Standard) {
                $strategy->setShardDepth($configuredDepth);
            } else {
                throw new RuntimeException('Configured shard depth cannot be applied: unsupported strategy instance.');
            }
        }

        // every write of a command (put, copy, move, …) is checked against the unique indexes of the storage
        $this->attachUniqueIndexes($storage);

        return $fn($storage, $dir);
    }

    protected function success(string $message): int
    {
        $this->io->writeln(sprintf('<ok>✔ %s</ok>', $message));
        return Command::SUCCESS;
    }

    protected function warning(string $message): int
    {
        $this->io->writeln(sprintf('<warn>⚠ %s</warn>', $message));
        return Command::SUCCESS;
    }

    protected function failure(string $message, int $code = Command::FAILURE): int
    {
        $this->io->writeln(sprintf('<err>✖ %s</err>', $message));
        return $code;
    }
}