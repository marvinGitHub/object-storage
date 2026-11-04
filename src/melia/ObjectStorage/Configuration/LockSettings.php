<?php

namespace melia\ObjectStorage\Configuration;

use melia\ObjectStorage\Configuration\Exception\InvalidConfigurationException;
use melia\ObjectStorage\Locking\Backends\SymfonyLockAdapter;

/**
 * Where the locks of a storage are kept: the backend of symfony/lock and its settings.
 *
 * Strings may refer to environment variables as %env(NAME)%, e.g. a password, so the secret isn't stored with the
 * configuration; they are resolved when the lock adapter is created.
 */
final readonly class LockSettings
{
    /** Lock files in a directory (FlockStore), the default: one host, no server. */
    public const string TYPE_FILE = 'file';
    /** System V semaphores (SemaphoreStore): one host, no lock files, needs ext-sysvsem. */
    public const string TYPE_SEMAPHORE = 'semaphore';
    /** Redis (RedisStore), e.g. redis://localhost:6379: several hosts. */
    public const string TYPE_REDIS = 'redis';
    /** Memcached (MemcachedStore), e.g. memcached://localhost:11211: several hosts, no shared locks. */
    public const string TYPE_MEMCACHED = 'memcached';
    /** A database via PDO (PdoStore), e.g. mysql:host=db;dbname=app: several hosts, no shared locks. */
    public const string TYPE_PDO = 'pdo';
    /** Any connection string symfony/lock understands (StoreFactory::createStore()), e.g. postgresql+advisory://… */
    public const string TYPE_DSN = 'dsn';

    public const array TYPES = [self::TYPE_FILE, self::TYPE_SEMAPHORE, self::TYPE_REDIS, self::TYPE_MEMCACHED, self::TYPE_PDO, self::TYPE_DSN];

    /** The default prefix of lock resources. */
    public const string PREFIX_DEFAULT = 'object-storage.';

    /**
     * @param string $type One of the TYPE_* constants.
     * @param string|null $dsn The connection of the backend; required for redis, memcached, pdo and dsn.
     * @param string|null $directory The directory of the lock files (file), by default the lock directory of the
     *        storage.
     * @param float|null $ttl The lifetime of a lock in seconds for expiring backends (e.g. Redis), so the locks of
     *        crashed processes are freed; null for no expiration.
     * @param string $prefix The prefix of the lock resources, to separate storages sharing a backend. Storages that
     *        are the same storage (e.g. on several hosts) must use the same prefix.
     * @param string|null $username The user of the database (pdo).
     * @param string|null $password The password of the database (pdo); prefer %env(NAME)%.
     * @throws InvalidConfigurationException
     */
    public function __construct(
        public string  $type = self::TYPE_FILE,
        public ?string $dsn = null,
        public ?string $directory = null,
        public ?float  $ttl = SymfonyLockAdapter::TTL_DEFAULT,
        public string  $prefix = self::PREFIX_DEFAULT,
        public ?string $username = null,
        public ?string $password = null,
    )
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidConfigurationException(sprintf('Unknown lock type "%s", expected one of %s.', $type, implode(', ', self::TYPES)));
        }
        if (in_array($type, [self::TYPE_REDIS, self::TYPE_MEMCACHED, self::TYPE_PDO, self::TYPE_DSN], true) && (null === $dsn || '' === trim($dsn))) {
            throw new InvalidConfigurationException(sprintf('Locks of type "%s" need a connection (dsn).', $type));
        }
        if (self::TYPE_REDIS === $type && !preg_match('/^rediss?:/', (string)$dsn)) {
            throw new InvalidConfigurationException('A Redis connection starts with redis: or rediss:, e.g. redis://localhost:6379.');
        }
        if (self::TYPE_MEMCACHED === $type && !str_starts_with((string)$dsn, 'memcached:')) {
            throw new InvalidConfigurationException('A Memcached connection starts with memcached:, e.g. memcached://localhost:11211.');
        }
        if (null !== $ttl && $ttl <= 0) {
            throw new InvalidConfigurationException('The lifetime of a lock (ttl) must be positive, or empty for no expiration.');
        }
    }

    /**
     * A short description for lists and logs; a password in the connection is masked.
     */
    public function describe(): string
    {
        return match ($this->type) {
            self::TYPE_FILE => 'file locks' . (null === $this->directory ? '' : ' in ' . $this->directory),
            self::TYPE_SEMAPHORE => 'semaphores',
            default => $this->type . ' ' . self::maskDsn((string)$this->dsn),
        };
    }

    /**
     * Hides the password of a connection string, e.g. redis://user:secret@host → redis://user:***@host.
     */
    public static function maskDsn(string $dsn): string
    {
        return preg_replace(['#(://[^:/@]*:)[^@]*@#', '/((?:^|;)password=)[^;]*/i'], ['$1***@', '$1***'], $dsn) ?? $dsn;
    }

    /**
     * @return array{type: string, dsn: ?string, directory: ?string, ttl: ?float, prefix: string, username: ?string, password: ?string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'dsn' => $this->dsn,
            'directory' => $this->directory,
            'ttl' => $this->ttl,
            'prefix' => $this->prefix,
            'username' => $this->username,
            'password' => $this->password,
        ];
    }

    /**
     * @param array<string, mixed> $data As returned by toArray(); missing keys get their defaults.
     * @throws InvalidConfigurationException
     */
    public static function fromArray(array $data): self
    {
        $ttl = $data['ttl'] ?? null;
        $empty = static fn(mixed $value): ?string => null === $value || '' === trim((string)$value) ? null : (string)$value;

        return new self(
            type: (string)($data['type'] ?? self::TYPE_FILE),
            dsn: $empty($data['dsn'] ?? null),
            directory: $empty($data['directory'] ?? null),
            ttl: array_key_exists('ttl', $data) ? (null === $ttl || '' === $ttl ? null : (float)$ttl) : SymfonyLockAdapter::TTL_DEFAULT,
            prefix: (string)($data['prefix'] ?? self::PREFIX_DEFAULT),
            username: $empty($data['username'] ?? null),
            password: $empty($data['password'] ?? null),
        );
    }
}
