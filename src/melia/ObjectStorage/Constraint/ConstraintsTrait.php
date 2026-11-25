<?php

namespace melia\ObjectStorage\Constraint;

use Closure;
use melia\ObjectStorage\Exception\ConstraintViolationException;
use melia\ObjectStorage\Exception\ObjectSavingFailureException;

/**
 * The constraints of a storage: registered rules every written object has to satisfy, checked by store() and
 * writeRaw() before the data of an object is written (see ConstraintChecker). Part of ObjectStorage.
 *
 * @mixin \melia\ObjectStorage\ObjectStorage
 */
trait ConstraintsTrait
{
    /** the registered constraints; kept by the storage, so they survive a new strategy, logger or dispatcher */
    private ?ConstraintRegistry $constraintRegistry = null;

    private function constraintRegistry(): ConstraintRegistry
    {
        return $this->constraintRegistry ??= new ConstraintRegistry();
    }

    /**
     * The checking of constraints, with the registered constraints and the strategy, event dispatcher and logger of
     * the storage as they are now.
     */
    protected function constraintChecker(): ConstraintChecker
    {
        $constraints = $this->constraintRegistry();
        $strategy = $this->getStrategy();
        $dispatcher = $this->getEventDispatcher();
        $logger = $this->getLogger();

        return $this->component(
            ConstraintChecker::class,
            [$constraints, $strategy, $dispatcher, $logger],
            static fn(): ConstraintChecker => new ConstraintChecker($constraints, $strategy, $dispatcher, $logger),
        );
    }

    /**
     * Registers a constraint, checked from now on for every object written.
     */
    public function addConstraint(ConstraintInterface $constraint): void
    {
        $this->constraintRegistry()->add($constraint);
    }

    public function removeConstraint(ConstraintInterface $constraint): void
    {
        $this->constraintRegistry()->remove($constraint);
    }

    /**
     * @return ConstraintInterface[]
     */
    public function getConstraints(): array
    {
        return $this->constraintRegistry()->all();
    }

    /**
     * Checks an object about to be written against all constraints, under its exclusive lock, see
     * ConstraintChecker::check(). store() and writeRaw() call it; code writing the files of objects itself (e.g. a
     * tool editing stored data) has to call it as well, and the returned callback after writing.
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
        return $this->constraintChecker()->check($uuid, $className, $data, $reservedReferenceName);
    }
}
