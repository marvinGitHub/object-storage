<?php

namespace melia\ObjectStorage\Query;

use melia\ObjectStorage\Path\Path;
use melia\ObjectStorage\Query\Ast\Between;
use melia\ObjectStorage\Query\Ast\Binary;
use melia\ObjectStorage\Query\Ast\BoundColumn;
use melia\ObjectStorage\Query\Ast\Column;
use melia\ObjectStorage\Query\Ast\Delete;
use melia\ObjectStorage\Query\Ast\Insert;
use melia\ObjectStorage\Query\Ast\Update;
use melia\ObjectStorage\Query\Ast\Expr;
use melia\ObjectStorage\Query\Ast\FunctionCall;
use melia\ObjectStorage\Query\Ast\InList;
use melia\ObjectStorage\Query\Ast\IsNull;
use melia\ObjectStorage\Query\Ast\Like;
use melia\ObjectStorage\Query\Ast\Literal;
use melia\ObjectStorage\Query\Ast\OutputColumn;
use melia\ObjectStorage\Query\Ast\Parameter;
use melia\ObjectStorage\Query\Ast\Select;
use melia\ObjectStorage\Query\Ast\Unary;
use melia\ObjectStorage\Query\Exception\QueryException;

/**
 * Binds a SELECT to its sources:
 * - a column whose first name is an alias is a path into that source (p.category.title), the alias alone the
 *   object (its UUID); without one, a path into the only source; with several sources the alias is required;
 * - ORDER BY, GROUP BY and HAVING may name a column of the selection by its alias, ORDER BY and GROUP BY also by its
 *   position (1, 2, …);
 * - parameters are replaced by their values;
 * - functions are checked; aggregates are allowed in the selection, HAVING and ORDER BY only, not nested;
 * - in a grouped query, columns outside of aggregates must be expressions of GROUP BY.
 *
 * @internal Not part of the public API; may change in any release.
 */
final class Planner
{
    /** scalar functions: name => [the fewest arguments, the most (null: any)] */
    public const array FUNCTIONS = [
        'LOWER' => [1, 1], 'UPPER' => [1, 1], 'LENGTH' => [1, 1], 'TRIM' => [1, 1], 'ABS' => [1, 1],
        'ROUND' => [1, 2], 'SUBSTR' => [2, 3], 'COALESCE' => [1, null], 'IFNULL' => [2, 2],
        // dates: the time zone (second argument) is UTC if not given
        'NOW' => [0, 0], 'DATETIME' => [1, 2], 'DATE' => [1, 2], 'DATE_ADD' => [3, 3],
        'YEAR' => [1, 2], 'MONTH' => [1, 2], 'DAY' => [1, 2], 'HOUR' => [1, 2], 'MINUTE' => [1, 2], 'SECOND' => [1, 2],
        // a reference to the object of the UUID, for INSERT and UPDATE
        'REF' => [1, 1],
    ];

    private const string PLAIN = 'plain';
    private const string ORDER = 'order';
    private const string GROUP = 'group';
    private const string HAVING = 'having';

    /** @var list<string> */
    private array $aliases = [];

    /** @var array<string, array{0: int, 1: Expr}> alias of the selection => [index, expression] */
    private array $outputs = [];

    /**
     * @param array<int|string, mixed> $parameters By position (from 0) or name.
     */
    public function __construct(private readonly array $parameters = [])
    {
    }

