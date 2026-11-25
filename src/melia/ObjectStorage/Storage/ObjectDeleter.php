<?php

namespace melia\ObjectStorage\Storage;

use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\DispatcherInterface;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\ObjectDeletionFailureException;
use melia\ObjectStorage\Exception\ObjectNotFoundException;
use melia\ObjectStorage\File\FilesInterface;
use melia\ObjectStorage\Locking\ObjectLocks;
use melia\ObjectStorage\Logger\LoggerInterface;
use melia\ObjectStorage\State\StateHandler;
use melia\ObjectStorage\Strategy\Policy\ChildDeletion;
use melia\ObjectStorage\Strategy\StrategyInterface;
use melia\ObjectStorage\Transaction;
use Throwable;

/**
 * Deleting objects: their files, cache entries and stubs, each object under its exclusive lock, and the objects they
 * reference along with them by the child deletion policy of the strategy.
 */
final readonly class ObjectDeleter
{
    public function __construct(
        private ObjectPaths          $paths,
        private FilesInterface       $files,
        private ObjectFiles          $objectFiles,
        private MetadataStore        $metadata,
        private StubIndex            $stubs,
        private ObjectCache          $cache,
        private ObjectLocks          $locks,
        private ?StrategyInterface   $strategy = null,
        private ?StateHandler        $stateHandler = null,
        private ?DispatcherInterface $dispatcher = null,
        private ?LoggerInterface     $logger = null,
    )
    {
    }

    /**
     * Deletes an object, and the objects it references by the child deletion policy.
     *
     * Referenced objects are deleted after the object itself, each under its own lock, so at most one lock is held
     * at once; a crash in between leaves unreferenced objects behind, never references to deleted ones.
     *
     * @param bool $applyChildDeletionPolicy False to delete only the object itself.
     * @param Transaction|null $transaction The transaction running, which records every object before it is deleted.
     * @throws ObjectDeletionFailureException If the object, or a referenced object, can't be deleted (referenced
     *         objects deleted meanwhile by another process aren't failures).
     */
    public function delete(string $uuid, bool $applyChildDeletionPolicy = true, ?Transaction $transaction = null): void
    {
        $pending = $this->deleteObject($uuid, $transaction);
        if (!$applyChildDeletionPolicy) {
            return;
        }
        $visited = [$uuid => true];
        $failures = [];

        while (null !== ($childUuid = array_shift($pending))) {
            if (isset($visited[$childUuid])) {
                continue;
            }
            $visited[$childUuid] = true;

            try {
                array_push($pending, ...$this->deleteObject($childUuid, $transaction));
            } catch (ObjectDeletionFailureException $e) {
                // deleted by another process in the meantime
                if ($e->getPrevious() instanceof ObjectNotFoundException) {
                    continue;
                }
                $this->logger?->log($e);
                $failures[] = $e;
            }
        }

        if ([] !== $failures) {
            throw new ObjectDeletionFailureException(
                message: sprintf('Object with uuid %s was deleted, but %u referenced object(s) could not be deleted', $uuid, count($failures)),
                previous: $failures[0]
            );
        }
    }

    public static function failure(string $uuid, ?Throwable $previous = null): ObjectDeletionFailureException
    {
        return new ObjectDeletionFailureException(message: 'Object with uuid ' . $uuid . ' could not be deleted', previous: $previous);
    }

    /**
     * Deletes a single object and determines which of the objects it references are deleted along with it.
     *
     * @return string[] The UUIDs of the referenced objects to delete next.
     * @throws ObjectDeletionFailureException
     */
    private function deleteObject(string $uuid, ?Transaction $transaction): array
    {
        $this->dispatcher?->dispatch(Events::BEFORE_DELETE, static fn() => new Context($uuid));

        try {
            if ($this->stateHandler?->safeModeEnabled()) {
                throw new ObjectDeletionFailureException('Safe mode is enabled. Object cannot be deleted.');
            }

            $this->locks->acquireExclusive($uuid);

            $this->cache->remove($uuid);

            if (!$this->objectFiles->exists($uuid)) {
                throw new ObjectNotFoundException(sprintf('Object with uuid %s not found', $uuid));
            }

            // complete an interrupted write first, so no leftovers of it remain (or are committed) after deleting
            if ($this->objectFiles->recover($uuid)) {
                $this->metadata->reload($uuid);
            }

            // under the lock, before anything is removed: also objects deleted along by the child deletion policy
            $transaction?->track($uuid);

            $className = $this->metadata->load($uuid)?->getClassName();
            $filePath = $this->paths->dataFile($uuid);

            // read before the data file is gone; under the lock, so the references are the ones being deleted
            $children = $this->findChildrenToDelete($uuid, (string)$className, $filePath);

            $this->objectFiles->delete($uuid);

            $this->metadata->forget($uuid);

            $this->stubs->remove($className, $uuid);

            $this->dispatcher?->dispatch(Events::AFTER_DELETE, static fn() => new Context($uuid));

            return $children;
        } catch (Throwable $e) {
            $this->dispatcher?->dispatch(Events::OBJECT_DELETION_FAILURE, static fn() => new Context($uuid));
            throw self::failure($uuid, $e);
        } finally {
            $this->locks->releaseUnlessRetained($uuid);
        }
    }

    /**
     * Determines the referenced objects to delete along with an object, according to the child deletion policy.
     * Data that can't be read or decoded yields no referenced objects, so a corrupted object can still be deleted.
     *
     * @return string[] The UUIDs of the referenced objects to delete.
     */
    private function findChildrenToDelete(string $uuid, string $className, string $filePath): array
    {
        $policy = $this->strategy?->getPolicyChildDeletion() ?? StrategyInterface::DEFAULT_POLICY_CHILD_DELETION;

        if (ChildDeletion::NEVER === $policy) {
            return [];
        }

        $metadata = $this->metadata->load($uuid);
        if (null === $metadata) {
            return [];
        }

        // the object is deleted anyway: data that can't be read or decoded means no references to follow, not a
        // damaged storage
        try {
            $raw = $this->files->read($filePath);
            $data = null === $raw ? null : $this->strategy?->unserialize($raw);
        } catch (Throwable $e) {
            $this->logger?->log($e);
            return [];
        }
        if (null === $data) {
            return [];
        }

        $children = [];
        foreach (References::in($data, $metadata->getReservedReferenceName()) as [$childUuid, $path]) {
            if ($childUuid === $uuid || isset($children[$childUuid])) {
                continue;
            }
            if (ChildDeletion::CALLBACK === $policy && !$this->callbackDeletesChild($uuid, $className, $childUuid, $path)) {
                continue;
            }
            $children[$childUuid] = true;
        }

        return array_keys($children);
    }

    /**
     * Whether the callback of the strategy deletes a referenced object along; not one that isn't stored (anymore),
     * nor if the callback fails (fail-closed: the referenced object is kept).
     */
    private function callbackDeletesChild(string $uuid, string $className, string $childUuid, array $path): bool
    {
        $childClassName = $this->metadata->load($childUuid)?->getClassName();
        if (null === $childClassName) {
            return false;
        }

        try {
            return true === $this->strategy->shouldDeleteChild($uuid, $className, $childUuid, $childClassName, $path);
        } catch (Throwable $e) {
            $this->logger?->log($e);
            return false;
        }
    }
}
