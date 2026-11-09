<?php

namespace melia\ObjectStorage\Transfer;

use InvalidArgumentException;
use melia\ObjectStorage\Exception\ObjectAlreadyExistsException;
use melia\ObjectStorage\Exception\ObjectNotFoundException;
use melia\ObjectStorage\ObjectStorage;
use Throwable;

/**
 * Copies or moves objects from one storage into another, as stored: the UUID, the revision, the expiration and
 * the checksum are kept, the objects aren't loaded. Each object is handled on its own: one failing doesn't stop
 * the others, and the result says what happened to each.
 *
 *   $transfer = new ObjectTransfer($source, $target);
 *   $result = $transfer->copy([$uuid], withReferences: true);
 *   $result = $transfer->move($source->list(Order::class), onConflict: ObjectTransfer::CONFLICT_SKIP);
 *
 * Moving copies an object and then deletes it in the source, only the object itself, whatever the child deletion
 * policy says. Referenced objects copied along are kept in the source, since other objects may reference them.
 */
final class ObjectTransfer
{
    /** An object of the UUID in the target is an error. */
    public const string CONFLICT_FAIL = 'fail';
    /** An object of the UUID in the target is kept, the object is skipped. */
    public const string CONFLICT_SKIP = 'skip';
    /** An object of the UUID in the target is replaced. */
    public const string CONFLICT_OVERWRITE = 'overwrite';

    public const array CONFLICTS = [self::CONFLICT_FAIL, self::CONFLICT_SKIP, self::CONFLICT_OVERWRITE];

    /** The maximum number of referenced objects copied along with one object, against huge graphs. */
    public const int MAX_REFERENCES = 10000;

    /**
     * @throws InvalidArgumentException If source and target are the same storage.
     */
    public function __construct(
        private readonly ObjectStorage $source,
        private readonly ObjectStorage $target,
    )
    {
        $sourceDir = realpath($source->getStorageDir()) ?: $source->getStorageDir();
        $targetDir = realpath($target->getStorageDir()) ?: $target->getStorageDir();
        if ($sourceDir === $targetDir) {
            throw new InvalidArgumentException('Source and target are the same storage.');
        }
    }

    /**
     * Copies objects into the target.
     *
     * @param iterable<string> $uuids The objects to copy.
     * @param bool $withReferences Whether to copy the objects they reference as well, recursively, so the copies
     *        don't refer to objects missing in the target. Referenced objects existing in the target are kept.
     * @param string $onConflict What to do if an object exists in the target, one of the CONFLICT_* constants.
     * @param callable(string $uuid, string $outcome): void|null $onProgress Called per object, with
     *        'transferred', 'skipped' or 'failed'.
     */
    public function copy(iterable $uuids, bool $withReferences = false, string $onConflict = self::CONFLICT_FAIL, ?callable $onProgress = null): TransferResult
    {
        return $this->transfer($uuids, false, $withReferences, $onConflict, $onProgress);
    }

    /**
     * Moves objects into the target: copies them and deletes them in the source.
     *
     * @param iterable<string> $uuids The objects to move.
     * @param bool $withReferences Whether to copy the objects they reference as well (they stay in the source).
     * @param string $onConflict What to do if an object exists in the target; a skipped object stays in the source.
     * @param callable(string $uuid, string $outcome): void|null $onProgress Called per object.
     */
    public function move(iterable $uuids, bool $withReferences = false, string $onConflict = self::CONFLICT_FAIL, ?callable $onProgress = null): TransferResult
    {
        return $this->transfer($uuids, true, $withReferences, $onConflict, $onProgress);
    }

    /**
     * @param iterable<string> $uuids
     */
    private function transfer(iterable $uuids, bool $move, bool $withReferences, string $onConflict, ?callable $onProgress): TransferResult
    {
        if (!in_array($onConflict, self::CONFLICTS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown conflict handling "%s", expected one of %s.', $onConflict, implode(', ', self::CONFLICTS)));
        }

        $result = new TransferResult();
        // the list is taken first: moving changes what the source lists
        $uuids = array_values(array_unique([...(is_array($uuids) ? $uuids : iterator_to_array($uuids, false))]));

        foreach ($uuids as $uuid) {
            $outcome = $this->transferOne((string)$uuid, $move, $withReferences, $onConflict, $result);
            if (null !== $onProgress) {
                $onProgress($uuid, $outcome);
            }
        }

        return $result;
    }

    /**
     * @return string 'transferred', 'skipped' or 'failed'
     */
    private function transferOne(string $uuid, bool $move, bool $withReferences, string $onConflict, TransferResult $result): string
    {
        $lockAdapter = $this->source->getLockAdapter();
        $lockedHere = false;

        try {
            // moving: nobody changes the object between copying and deleting it
            if ($move && null !== $lockAdapter && !$lockAdapter->hasActiveExclusiveLock($uuid)) {
                $lockAdapter->acquireExclusiveLock($uuid);
                $lockedHere = true;
            }

            $object = $this->source->readRaw($uuid);
            if ($object->isExpired()) {
                $result->skipped[$uuid] = 'expired';
                return 'skipped';
            }

            if ($this->target->exists($uuid) && self::CONFLICT_SKIP === $onConflict) {
                $result->skipped[$uuid] = 'exists in the target';
                return 'skipped';
            }

            $this->target->writeRaw($object, self::CONFLICT_OVERWRITE === $onConflict);

            if ($withReferences) {
                $this->copyReferences($object, $result);
            }

            if ($move) {
                $this->source->delete($uuid, applyChildDeletionPolicy: false);
            }

            $result->transferred[] = $uuid;
            return 'transferred';
        } catch (ObjectAlreadyExistsException) {
            $result->failed[$uuid] = 'exists in the target';
            return 'failed';
        } catch (ObjectNotFoundException) {
            $result->failed[$uuid] = 'not found';
            return 'failed';
        } catch (Throwable $e) {
            $message = $e->getMessage();
            for ($previous = $e->getPrevious(); null !== $previous; $previous = $previous->getPrevious()) {
                $message .= ': ' . $previous->getMessage();
            }
            $result->failed[$uuid] = ($move && $this->target->exists($uuid) && $this->source->exists($uuid) ? 'copied, but not deleted in the source: ' : '') . $message;
            return 'failed';
        } finally {
            // a transaction on the source retains the locks of the objects it deleted
            if ($lockedHere && !$this->source->isLockRetained($uuid) && $lockAdapter->isLockedByThisProcess($uuid)) {
                $lockAdapter->releaseLock($uuid);
            }
        }
    }

    /**
     * Copies the objects an object references, recursively; those existing in the target are kept, those missing
     * in the source are left out (the reference is dangling in the source too).
     */
    private function copyReferences(RawObject $object, TransferResult $result): void
    {
        $pending = $object->references;
        $seen = [$object->uuid => true];

        while (null !== ($uuid = array_shift($pending))) {
            if (isset($seen[$uuid])) {
                continue;
            }
            $seen[$uuid] = true;
            if (count($seen) > self::MAX_REFERENCES) {
                throw new InvalidArgumentException(sprintf('Object %s references more than %d objects.', $object->uuid, self::MAX_REFERENCES));
            }

            if ($this->target->exists($uuid) || !$this->source->exists($uuid)) {
                continue;
            }

            $referenced = $this->source->readRaw($uuid);
            if ($referenced->isExpired()) {
                continue;
            }

            try {
                $this->target->writeRaw($referenced);
                $result->references[] = $uuid;
            } catch (ObjectAlreadyExistsException) {
                // written by another process meanwhile
            }

            array_push($pending, ...$referenced->references);
        }
    }
}
