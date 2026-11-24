<?php

namespace melia\ObjectStorage\Storage;

use Closure;
use melia\ObjectStorage\Exception\ClassnameRegistrationFailureException;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\Exception\SafeModeActivationFailedException;
use melia\ObjectStorage\Exception\SerializationFailureException;
use melia\ObjectStorage\File\Directory;
use melia\ObjectStorage\File\IO\AdapterInterface;
use melia\ObjectStorage\Locking\LockAdapterInterface;
use Throwable;

/**
 * The registry of class names (classnames.json): the classes of the stored objects, class name => class name.
 *
 * It is written as a whole, under its lock, so processes registering at the same time don't overwrite each other's
 * class names; a registry that exists but can't be read is never written over. Kept in memory once read; forget()
 * reads it again, e.g. after another process changed it.
 */
final class ClassNameRegistry
{
    /** the name of the lock guarding the registry */
    public const string LOCK = 'classnames';

    /** @var array<string, string>|null the registry as last read or written, null if not read (or unreadable) */
    private ?array $classNames = null;

    public function __construct(
        private readonly ObjectPaths           $paths,
        private readonly AdapterInterface      $io,
        private readonly JsonStore             $json,
        private readonly ?LockAdapterInterface $locks = null,
    )
    {
    }

    /**
     * The registered class names; empty if the registry can't be read (not kept: the next call reads it again).
     *
     * @return array<string, string>
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     */
    public function all(): array
    {
        if (null === $this->classNames) {
            $registry = $this->read();
            if (null === $registry) {
                return [];
            }
            $this->classNames = $registry;
        }
        return $this->classNames;
    }

    /**
     * Registers a class name, if it isn't registered yet; read again under the lock first, so class names other
     * processes registered meanwhile are kept.
     *
     * @throws ClassnameRegistrationFailureException
     */
    public function register(string $className): void
    {
        if (isset($this->all()[$className])) {
            return;
        }

        try {
            $this->locked(function () use ($className): void {
                $this->classNames = null;
                $registry = $this->read();
                // an unreadable registry is not written over: the class names in it would be lost
                if (null === $registry) {
                    throw new IOException('Unable to read the class name registry ' . $this->paths->classNamesFile());
                }
                $registry[$className] = $className;
                $this->classNames = $registry;
                $this->write($registry);
            });
        } catch (Throwable $e) {
            throw new ClassnameRegistrationFailureException(message: sprintf('Unable to register classname: %s', $className), previous: $e);
        }
    }

    /**
     * Replaces the registry by the class names found, under its lock (held while they are found).
     *
     * @param Closure(): array<string, string> $find Finds the class names, class name => class name.
     * @return array<string, string> The registered class names, sorted.
     * @throws ClassnameRegistrationFailureException
     */
    public function rebuild(Closure $find): array
    {
        try {
            return $this->locked(function () use ($find): array {
                $classNames = $find();
                ksort($classNames);
                $this->write($classNames);
                return $this->classNames = $classNames;
            });
        } catch (Throwable $e) {
            throw new ClassnameRegistrationFailureException(message: 'Unable to rebuild the registry of class names', previous: $e);
        }
    }

    /**
     * Forgets the registry as read, so it is read again.
     */
    public function forget(): void
    {
        $this->classNames = null;
    }

    /**
     * The registry as stored: empty if there is none yet, null if it can't be read.
     *
     * @return array<string, string>|null
     * @throws SafeModeActivationFailedException
     * @throws SerializationFailureException
     */
    private function read(): ?array
    {
        $file = $this->paths->classNamesFile();
        if (!$this->io->isFile($file)) {
            return [];
        }
        try {
            return $this->json->read($file);
        } catch (IOException) {
            return null;
        }
    }

    /**
     * @param array<string, string> $classNames
     */
    private function write(array $classNames): void
    {
        Directory::createIfNotExists($this->io, $this->paths->stubDirectory());
        $this->json->write($this->paths->classNamesFile(), $classNames);
    }

    /**
     * Runs a callback under the lock of the registry, taken unless this process holds it already.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    private function locked(Closure $callback): mixed
    {
        $lockedHere = false;
        try {
            if (null !== $this->locks && !$this->locks->hasActiveExclusiveLock(self::LOCK)) {
                $this->locks->acquireExclusiveLock(self::LOCK);
                $lockedHere = true;
            }
            return $callback();
        } finally {
            if ($lockedHere) {
                $this->locks->releaseLock(self::LOCK);
            }
        }
    }
}
