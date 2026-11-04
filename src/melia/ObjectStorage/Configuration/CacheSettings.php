<?php

namespace melia\ObjectStorage\Configuration;

use melia\ObjectStorage\Configuration\Exception\InvalidConfigurationException;

/**
 * How a storage caches objects and their metadata: in memory of the process (the default), not at all, or in a
 * cache shared between requests and processes (via symfony/cache).
 *
 * A shared cache isn't updated when another process changes an object without going through the same cache.
 */
final readonly class CacheSettings
{
    /** In the memory of the process, the default. */
    public const string TYPE_MEMORY = 'memory';
    /** No cache: every load reads the file. */
    public const string TYPE_NONE = 'none';
    /** APCu, shared by the processes of one PHP server; needs ext-apcu. */
    public const string TYPE_APCU = 'apcu';
    /** Files in a directory, shared by the processes of one host. */
    public const string TYPE_FILESYSTEM = 'filesystem';
    /** Redis, e.g. redis://localhost:6379, shared across hosts. */
    public const string TYPE_REDIS = 'redis';
    /** Memcached, e.g. memcached://localhost:11211, shared across hosts. */
    public const string TYPE_MEMCACHED = 'memcached';

    public const array TYPES = [self::TYPE_MEMORY, self::TYPE_NONE, self::TYPE_APCU, self::TYPE_FILESYSTEM, self::TYPE_REDIS, self::TYPE_MEMCACHED];

    /**
     * @param string $type One of the TYPE_* constants.
     * @param string|null $dsn The connection (redis, memcached); may refer to environment variables as %env(NAME)%.
     * @param string|null $directory The directory of the cache files (filesystem), by default the system's
     *        temporary directory.
     * @param int $defaultLifetime Seconds an entry is kept in a shared cache, 0 for as long as the cache keeps it.
     * @param string $namespace Separates the entries of storages sharing a cache, derived from the directory of
     *        the storage if empty. Storages that are the same storage (e.g. on several hosts) must use the same one.
     * @throws InvalidConfigurationException
     */
    public function __construct(
        public string  $type = self::TYPE_MEMORY,
        public ?string $dsn = null,
        public ?string $directory = null,
        public int     $defaultLifetime = 0,
        public string  $namespace = '',
    )
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidConfigurationException(sprintf('Unknown cache type "%s", expected one of %s.', $type, implode(', ', self::TYPES)));
        }
        if (in_array($type, [self::TYPE_REDIS, self::TYPE_MEMCACHED], true) && (null === $dsn || !str_starts_with($dsn, self::TYPE_REDIS === $type ? 'redis' : 'memcached:'))) {
            throw new InvalidConfigurationException(sprintf('A %s cache needs a connection, e.g. %s.', $type, self::TYPE_REDIS === $type ? 'redis://localhost:6379' : 'memcached://localhost:11211'));
        }
        if ($defaultLifetime < 0) {
            throw new InvalidConfigurationException('The lifetime of cache entries must not be negative.');
        }
        // the characters symfony/cache accepts in a namespace
        if ('' !== $namespace && !preg_match('/^[-+.A-Za-z0-9_]{1,64}$/', $namespace)) {
            throw new InvalidConfigurationException('A cache namespace consists of letters, digits, "-", "+", "_" and "." (at most 64).');
        }
    }

    /**
     * Whether processes share the cache.
     */
    public function isShared(): bool
    {
        return !in_array($this->type, [self::TYPE_MEMORY, self::TYPE_NONE], true);
    }

    /**
     * A short description for lists and logs; a password in the connection is masked.
     */
    public function describe(): string
    {
        return match ($this->type) {
            self::TYPE_MEMORY => 'in memory',
            self::TYPE_NONE => 'no cache',
            self::TYPE_APCU => 'APCu',
            self::TYPE_FILESYSTEM => 'files' . (null === $this->directory ? '' : ' in ' . $this->directory),
            default => $this->type . ' ' . LockSettings::maskDsn((string)$this->dsn),
        };
    }

    /**
     * @return array{type: string, dsn: ?string, directory: ?string, defaultLifetime: int, namespace: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'dsn' => $this->dsn,
            'directory' => $this->directory,
            'defaultLifetime' => $this->defaultLifetime,
            'namespace' => $this->namespace,
        ];
    }

    /**
     * @param array<string, mixed> $data As returned by toArray(); missing keys get their defaults.
     * @throws InvalidConfigurationException
     */
    public static function fromArray(array $data): self
    {
        $empty = static fn(mixed $value): ?string => null === $value || '' === trim((string)$value) ? null : (string)$value;

        return new self(
            type: (string)($data['type'] ?? self::TYPE_MEMORY),
            dsn: $empty($data['dsn'] ?? null),
            directory: $empty($data['directory'] ?? null),
            defaultLifetime: (int)($data['defaultLifetime'] ?? 0),
            namespace: trim((string)($data['namespace'] ?? '')),
        );
    }
}
