<?php

namespace melia\ObjectStorage\Query\Exception;

use melia\ObjectStorage\Query\Result;
use Throwable;

/**
 * A statement of a script failed (Oql::script()): the script stopped there. The position is the offset in the whole
 * script.
 *
 * @experimental OQL is experimental in 1.x: its syntax, semantics and this API may change in a minor release (see docs/query.md).
 */
class ScriptException extends QueryException
{
    /**
     * @param int $statement The index of the failed statement, from 0.
     * @param list<Result> $results The results of the statements before it.
     * @param bool $rolledBack Whether a transaction the script started was rolled back because of the failure.
     */
    public function __construct(
        string              $message,
        ?int                $position,
        public readonly int   $statement,
        public readonly array $results,
        public readonly bool  $rolledBack,
        ?Throwable          $previous = null,
    )
    {
        parent::__construct($message, $position, $previous);
    }
}
