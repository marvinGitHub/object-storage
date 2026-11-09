<?php

namespace melia\ObjectStorage\Search;

use DateTimeInterface;
use melia\ObjectStorage\Serialization\DateTimeValue;

/**
 * The type of an indexed field. Loupe derives the type of an attribute from the first document and rejects
 * documents with another type, so every value is converted to the declared type. Values that can't be converted
 * without losing information (e.g. "abc" for a number) are indexed as null.
 */
enum FieldType: string
{
    case STRING = 'string';
    case NUMBER = 'number';
    case BOOLEAN = 'boolean';
    case STRING_ARRAY = 'array<string>';
    case NUMBER_ARRAY = 'array<number>';
    /** a point in time, indexed as the seconds since 1970-01-01 UTC (a number), from dates or texts that are dates */
    case DATE = 'date';
    case DATE_ARRAY = 'array<date>';

    /** whether values of the type are lists */
    public function isList(): bool
    {
        return in_array($this, [self::STRING_ARRAY, self::NUMBER_ARRAY, self::DATE_ARRAY], true);
    }

    /** whether the type is indexed as numbers (filters compare numbers) */
    public function isNumeric(): bool
    {
        return in_array($this, [self::NUMBER, self::NUMBER_ARRAY, self::DATE, self::DATE_ARRAY], true);
    }

    /**
     * Converts a stored value to this type.
     *
     * @param mixed $value The value as stored (references already replaced by their UUID, dates by
     *        DateTimeInterface).
     * @return mixed The converted value, or null if the value can't be converted.
     */
    public function convert(mixed $value): mixed
    {
        return match ($this) {
            self::STRING => static::toString($value),
            self::NUMBER => static::toNumber($value),
            self::BOOLEAN => static::toBoolean($value),
            self::STRING_ARRAY => static::toList($value, static fn(mixed $v): ?string => static::toString($v)),
            self::NUMBER_ARRAY => static::toList($value, static fn(mixed $v): int|float|null => static::toNumber($v)),
            self::DATE => static::toDate($value),
            self::DATE_ARRAY => static::toList($value, static fn(mixed $v): int|float|null => static::toDate($v)),
        };
    }

    /**
     * Guesses the type of a stored value, e.g. to suggest fields.
     */
    public static function guess(mixed $value): self
    {
        return match (true) {
            is_bool($value) => self::BOOLEAN,
            is_int($value), is_float($value) => self::NUMBER,
            $value instanceof DateTimeInterface => self::DATE,
            is_array($value) && array_is_list($value) && [] !== $value && array_all($value, static fn(mixed $v): bool => $v instanceof DateTimeInterface) => self::DATE_ARRAY,
            is_array($value) && array_is_list($value) && [] !== $value && array_all($value, static fn(mixed $v): bool => is_int($v) || is_float($v)) => self::NUMBER_ARRAY,
            is_array($value) && array_is_list($value) => self::STRING_ARRAY,
            default => self::STRING,
        };
    }

    private static function toString(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string)$value,
            // as stored: sorts in the order of time
            $value instanceof DateTimeInterface => DateTimeValue::utc($value),
            is_bool($value) => $value ? 'true' : 'false',
            default => null,
        };
    }

    private static function toNumber(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeValue::timestamp($value);
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return trim($value) + 0;
        }

        return null;
    }

    /**
     * A point in time as the seconds since 1970-01-01 UTC: of a date, of a text that is one (UTC without offset,
     * see DateTimeValue::parse()), or a number taken as such.
     */
    private static function toDate(mixed $value): int|float|null
    {
        return match (true) {
            $value instanceof DateTimeInterface => DateTimeValue::timestamp($value),
            is_int($value), is_float($value) => is_finite((float)$value) ? $value : null,
            is_string($value) => ($date = DateTimeValue::parse(trim($value))) ? DateTimeValue::timestamp($date) : null,
            default => null,
        };
    }

    private static function toBoolean(mixed $value): ?bool
    {
        return match (true) {
            is_bool($value) => $value,
            0 === $value, '0' === $value, 'false' === $value => false,
            1 === $value, '1' === $value, 'true' === $value => true,
            default => null,
        };
    }

    /**
     * Converts a list (or a single value) element by element; elements that can't be converted are left out.
     */
    private static function toList(mixed $value, callable $convert): ?array
    {
        if (null === $value) {
            return null;
        }

        $values = is_array($value) ? array_values($value) : [$value];
        $result = [];

        foreach ($values as $v) {
            $converted = $convert($v);
            if (null !== $converted) {
                $result[] = $converted;
            }
        }

        return $result;
    }
}
