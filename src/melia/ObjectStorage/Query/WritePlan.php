<?php

namespace melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\Ast\Expr;
use melia\ObjectStorage\Query\Ast\Source;
use melia\ObjectStorage\Query\Ast\Target;

/**
 * An INSERT, UPDATE, DELETE or ALTER CLASS … DROP ATTRIBUTE bound to its source: the properties written and their values (INSERT: one list of
 * values per object; UPDATE: one list, evaluated for every object), the condition of UPDATE and DELETE. UPDATE also
 * names what it sets as targets (properties or paths into them), and the paths it removes.
 *
 * @internal Not part of the public API; may change in any release.
 */
final readonly class WritePlan
{
    public const string INSERT = 'INSERT';
    public const string UPDATE = 'UPDATE';
    public const string DELETE = 'DELETE';
    /** ALTER CLASS … DROP ATTRIBUTE: the properties are removed */
    public const string DROP = 'ALTER';

    /**
     * @param list<string> $properties
     * @param list<list<Expr>> $rows
     * @param list<Target> $targets UPDATE: what is set, in the order of the values.
     * @param list<Target> $removals UPDATE: the paths removed, after setting.
     */
    public function __construct(
        public string $statement,
        public Source $source,
        public array  $properties = [],
        public array  $rows = [],
        public ?Expr  $where = null,
        public array  $targets = [],
        public array  $removals = [],
    )
    {
    }
}
