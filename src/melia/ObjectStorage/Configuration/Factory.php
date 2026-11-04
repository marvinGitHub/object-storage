<?php

namespace melia\ObjectStorage\Configuration;

use melia\ObjectStorage\Cache\InMemoryCache;
use melia\ObjectStorage\Configuration\Exception\ConfigurationException;
use melia\ObjectStorage\Configuration\Exception\InvalidConfigurationException;
use melia\ObjectStorage\Locking\Backends\SymfonyLockAdapter;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Strategy\Standard;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\PdoStore;
use Symfony\Component\Lock\Store\SemaphoreStore;
use Symfony\Component\Lock\Store\StoreFactory;
use Throwable;

/**
 * Creates what a configuration describes: the lock adapter and the caches of a storage, and applies the settings of
 * the strategy and the writer. Used by the constructor of ObjectStorage; usable on its own, e.g. to check that a
 * configuration works.
 */
final class Factory
{
    /** Cache entries of the objects. */
    public const string CACHE_OBJECTS = 'objects';
    /** Cache entries of the metadata. */
    public const string CACHE_METADATA = 'metadata';

    /**
     * Creates the lock adapter of a storage.
     *
     * @param LockSettings $settings Where the locks are kept.
     * @param string $storageDir The directory of the storage; file locks are kept in its lock directory by default.
     * @throws ConfigurationException If the backend isn't available or the connection is invalid.
     */
    public function createLockAdapter(LockSettings $settings, string $storageDir): SymfonyLockAdapter
    {
        try {
            $store = match ($settings->type) {
                LockSettings::TYPE_FILE => new FlockStore($this->lockDirectory($settings, $storageDir)),
                LockSettings::TYPE_SEMAPHORE => $this->semaphoreStore(),
                LockSettings::TYPE_PDO => new PdoStore(self::resolve((string)$settings->dsn), array_filter([
                    'db_username' => null === $settings->username ? null : self::resolve($settings->username),
                    'db_password' => null === $settings->password ? null : self::resolve($settings->password),
                ], static fn(?string $value): bool => null !== $value)),
                default => StoreFactory::createStore(self::resolve((string)$settings->dsn)),
            };
        } catch (ConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ConfigurationException(message: sprintf('Unable to create the lock store (%s): %s', $settings->describe(), $e->getMessage()), previous: $e);
        }

        return new SymfonyLockAdapter($store, $settings->ttl, $settings->prefix);
    }

    /**
     * Creates a cache of a storage.
     *
     * @param CacheSettings $settings How to cache.
     * @param string $storageDir The directory of the storage, for the namespace of shared caches.
     * @param string $kind CACHE_OBJECTS or CACHE_METADATA, kept apart in shared caches.
     * @return CacheInterface|null Null for no cache.
     * @throws ConfigurationException If the backend isn't available or the connection is invalid.
     */
    public function createCache(CacheSettings $settings, string $storageDir, string $kind = self::CACHE_OBJECTS): ?CacheInterface
    {
        $namespace = $this->cacheNamespace($settings, $storageDir) . '.' . $kind;

        try {
            return match ($settings->type) {
                CacheSettings::TYPE_NONE => null,
                CacheSettings::TYPE_MEMORY => new InMemoryCache(),
                CacheSettings::TYPE_APCU => ApcuAdapter::isSupported()
                    ? new Psr16Cache(new ApcuAdapter($namespace, $settings->defaultLifetime))
                    : throw new ConfigurationException('An APCu cache needs ext-apcu, enabled (apc.enable_cli=1 on the command line).'),
                CacheSettings::TYPE_FILESYSTEM => new Psr16Cache(new FilesystemAdapter($namespace, $settings->defaultLifetime, $settings->directory)),
                CacheSettings::TYPE_REDIS => new Psr16Cache(new RedisAdapter(
                    AbstractAdapter::createConnection(self::resolve((string)$settings->dsn), ['lazy' => true]),
                    $namespace,
                    $settings->defaultLifetime
                )),
                CacheSettings::TYPE_MEMCACHED => new Psr16Cache(new MemcachedAdapter(
                    MemcachedAdapter::createConnection(self::resolve((string)$settings->dsn)),
                    $namespace,
                    $settings->defaultLifetime
                )),
            };
        } catch (ConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ConfigurationException(message: sprintf('Unable to create the cache (%s): %s', $settings->describe(), $e->getMessage()), previous: $e);
        }
    }

