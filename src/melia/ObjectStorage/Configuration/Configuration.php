<?php

namespace melia\ObjectStorage\Configuration;

use melia\ObjectStorage\Configuration\Exception\InvalidConfigurationException;
use melia\ObjectStorage\Strategy\Policy\ChildDeletion;
use melia\ObjectStorage\Strategy\Policy\ChildPersistence;

/**
 * The settings of a storage that can be stored and shared: where the locks are kept, how objects are cached,
 * the settings of the strategy and whether writes are flushed to disk.
 *
 * Configurations are named, so a storage is opened with its directory and the name of its configuration, see
 * Registry. Settings that are null keep the default of the storage.
 */
final readonly class Configuration
{
    /** Lowercase letters, digits, "_" and "-", so names are unique on case-insensitive file systems too. */
    public const string NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /**
     * @param string $name The unique name.
     * @param string $description What the configuration is for.
     * @param LockSettings $lock Where the locks are kept.
     * @param CacheSettings $cache How objects and metadata are cached.
     * @param int|null $shardDepth Directory levels of the object files; set it for new storages only, existing
     *        ones are moved with maintenance:rebuild-shards. Null keeps the default.
     * @param int|null $maxDepth The maximum nesting depth of an object graph, null for the default.
     * @param bool $lifetimeInheritance Whether referenced objects expire with the referencing object.
     * @param bool $checksumValidation Whether checksums are verified when loading (recommended).
     * @param bool $optimisticLocking Whether store() fails if someone else stored the object meanwhile.
     * @param int|null $childPersistence A ChildPersistence policy, null for the default. CALLBACK needs a strategy
     *        of its own and isn't accepted.
     * @param int|null $childDeletion A ChildDeletion policy, null for the default; CALLBACK isn't accepted either.
     * @param bool $fsync Whether writes are flushed to disk (durable, slower).
     * @throws InvalidConfigurationException
     */
    public function __construct(
        public string        $name,
        public string        $description = '',
        public LockSettings  $lock = new LockSettings(),
        public CacheSettings $cache = new CacheSettings(),
        public ?int          $shardDepth = null,
        public ?int          $maxDepth = null,
        public bool          $lifetimeInheritance = false,
        public bool          $checksumValidation = true,
        public bool          $optimisticLocking = false,
        public ?int          $childPersistence = null,
        public ?int          $childDeletion = null,
        public bool          $fsync = true,
    )
    {
        self::validate($this);
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function validate(self $configuration): void
    {
        if (!preg_match(self::NAME_PATTERN, $configuration->name)) {
            throw new InvalidConfigurationException(sprintf('Invalid configuration name "%s": lowercase letters, digits, "_" and "-", starting with a letter or digit, at most 64.', $configuration->name));
        }
        if (null !== $configuration->shardDepth && ($configuration->shardDepth < 0 || $configuration->shardDepth > 16)) {
            throw new InvalidConfigurationException('The shard depth must be between 0 and 16.');
        }
        if (null !== $configuration->maxDepth && $configuration->maxDepth < 1) {
            throw new InvalidConfigurationException('The maximum depth must be at least 1.');
        }
        $persistence = [ChildPersistence::ALWAYS, ChildPersistence::NEVER, ChildPersistence::IF_NOT_EXIST];
        if (null !== $configuration->childPersistence && !in_array($configuration->childPersistence, $persistence, true)) {
            throw new InvalidConfigurationException('Unknown child persistence policy; CALLBACK needs a strategy of its own.');
        }
        if (null !== $configuration->childDeletion && !in_array($configuration->childDeletion, [ChildDeletion::ALWAYS, ChildDeletion::NEVER], true)) {
            throw new InvalidConfigurationException('Unknown child deletion policy; CALLBACK needs a strategy of its own.');
        }
    }

    /**
     * The configuration under another name.
     *
     * @throws InvalidConfigurationException
     */
    public function withName(string $name): self
    {
        return self::fromArray(['name' => $name] + $this->toArray());
    }

    /**
     * The configuration of the internal storages of a storage (search indexes, maintenance): the same locks, so
     * all processes coordinate, but no cache, since other processes change them, and the default strategy, since
     * they were created with it.
     */
    public function forInternalStorage(): self
    {
        return new self($this->name, $this->description, $this->lock, new CacheSettings(CacheSettings::TYPE_NONE), fsync: $this->fsync);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'lock' => $this->lock->toArray(),
            'cache' => $this->cache->toArray(),
            'shardDepth' => $this->shardDepth,
            'maxDepth' => $this->maxDepth,
            'lifetimeInheritance' => $this->lifetimeInheritance,
            'checksumValidation' => $this->checksumValidation,
            'optimisticLocking' => $this->optimisticLocking,
            'childPersistence' => $this->childPersistence,
            'childDeletion' => $this->childDeletion,
            'fsync' => $this->fsync,
        ];
    }

    /**
     * @param array<string, mixed> $data As returned by toArray(); missing keys get their defaults.
     * @throws InvalidConfigurationException
     */
    public static function fromArray(array $data): self
    {
        $int = static fn(mixed $value): ?int => null === $value || '' === $value ? null : (int)$value;

        return new self(
            name: (string)($data['name'] ?? ''),
            description: trim((string)($data['description'] ?? '')),
            lock: LockSettings::fromArray(is_array($data['lock'] ?? null) ? $data['lock'] : []),
            cache: CacheSettings::fromArray(is_array($data['cache'] ?? null) ? $data['cache'] : []),
            shardDepth: $int($data['shardDepth'] ?? null),
            maxDepth: $int($data['maxDepth'] ?? null),
            lifetimeInheritance: (bool)($data['lifetimeInheritance'] ?? false),
            checksumValidation: (bool)($data['checksumValidation'] ?? true),
            optimisticLocking: (bool)($data['optimisticLocking'] ?? false),
            childPersistence: $int($data['childPersistence'] ?? null),
            childDeletion: $int($data['childDeletion'] ?? null),
            fsync: (bool)($data['fsync'] ?? true),
        );
    }

    /**
     * Stored as plain data, so the classes may change without breaking stored configurations.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @throws InvalidConfigurationException
     */
    public function __unserialize(array $data): void
    {
        $configuration = self::fromArray($data);
        foreach (get_object_vars($configuration) as $property => $value) {
            $this->{$property} = $value;
        }
    }
}
