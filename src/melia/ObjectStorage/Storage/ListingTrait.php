<?php

namespace melia\ObjectStorage\Storage;

use Generator;
use IteratorAggregate;
use melia\ObjectStorage\Exception\MemoryConsumptionDetectionFailureException;
use melia\ObjectStorage\Exception\ObjectExistenceEvaluationFailureException;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Strategy\StrategyInterface;
use Throwable;
use Traversable;

/**
 * Whether objects exist, listing them (by their stubs or files), and their memory consumption.
 *
 * Part of ObjectStorage, kept apart for readability.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait ListingTrait
{
    /**
     * Checks if a file exists for a specific UUID.
     *
     * @param string $uuid The UUID to check if the associated file exists.
     * @return bool True if the file exists, false otherwise.
     * @throws ObjectExistenceEvaluationFailureException
     */
    public function exists(string $uuid): bool
    {
        try {
            return $this->getIOAdapter()->isFile($this->getFilePathData($uuid));
        } catch (Throwable $e) {
            throw new ObjectExistenceEvaluationFailureException(message: sprintf('Unable to check if object with uuid: %s exists', $uuid), previous: $e);
        }
    }

    /**
     * Retrieves a list of all available UUIDs by extracting keys from the stored files.
     *
     * @return Traversable Returns a traversable of UUIDs
     */
    public function list(?string $className = null): Traversable
    {
        if (null !== $className && $this->stubIndex()->hasClass($className)) {
            return $this->stubIndex()->uuidsOf($className);
        }

        return $this->createObjectIterator($className);
    }

    /**
     * The UUIDs of the stubs in a directory of stubs, see StubIndex::uuidsIn().
     *
     * @return Traversable<string, string> UUID => UUID
     */
    protected function createStubIterator(string $pathClassStubs): Traversable
    {
        return StubIndex::uuidsIn($pathClassStubs);
    }

    /**
     * Creates an iterator that filters objects based on the provided classname and retrieves them from storage.
     *
     * @param string|null $className The classname to filter objects by. If null, no filtering is applied.
     * @return Traversable An iterator for traversing filtered objects.
     */
    protected function createObjectIterator(?string $className): Traversable
    {
        return new readonly class ($className, static::FILE_SUFFIX_OBJECT, $this) implements IteratorAggregate {
            public function __construct(
                private ?string       $className,
                private string        $extension,
                private ObjectStorage $storage
            )
            {
            }

            public function getIterator(): Generator
            {
                $extensionLen = strlen($this->extension);

                $depth = $this->storage->getStrategy()?->getShardDepth() ?? StrategyInterface::DEFAULT_SHARD_DEPTH;

                // In this codebase, sharded directories are stored under "<storageDir>/shards/<c1>/<c2>/..."
                $baseDir = $this->storage->getShardDir();

                clearstatcache(true, $baseDir);

                $iterateLeafDirs = static function (string $baseDir, int $depth): Generator {
                    if ($depth <= 0) {
                        yield $baseDir;
                        return;
                    }

                    $dirs = [$baseDir];

                    for ($level = 0; $level < $depth; $level++) {
                        $next = [];

                        foreach ($dirs as $dir) {
                            clearstatcache(true, $dir);

                            $children = glob($dir . DIRECTORY_SEPARATOR . '?', GLOB_ONLYDIR) ?: [];
                            foreach ($children as $child) {
                                $next[] = $child;
                            }
                        }

                        $dirs = $next;

                        if ($dirs === []) {
                            return;
                        }
                    }

                    foreach ($dirs as $leaf) {
                        yield $leaf;
                    }
                };

                foreach ($iterateLeafDirs($baseDir, $depth) as $leafDir) {
                    clearstatcache(true, $leafDir);

                    $files = glob($leafDir . DIRECTORY_SEPARATOR . '*' . $this->extension) ?: [];

                    foreach ($files as $file) {
                        $uuid = substr(basename($file), 0, -$extensionLen);

                        if (null !== $this->className) {
                            try {
                                if ($this->storage->getClassName($uuid) !== $this->className) {
                                    continue;
                                }
                            } catch (Throwable $e) {
                                $this->storage->getLogger()?->log($e);
                                continue;
                            }
                        }

                        yield $uuid => $uuid;
                    }
                }
            }
        };
    }

    /**
     * @throws MemoryConsumptionDetectionFailureException
     */
    public function getMemoryConsumption(string $uuid): int
    {
        $adapter = $this->getIOAdapter();

        try {
            if (false === $fileSizeData = $adapter->fileSize($path = $this->getFilePathData($uuid))) {
                throw new MemoryConsumptionDetectionFailureException('Unable to determine file size for file ' . $path);
            }

            if (false === $fileSizeMetadata = $adapter->fileSize($path = $this->getFilePathMetadata($uuid))) {
                throw new MemoryConsumptionDetectionFailureException('Unable to determine file size for file ' . $path);
            }

            if (false === $fileSizeStub = $adapter->fileSize($path = $this->getFilePathStub($this->getClassName($uuid), $uuid))) {
                throw new MemoryConsumptionDetectionFailureException('Unable to determine file size for file ' . $path);
            }

            return $fileSizeData + $fileSizeMetadata + $fileSizeStub;
        } catch (Throwable $e) {
            throw new MemoryConsumptionDetectionFailureException(message: 'Unable to determine memory consumption for uuid: ' . $uuid, previous: $e);
        }
    }
}
