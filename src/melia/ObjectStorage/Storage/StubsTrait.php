<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Context\StubContext;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\ClassnameRegistrationFailureException;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\Exception\SafeModeActivationFailedException;
use melia\ObjectStorage\Exception\SerializationFailureException;
use melia\ObjectStorage\Exception\StubDeletionFailureException;
use melia\ObjectStorage\Exception\StubSavingFailureException;
use melia\ObjectStorage\Util\Maintenance\StubRebuilder;
use Throwable;

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
    /** @var array<string>|null */
    protected ?array $registeredClassNamesCache = null;
    /**
     * @param string $className
     * @param string $uuid
     * @throws StubSavingFailureException
     */
    public function createStub(string $className, string $uuid): void
    {
        try {
            $adapter = $this->getIOAdapter();

            // registered also for an existing stub: the registry may have been lost (e.g. while rebuilding stubs)
            $this->registerClassname($className);

            $pathname = $this->getFilePathStub($className, $uuid);
            if ($adapter->isFile($pathname)) {
                return;
            }

            $this->createDirectoryIfNotExist(pathinfo($pathname, PATHINFO_DIRNAME));
            $adapter->touch($pathname);
            $this->getEventDispatcher()?->dispatch(Events::STUB_CREATED, static fn() => new StubContext($uuid, $className));
        } catch (Throwable $e) {
            $this->getLogger()?->log($e);
            $this->getEventDispatcher()?->dispatch(Events::STUB_WRITE_FAILED, static fn() => new StubContext($uuid, $className));
            throw new StubSavingFailureException(message: sprintf('Stub creation failed for uuid: %s and class: %s', $uuid, $className), previous: $e);
        }
    }

    /**
     * Deletes a stub file for the specified class name and UUID if it exists.
     *
     * @param string $className The name of the class associated with the stub.
     * @param string $uuid The unique identifier associated with the stub.
     * @return void
     * @throws StubDeletionFailureException If the stub file could not be deleted.
     */
    protected function deleteStub(string $className, string $uuid): void
    {
        $filePathStub = $this->getFilePathStub($className, $uuid);
        $adapter = $this->getIOAdapter();

        if ($adapter->isFile($filePathStub) && !$adapter->unlink($filePathStub)) {
            throw new StubDeletionFailureException(sprintf('Stub for uuid %s and classname %s could not be deleted', $uuid, $className));
        }
        $this->getEventDispatcher()?->dispatch(Events::STUB_REMOVED, static fn() => new StubContext($uuid, $className));
    }

    /**
     * @throws SerializationFailureException
     * @throws SafeModeActivationFailedException
     * @throws ClassnameRegistrationFailureException
     */
    protected function registerClassname(string $className): void
    {
        $registeredClassnames = $this->getRegisteredClassnames(); // cached in memory
        if (isset($registeredClassnames[$className])) {
            return;
        }

        $lockAdapter = $this->getLockAdapter();
        $lockedHere = false;
        try {
            // the registry is written as a whole: without the lock, processes registering at the same time
            // overwrite each other's class names
            if (null !== $lockAdapter && false === $lockAdapter->hasActiveExclusiveLock(static::LOCK_CLASSNAMES)) {
                $lockAdapter->acquireExclusiveLock(static::LOCK_CLASSNAMES);
                $lockedHere = true;
            }

            // other processes may have registered class names since the registry was read
            $this->registeredClassNamesCache = null;
            $this->getRegisteredClassnames();
            // an unreadable registry is not written over: the class names in it would be lost
            if (null === $this->registeredClassNamesCache) {
                throw new IOException('Unable to read the class name registry ' . $this->getFilePathClassnames());
            }
            $this->registeredClassNamesCache[$className] = $className;

            $this->createDirectoryIfNotExist($this->getStubDirectory());
            $this->getJsonFileWriter()->write($this->getFilePathClassnames(), $this->registeredClassNamesCache);
        } catch (Throwable $e) {
            throw new ClassnameRegistrationFailureException(message: sprintf('Unable to register classname: %s', $className), previous: $e);
        } finally {
            if ($lockedHere) {
                $lockAdapter->releaseLock(static::LOCK_CLASSNAMES);
            }
        }
    }

    /**
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     */
    public function getRegisteredClassnames(): ?array
    {
        if ($this->registeredClassNamesCache !== null) {
            return $this->registeredClassNamesCache;
        }

        $filenameClassnames = $this->getFilePathClassnames();
        if ($this->getIOAdapter()?->isFile($filenameClassnames)) {
            // unreadable: nothing is cached, so registerClassname() doesn't write over it and the next call reads again
            $registered = $this->getJsonFileReader()->readJson($filenameClassnames);
            if (null === $registered) {
                return [];
            }
            $this->registeredClassNamesCache = $registered;
        } else {
            $this->registeredClassNamesCache = [];
        }

        return $this->registeredClassNamesCache;
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
        $lockAdapter = $this->getLockAdapter();
        $lockedHere = false;

        try {
            if (null !== $lockAdapter && false === $lockAdapter->hasActiveExclusiveLock(static::LOCK_CLASSNAMES)) {
                $lockAdapter->acquireExclusiveLock(static::LOCK_CLASSNAMES);
                $lockedHere = true;
            }

            // the stub directories are named after the hash of the class name: the class is read from an object
            $classNames = [];
            foreach (glob($this->getStubDirectory() . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $classStubDirectory) {
                foreach ($this->createStubIterator($classStubDirectory) as $uuid) {
                    $className = $this->getClassName($uuid);
                    if (null !== $className && md5($className) === basename($classStubDirectory)) {
                        $classNames[$className] = $className;
                        break;
                    }
                }
            }
            ksort($classNames);

            $this->createDirectoryIfNotExist($this->getStubDirectory());
            $this->getJsonFileWriter()->write($this->getFilePathClassnames(), $classNames);
            $this->registeredClassNamesCache = $classNames;

            return $classNames;
        } catch (Throwable $e) {
            throw new ClassnameRegistrationFailureException(message: 'Unable to rebuild the registry of class names', previous: $e);
        } finally {
            if ($lockedHere) {
                $lockAdapter->releaseLock(static::LOCK_CLASSNAMES);
            }
        }
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
        $sync = $this->getWriter()->syncEnabled();

        foreach ([$this->getFilePathMetadata($uuid), $this->getFilePathData($uuid)] as $path) {
            if ($adapter->isFile($path)) {
                $sync
                    ? $adapter->moveFileSync($path, $this->getFilePathCorruptedArtifacts())
                    : $adapter->moveFile($path, $this->getFilePathCorruptedArtifacts());
            }
        }
    }
}
