<?php

namespace melia\ObjectStorage\Locking\Backends;

use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\Exception\LockException;
use melia\ObjectStorage\Logger\LoggerAwareTrait;
use Throwable;

class FileSystem extends LockAdapterAbstract
{
    const TYPE_SHARED = 0;
    const TYPE_EXLUSIVE = 1;

    use LoggerAwareTrait;

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
    public function aquireSharedLock(string $uuid, int $timeout = 10): void
    {
        $this->lock($uuid, static::TYPE_SHARED, $timeout);
    }

    /**
     * Acquires a lock for a given resource identified by a unique identifier (UUID).
     * Locks can be exclusive or shared, with a configurable timeout.
     *
     * @param string $uuid A unique identifier for the resource to lock.
     * @param int $type
     * @param float $timeout The maximum duration (in seconds) to wait for acquiring the lock. Defaults to the class constant LOCK_TIMEOUT.
     * @return void
     * @throws LockException If the lock file cannot be opened, or if the timeout is reached while waiting for the lock.
     */
    private function lock(string $uuid, int $type, float $timeout = self::LOCK_TIMEOUT_DEFAULT): void
    {
        if ($this->getStateHandler()->safeModeEnabled()) {
            throw new LockException('Safe mode is enabled. Object cannot be locked.');
        }

        if ($type === static::TYPE_SHARED && $this->hasActiveSharedLock($uuid)) {
            return;
        }

        if ($type === static::TYPE_EXLUSIVE && $this->hasActiveExclusiveLock($uuid)) {
            return;
        }

        if ($this->isLockedByOtherProcess($uuid)) {
            throw new LockException(sprintf('Lock already acquired from other process for uuid %s', $uuid));
        }

        $lockFile = $this->getLockFilePath($uuid);
        $startTime = microtime(true);
        $lockType = $type === static::TYPE_SHARED ? LOCK_SH : LOCK_EX;

        if (!file_exists($lockFile)) {
            file_put_contents($lockFile, '');
        }

        $handle = fopen($lockFile, 'r+');
        if ($handle === false) {
            throw new LockException('Unable to open lock file: ' . $lockFile);
        }

        while (!flock($handle, $lockType | LOCK_NB)) {
            if (microtime(true) - $startTime > $timeout) {
                fclose($handle);
                throw new LockException(sprintf('Timeout while waiting for lock: %s (%s)', $uuid, ($type === static::TYPE_SHARED ? 'shared' : 'exclusive')));
            }
            usleep(100000); // 100ms
        }

        $this->activeLocks[$uuid] = [
            'handle' => $handle,
            'shared' => $type === static::TYPE_SHARED,
            'exclusive' => $type === static::TYPE_EXLUSIVE,
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

    public function isLockedByOtherProcess(?string $uuid): bool
    {
        return false === $this->isLockedByThisProcess($uuid) && file_exists($this->getLockFilePath($uuid));
    }

    /**
     * Checks if a lock associated with the given unique identifier (UUID) is held by the current process.
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
    public function aquireExclusiveLock(string $uuid, int $timeout = 10): void
    {
        $this->lock($uuid, static::TYPE_EXLUSIVE, $timeout);
    }

    /**
     * Releases all active locks held by the system. If an error occurs during unlocking,
     * it logs the error using the provided logger.
     *
     * @return void
     */
    public function releaseActiveLocks(): void
    {
        foreach (array_keys($this->activeLocks) as $uuid) {
            try {
                $this->releaseLock($uuid);
            } catch (Throwable $e) {
                $this->getLogger()?->log(new LockException(sprintf('Error while unlocking object %s', $uuid), Exception::CODE_FAILURE_OBJECT_UNLOCK, $e));
            }
        }
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

        if (false === flock($lock['handle'], LOCK_UN)) {
            throw new LockException('Unable to release lock: ' . $uuid);
        }

        if (false === fclose($lock['handle'])) {
            throw new LockException('Unable to close lock file: ' . $uuid);
        }

        $path = $this->getLockFilePath($uuid);

        if (file_exists($path) && false === @unlink($this->getLockFilePath($uuid))) {
            throw new LockException('Unable to delete lock file: ' . $uuid);
        }

        unset($this->activeLocks[$uuid]);
    }

    public function getActiveLocks(): array
    {
        return $this->activeLocks;
    }
}