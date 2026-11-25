<?php

namespace melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\Ast\FunctionCall;
use melia\ObjectStorage\Query\Exception\QueryException;

/**
 * The aggregates COUNT, SUM, AVG, MIN and MAX of a group, by their running values: a group keeps these, not its
 * rows.
 *
 * @internal part of Executor
 */
final class Aggregates
{
    /**
     * The running values of an aggregate of a group with no rows yet.
     *
     * @return array{count: int, sum: int|float, value: mixed, seen: array<string, true>}
     */
    public static function newState(): array
    {
        return ['count' => 0, 'sum' => 0, 'value' => null, 'seen' => []];
    }

    /**
     * Adds the value of a row of a group to the running values of an aggregate: NULLs aren't counted; with DISTINCT,
     * a value only once. COUNT(*) counts every row, whatever the value.
     *
     * @param array{count: int, sum: int|float, value: mixed, seen: array<string, true>} $state
     */
    public static function accumulate(FunctionCall $call, array &$state, mixed $value): void
    {
        if ($call->star) {
            $state['count']++;
            return;
        }
        if (null === $value) {
            return;
        }
        if ($call->distinct) {
            $key = Values::key($value);
            if (isset($state['seen'][$key])) {
                return;
            }
            $state['seen'][$key] = true;
        }
        $state['count']++;
        switch ($call->name) {
            case 'SUM':
            case 'AVG':
                $state['sum'] += Values::number($value) ?? 0;
                break;
            case 'MIN':
            case 'MAX':
                if (null === $state['value']) {
                    $state['value'] = $value;
                    break;
                }
                $order = Values::sortOrder($value, $state['value']);
                if (('MIN' === $call->name && $order < 0) || ('MAX' === $call->name && $order > 0)) {
                    $state['value'] = $value;
                }
                break;
        }
    }

    /**
     * The value of an aggregate of a group, from its running values (by the id of the aggregate): SUM of integers is
     * an integer, AVG a float; SUM, AVG, MIN and MAX of no values are NULL.
     *
     * @param array<int, array> $states
     */
    public static function value(FunctionCall $call, array $states): mixed
    {
        $state = $states[spl_object_id($call)] ?? self::newState();
        return match ($call->name) {
            'COUNT' => $state['count'],
            'SUM' => 0 === $state['count'] ? null : $state['sum'],
            'AVG' => 0 === $state['count'] ? null : (float)$state['sum'] / $state['count'],
            'MIN', 'MAX' => $state['value'],
            default => throw new QueryException('Unknown aggregate ' . $call->name),
        };
    }
}
