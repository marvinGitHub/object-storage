<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\File\Directory;
use melia\ObjectStorage\Strategy\StrategyInterface;

/**
 * The paths of the files of the storage, delegated to ObjectPaths (see docs/architecture.md): the public methods
 * of the facade stay as they were.
 *
 * Part of ObjectStorage, kept apart for readability.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait PathsTrait
{
    /** the paths for the current directory and shard depth, see objectPaths() */
    private ?ObjectPaths $objectPaths = null;

    /**
     * The paths of the files of the storage, for its directory and the shard depth of its strategy as they are now:
     * created again when either changed.
     */
    protected function objectPaths(): ObjectPaths
    {
        $shardDepth = $this->getStrategy()?->getShardDepth() ?? StrategyInterface::DEFAULT_SHARD_DEPTH;
        if (null === $this->objectPaths || !$this->objectPaths->isFor($this->storageDir, $shardDepth)) {
            $this->objectPaths = new ObjectPaths($this->storageDir, $shardDepth);
        }

        return $this->objectPaths;
    }

    /**
     * Retrieves the directory of the default file locks.
     */
    public function getLockDir(): string
    {
        return $this->objectPaths()->lockDirectory();
    }

    /**
     * Retrieves the directory path where shard files are stored.
     */
    public function getShardDir(): string
    {
        return $this->objectPaths()->shardDirectory();
    }

    /**
     * Builds the path of the sharded directory for a UUID (see ObjectPaths::shardedDirectory()). The directory is
     * not created: the writer creates it when the first file is written, so looking up unknown UUIDs leaves no
     * empty directories behind.
     *
     * @return string The path of the directory, with a trailing directory separator.
     */
    public function buildShardedDirectory(string $uuid): string
    {
        return $this->objectPaths()->shardedDirectory($uuid);
    }

    /**
     * The data file of an object.
     */
    public function getFilePathData(string $uuid): string
    {
        return $this->objectPaths()->dataFile($uuid);
    }

    /**
     * The metadata file of an object.
     */
    public function getFilePathMetadata(string $uuid): string
    {
        return $this->objectPaths()->metadataFile($uuid);
    }

    /**
     * The files an object is stored in, in the order they are written (see ObjectPaths::objectFiles()).
     *
     * @return array{string, string} The paths of the data file and the metadata file.
     */
    protected function getObjectFiles(string $uuid): array
    {
        return $this->objectPaths()->objectFiles($uuid);
    }

    /**
     * The stub of an object of a class.
     */
    public function getFilePathStub(string $className, string $uuid): string
    {
        return $this->objectPaths()->stubFile($className, $uuid);
    }

    /**
     * The directory of the stubs of a class.
     */
    public function getClassStubDirectory(string $className): string
    {
        return $this->objectPaths()->classStubDirectory($className);
    }

    /**
     * The directory of the stubs.
     */
    public function getStubDirectory(): string
    {
        return $this->objectPaths()->stubDirectory();
    }

    /**
     * The registry of class names.
     */
    protected function getFilePathClassnames(): string
    {
        return $this->objectPaths()->classNamesFile();
    }

    /**
     * The directory damaged objects are moved to.
     */
    protected function getFilePathCorruptedArtifacts(): string
    {
        return $this->objectPaths()->corruptedDirectory();
    }

    /**
     * @throws IOException
     * @link Directory::createIfNotExists
     */
    protected function createDirectoryIfNotExist(string $directory): void
    {
        Directory::createIfNotExists($this->getIOAdapter(), $directory);
    }
}
