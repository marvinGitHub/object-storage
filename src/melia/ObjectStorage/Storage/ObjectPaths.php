<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\UUID\Helper;

/**
 * The paths of the files of a storage: object, metadata and stub files, sharded by the leading characters of the
 * UUID, and the directories of locks, stubs and damaged objects.
 *
 * Immutable: a storage creates a new one when its directory or its shard depth changes (see docs/architecture.md).
 * Nothing is created on disk here.
 */
final readonly class ObjectPaths
{
    public const string SUFFIX_OBJECT = '.obj';
    public const string SUFFIX_METADATA = '.metadata';
    public const string SUFFIX_STUB = '.stub';

    /** the most directory levels of the sharding */
    public const int MAX_SHARD_DEPTH = 32;

    private int $shardDepth;

    /**
     * @param string $storageDir The directory of the storage, without a trailing separator.
     * @param int $shardDepth Directory levels per UUID, one per leading character (at most MAX_SHARD_DEPTH).
     */
    public function __construct(
        private string $storageDir,
        int            $shardDepth,
    )
    {
        $this->shardDepth = min($shardDepth, self::MAX_SHARD_DEPTH);
    }

    /**
     * Whether these are the paths of a storage directory with a shard depth, i.e. still valid for it.
     */
    public function isFor(string $storageDir, int $shardDepth): bool
    {
        return $storageDir === $this->storageDir && min($shardDepth, self::MAX_SHARD_DEPTH) === $this->shardDepth;
    }

    /** the directory of the default file locks */
    public function lockDirectory(): string
    {
        return $this->storageDir . DIRECTORY_SEPARATOR . 'locks';
    }

    /** the directory of the sharded object files */
    public function shardDirectory(): string
    {
        return $this->storageDir . DIRECTORY_SEPARATOR . 'shards';
    }

    /**
     * The sharded directory of a UUID: one level per leading character of the UUID (without hyphens), as many as
     * the shard depth (depth 0: the shard directory itself). With a trailing directory separator.
     */
    public function shardedDirectory(string $uuid): string
    {
        return $this->shardDirectory() . DIRECTORY_SEPARATOR
            . chunk_split(substr(Helper::removeHyphens($uuid), 0, $this->shardDepth), 1, DIRECTORY_SEPARATOR);
    }

    /** the data file of an object */
    public function dataFile(string $uuid): string
    {
        return $this->shardedDirectory($uuid) . $uuid . self::SUFFIX_OBJECT;
    }

    /** the metadata file of an object */
    public function metadataFile(string $uuid): string
    {
        return $this->shardedDirectory($uuid) . $uuid . self::SUFFIX_METADATA;
    }

    /**
     * The files of an object in the order they are written: the data before the metadata holding its checksum, so a
     * committed metadata file always belongs to a committed data file.
     *
     * @return array{string, string} The data file and the metadata file.
     */
    public function objectFiles(string $uuid): array
    {
        return [$this->dataFile($uuid), $this->metadataFile($uuid)];
    }

    /** the directory of the stubs */
    public function stubDirectory(): string
    {
        return $this->storageDir . DIRECTORY_SEPARATOR . 'stubs';
    }

    /** the directory of the stubs of a class */
    public function classStubDirectory(string $className): string
    {
        return $this->stubDirectory() . DIRECTORY_SEPARATOR . md5($className);
    }

    /** the stub of an object of a class */
    public function stubFile(string $className, string $uuid): string
    {
        return $this->classStubDirectory($className) . DIRECTORY_SEPARATOR . $uuid . self::SUFFIX_STUB;
    }

    /** the registry of class names */
    public function classNamesFile(): string
    {
        return $this->stubDirectory() . DIRECTORY_SEPARATOR . 'classnames.json';
    }

    /** the directory damaged objects are moved to */
    public function corruptedDirectory(): string
    {
        return $this->storageDir . DIRECTORY_SEPARATOR . 'corrupted';
    }
}
