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
     *
     * @internal Not part of the public API; may change in any release.
     */
    public function objectPaths(): ObjectPaths
    {
        $shardDepth = $this->getStrategy()?->getShardDepth() ?? StrategyInterface::DEFAULT_SHARD_DEPTH;
        if (null === $this->objectPaths || !$this->objectPaths->isFor($this->storageDir, $shardDepth)) {
            $this->objectPaths = new ObjectPaths($this->storageDir, $shardDepth);
        }

        return $this->objectPaths;
    }

    /**
     * Retrieves the directory path where shard files are stored.
     */
    public function getShardDir(): string
    {
        return $this->objectPaths()->shardDirectory();
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
