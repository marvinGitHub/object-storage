<?php

namespace melia\ObjectStorage\Query;

use DateTimeImmutable;
use DateTimeZone;
use melia\ObjectStorage\Query\Exception\QueryException;
use Throwable;

/**
 * The scalar functions of OQL (not the aggregates, not REF(), CREATED() and MODIFIED(), which need the row): of texts,
 * numbers (see Math) and dates. NOW() is the same for the whole statement, so one instance serves one statement.
 *
 * @internal part of Executor
 */
final class Functions
{
    /** the units of DATE_ADD and DATE_SUB, as names of PHP's relative formats */
    private const array DATE_UNITS = ['SECOND' => 'second', 'MINUTE' => 'minute', 'HOUR' => 'hour', 'DAY' => 'day', 'WEEK' => 'week', 'MONTH' => 'month', 'YEAR' => 'year'];

    /** NOW(): the same for the whole statement */
    private ?DateValue $now = null;

    /**
     * The value of a function of the values of its arguments; most functions are NULL for a first argument NULL.
     *
     * @param list<mixed> $arguments
     * @throws QueryException If there is no such function, or an argument is invalid (a time zone, a unit).
     */
    public function call(string $name, array $arguments): mixed
    {
        [$first] = $arguments + [null];
        switch ($name) {
            case 'CONCAT':
                return self::concat('', $arguments);
            case 'CONCAT_WS':
                $separator = Values::text($first);
                return null === $separator ? null : self::concat($separator, array_slice($arguments, 1));
            case 'COALESCE':
                foreach ($arguments as $argument) {
                    if (null !== $argument) {
                        return $argument;
                    }
                }
                return null;
            case 'IFNULL':
                return $arguments[0] ?? $arguments[1];
            case 'PI':
            case 'RAND':
                return Math::call($name, []);
            case 'NOW':
                // the same for the whole statement
                return $this->now ??= new DateValue(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        }
        if (null === $first) {
            return null;
        }
        switch ($name) {
            case 'LOWER':
                return mb_strtolower(Values::text($first), 'UTF-8');
            case 'UPPER':
                return mb_strtoupper(Values::text($first), 'UTF-8');
            case 'LENGTH':
                return mb_strlen(Values::text($first), 'UTF-8');
            case 'CARDINALITY':
                // as in SQL for arrays (and Countable values); objects have no schema, so not a list (a text, a number) is NULL, not an error
                return is_countable($first) ? count($first) : null;
            case 'TRIM':
                return trim(Values::text($first), ' ');
            case 'ABS':
                $number = Values::number($first);
                return null === $number ? null : abs($number);
            case 'ROUND':
                $number = Values::number($first);
                $digits = isset($arguments[1]) ? (int)Values::number($arguments[1]) : 0;
                return null === $number ? null : round($number, $digits);
            case 'REGEXP_SUBSTR':
                $pattern = Values::text($arguments[1]);
                if (null === $pattern) {
                    return null;
                }
                $found = @preg_match(Values::regexp($pattern), Values::text($first), $match);
                return 1 === $found ? $match[0] : null;
            case 'REGEXP_REPLACE':
                $pattern = Values::text($arguments[1]);
                $replacement = Values::text($arguments[2]);
                if (null === $pattern || null === $replacement) {
                    return null;
                }
                // $1 or \1 for the groups
                return @preg_replace(Values::regexp($pattern), $replacement, Values::text($first));
            case 'SUBSTR':
                return self::substr(Values::text($first), $arguments[1], $arguments[2] ?? null);
            case 'DATETIME':
                return self::date($first, $arguments[1] ?? null);
            case 'DATE':
            case 'YEAR':
            case 'MONTH':
            case 'DAY':
            case 'HOUR':
            case 'MINUTE':
            case 'SECOND':
                return self::datePart($name, $first, $arguments[1] ?? null);
            case 'DATE_ADD':
                return self::dateAdd($first, $arguments[1], $arguments[2]);
            case 'DATE_SUB':
                return self::dateAdd($first, $arguments[1], $arguments[2], true);
        }
        if (isset(Math::FUNCTIONS[$name])) {
            return Math::call($name, $arguments);
        }
        throw new QueryException('Unknown function ' . $name);
    }

    /**
     * The values as texts, joined by a separator; as in PostgreSQL and SQLite, NULL is left out (|| gives NULL).
     */
    private static function concat(string $separator, array $values): string
    {
        return implode($separator, array_map([Values::class, 'text'], array_filter($values, static fn(mixed $v): bool => null !== $v)));
    }

    /**
     * SUBSTR(text, start, length): from 1; a negative start counts from the end.
     */
    private static function substr(?string $text, mixed $start, mixed $length): ?string
    {
        $start = (int)Values::number($start);
        $length = null === $length ? null : (int)Values::number($length);
        if (null !== $length && $length < 0) {
            return '';
        }
        return match (true) {
            $start > 0 => mb_substr($text, $start - 1, $length, 'UTF-8'),
            $start < 0 => mb_substr($text, $start, $length, 'UTF-8'),
            null === $length => $text,
            default => mb_substr($text, 0, max(0, $length - 1), 'UTF-8'),
        };
    }

    /**
     * DATE(), YEAR(), MONTH(), DAY(), HOUR(), MINUTE(), SECOND() of a date, in a time zone (UTC by default).
     */
    private static function datePart(string $name, mixed $value, mixed $timezone): string|int|null
    {
        $timezone = self::timezone($timezone);
        $date = self::date($value, $timezone)?->in($timezone);
        return null === $date ? null : match ($name) {
            'DATE' => $date->format('x-m-d'),
            'YEAR' => (int)$date->format('Y'),
            'MONTH' => (int)$date->format('n'),
            'DAY' => (int)$date->format('j'),
            'HOUR' => (int)$date->format('G'),
            'MINUTE' => (int)$date->format('i'),
            'SECOND' => (int)$date->format('s'),
            default => throw new QueryException('Unknown function ' . $name),
        };
    }

    /**
     * A value as a date: a date; a text that is one (in the time zone if it has no Z or offset); a number as seconds
     * since 1970-01-01 UTC. Null for anything else.
     */
    private static function date(mixed $value, mixed $timezone = null): ?DateValue
    {
        $value = Values::normalize($value);
        if ($value instanceof DateValue) {
            return $value;
        }
        if (is_string($value)) {
            return DateValue::fromText($value, self::timezone($timezone));
        }
        if ((is_int($value) || is_float($value)) && is_finite($value) && abs($value) < 1e12) {
            $date = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $value), new DateTimeZone('UTC'));
            return false === $date ? null : new DateValue($date);
        }
        return null;
    }

    /** the name of a time zone given to a date function, UTC if none */
    private static function timezone(mixed $timezone): string
    {
        if (null === $timezone) {
            return 'UTC';
        }
        $name = (string)Values::text($timezone);
        try {
            return (new DateTimeZone($name))->getName();
        } catch (Throwable) {
            throw new QueryException(sprintf("Unknown time zone '%s'", $name));
        }
    }

    /**
     * DATE_ADD(date, amount, unit): the date plus a whole number of seconds, minutes, hours, days, weeks, months or
     * years (negative to subtract), in the time zone of the date (a day is a day of the calendar, also across daylight
     * saving time). As in PHP and SQLite, a month that doesn't have the day goes on into the next (January 31 plus a
     * month is March 3, or 2 in a leap year). DATE_SUB(date, amount, unit) subtracts ($subtract).
     */
    private static function dateAdd(mixed $value, mixed $amount, mixed $unit, bool $subtract = false): ?DateValue
    {
        $function = $subtract ? 'DATE_SUB' : 'DATE_ADD';
        $date = self::date($value);
        $number = Values::number($amount);
        $unitName = Values::text($unit);
        if (null === $date || null === $number || null === $unitName) {
            return null;
        }
        $key = rtrim(strtoupper(trim($unitName)), 'S');
        if (!isset(self::DATE_UNITS[$key])) {
            throw new QueryException(sprintf("Unknown unit '%s' for %s: SECOND, MINUTE, HOUR, DAY, WEEK, MONTH or YEAR", $unitName, $function));
        }
        if (floor($number) != $number || abs($number) > 1e9) {
            throw new QueryException(sprintf('%s %s whole %ss, not %s', $function, $subtract ? 'subtracts' : 'adds', strtolower($key), $number));
        }
        if ($subtract) {
            $number = -$number;
        }
        $changed = $date->date->modify(sprintf('%+d %s', (int)$number, self::DATE_UNITS[$key]));
        return new DateValue($changed, $date->class);
    }
}
