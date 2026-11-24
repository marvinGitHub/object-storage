<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\ClassnameRegistrationFailureException;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\Exception\SafeModeActivationFailedException;
use melia\ObjectStorage\Exception\SerializationFailureException;
use melia\ObjectStorage\Exception\StubDeletionFailureException;
use melia\ObjectStorage\Exception\StubSavingFailureException;
use melia\ObjectStorage\Util\Maintenance\StubRebuilder;

/**
 * The stubs (the index of objects by class) and the registry of class names; rebuilding them, moving damaged
 * objects aside.
 *
 * Part of ObjectStorage, kept apart for readability.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait StubsTrait
{
    /**
     * The registry of class names of the storage, with its paths, IO adapter, JSON files and lock adapter as they
     * are now; it keeps the registry in memory once read.
     */
    protected function classNameRegistry(): ClassNameRegistry
    {
        $paths = $this->objectPaths();
        $io = $this->getIOAdapter();
        $json = $this->jsonStore();
        $locks = $this->getLockAdapter();

        return $this->component(
            ClassNameRegistry::class,
            [$paths, $io, $json, $locks],
            static fn(): ClassNameRegistry => new ClassNameRegistry($paths, $io, $json, $locks),
        );
    }

    /**
     * The stubs of the storage, with its paths, IO adapter, registry of class names, event dispatcher and logger as
     * they are now.
     */
    protected function stubIndex(): StubIndex
    {
        $paths = $this->objectPaths();
        $io = $this->getIOAdapter();
        $registry = $this->classNameRegistry();
        $dispatcher = $this->getEventDispatcher();
        $logger = $this->getLogger();

        return $this->component(
            StubIndex::class,
            [$paths, $io, $registry, $dispatcher, $logger],
            static fn(): StubIndex => new StubIndex($paths, $io, $registry, $dispatcher, $logger),
        );
    }

    /**
     * Adds the stub of an object, see StubIndex::add().
     *
     * @throws StubSavingFailureException
     */
    public function createStub(string $className, string $uuid): void
    {
        $this->stubIndex()->add($className, $uuid);
    }

    /**
     * Removes the stub of an object if it exists, see StubIndex::remove().
     *
     * @throws StubDeletionFailureException If the stub file could not be deleted.
     */
    protected function deleteStub(string $className, string $uuid): void
    {
        $this->stubIndex()->remove($className, $uuid);
    }

    /**
     * Registers a class name, see ClassNameRegistry::register().
     *
     * @throws ClassnameRegistrationFailureException
     */
    protected function registerClassname(string $className): void
    {
        $this->classNameRegistry()->register($className);
    }

    /**
     * The registered class names, see ClassNameRegistry::all().
     *
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     */
    public function getRegisteredClassnames(): ?array
    {
        return $this->classNameRegistry()->all();
    }

    /**
     * Get a list of classnames for a subset of objects
     *
     * @param array|null $subSet
     * @return array
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     */
    public function getClassNames(?array $subSet = null): array
    {
        if (null === $subSet) {
            $registeredClassnames = $this->getRegisteredClassnames();
            if ($registeredClassnames !== null) {
                return $registeredClassnames;
            }
        }

        $classNames = [];
        foreach ($this->getSelection($subSet) as $uuid) {
            $className = $this->getClassName($uuid);
            if ($className) {
                $classNames[$className] = $className;
            }
        }
        return array_values($classNames);
    }

    /**
     * Rebuilds the class index (the stubs) in place: creates the missing stubs, removes those of objects that
     * don't exist or are of another class, and rebuilds the registry of class names. The index stays usable
     * meanwhile. Continues a rebuild started step by step, see StubRebuilder.
     *
     * @param callable(array): void|null $onProgress Called with the progress after every step.
     * @return array The final progress, see StubRebuilder::getProgress().
     */
    public function rebuildStubs(?callable $onProgress = null): array
    {
        $rebuilder = new StubRebuilder();
        $rebuilder->setStorage($this);

        return $rebuilder->rebuildStubs($onProgress);
    }

    /**
     * Rebuilds the registry of class names from the stubs: a class is registered if it has a stub of an object of
     * this class. Classes without objects are removed from it.
     *
     * @return array<string, string> The registered class names.
     * @throws ClassnameRegistrationFailureException
     */
    public function rebuildClassnameRegistry(): array
    {
        $stubs = $this->stubIndex();

        // the stub directories are named after the hash of the class name: the class is read from an object
        return $this->classNameRegistry()->rebuild(function () use ($stubs): array {
            $classNames = [];
            foreach ($stubs->classDirectories() as $classStubDirectory) {
                foreach (StubIndex::uuidsIn($classStubDirectory) as $uuid) {
                    $className = $this->getClassName($uuid);
                    if (null !== $className && md5($className) === basename($classStubDirectory)) {
                        $classNames[$className] = $className;
                        break;
                    }
                }
            }
            return $classNames;
        });
    }

    /**
     * Marks the file associated with the given UUID as corrupted by moving it
     * to the directory for corrupted artifacts.
     *
     * @param string $uuid The unique identifier of the file to be marked as corrupted.
     *
     * @return void
     * @throws IOException
     */
    public function markAsCorrupted(string $uuid): void
    {
        $this->moveToCorruptedArtifacts($uuid);
    }

    /**
     * Moves the files of an object aside (see markAsCorrupted()) if it really has no class: under its lock, after
     * completing an interrupted write. An object being written by another process at the moment, or whose write
     * was interrupted by a crash, has its metadata (and class) only once that write is complete; and metadata that
     * exists but can't be read at the moment is no reason to move the object aside either.
     *
     * @param string $uuid The unique identifier of the object.
     * @return bool True if the files were moved aside, false if the object has a class (or doesn't exist).
     * @throws IOException If the metadata exists but can't be read.
     * @throws \melia\ObjectStorage\Exception\LockException If the object can't be locked.
     */
    public function markAsCorruptedIfClassless(string $uuid): bool
    {
        $lockAdapter = $this->getLockAdapter();
        $lockedHere = false;
        // in safe mode nothing can be locked for writing, but no other process writes either
        if (null !== $lockAdapter && !$lockAdapter->hasActiveExclusiveLock($uuid) && !$this->getStateHandler()?->safeModeEnabled()) {
            $lockAdapter->acquireExclusiveLock($uuid);
            $lockedHere = true;
        }

        try {
            $this->recoverInterruptedWrite($uuid);

            $adapter = $this->getIOAdapter();
            $filePathMetadata = $this->getFilePathMetadata($uuid);
            if ($adapter->isFile($filePathMetadata) && false === $adapter->fileGetContents($filePathMetadata)) {
                throw new IOException('Unable to read the metadata file ' . $filePathMetadata);
            }

            if (!$this->exists($uuid) || !empty($this->reloadMetadata($uuid)?->getClassName())) {
                return false;
            }

            $this->moveToCorruptedArtifacts($uuid);
            return true;
        } finally {
            if ($lockedHere && !$this->isLockRetained($uuid)) {
                $lockAdapter->releaseLock($uuid);
            }
        }
    }

    /**
     * @throws IOException
     */
    private function moveToCorruptedArtifacts(string $uuid): void
    {
        $this->getEventDispatcher()?->dispatch(Events::OBJECT_CORRUPTION_DETECTED, static fn() => new Context($uuid));
        $adapter = $this->getIOAdapter();

        // flushed like every other write, so the moved files don't reappear after a power loss
        $sync = $this->getFiles()->syncEnabled();

        foreach ([$this->getFilePathMetadata($uuid), $this->getFilePathData($uuid)] as $path) {
            if ($adapter->isFile($path)) {
                $sync
                    ? $adapter->moveFileSync($path, $this->getFilePathCorruptedArtifacts())
                    : $adapter->moveFile($path, $this->getFilePathCorruptedArtifacts());
            }
        }
    }
}
