<?php

namespace melia\ObjectStorage\Search\Exception;

/**
 * A search names a field by a path that isn't the path of a field of the index, e.g. "friend.friend.name" when only
 * "friend.name" is indexed.
 */
class UnknownPathException extends SearchException
{
}
