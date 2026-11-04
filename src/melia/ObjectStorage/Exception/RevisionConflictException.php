<?php

namespace melia\ObjectStorage\Exception;

/**
 * Thrown by store() with optimistic locking enabled, when the object has been stored by another
 * process (or another instance) since this process loaded or stored it.
 */
class RevisionConflictException extends ObjectSavingFailureException
{
}
