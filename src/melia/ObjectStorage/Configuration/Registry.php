<?php

namespace melia\ObjectStorage\Configuration;

use melia\ObjectStorage\Configuration\Exception\ConfigurationAlreadyExistsException;
use melia\ObjectStorage\Configuration\Exception\ConfigurationException;
use melia\ObjectStorage\Configuration\Exception\ConfigurationNotFoundException;
use melia\ObjectStorage\File\JsonFileReader;
use melia\ObjectStorage\File\JsonFileWriter;
use melia\ObjectStorage\Logger\LoggerInterface;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\UUID\Helper;
use Throwable;

/**
 * Named configurations, kept as objects of an object storage of their own, and their assignment to storages.
 *
 * Names are unique, also when several processes create a configuration at the same time. A storage is assigned a
 * configuration by name, so it is opened with its directory alone, or with its directory and a name:
 *
 *   $configurations = new Registry('/etc/my-app/object-storage');
 *   $configurations->create(new Configuration('shared', lock: new LockSettings(LockSettings::TYPE_REDIS, 'redis://redis')));
 *   $configurations->assign('/var/storage', 'shared');
 *
 *   $storage = $configurations->open('/var/storage');           // with the assigned configuration
 *   $storage = $configurations->open('/var/storage', 'shared'); // with the named one
 */
final class Registry
{
    /** The file in the directory of a storage naming its configuration. */
    public const string ASSIGNMENT_FILE = 'configuration.json';

    private ?ObjectStorage $internal = null;

