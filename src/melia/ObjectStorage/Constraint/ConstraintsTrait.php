<?php

namespace melia\ObjectStorage\Constraint;

use Closure;
use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\ConstraintViolationException;
use melia\ObjectStorage\Exception\ObjectSavingFailureException;
use Throwable;

/**
 * The constraints of a storage: registered rules every written object has to satisfy, checked by store() and
 * writeRaw() before the data of an object is written. Part of ObjectStorage.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait ConstraintsTrait
{
    /** @var ConstraintInterface[] */
    private array $constraints = [];

    /**
     * Registers a constraint, checked from now on for every object written.
     */
    public function addConstraint(ConstraintInterface $constraint): void
    {
        if (!in_array($constraint, $this->constraints, true)) {
            $this->constraints[] = $constraint;
        }
    }

    public function removeConstraint(ConstraintInterface $constraint): void
    {
        $this->constraints = array_values(array_filter($this->constraints, static fn(ConstraintInterface $c): bool => $c !== $constraint));
    }

    /**
     * @return ConstraintInterface[]
     */
    public function getConstraints(): array
    {
        return $this->constraints;
    }

    /**
     * Checks an object about to be written against all constraints, under its exclusive lock. store() and
     * writeRaw() call it; code writing the files of objects itself (e.g. a tool editing stored data) has to call it
     * as well, and the returned callback after writing.
     *
     * @param string $uuid The UUID of the object.
     * @param string $className Its class.
     * @param string $data Its data as it will be stored (JSON).
     * @param string $reservedReferenceName The key marking references in the data.
     * @return Closure(bool $written): void To call after the write, successful or not.
     * @throws ConstraintViolationException If a constraint is violated; what other constraints reserved is released.
     * @throws ObjectSavingFailureException If a constraint can't be checked, e.g. its lock isn't available.
     */
    public function checkConstraints(string $uuid, string $className, string $data, string $reservedReferenceName): Closure
    {
        if ([] === $this->constraints) {
            return static function (bool $written): void {
            };
        }

        // the data is the one about to be written: invalid JSON there is a bug, not a damaged file
        $decoded = $this->getJsonFileReader(safeModeOnCorruption: false)->tryDecode($data) ?? [];

        $finalizers = [];
        try {
            foreach ($this->constraints as $constraint) {
                $finalize = $constraint->check($uuid, $className, $decoded, $reservedReferenceName);
                if (null !== $finalize) {
                    $finalizers[] = $finalize;
                }
            }
        } catch (Throwable $e) {
            // nothing is written: what the constraints checked before reserved is released
            foreach ($finalizers as $finalize) {
                try {
                    $finalize(false);
                } catch (Throwable $release) {
                    $this->getLogger()?->log($release);
                }
            }
            if ($e instanceof ConstraintViolationException) {
                $this->getEventDispatcher()?->dispatch(Events::CONSTRAINT_VIOLATION, static fn() => new Context($uuid));
                throw $e;
            }
            // e.g. a lock timeout: not a violation, but the object can't be written either
            throw new ObjectSavingFailureException(message: sprintf('Unable to check the constraints of %s: %s', $uuid, $e->getMessage()), previous: $e);
        }

        return function (bool $written) use ($finalizers): void {
            foreach ($finalizers as $finalize) {
                try {
                    $finalize($written);
                } catch (Throwable $e) {
                    // the object is written (or not) anyway; a constraint that couldn't keep up repairs itself
                    $this->getLogger()?->log($e);
                }
            }
        };
    }
}
