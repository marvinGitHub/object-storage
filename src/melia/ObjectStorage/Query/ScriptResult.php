<?php

namespace melia\ObjectStorage\Query;

/**
 * The results of a script (Oql::script()), one per statement, in their order.
 *
 * @experimental OQL is experimental in 1.x: its syntax, semantics and this API may change in a minor release (see docs/query.md).
 */
final readonly class ScriptResult
{
    /**
     * @param list<Result> $results One per statement.
     * @param bool $rolledBack Whether the script left a transaction it started open, which was rolled back: its
     *        changes weren't kept.
     * @param bool $preview Whether it was a preview: everything was rolled back at the end.
     */
    public function __construct(
        public array $results,
        public bool  $rolledBack = false,
        public bool  $preview = false,
    )
    {
    }

    /**
     * The result of the last statement.
     */
    public function last(): ?Result
    {
        return [] === $this->results ? null : $this->results[count($this->results) - 1];
    }
}