    /**
     * @throws QueryException
     */
    public function plan(Select $select): Plan
    {
        $this->aliases = [];
        foreach ([$select->from, ...array_map(static fn($join) => $join->source, $select->joins)] as $source) {
            if (in_array($source->alias, $this->aliases, true)) {
                throw new QueryException(sprintf('The alias "%s" is used twice: give one of the sources another one', $source->alias), $source->position);
            }
            $this->aliases[] = $source->alias;
        }

        // the selection
        $items = [];
        $this->outputs = [];
        foreach ($select->items as $item) {
            if ($item->isStar()) {
                if (null !== $item->starOf && !in_array($item->starOf, $this->aliases, true)) {
                    throw new QueryException(sprintf('Unknown alias "%s" of %s', $item->starOf, $item->text));
                }
                $items[] = ['name' => $item->text, 'expression' => null, 'starOf' => $item->starOf ?? '*'];
                continue;
            }
            $expression = $this->bind($item->expression, self::PLAIN, true);
            $name = $item->alias ?? $item->text;
            if (null !== $item->alias) {
                $this->outputs[$item->alias] ??= [count($items), $expression];
            }
            $items[] = ['name' => $name, 'expression' => $expression, 'starOf' => null];
        }

        // joins: the sources joined so far are visible in ON
        $joins = [];
        $visible = [$select->from->alias];
        foreach ($select->joins as $join) {
            $visible[] = $join->source->alias;
            $on = $this->bind($join->on, self::PLAIN, false, $visible);
            $joins[] = ['type' => $join->type, 'source' => $join->source, 'on' => $on, 'hash' => $this->hashOf($on, $join->source->alias)];
        }

        $where = null === $select->where ? null : $this->bind($select->where, self::PLAIN, false);

        $groupBy = [];
        foreach ($select->groupBy as $expression) {
            $groupBy[] = $this->bindPosition($expression, $items, self::GROUP) ?? $this->bind($expression, self::GROUP, false);
        }

        $having = null === $select->having ? null : $this->bind($select->having, self::HAVING, true);

        $orderBy = [];
        foreach ($select->orderBy as $order) {
            $orderBy[] = [$this->bindPosition($order->expression, $items, self::ORDER) ?? $this->bind($order->expression, self::ORDER, true), $order->descending];
        }

        foreach ([...array_column($items, 'expression'), ...array_column($joins, 'on'), $where, ...$groupBy, $having, ...array_column($orderBy, 0)] as $expression) {
            if (null !== $expression && $this->hasFunction($expression, 'REF')) {
                throw new QueryException('REF() is for the values of INSERT and UPDATE');
            }
        }

        $grouped = [] !== $groupBy || null !== $having
            || [] !== array_filter($items, fn(array $item): bool => null !== $item['expression'] && $this->hasAggregate($item['expression']))
            || [] !== array_filter($orderBy, fn(array $order): bool => $this->hasAggregate($order[0]));
        if ($grouped) {
            $keys = array_map([Printer::class, 'print'], $groupBy);
            foreach ($items as $item) {
                if (null === $item['expression']) {
                    throw new QueryException(sprintf('%s isn\'t possible with GROUP BY or aggregates', $item['name']));
                }
                $this->checkGrouped($item['expression'], $keys);
            }
            if (null !== $having) {
                $this->checkGrouped($having, $keys);
            }
            foreach ($orderBy as [$expression]) {
                $this->checkGrouped($expression, $keys);
            }
        }

        return new Plan(
            $select->distinct,
            $items,
            $select->from,
            $joins,
            $where,
            $groupBy,
            $grouped,
            $having,
            $orderBy,
            null === $select->limit ? null : $this->count($select->limit, 'LIMIT'),
            null === $select->offset ? 0 : $this->count($select->offset, 'OFFSET'),
        );
    }

    /**
     * A number of rows (LIMIT, OFFSET): an integer not below 0, a literal or a parameter.
     */
    private function count(Expr $expression, string $clause): int
    {
        $bound = $this->bind($expression, self::PLAIN, false);
        $value = $bound instanceof Literal ? $bound->value : null;
        if (is_string($value) && ctype_digit($value)) {
            $value = (int)$value;
        }
        if (!is_int($value) || $value < 0) {
            throw new QueryException(sprintf('%s needs a whole number of 0 or more', $clause));
        }
        return $value;
    }

    /**
     * ORDER BY 2, GROUP BY 1: a position in the selection.
     */
    private function bindPosition(Expr $expression, array $items, string $mode): ?Expr
    {
        if (!$expression instanceof Literal || !is_int($expression->value)) {
            return null;
        }
        $index = $expression->value - 1;
        if (!isset($items[$index]) || null === $items[$index]['expression']) {
            throw new QueryException(sprintf('%s %d isn\'t a column of the selection', self::ORDER === $mode ? 'ORDER BY' : 'GROUP BY', $expression->value));
        }
        return self::ORDER === $mode ? new OutputColumn($index) : $items[$index]['expression'];
    }

    /**
     * @param list<string>|null $visible The aliases visible (ON: those joined so far); all by default.
     */
    private function bind(Expr $expression, string $mode, bool $aggregates, ?array $visible = null, bool $inAggregate = false): Expr
    {
        $visible ??= $this->aliases;
        $bind = fn(Expr $child) => $this->bind($child, $mode, $aggregates, $visible, $inAggregate);

        return match (true) {
            $expression instanceof Literal => $expression,
            $expression instanceof Parameter => $this->parameter($expression),
            $expression instanceof Column => $this->column($expression, $mode, $visible),
            $expression instanceof FunctionCall => $this->function($expression, $mode, $aggregates, $visible, $inAggregate),
            $expression instanceof Unary => new Unary($expression->operator, $bind($expression->operand)),
            $expression instanceof Binary => new Binary($expression->operator, $bind($expression->left), $bind($expression->right)),
            $expression instanceof IsNull => new IsNull($bind($expression->operand), $expression->negated),
            $expression instanceof InList => new InList($bind($expression->operand), array_map($bind, $expression->values), $expression->negated),
            $expression instanceof Between => new Between($bind($expression->operand), $bind($expression->low), $bind($expression->high), $expression->negated),
            $expression instanceof Like => new Like($bind($expression->operand), $bind($expression->pattern), $expression->negated),
            default => throw new QueryException('Unknown expression ' . $expression::class),
        };
    }

