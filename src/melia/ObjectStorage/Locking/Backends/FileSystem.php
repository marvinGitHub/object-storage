<?php

namespace melia\ObjectStorage\Locking\Backends;

use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\Exception\LockException;
use melia\ObjectStorage\File\IO\AdapterAwareTrait;
use melia\ObjectStorage\File\IO\RealAdapter;
use melia\ObjectStorage\File\WriterAwareTrait;
use melia\ObjectStorage\Logger\LoggerAwareTrait;
use Throwable;

class FileSystem extends LockAdapterAbstract
{
    const TYPE_SHARED = 0;
    const TYPE_EXCLUSIVE = 1;

    use LoggerAwareTrait;
    use WriterAwareTrait;
    use AdapterAwareTrait;

    /**
     * Defines the suffix used for lock files to indicate processing or restricted access.
     */
    private const FILE_SUFFIX_LOCK = '.lock';

    /**
     * An array to store currently active locks.
     */
    private array $activeLocks = [];

    private string $lockDir;

    public function __construct(string $lockDir)
    {
        $this->setLockDir($lockDir);
        $this->setIOAdapter(new RealAdapter());
    }

    public function getLockDir(): string
    {
        return $this->lockDir;
    }

    public function setLockDir(string $lockDir): void
    {
        $this->lockDir = $lockDir;
    }

    /**
     * @throws LockException
     */
    public function acquireSharedLock(string $uuid, int|float $timeout = 10): void
    {
        $this->lock($uuid, static::TYPE_SHARED, $timeout);
        $this->getEventDispatcher()?->dispatch(Events::SHARED_LOCK_ACQUIRED, fn() => new Context($uuid));
    }

    /**
     * Acquires a lock for a given resource identified by a unique identifier (UUID).
     * Locks can be exclusive or shared, with a configurable timeout.
     *
     * @param string $uuid A unique identifier for the resource to lock.
     * @param int $type
     * @param int|float $timeout The maximum duration (in seconds) to wait for acquiring the lock. Defaults to the class constant LOCK_TIMEOUT.
     * @return void
     * @throws LockException If the lock file cannot be opened, or if the timeout is reached while waiting for the lock.
     */
    private function lock(string $uuid, int $type, int|float $timeout = self::LOCK_TIMEOUT_DEFAULT): void
    {
        if ($this->getStateHandler()?->safeModeEnabled()) {
            throw new LockException('Safe mode is enabled. Object cannot be locked.');
        }

        if ($type === static::TYPE_SHARED && $this->hasActiveSharedLock($uuid)) {
            return;
        }

        if ($type === static::TYPE_EXCLUSIVE && $this->hasActiveExclusiveLock($uuid)) {
            return;
        }

        if ($this->isLockedByOtherProcess($uuid)) {
            throw new LockException(sprintf('Lock already acquired from other process for uuid %s', $uuid));
        }

        $lockFile = $this->getLockFilePath($uuid);
        $startTime = microtime(true);
        $lockType = match ($type) {
            static::TYPE_SHARED => LOCK_SH,
            static::TYPE_EXCLUSIVE => LOCK_EX,
            default => throw new LockException('Invalid lock type'),
        };

        $adapter = $this->getIOAdapter();
        $handle = $adapter->fopen($lockFile, 'w+');
        if ($handle === false) {
            throw new LockException('Unable to open lock file: ' . $lockFile);
        }

        while (!$adapter->flock($handle, $lockType | LOCK_NB)) {
            if (microtime(true) - $startTime > $timeout) {
                fclose($handle);
                throw new LockException(sprintf('Timeout while waiting for lock: %s (%s)', $uuid, ($type === static::TYPE_SHARED ? 'shared' : 'exclusive')));
            }
            usleep(100000); // 100ms
        }

        $this->activeLocks[$uuid] = [
            'handle' => $handle,
            'shared' => $type === static::TYPE_SHARED,
            'exclusive' => $type === static::TYPE_EXCLUSIVE,
        ];
    }

    /**
     * Checks whether a specified resource identified by a UUID has a shared lock.
     *
     * @param string $uuid The unique identifier of the resource to check.
     * @return bool Returns true if the resource has a shared lock; otherwise, returns false.
     */
    public function hasActiveSharedLock(string $uuid): bool
    {
        return isset($this->activeLocks[$uuid]) && $this->activeLocks[$uuid]['shared'];
    }

