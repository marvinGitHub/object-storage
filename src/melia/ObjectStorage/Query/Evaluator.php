<?php

namespace melia\ObjectStorage\Query;

use Closure;
use melia\ObjectStorage\Path\Path;
use melia\ObjectStorage\Query\Ast\Between;
use melia\ObjectStorage\Query\Ast\Binary;
use melia\ObjectStorage\Query\Ast\BoundColumn;
use melia\ObjectStorage\Query\Ast\Expr;
use melia\ObjectStorage\Query\Ast\FunctionCall;
use melia\ObjectStorage\Query\Ast\InList;
use melia\ObjectStorage\Query\Ast\IsNull;
use melia\ObjectStorage\Query\Ast\Like;
use melia\ObjectStorage\Query\Ast\Literal;
use melia\ObjectStorage\Query\Ast\Unary;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Search\DocumentBuilder;
use melia\ObjectStorage\UUID\Validator as UuidValidator;

/**
 * The values of expressions for a row: an array of objects by their alias, as ObjectSource reads them (null for the
 * missing object of a LEFT JOIN). Paths follow references through the referenced objects of the statement; a
 * condition on a path with * holds if it holds for any element.
 *
 * @internal part of Executor
 */
final class Evaluator
{
    /** the comparison operators */
    private const array COMPARISONS = ['=', '!=', '<', '<=', '>', '>='];

    /**
     * @param Closure(string): ?array{0: array, 1: string} $follow The referenced objects, see ObjectSource::follow().
     * @param Functions $functions The scalar functions of the statement.
     */
    public function __construct(
        private readonly Closure   $follow,
        private readonly Functions $functions,
    )
    {
    }

    /**
     * Whether a condition is true for a row; no condition always is.
     */
    public function holds(?Expr $condition, array $row, ?array $group = null): bool
    {
        return null === $condition || true === Values::truth($this->evaluate($condition, $row, $group));
    }

    /**
     * The value of an expression for a row; for a group (grouped queries), $group holds the running values of its
     * aggregates (by the id of the aggregate, see Aggregates) and the row is its first.
     */
    public function evaluate(Expr $expression, array $row, ?array $group = null): mixed
    {
        $value = fn(Expr $child): mixed => $this->evaluate($child, $row, $group);

        if ($expression instanceof Binary || $expression instanceof InList || $expression instanceof Between || $expression instanceof Like) {
            $wildcard = self::wildcardOperand($expression);
            if (null !== $wildcard) {
                return $this->anyElement($expression, $wildcard, $row, $group);
            }
        }

        return match (true) {
            $expression instanceof Literal => $expression->value,
            $expression instanceof BoundColumn => $this->column($expression, $row),
            $expression instanceof FunctionCall => $this->call($expression, $row, $group, $value),
            $expression instanceof Unary => self::unary($expression, $value),
            $expression instanceof Binary => self::binary($expression, $value),
            $expression instanceof IsNull => (null === $value($expression->operand)) !== $expression->negated,
            $expression instanceof InList => self::inList($expression, $value),
            $expression instanceof Between => self::between($expression, $value),
            $expression instanceof Like => self::like($expression, $value),
            default => throw new QueryException('Unknown expression ' . $expression::class),
        };
    }

    /**
     * A value read from an object: a date as stored becomes a DateValue. Values read through references are
     * marked with the reserved key of the object they were read from: dates there use the default key, unless
     * their object has another one (rare: a property with the name of the key).
     */
    public static function valueOf(mixed $found, array $object): mixed
    {
        if (!is_array($found)) {
            return $found;
        }
        return DateValue::fromStored($found, $object['dateTime'] ?? null) ?? $found;
    }

    /**
     * The value of a column: the UUID of the object of its alias, or the value at its path; with .UUID, the UUID of
     * a reference there (UUIDs of references for a path with *).
     */
    private function column(BoundColumn $column, array $row): mixed
    {
        $object = $row[$column->alias] ?? null;
        if (null === $object) {
            return null;
        }
        if ([] === $column->path) {
            return $object['uuid'];
        }
        $found = DocumentBuilder::extract($object['data'], $column->path, $object['reserved'], $this->follow);
        if (!$column->uuid) {
            return self::valueOf($found, $object);
        }
        // p.category.UUID: a reference there is its UUID; anything else isn't one
        $isUuid = static fn(mixed $v): bool => is_string($v) && UuidValidator::validate($v);
        if (is_array($found) && Path::hasWildcard($column->path)) {
            $uuids = array_values(array_filter($found, $isUuid));
            return [] === $uuids ? null : $uuids;
        }
        return $isUuid($found) ? $found : null;
    }

    /**
     * The value of a call: REF(), CREATED() and MODIFIED() (of the metadata of the object of the alias), an
     * aggregate of the group or a function of the values of its arguments.
     */
    private function call(FunctionCall $call, array $row, ?array $group, Closure $value): mixed
    {
        switch ($call->name) {
            case 'REF':
                $uuid = Values::text($value($call->arguments[0]));
                return null === $uuid ? null : new Reference($uuid);
            case 'CREATED':
            case 'MODIFIED':
                $column = $call->arguments[0];
                $timestamp = $column instanceof BoundColumn ? ($row[$column->alias][strtolower($call->name)] ?? null) : null;
                return null === $timestamp ? null : DateValue::ofTimestamp((float)$timestamp);
        }
        return $call->isAggregate()
            ? Aggregates::value($call, $group ?? throw new QueryException(sprintf('%s needs a group', $call->name)))
            : $this->functions->call($call->name, array_map($value, $call->arguments));
    }

