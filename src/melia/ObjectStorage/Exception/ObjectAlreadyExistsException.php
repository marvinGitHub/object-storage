<?php

namespace melia\ObjectStorage\Exception;

/**
 * An object of the UUID exists already, e.g. in the target of a copy.
 */
class ObjectAlreadyExistsException extends ObjectSavingFailureException
{
}
