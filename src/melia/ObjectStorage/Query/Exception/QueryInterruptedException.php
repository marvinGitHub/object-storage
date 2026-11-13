<?php

namespace melia\ObjectStorage\Query\Exception;

/**
 * A statement stopped while it ran: its maximum runtime passed or it was cancelled (see the $interrupt of Oql). What
 * it wrote is rolled back, as when any statement fails.
 *
 * @experimental OQL is experimental in 1.x: its syntax, semantics and this API may change in a minor release (see docs/query.md).
 */
class QueryInterruptedException extends QueryException
{
}
