<?php

namespace melia\ObjectStorage\Constraint;

/**
 * The constraints registered with a storage instance, in the order of registration; each one once.
 *
 * State of the storage, kept apart from ConstraintChecker so it survives a new strategy, logger or event dispatcher.
 */
final class ConstraintRegistry
{
    /** @var ConstraintInterface[] */
    private array $constraints = [];

    public function add(ConstraintInterface $constraint): void
    {
        if (!in_array($constraint, $this->constraints, true)) {
            $this->constraints[] = $constraint;
        }
    }

    public function remove(ConstraintInterface $constraint): void
    {
        $this->constraints = array_values(array_filter($this->constraints, static fn(ConstraintInterface $c): bool => $c !== $constraint));
    }

    /**
     * @return ConstraintInterface[]
     */
    public function all(): array
    {
        return $this->constraints;
    }
}
