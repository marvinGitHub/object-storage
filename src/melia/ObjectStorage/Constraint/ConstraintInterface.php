<?php

namespace melia\ObjectStorage\Constraint;

use Closure;
use melia\ObjectStorage\Exception\ConstraintViolationException;

/**
 * A rule every object written to a storage has to satisfy, e.g. a unique value (Unique\Manager). Registered with
 * ObjectStorage::addConstraint(), it is checked by store() and writeRaw() before the data of an object is written:
 * under the exclusive lock of the object, with the data as it will be stored.
 */
interface ConstraintInterface
{
    /**
     * Checks an object about to be written; throws to prevent the write.
     *
     * @param string $uuid The UUID of the object.
     * @param string $className Its class.
     * @param array $data Its data as it will be stored (references as ['<reserved>' => '<uuid>']).
     * @param string $reservedReferenceName The key marking references in $data.
     * @return Closure(bool $written): void|null Called after the write, successful or not, still under the lock of
     *         the object: to keep or release what the check reserved. Null if there is nothing to do.
     * @throws ConstraintViolationException If the object violates the constraint; nothing is written.
     */
    public function check(string $uuid, string $className, array $data, string $reservedReferenceName): ?Closure;
}
