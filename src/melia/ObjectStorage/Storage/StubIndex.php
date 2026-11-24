<?php

namespace melia\ObjectStorage\Storage;

use FilterIterator;
use GlobIterator;
use Iterator;
use melia\ObjectStorage\Event\Context\StubContext;
use melia\ObjectStorage\Event\DispatcherInterface;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\StubDeletionFailureException;
use melia\ObjectStorage\Exception\StubSavingFailureException;
use melia\ObjectStorage\File\Directory;
use melia\ObjectStorage\File\IO\AdapterInterface;
use melia\ObjectStorage\Logger\LoggerInterface;
use Throwable;
use Traversable;

/**
 * The stubs: the index of objects by class, an empty file per object in a directory per class. A stub registers
 * its class in the registry of class names.
 */
final readonly class StubIndex
{
    public function __construct(
        private ObjectPaths          $paths,
        private AdapterInterface     $io,
        private ClassNameRegistry    $registry,
        private ?DispatcherInterface $dispatcher = null,
        private ?LoggerInterface     $logger = null,
    )
    {
    }

    /**
     * Adds the stub of an object; its class is registered also if the stub exists (the registry may have been lost,
     * e.g. while rebuilding the stubs).
     *
     * @throws StubSavingFailureException
     */
    public function add(string $className, string $uuid): void
    {
        try {
            $this->registry->register($className);

            $stub = $this->paths->stubFile($className, $uuid);
            if ($this->io->isFile($stub)) {
                return;
            }

            Directory::createIfNotExists($this->io, dirname($stub));
            $this->io->touch($stub);
            $this->dispatcher?->dispatch(Events::STUB_CREATED, static fn() => new StubContext($uuid, $className));
        } catch (Throwable $e) {
            $this->logger?->log($e);
            $this->dispatcher?->dispatch(Events::STUB_WRITE_FAILED, static fn() => new StubContext($uuid, $className));
            throw new StubSavingFailureException(message: sprintf('Stub creation failed for uuid: %s and class: %s', $uuid, $className), previous: $e);
        }
    }

    /**
     * Removes the stub of an object, if there is one.
     *
     * @throws StubDeletionFailureException If it can't be removed.
     */
    public function remove(string $className, string $uuid): void
    {
        $stub = $this->paths->stubFile($className, $uuid);
        if ($this->io->isFile($stub) && !$this->io->unlink($stub)) {
            throw new StubDeletionFailureException(sprintf('Stub for uuid %s and classname %s could not be deleted', $uuid, $className));
        }
        $this->dispatcher?->dispatch(Events::STUB_REMOVED, static fn() => new StubContext($uuid, $className));
    }

    /**
     * Whether objects of a class are indexed (its directory of stubs exists).
     */
    public function hasClass(string $className): bool
    {
        return $this->io->isDir($this->paths->classStubDirectory($className));
    }

    /**
     * The UUIDs of the objects of a class, by their stubs.
     *
     * @return Traversable<string, string> UUID => UUID
     */
    public function uuidsOf(string $className): Traversable
    {
        return self::uuidsIn($this->paths->classStubDirectory($className));
    }

    /**
     * The directories of stubs, one per class (named after a hash of the class name).
     *
     * @return list<string>
     */
    public function classDirectories(): array
    {
        return glob($this->paths->stubDirectory() . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
    }

    /**
     * The UUIDs of the stubs in a directory of stubs; read anew on every iteration.
     *
     * @return Traversable<string, string> UUID => UUID
     */
    public static function uuidsIn(string $classStubDirectory): Traversable
    {
        $pattern = $classStubDirectory . DIRECTORY_SEPARATOR . '*' . ObjectPaths::SUFFIX_STUB;

        return new class (new GlobIterator($pattern), ObjectPaths::SUFFIX_STUB, $classStubDirectory) extends FilterIterator {
            public function __construct(Iterator $iterator, private readonly string $extension, private readonly string $directory)
            {
                parent::__construct($iterator);
            }

            public function rewind(): void
            {
                clearstatcache(true, $this->directory);
                parent::rewind();
            }

            public function accept(): bool
            {
                return true;
            }

            public function current(): string
            {
                return basename(parent::current(), $this->extension);
            }

            public function key(): string
            {
                return $this->current();
            }
        };
    }
}