    /**
     * Applies the settings of the strategy and the writer to a storage. The strategy settings apply to the
     * Standard strategy and its subclasses; a strategy of its own keeps its settings.
     *
     * @param bool $applyStrategy False to apply the writer settings only, e.g. for a strategy passed explicitly.
     */
    public function apply(ObjectStorage $storage, Configuration $configuration, bool $applyStrategy = true): void
    {
        $storage->getWriter()->setSyncEnabled($configuration->fsync);

        $strategy = $storage->getStrategy();
        if (!$applyStrategy || !$strategy instanceof Standard) {
            return;
        }

        if (null !== $configuration->shardDepth) {
            $strategy->setShardDepth($configuration->shardDepth);
        }
        if (null !== $configuration->maxDepth) {
            $strategy->setMaxDepth($configuration->maxDepth);
        }
        $configuration->lifetimeInheritance ? $strategy->enableLifetimeInheritance() : $strategy->disableLifetimeInheritance();
        $configuration->checksumValidation ? $strategy->enableChecksumValidation() : $strategy->disableChecksumValidation();
        $configuration->optimisticLocking ? $strategy->enableOptimisticLocking() : $strategy->disableOptimisticLocking();
        if (null !== $configuration->childPersistence) {
            $strategy->setPolicyChildPersistence($configuration->childPersistence);
        }
        if (null !== $configuration->childDeletion) {
            $strategy->setPolicyChildDeletion($configuration->childDeletion);
        }
    }

    /**
     * Checks that the backends of a configuration work: takes and releases a lock, and writes, reads and removes a
     * cache entry.
     *
     * @return array<string, string> What was checked => the result, e.g. ['lock' => 'ok'].
     * @throws ConfigurationException With the first failure.
     */
    public function check(Configuration $configuration, string $storageDir): array
    {
        $results = [];

        $lockAdapter = $this->createLockAdapter($configuration->lock, $storageDir);
        $resource = 'configuration-check-' . bin2hex(random_bytes(8));
        try {
            $lockAdapter->acquireExclusiveLock($resource, 2);
            $lockAdapter->releaseLock($resource);
        } catch (Throwable $e) {
            throw new ConfigurationException(message: sprintf('Locking failed (%s): %s', $configuration->lock->describe(), $e->getMessage()), previous: $e);
        }
        $results['lock'] = $configuration->lock->describe() . ': ok';

        $cache = $this->createCache($configuration->cache, $storageDir);
        if (null === $cache) {
            $results['cache'] = 'no cache';
            return $results;
        }
        $key = 'configuration-check-' . bin2hex(random_bytes(8));
        try {
            $cache->set($key, 'ok');
            $value = $cache->get($key);
            $cache->delete($key);
        } catch (Throwable $e) {
            throw new ConfigurationException(message: sprintf('Caching failed (%s): %s', $configuration->cache->describe(), $e->getMessage()), previous: $e);
        }
        if ('ok' !== $value) {
            throw new ConfigurationException(sprintf('Caching failed (%s): an entry written was not read back.', $configuration->cache->describe()));
        }
        $results['cache'] = $configuration->cache->describe() . ': ok';

        return $results;
    }

    /**
     * Replaces references to environment variables, %env(NAME)%, by their values.
     *
     * @throws InvalidConfigurationException If a variable isn't set.
     */
    public static function resolve(string $value): string
    {
        return preg_replace_callback('/%env\(([A-Za-z_][A-Za-z0-9_]*)\)%/', static function (array $match): string {
            $resolved = $_ENV[$match[1]] ?? $_SERVER[$match[1]] ?? getenv($match[1]);
            if (false === $resolved || null === $resolved || is_array($resolved)) {
                throw new InvalidConfigurationException(sprintf('The environment variable %s is not set.', $match[1]));
            }
            return (string)$resolved;
        }, $value) ?? $value;
    }

    /**
     * @throws ConfigurationException
     */
    private function lockDirectory(LockSettings $settings, string $storageDir): string
    {
        $directory = null === $settings->directory ? $storageDir . DIRECTORY_SEPARATOR . 'locks' : self::resolve($settings->directory);

        // another process may create the directory at the same time: check again instead of warning
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new ConfigurationException('Unable to create the lock directory ' . $directory);
        }

        return $directory;
    }

    /**
     * @throws ConfigurationException
     */
    private function semaphoreStore(): SemaphoreStore
    {
        if (!SemaphoreStore::isSupported()) {
            throw new ConfigurationException('Semaphore locks need ext-sysvsem (not available on Windows).');
        }
        return new SemaphoreStore();
    }

    /**
     * The namespace of a storage in a shared cache: the configured one, or one derived from its directory.
     */
    private function cacheNamespace(CacheSettings $settings, string $storageDir): string
    {
        if ('' !== $settings->namespace) {
            return $settings->namespace;
        }
        $path = realpath($storageDir) ?: $storageDir;
        return 'object-storage.' . substr(md5($path), 0, 12);
    }
}
