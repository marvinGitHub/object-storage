<?php

namespace melia\ObjectStorage\Query;

use Closure;
use melia\ObjectStorage\Path\PathSegment;
use melia\ObjectStorage\Query\Ast\BoundColumn;
use melia\ObjectStorage\Query\Ast\Expr;
use melia\ObjectStorage\Query\Ast\FunctionCall;

/**
 * What a plan reads, found before it runs: the properties of each alias (so joined objects and the first rows of
 * groups keep only those) and the aggregates (whose running values a group keeps).
 *
 * @internal part of Executor
 */
final class PlanAnalysis
{
    /**
     * The properties a plan reads of each alias: the first names of its paths; null for all (alias.*, *).
     *
     * @return array<string, ?array<string, true>>
     */
    public static function keysUsed(Plan $plan): array
    {
        $keys = array_fill_keys($plan->aliases(), []);
        $collect = static function (Expr $expression) use (&$keys): void {
            if (!$expression instanceof BoundColumn || [] === $expression->path || !is_array($keys[$expression->alias] ?? null)) {
                return;
            }
            $first = $expression->path[0];
            if (is_string($first)) {
                $keys[$expression->alias][$first] = true;
            } elseif (PathSegment::KEY === $first->kind) {
                $keys[$expression->alias][(string)$first->value] = true;
            } else {
                $keys[$expression->alias] = null; // p[0], p.*: any property
            }
        };
        foreach ($plan->items as $item) {
            if (null === $item['expression']) {
                foreach (self::starAliases($plan, $item) as $alias) {
                    $keys[$alias] = null;
                }
            }
        }
        $expressions = [
            ...array_column($plan->items, 'expression'), ...array_column($plan->joins, 'on'),
            $plan->where, $plan->having, ...$plan->groupBy, ...array_column($plan->orderBy, 0),
        ];
        foreach ($expressions as $expression) {
            self::walk($expression, $collect);
        }
        return $keys;
    }

    /**
     * The aggregates of the selection, HAVING and ORDER BY, by their id (not those inside aggregates).
     *
     * @return array<int, FunctionCall>
     */
    public static function aggregatesOf(Plan $plan): array
    {
        $aggregates = [];
        $collect = static function (Expr $expression) use (&$aggregates): bool {
            if ($expression instanceof FunctionCall && $expression->isAggregate()) {
                $aggregates[spl_object_id($expression)] = $expression;
                return false;
            }
            return true;
        };
        foreach ([...array_column($plan->items, 'expression'), $plan->having, ...array_column($plan->orderBy, 0)] as $expression) {
            self::walk($expression, $collect);
        }
        return $aggregates;
    }

    /**
     * The aliases an item * or alias.* stands for.
     *
     * @return list<string>
     */
    public static function starAliases(Plan $plan, array $item): array
    {
        return '*' === $item['starOf'] ? $plan->aliases() : [$item['starOf']];
    }

    /**
     * The properties to keep of the objects of an alias (null: all, none if the query reads none).
     *
     * @param array<string, ?array<string, true>> $keep
     * @return ?array<string, true>
     */
    public static function keepOf(array $keep, string $alias): ?array
    {
        return array_key_exists($alias, $keep) ? $keep[$alias] : [];
    }

    /**
     * An object with only the properties a query reads (references among them still lead to their objects).
     *
     * @param ?array<string, true> $keys null: all
     */
    public static function stripped(?array $object, ?array $keys): ?array
    {
        if (null === $object || null === $keys) {
            return $object;
        }
        $object['data'] = array_intersect_key($object['data'], $keys);
        return $object;
    }

    /**
     * Calls $visit for an expression and, unless it returns false, for its children, and so on.
     *
     * @param Closure(Expr): (bool|void) $visit
     */
    private static function walk(?Expr $expression, Closure $visit): void
    {
        if (null === $expression || false === $visit($expression)) {
            return;
        }
        foreach ($expression->children() as $child) {
            self::walk($child, $visit);
        }
    }
}
