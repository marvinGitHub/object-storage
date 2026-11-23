<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\File\Directory;
use melia\ObjectStorage\Strategy\StrategyInterface;
use melia\ObjectStorage\UUID\Helper;

/**
 * The paths of the files of the storage: object, metadata and stub files, sharded by UUID.
 *
 * Part of ObjectStorage, kept apart for readability.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait PathsTrait
{
    /**
     * Retrieves the directory of the default file locks.
     *
     * @return string The full path to the lock directory.
     */
    public function getLockDir(): string
    {
        return $this->storageDir . DIRECTORY_SEPARATOR . 'locks';
    }

    /**
     * Retrieves the directory path where shard files are stored.
     *
     * @return string The full path to the shard directory.
     */
    public function getShardDir(): string
    {
        return $this->storageDir . DIRECTORY_SEPARATOR . 'shards';
    }

    /**
     * Builds the path of the sharded directory for a UUID: one directory level per leading character of the UUID,
     * as many levels as the configured shard depth (depth 0 => <storage>/shards). The directory is not created:
     * the writer creates it when the first file is written, so looking up unknown UUIDs leaves no empty
     * directories behind.
     *
     * @param string $uuid
     * @return string The path of the directory, with a trailing directory separator.
     */
    public function buildShardedDirectory(string $uuid): string
    {
        $depth = min($this->getStrategy()?->getShardDepth() ?? StrategyInterface::DEFAULT_SHARD_DEPTH, 32);

        return $this->getShardDir() . DIRECTORY_SEPARATOR . chunk_split(substr(Helper::removeHyphens($uuid), 0, $depth), 1, DIRECTORY_SEPARATOR);
    }

    /**
     * Generates the file path for a given UUID.
     *
     * @param string $uuid The unique identifier used to generate the file path.
     * @return string The full file path constructed using the UUID.
     * @throws IOException
     */
    public function getFilePathData(string $uuid): string
    {
        return $this->buildShardedDirectory($uuid) . $uuid . static::FILE_SUFFIX_OBJECT;
    }

    /**
     * Generates the file path for the metadata associated with a specific UUID.
     *
     * @param string $uuid The unique identifier used to build the metadata file path.
     * @return string Returns the file path of the metadata corresponding to the provided UUID.
     * @throws IOException
     */
    public function getFilePathMetadata(string $uuid): string
    {
        return $this->buildShardedDirectory($uuid) . $uuid . static::FILE_SUFFIX_METADATA;
    }

    /**
     * Returns the files an object is stored in, in the order they are written: the data before the metadata
     * holding its checksum, so a committed metadata file always belongs to a committed data file.
     *
     * @param string $uuid The unique identifier of the object.
     * @return array{string, string} The paths of the data file and the metadata file.
     * @throws IOException
     */
    protected function getObjectFiles(string $uuid): array
    {
        return [$this->getFilePathData($uuid), $this->getFilePathMetadata($uuid)];
    }

    /**
     * Generates the file path for a stub associated with a specific class and UUID.
     *
     * @param string $className The name of the class for which the stub is being generated.
     * @param string $uuid The unique identifier used to differentiate the stub file.
     * @return string The full file path for the stub.
     */
    public function getFilePathStub(string $className, string $uuid): string
    {
        return $this->getClassStubDirectory($className) . DIRECTORY_SEPARATOR . $uuid . '.stub';
    }

    /**
     * Retrieves the directory path where the class stub for the given class name is stored.
     *
     * @param string $className The name of the class for which the stub directory path is being generated.
     * @return string The full path to the class stub directory.
     */
    public function getClassStubDirectory(string $className): string
    {
        return $this->getStubDirectory() . DIRECTORY_SEPARATOR . md5($className);
    }

    /**
     * Retrieves the path to the stub directory.
     *
     * @return string The full path to the stub directory.
     */
    public function getStubDirectory(): string
    {
        return $this->getStorageDir() . DIRECTORY_SEPARATOR . 'stubs';
    }

    /**
     * Retrieves the file path for the classnames JSON file.
     *
     * @return string The file path of the classnames JSON file.
     */
    protected function getFilePathClassnames(): string
    {
        return $this->getStubDirectory() . DIRECTORY_SEPARATOR . 'classnames.json';
    }

    /**
     * Retrieves the file path for storing corrupted artifacts.
     *
     * @return string The file path where corrupted artifacts are stored.
     */
    protected function getFilePathCorruptedArtifacts(): string
    {
        return $this->getStorageDir() . DIRECTORY_SEPARATOR . 'corrupted';
    }

    /**
     * @param string $directory
     * @return void
     * @throws IOException
     * @link Directory::createIfNotExists
     */
    protected function createDirectoryIfNotExist(string $directory): void
    {
        Directory::createIfNotExists($this->getIOAdapter(), $directory);
    }
}
