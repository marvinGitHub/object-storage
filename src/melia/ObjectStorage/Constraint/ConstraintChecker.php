<?php

namespace melia\ObjectStorage\Constraint;

use Closure;
use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\DispatcherInterface;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\ConstraintViolationException;
use melia\ObjectStorage\Exception\ObjectSavingFailureException;
use melia\ObjectStorage\Logger\LoggerInterface;
use melia\ObjectStorage\Strategy\StrategyInterface;
use Throwable;

/**
 * Checks an object about to be written against the registered constraints, under its exclusive lock.
 */
final readonly class ConstraintChecker
{
    public function __construct(
        private ConstraintRegistry   $constraints,
        private ?StrategyInterface   $strategy = null,
        private ?DispatcherInterface $dispatcher = null,
        private ?LoggerInterface     $logger = null,
    )
    {
    }

    /**
     * @param string $uuid The UUID of the object.
     * @param string $className Its class.
     * @param string $data Its data as it will be stored (JSON).
     * @param string $reservedReferenceName The key marking references in the data.
     * @return Closure(bool $written): void To call after the write, successful or not.
     * @throws ConstraintViolationException If a constraint is violated (CONSTRAINT_VIOLATION dispatched); what other
     *         constraints reserved is released.
     * @throws ObjectSavingFailureException If a constraint can't be checked, e.g. its lock isn't available.
     */
    public function check(string $uuid, string $className, string $data, string $reservedReferenceName): Closure
    {
        $constraints = $this->constraints->all();
        if ([] === $constraints) {
            return static function (bool $written): void {
            };
        }

        // the data is the one about to be written: invalid JSON there is a bug, not a damaged file
        try {
            $decoded = $this->strategy?->unserialize($data) ?? [];
        } catch (Throwable $e) {
            $this->logger?->log($e);
            $decoded = [];
        }

        $finalizers = [];
        try {
            foreach ($constraints as $constraint) {
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
                    $this->logger?->log($release);
                }
            }
            if ($e instanceof ConstraintViolationException) {
                $this->dispatcher?->dispatch(Events::CONSTRAINT_VIOLATION, static fn() => new Context($uuid));
                throw $e;
            }
            // e.g. a lock timeout: not a violation, but the object can't be written either
            throw new ObjectSavingFailureException(message: sprintf('Unable to check the constraints of %s: %s', $uuid, $e->getMessage()), previous: $e);
        }

        $logger = $this->logger;
        return static function (bool $written) use ($finalizers, $logger): void {
            foreach ($finalizers as $finalize) {
                try {
                    $finalize($written);
                } catch (Throwable $e) {
                    // the object is written (or not) anyway; a constraint that couldn't keep up repairs itself
                    $logger?->log($e);
                }
            }
        };
    }
}