    /**
     * Checks if an exclusive lock is held for the given unique identifier.
     *
     * @param string $uuid The unique identifier to check for an exclusive lock.
     * @return bool Returns true if an exclusive lock is held for the given identifier; otherwise, false.
     */
    public function hasActiveExclusiveLock(string $uuid): bool
    {
        return isset($this->activeLocks[$uuid]) && $this->activeLocks[$uuid]['exclusive'];
    }

    /**
     * Checks if the lock is held by a process other than the current one.
     *
     * @param string|null $uuid The unique identifier for the lock, or null if no identifier is provided.
     * @return bool Returns true if the lock is held by another process, otherwise false.
     */
    public function isLockedByOtherProcess(?string $uuid): bool
    {
        return false === $this->isLockedByThisProcess($uuid) && $this->getIOAdapter()->isFile($this->getLockFilePath($uuid));
    }

    /**
     * Checks if the current process holds a lock associated with the given unique identifier (UUID).
     *
     * @param string|null $uuid The unique identifier for the lock to be checked. Can be null.
     * @return bool Returns true if the lock exists for the given UUID and is associated with this process, false otherwise.
     */
    public function isLockedByThisProcess(?string $uuid): bool
    {
        if (null === $uuid) {
            return false;
        }
        return isset($this->activeLocks[$uuid]);
    }

    /**
     * Generates the full file path for a lock file associated with a given unique identifier.
     * The path includes the storage directory, the UUID, and a predefined lock file suffix.
     *
     * @param string $uuid A unique identifier used to generate the lock file path.
     * @return string Returns the full file path for the lock file.
     */
    private function getLockFilePath(string $uuid): string
    {
        return $this->lockDir . DIRECTORY_SEPARATOR . $uuid . self::FILE_SUFFIX_LOCK;
    }

    /**
     * @throws LockException
     */
    public function acquireExclusiveLock(string $uuid, int|float $timeout = 10): void
    {
        $this->lock($uuid, static::TYPE_EXCLUSIVE, $timeout);
        $this->getEventDispatcher()?->dispatch(Events::EXCLUSIVE_LOCK_ACQUIRED, fn() => new Context($uuid));
    }

    /**
     * Releases all active locks held by the system. If an error occurs during unlocking,
     * it logs the error using the provided logger.
     *
     * @return void
     */
    public function releaseActiveLocks(): void
    {
        foreach ($this->getActiveLocks() as $uuid) {
            try {
                $this->releaseLock($uuid);
            } catch (Throwable $e) {
                $this->getLogger()?->log(new LockException(sprintf('Error while unlocking object %s', $uuid), Exception::CODE_FAILURE_OBJECT_UNLOCK, $e));
            }
        }
    }

    /**
     * Retrieves the list of currently active lock identifiers.
     *
     * @return string[] An array of identifiers for active locks.
     */
    public function getActiveLocks(): array
    {
        return array_keys($this->activeLocks);
    }

    /**
     * Releases an active lock associated with the given unique identifier (UUID).
     * Frees the associated file handle, removes the lock, and deletes the lock file.
     *
     * @param string $uuid The unique identifier for the lock to be released.
     * @return void
     * @throws LockException If no active lock is found for the given UUID.
     */
    public function releaseLock(string $uuid): void
    {
        $lock = $this->activeLocks[$uuid] ?? null;

        if (null === $lock) {
            throw new LockException('No active lock found for uuid: ' . $uuid);
        }

        $adapter = $this->getIOAdapter();

        if (false === $adapter->flock($lock['handle'], LOCK_UN)) {
            throw new LockException('Unable to release lock: ' . $uuid);
        }

        if (false === $adapter->fclose($lock['handle'])) {
            throw new LockException('Unable to close lock file: ' . $uuid);
        }

        $path = $this->getLockFilePath($uuid);

        if ($adapter->isFile($path) && false === $adapter->unlink($path)) {
            throw new LockException('Unable to delete lock file: ' . $uuid);
        }

        unset($this->activeLocks[$uuid]);

        $this->getEventDispatcher()?->dispatch(Events::LOCK_RELEASED, fn() => new Context($uuid));
    }
}