    private function parameter(Parameter $parameter): Literal
    {
        $key = ctype_digit($parameter->name) ? (int)$parameter->name : $parameter->name;
        if (!array_key_exists($key, $this->parameters)) {
            throw new QueryException(
                is_int($key) ? sprintf('No value for the parameter ? number %d', $key + 1) : sprintf('No value for the parameter :%s', $key),
                $parameter->position
            );
        }
        $value = $this->parameters[$key];
        if ($value instanceof \DateTimeInterface) {
            return new Literal(DateValue::of($value));
        }
        if (!(null === $value || is_scalar($value))) {
            throw new QueryException(sprintf('The parameter %s must be a string, number, boolean, date or null', is_int($key) ? '?' : ':' . $key), $parameter->position);
        }
        return new Literal($value);
    }

    private function column(Column $column, string $mode, array $visible): Expr
    {
        $segments = $column->segments;
        $text = Path::toString([...$segments, ...($column->uuid ? ['UUID'] : [])]);
        if ([] === $this->aliases) {
            throw new QueryException(sprintf('"%s" isn\'t possible here: there is no object to read it of', $text), $column->position);
        }

        // UUID alone: of the only source
        if ([] === $segments) {
            if (1 === count($this->aliases)) {
                return new BoundColumn($this->aliases[0], [], true);
            }
            throw new QueryException(sprintf('UUID needs the alias of its source, e.g. %s.UUID', $this->aliases[0]), $column->position);
        }
        $first = $segments[0];

        // an alias of the selection (not of a source): ORDER BY, GROUP BY, HAVING
        if (!$column->uuid && 1 === count($segments) && self::PLAIN !== $mode && isset($this->outputs[$first]) && !in_array($first, $this->aliases, true)) {
            [$index, $expression] = $this->outputs[$first];
            return self::ORDER === $mode ? new OutputColumn($index) : $expression;
        }
        if (in_array($first, $this->aliases, true)) {
            if (!in_array($first, $visible, true)) {
                throw new QueryException(sprintf('"%s" isn\'t joined yet here: ON sees the sources joined before', $first), $column->position);
            }
            return new BoundColumn($first, array_slice($segments, 1), $column->uuid);
        }
        if (1 === count($this->aliases)) {
            return new BoundColumn($this->aliases[0], $segments, $column->uuid);
        }
        throw new QueryException(sprintf('"%s" needs the alias of its source (%s)', $text, implode(', ', $this->aliases)), $column->position);
    }

    // ---- INSERT, UPDATE, DELETE ----

    /**
     * @throws QueryException
     */
    public function planWrite(Insert|Update|Delete $statement): WritePlan
    {
        if ($statement instanceof Insert) {
            // values without objects to read
            $this->aliases = [];
            $this->outputs = [];
            if (count(array_unique($statement->properties)) !== count($statement->properties)) {
                throw new QueryException('A property is named twice');
            }
            $rows = array_map(fn(array $values): array => array_map(fn(Expr $value): Expr => $this->value($value), $values), $statement->rows);
            return new WritePlan(WritePlan::INSERT, $statement->into, $statement->properties, $rows);
        }

        $source = $statement->source;
        $this->aliases = [$source->alias];
        $this->outputs = [];
        $where = null === $statement->where ? null : $this->bind($statement->where, self::PLAIN, false);
        if (null !== $where && $this->hasFunction($where, 'REF')) {
            throw new QueryException('REF() is for the values of INSERT and UPDATE');
        }

        if ($statement instanceof Delete) {
            return new WritePlan(WritePlan::DELETE, $source, [], [], $where);
        }

        $properties = array_map(static fn(array $assignment): string => $assignment[0], $statement->assignments);
        if (count(array_unique($properties)) !== count($properties)) {
            throw new QueryException('A property is set twice');
        }
        $values = array_map(fn(array $assignment): Expr => $this->value($assignment[1]), $statement->assignments);
        return new WritePlan(WritePlan::UPDATE, $source, $properties, [$values], $where);
    }

    /** a value to write: REF(…) only as the whole value */
    private function value(Expr $expression): Expr
    {
        $bound = $this->bind($expression, self::PLAIN, false);
        $inner = $bound instanceof FunctionCall && 'REF' === $bound->name ? $bound->arguments : [$bound];
        foreach ($inner as $part) {
            if ($this->hasFunction($part, 'REF')) {
                throw new QueryException('REF() is a whole value, e.g. SET category = REF(:uuid)');
            }
        }
        return $bound;
    }

