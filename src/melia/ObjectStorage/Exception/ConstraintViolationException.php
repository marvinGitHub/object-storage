<?php

namespace melia\ObjectStorage\Exception;

/**
 * An object violates a constraint of the storage (Constraint\ConstraintInterface), so it wasn't written.
 */
class ConstraintViolationException extends ObjectSavingFailureException
{
}