    private static function unary(Unary $expression, Closure $value): mixed
    {
        $operand = $value($expression->operand);
        if ('NOT' === $expression->operator) {
            return Values::not(Values::truth($operand));
        }
        $number = Values::number($operand);
        return null === $number ? null : -$number;
    }

    private static function inList(InList $expression, Closure $value): ?bool
    {
        $operand = $value($expression->operand);
        $result = false;
        foreach ($expression->values as $candidate) {
            $result = Values::or($result, self::equals($operand, $value($candidate)));
            if (true === $result) {
                break;
            }
        }
        return $expression->negated ? Values::not($result) : $result;
    }

    private static function between(Between $expression, Closure $value): ?bool
    {
        $operand = $value($expression->operand);
        $low = Values::compare($operand, $value($expression->low));
        $high = Values::compare($operand, $value($expression->high));
        $result = Values::and(null === $low ? null : $low >= 0, null === $high ? null : $high <= 0);
        return $expression->negated ? Values::not($result) : $result;
    }

    private static function like(Like $expression, Closure $value): ?bool
    {
        $operand = Values::text($value($expression->operand));
        $pattern = Values::text($value($expression->pattern));
        if (null === $operand || null === $pattern) {
            return null;
        }
        $matches = Values::like($operand, $pattern);
        return null === $matches ? null : $matches !== $expression->negated;
    }

    private static function equals(mixed $a, mixed $b): ?bool
    {
        $order = Values::compare($a, $b);
        return null === $order ? null : 0 === $order;
    }

    private static function binary(Binary $expression, Closure $value): mixed
    {
        $operator = $expression->operator;
        if ('AND' === $operator) {
            $left = Values::truth($value($expression->left));
            return false === $left ? false : Values::and($left, Values::truth($value($expression->right)));
        }
        if ('OR' === $operator) {
            $left = Values::truth($value($expression->left));
            return true === $left ? true : Values::or($left, Values::truth($value($expression->right)));
        }

        $left = $value($expression->left);
        $right = $value($expression->right);
        if (in_array($operator, self::COMPARISONS, true)) {
            $order = Values::compare($left, $right);
            return null === $order ? null : match ($operator) {
                '=' => 0 === $order,
                '!=' => 0 !== $order,
                '<' => $order < 0,
                '<=' => $order <= 0,
                '>' => $order > 0,
                '>=' => $order >= 0,
            };
        }
        if ('REGEXP' === $operator || '||' === $operator) {
            $left = Values::text($left);
            $right = Values::text($right);
            if (null === $left || null === $right) {
                return null;
            }
            return 'REGEXP' === $operator ? Values::matches($left, $right) : $left . $right;
        }

        $left = Values::number($left);
        $right = Values::number($right);
        if (null === $left || null === $right) {
            return null;
        }
        $result = match ($operator) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            // as in MySQL: 7 / 2 is 3.5; NULL for a division by 0
            '/' => 0 == $right ? null : $left / $right,
            // the remainder with the sign of the dividend, the integer quotient
            '%' => Math::mod($left, $right),
            'DIV' => Math::div($left, $right),
            default => throw new QueryException('Unknown operator ' . $operator),
        };

        // NULL for a result that isn't a finite number (as the functions of Math): NAN compares as greater than
        // and unequal to everything, so "x > 1e999 - 1e999" held for every object
        return is_float($result) && !is_finite($result) ? null : $result;
    }

    // ---- paths with * ----

    /**
     * The column with * a condition compares directly (=, <, IN, BETWEEN, LIKE …), if any: such a condition is
     * checked for each element of the list.
     */
    private static function wildcardOperand(Binary|InList|Between|Like $expression): ?BoundColumn
    {
        $operands = match (true) {
            $expression instanceof Binary => in_array($expression->operator, [...self::COMPARISONS, 'REGEXP'], true) ? [$expression->left, $expression->right] : [],
            default => [$expression->operand],
        };
        foreach ($operands as $operand) {
            if ($operand instanceof BoundColumn && Path::hasWildcard($operand->path)) {
                return $operand;
            }
        }
        return null;
    }

    /**
     * A condition on a path with *: true if it is true for any element (friends.*.name = 'Alex'); negated (!=,
     * NOT IN, NOT BETWEEN, NOT LIKE) true if it is true for none, also for no elements. As in SQL, NULL if it is
     * true for none but unknown for one.
     */
    private function anyElement(Binary|InList|Between|Like $condition, BoundColumn $column, array $row, ?array $group): ?bool
    {
        $negated = $condition instanceof Binary ? '!=' === $condition->operator : $condition->negated;
        $object = $row[$column->alias] ?? null;
        $found = $this->evaluate($column, $row, $group);
        $elements = null === $found ? [] : (is_array($found) && array_is_list($found) ? $found : [$found]);

        $result = false;
        foreach ($elements as $element) {
            $literal = new Literal(null === $object ? $element : self::valueOf($element, $object));
            $positive = match (true) {
                $condition instanceof Binary => new Binary(
                    '!=' === $condition->operator ? '=' : $condition->operator,
                    $condition->left === $column ? $literal : $condition->left,
                    $condition->left === $column ? $condition->right : $literal,
                ),
                $condition instanceof InList => new InList($literal, $condition->values, false),
                $condition instanceof Between => new Between($literal, $condition->low, $condition->high, false),
                $condition instanceof Like => new Like($literal, $condition->pattern, false),
            };
            $result = Values::or($result, Values::truth($this->evaluate($positive, $row, $group)));
            if (true === $result) {
                break;
            }
        }
        return $negated ? Values::not($result) : $result;
    }
}
