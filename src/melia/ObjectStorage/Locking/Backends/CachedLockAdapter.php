<?php

namespace melia\ObjectStorage\Locking\Backends;

use melia\ObjectStorage\Exception\LockException;
use melia\ObjectStorage\Locking\LockAdapterInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Symfony\Component\Cache\Psr16Cache as Cache;

class CachedLockAdapter extends LockAdapterAbstract
{
    private Cache $cache;
    private string $processId;
    private array $activeLocks = [];

    public function __construct(Cache $cache, private string $prefix = 'lock:')
    {
        $this->cache = $cache;
        $this->processId = uniqid(gethostname() . '_', true);
    }

    /**
     * @throws InvalidArgumentException
     * @throws LockException
     */
    public function acquireSharedLock(string $uuid, float|int $timeout = LockAdapterInterface::LOCK_TIMEOUT_DEFAULT): bool
    {
        $key = $this->getLockKey($uuid);
        $startTime = microtime(true);
        $timeoutSeconds = $timeout;

        while (true) {
            $lockData = $this->cache->get($key);

            if (empty($lockData)) {
                // No lock exists, create a shared lock
                $newLockData = [
                    'type' => self::LOCK_TYPE_SHARED,
                    'holders' => [$this->processId => time()],
                ];

                if ($this->cache->set($key, $newLockData) !== false) {
                    $this->activeLocks[$uuid] = self::LOCK_TYPE_SHARED;
                    return true;

                }
            } elseif ($lockData['type'] === self::LOCK_TYPE_SHARED) {
                // Shared lock exists, add this process
                $lockData['holders'][$this->processId] = time();

                if ($this->cache->set($key, $lockData) !== false) {
                    $this->activeLocks[$uuid] = self::LOCK_TYPE_SHARED;
                    return true;
                }
            }

            // Check timeout
            if ((microtime(true) - $startTime) >= $timeoutSeconds) {
                throw new LockException(
                    sprintf('Timeout while waiting for lock: %s (shared)', $uuid)
                );
            }

            usleep(10000); // Wait 10ms before retry
        }
    }

    /**
     * @throws LockException
     */
    public function acquireExclusiveLock(string $uuid, float|int $timeout = LockAdapterInterface::LOCK_TIMEOUT_DEFAULT): bool
    {
        $key = $this->getLockKey($uuid);
        $startTime = microtime(true);
        $timeoutSeconds = $timeout;

        while (true) {
            $lockData = $this->cache->get($key);

            if (empty($lockData)) {
                $lockData = [
                    'type' => self::LOCK_TYPE_EXCLUSIVE,
                    'holder' => $this->processId,
                    'time' => time(),
                ];

                // Try to acquire exclusive lock (only works if the key doesn't exist)
                if ($this->cache->set($key, $lockData) !== false) {
                    $this->activeLocks[$uuid] = self::LOCK_TYPE_EXCLUSIVE;
                    return true;
                }
            }

            // Check timeout
            if ((microtime(true) - $startTime) >= $timeoutSeconds) {
                throw new LockException(
                    sprintf('Timeout while waiting for lock: %s (exclusive)', $uuid)
                );
            }

            usleep(10000); // Wait 10ms before retry
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public function releaseLock(string $uuid): void
    {
        $key = $this->getLockKey($uuid);
        $lockData = $this->cache->get($key);

        if (empty($lockData)) {
            unset($this->activeLocks[$uuid]);
            return;
        }

        if ($lockData['type'] === self::LOCK_TYPE_EXCLUSIVE) {
            // Release exclusive lock
            if (isset($lockData['holder']) && $lockData['holder'] === $this->processId) {
                $this->cache->delete($key);
            }
        } elseif ($lockData['type'] === self::LOCK_TYPE_SHARED) {
            // Remove this process from shared lock holders
            if (isset($lockData['holders'][$this->processId])) {
                unset($lockData['holders'][$this->processId]);

                if (empty($lockData['holders'])) {
                    $this->cache->delete($key);
                } else {
                    $this->cache->set($key, $lockData);
                }
            }
        }

        unset($this->activeLocks[$uuid]);
    }

    public function isLockedByOtherProcess(?string $uuid): bool
    {
        if ($uuid === null) {
            return false;
        }

        $key = $this->getLockKey($uuid);
        $lockData = $this->cache->get($key);

        if (empty($lockData)) {
            return false;
        }

        if ($lockData['type'] === self::LOCK_TYPE_EXCLUSIVE) {
            return $lockData['holder'] !== $this->processId;
        }

        // For shared locks, check if there are any other holders
        if ($lockData['type'] === self::LOCK_TYPE_SHARED) {
            return count($lockData['holders']) > 1 || !isset($lockData['holders'][$this->processId]);
        }

        return false;
    }

    public function isLockedByThisProcess(?string $uuid): bool
    {
        if ($uuid === null) {
            return false;
        }

        return isset($this->activeLocks[$uuid]);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function releaseActiveLocks(): void
    {
        foreach (array_keys($this->activeLocks) as $uuid) {
            $this->releaseLock($uuid);
        }
    }

    public function getActiveLocks(): array
    {
        return array_keys($this->activeLocks);
    }

    public function hasActiveSharedLock(string $uuid): bool
    {
        return isset($this->activeLocks[$uuid]) &&
            $this->activeLocks[$uuid] === self::LOCK_TYPE_SHARED;
    }

    public function hasActiveExclusiveLock(string $uuid): bool
    {
        return isset($this->activeLocks[$uuid]) &&
            $this->activeLocks[$uuid] === self::LOCK_TYPE_EXCLUSIVE;
    }

    private function getLockKey(string $uuid): string
    {
        return $this->prefix . $uuid;
    }
}