    private function hasFunction(Expr $expression, string $name): bool
    {
        if ($expression instanceof FunctionCall && $name === $expression->name) {
            return true;
        }
        foreach ($expression->children() as $child) {
            if ($this->hasFunction($child, $name)) {
                return true;
            }
        }
        return false;
    }

    private function function(FunctionCall $call, string $mode, bool $aggregates, array $visible, bool $inAggregate): FunctionCall
    {
        if ($call->isAggregate()) {
            if ($inAggregate) {
                throw new QueryException(sprintf('%s inside of an aggregate isn\'t possible', $call->name), $call->position);
            }
            if (!$aggregates) {
                throw new QueryException(sprintf('%s isn\'t possible here: aggregates go in the selection, HAVING or ORDER BY', $call->name), $call->position);
            }
            if (!$call->star && 1 !== count($call->arguments)) {
                throw new QueryException(sprintf('%s takes one argument', $call->name), $call->position);
            }
            $arguments = array_map(fn(Expr $argument) => $this->bind($argument, $mode, false, $visible, true), $call->arguments);
            return new FunctionCall($call->name, $arguments, $call->distinct, $call->star, $call->position);
        }

        if (!isset(self::FUNCTIONS[$call->name])) {
            throw new QueryException(sprintf('Unknown function %s', $call->name), $call->position);
        }
        if ($call->distinct) {
            throw new QueryException(sprintf('DISTINCT is possible in aggregates only, not in %s', $call->name), $call->position);
        }
        [$min, $max] = self::FUNCTIONS[$call->name];
        $count = count($call->arguments);
        if ($count < $min || (null !== $max && $count > $max)) {
            throw new QueryException(sprintf('%s takes %s', $call->name, $min === $max ? "$min argument" . (1 === $min ? '' : 's') : ($max ? "$min to $max arguments" : "at least $min argument" . (1 === $min ? '' : 's'))), $call->position);
        }
        $arguments = array_map(fn(Expr $argument) => $this->bind($argument, $mode, $aggregates, $visible, $inAggregate), $call->arguments);
        return new FunctionCall($call->name, $arguments, false, false, $call->position);
    }

    private function hasAggregate(Expr $expression): bool
    {
        if ($expression instanceof FunctionCall && $expression->isAggregate()) {
            return true;
        }
        foreach ($expression->children() as $child) {
            if ($this->hasAggregate($child)) {
                return true;
            }
        }
        return false;
    }

    /**
     * In a grouped query: columns only in aggregates or in expressions of GROUP BY.
     *
     * @param list<string> $keys The expressions of GROUP BY, printed.
     */
    private function checkGrouped(Expr $expression, array $keys): void
    {
        if (in_array(Printer::print($expression), $keys, true)) {
            return;
        }
        if ($expression instanceof FunctionCall && $expression->isAggregate()) {
            return;
        }
        if ($expression instanceof BoundColumn) {
            throw new QueryException(sprintf('%s must be in GROUP BY or in an aggregate, e.g. MIN(%1$s)', Printer::print($expression)));
        }
        foreach ($expression->children() as $child) {
            $this->checkGrouped($child, $keys);
        }
    }

    /**
     * An equality of ON to join by hashing: one side of the rows joined so far, the other of the joined source.
     *
     * @return array{0: Expr, 1: Expr}|null
     */
    private function hashOf(Expr $on, string $alias): ?array
    {
        $conditions = [];
        $collect = static function (Expr $expression) use (&$collect, &$conditions): void {
            if ($expression instanceof Binary && 'AND' === $expression->operator) {
                $collect($expression->left);
                $collect($expression->right);
            } else {
                $conditions[] = $expression;
            }
        };
        $collect($on);

        foreach ($conditions as $condition) {
            if (!$condition instanceof Binary || '=' !== $condition->operator) {
                continue;
            }
            $left = self::aliasesOf($condition->left);
            $right = self::aliasesOf($condition->right);
            if ([$alias] === $right && [] !== $left && !in_array($alias, $left, true)) {
                return [$condition->left, $condition->right];
            }
            if ([$alias] === $left && [] !== $right && !in_array($alias, $right, true)) {
                return [$condition->right, $condition->left];
            }
        }
        return null;
    }

    /**
     * @return list<string> The aliases an expression reads.
     */
    public static function aliasesOf(Expr $expression): array
    {
        $aliases = $expression instanceof BoundColumn ? [$expression->alias] : [];
        foreach ($expression->children() as $child) {
            $aliases = [...$aliases, ...self::aliasesOf($child)];
        }
        return array_values(array_unique($aliases));
    }
}