    /**
     * @param string $directory Where the configurations are kept, created if it doesn't exist.
     * @param LoggerInterface|null $logger Receives errors that don't stop an operation, e.g. an unreadable
     *        configuration while listing.
     */
    public function __construct(
        private readonly string           $directory,
        private readonly ?LoggerInterface $logger = null,
    )
    {
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * The UUID a configuration is stored under, derived from its name.
     */
    public static function uuidFor(string $name): string
    {
        return Helper::createFixedUuidV4('melia/object-storage:configuration:' . $name);
    }

    /**
     * The object storage keeping the configurations (file locks, no cache: other processes change them).
     *
     * @throws ConfigurationException
     */
    public function getInternalStorage(): ObjectStorage
    {
        if (null === $this->internal) {
            try {
                $internal = new ObjectStorage($this->directory, logger: $this->logger);
            } catch (Throwable $e) {
                throw new ConfigurationException(message: 'Unable to open the storage of the configurations in ' . $this->directory, previous: $e);
            }
            $internal->setCache(null);
            $this->internal = $internal;
        }

        return $this->internal;
    }

    /**
     * All configurations, by name.
     *
     * @return array<string, Configuration>
     * @throws ConfigurationException
     */
    public function list(): array
    {
        $internal = $this->getInternalStorage();
        $configurations = [];

        foreach ($internal->list(Configuration::class) as $uuid) {
            try {
                $configuration = $internal->load($uuid);
                if ($configuration instanceof Configuration) {
                    $configurations[$configuration->name] = $configuration;
                }
            } catch (Throwable $e) {
                $this->logger?->log($e);
            }
        }

        ksort($configurations);
        return $configurations;
    }

    /**
     * @throws ConfigurationException
     */
    public function has(string $name): bool
    {
        return 1 === preg_match(Configuration::NAME_PATTERN, $name) && $this->getInternalStorage()->exists(self::uuidFor($name));
    }

    /**
     * @throws ConfigurationNotFoundException If there is no configuration of the name.
     * @throws ConfigurationException
     */
    public function get(string $name): Configuration
    {
        if (!$this->has($name)) {
            throw new ConfigurationNotFoundException(sprintf('There is no configuration "%s".', $name));
        }

        try {
            $configuration = $this->getInternalStorage()->load(self::uuidFor($name));
        } catch (Throwable $e) {
            throw new ConfigurationException(message: sprintf('Unable to load the configuration "%s".', $name), previous: $e);
        }
        if (!$configuration instanceof Configuration) {
            throw new ConfigurationNotFoundException(sprintf('There is no configuration "%s".', $name));
        }

        return $configuration;
    }

    /**
     * Creates a configuration; of several processes creating one of the same name, only the first succeeds.
     *
     * @throws ConfigurationAlreadyExistsException If a configuration of the name exists.
     * @throws ConfigurationException
     */
    public function create(Configuration $configuration): void
    {
        $this->write($configuration, false);
    }

    /**
     * Replaces a configuration. Storages opened before keep the previous settings until they are opened again.
     *
     * @throws ConfigurationNotFoundException If there is no configuration of the name.
     * @throws ConfigurationException
     */
    public function update(Configuration $configuration): void
    {
        $this->write($configuration, true);
    }

    /**
     * Removes a configuration. Storages it is assigned to can't be opened with it anymore: assign them another
     * one first.
     *
     * @return bool False if there was no configuration of the name.
     * @throws ConfigurationException
     */
    public function delete(string $name): bool
    {
        if (!$this->has($name)) {
            return false;
        }

        try {
            $this->getInternalStorage()->delete(self::uuidFor($name));
        } catch (Throwable $e) {
            throw new ConfigurationException(message: sprintf('Unable to delete the configuration "%s".', $name), previous: $e);
        }
        return true;
    }

    /**
     * Assigns a configuration to a storage, by name; null removes the assignment, so the storage is opened with
     * the defaults. The name is kept in the file ASSIGNMENT_FILE in the directory of the storage.
     *
     * @throws ConfigurationNotFoundException If there is no configuration of the name.
     * @throws ConfigurationException If the file can't be written.
     */
    public function assign(string $storageDir, ?string $name): void
    {
        $file = self::assignmentFile($storageDir);

        if (null === $name) {
            if (is_file($file) && !@unlink($file) && is_file($file)) {
                throw new ConfigurationException('Unable to remove ' . $file);
            }
            return;
        }

        if (!$this->has($name)) {
            throw new ConfigurationNotFoundException(sprintf('There is no configuration "%s".', $name));
        }

        // written atomically (the directory is created), so readers see the old or the new assignment
        try {
            (new JsonFileWriter(flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))->write($file, ['configuration' => $name]);
        } catch (Throwable $e) {
            throw new ConfigurationException(message: 'Unable to write ' . $file, previous: $e);
        }
    }

    /**
     * The name of the configuration assigned to a storage, null if none.
     *
     * @throws ConfigurationException If the file is unreadable.
     */
    public static function getAssignedName(string $storageDir): ?string
    {
        $file = self::assignmentFile($storageDir);
        if (!is_file($file)) {
            return null;
        }

        try {
            $data = (new JsonFileReader())->readJson($file);
        } catch (Throwable $e) {
            throw new ConfigurationException(message: 'Unreadable assignment of a configuration: ' . $file, previous: $e);
        }
        $name = $data['configuration'] ?? null;
        if (!is_string($name) || !preg_match(Configuration::NAME_PATTERN, $name)) {
            throw new ConfigurationException('Unreadable assignment of a configuration: ' . $file);
        }

        return $name;
    }

    /**
     * Opens a storage with a configuration: the named one, or the one assigned to the storage; without either,
     * with the defaults.
     *
     * @param string $storageDir The directory of the storage.
     * @param string|null $name The name of the configuration, null for the assigned one.
     * @param LoggerInterface|null $logger The logger of the storage.
     * @throws ConfigurationNotFoundException If the configuration doesn't exist.
     * @throws ConfigurationException If the configuration can't be applied, e.g. a lock backend is unreachable.
     */
    public function open(string $storageDir, ?string $name = null, ?LoggerInterface $logger = null): ObjectStorage
    {
        $name ??= self::getAssignedName($storageDir);

        try {
            return new ObjectStorage($storageDir, logger: $logger, configuration: null === $name ? null : $this->get($name));
        } catch (ConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ConfigurationException(message: 'Unable to open the storage ' . $storageDir, previous: $e);
        }
    }

    /**
     * @throws ConfigurationAlreadyExistsException
     * @throws ConfigurationNotFoundException
     * @throws ConfigurationException
     */
    private function write(Configuration $configuration, bool $exists): void
    {
        $internal = $this->getInternalStorage();
        $uuid = self::uuidFor($configuration->name);
        $lockAdapter = $internal->getLockAdapter();

        // checking and writing happen under the lock of the UUID derived from the name, so names are unique
        try {
            $lockAdapter->acquireExclusiveLock($uuid);
        } catch (Throwable $e) {
            throw new ConfigurationException(message: sprintf('Unable to save the configuration "%s".', $configuration->name), previous: $e);
        }

        try {
            $found = $internal->exists($uuid);
            if (!$exists && $found) {
                throw new ConfigurationAlreadyExistsException(sprintf('A configuration "%s" exists already.', $configuration->name));
            }
            if ($exists && !$found) {
                throw new ConfigurationNotFoundException(sprintf('There is no configuration "%s".', $configuration->name));
            }
            $internal->store($configuration, $uuid);
        } catch (ConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ConfigurationException(message: sprintf('Unable to save the configuration "%s".', $configuration->name), previous: $e);
        } finally {
            if ($lockAdapter->isLockedByThisProcess($uuid)) {
                $lockAdapter->releaseLock($uuid);
            }
        }
    }

    private static function assignmentFile(string $storageDir): string
    {
        return rtrim($storageDir, '/\\') . DIRECTORY_SEPARATOR . self::ASSIGNMENT_FILE;
    }
}
