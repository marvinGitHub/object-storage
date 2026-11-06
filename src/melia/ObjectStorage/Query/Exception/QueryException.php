<?php

namespace melia\ObjectStorage\Query\Exception;

use RuntimeException;
use Throwable;

/**
 * A query that can't be run: invalid syntax, an unknown class or alias, a column outside of GROUP BY, …
 * The position is the offset in the query (bytes), if known.
 *
 * @experimental OQL is experimental in 1.x: its syntax, semantics and this API may change in a minor release (see docs/query.md).
 */
class QueryException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $position = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function at(string $message, string $query, int $position): self
    {
        // line and column for people, the offset for programs
        $before = substr($query, 0, $position);
        $line = substr_count($before, "\n") + 1;
        $column = $position - (false === ($last = strrpos($before, "\n")) ? -1 : $last);
        return new self(sprintf('%s (line %d, column %d)', $message, $line, $column), $position);
    }
}
