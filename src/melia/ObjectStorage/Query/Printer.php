<?php

namespace melia\ObjectStorage\Query;

use melia\ObjectStorage\Path\Path;
use melia\ObjectStorage\Query\Ast\Between;
use melia\ObjectStorage\Query\Ast\Binary;
use melia\ObjectStorage\Query\Ast\BoundColumn;
use melia\ObjectStorage\Query\Ast\Column;
use melia\ObjectStorage\Query\Ast\Expr;
use melia\ObjectStorage\Query\Ast\FunctionCall;
use melia\ObjectStorage\Query\Ast\InList;
use melia\ObjectStorage\Query\Ast\IsNull;
use melia\ObjectStorage\Query\Ast\Like;
use melia\ObjectStorage\Query\Ast\Literal;
use melia\ObjectStorage\Query\Ast\OutputColumn;
use melia\ObjectStorage\Query\Ast\Parameter;
use melia\ObjectStorage\Query\Ast\Unary;

/**
 * An expression as text, the same for the same expression however it was written (spaces, case of keywords,
 * parentheses): to compare expressions, e.g. of the selection with those of GROUP BY.
 *
 * @internal Not part of the public API; may change in any release.
 */
final class Printer
{
    public static function print(Expr $expression): string
    {
        return match (true) {
            $expression instanceof Literal => self::literal($expression->value),
            $expression instanceof Parameter => is_numeric($expression->name) ? '?' . $expression->name : ':' . $expression->name,
            $expression instanceof Column => Path::toString([...$expression->segments, ...($expression->uuid ? ['UUID'] : [])]),
            $expression instanceof BoundColumn => Path::toString([$expression->alias, ...$expression->path, ...($expression->uuid && [] !== $expression->path ? ['UUID'] : [])]),
            $expression instanceof OutputColumn => '#' . ($expression->index + 1),
            $expression instanceof FunctionCall => $expression->name . '(' . ($expression->star ? '*' : ($expression->distinct ? 'DISTINCT ' : '') . implode(', ', array_map([self::class, 'print'], $expression->arguments))) . ')',
            $expression instanceof Unary => 'NOT' === $expression->operator ? 'NOT (' . self::print($expression->operand) . ')' : '-(' . self::print($expression->operand) . ')',
            $expression instanceof Binary => '(' . self::print($expression->left) . ' ' . $expression->operator . ' ' . self::print($expression->right) . ')',
            $expression instanceof IsNull => '(' . self::print($expression->operand) . ' IS ' . ($expression->negated ? 'NOT ' : '') . 'NULL)',
            $expression instanceof InList => '(' . self::print($expression->operand) . ($expression->negated ? ' NOT' : '') . ' IN (' . implode(', ', array_map([self::class, 'print'], $expression->values)) . '))',
            $expression instanceof Between => '(' . self::print($expression->operand) . ($expression->negated ? ' NOT' : '') . ' BETWEEN ' . self::print($expression->low) . ' AND ' . self::print($expression->high) . ')',
            $expression instanceof Like => '(' . self::print($expression->operand) . ($expression->negated ? ' NOT' : '') . ' LIKE ' . self::print($expression->pattern) . ')',
            default => $expression::class,
        };
    }

    private static function literal(mixed $value): string
    {
        return match (true) {
            null === $value => 'NULL',
            true === $value => 'TRUE',
            false === $value => 'FALSE',
            is_string($value) => "'" . str_replace("'", "''", $value) . "'",
            is_float($value) => json_encode($value, JSON_PRESERVE_ZERO_FRACTION),
            $value instanceof DateValue => "'" . $value->utc . "'",
            default => (string)$value,
        };
    }
}
