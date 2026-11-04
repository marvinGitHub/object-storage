<?php

namespace melia\ObjectStorage\Search;

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

    /**
     * Converts a stored value to this type.
     *
     * @param mixed $value The value as stored (references already replaced by their UUID).
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
            is_bool($value) => $value ? 'true' : 'false',
            default => null,
        };
    }

    private static function toNumber(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return trim($value) + 0;
        }

        return null;
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
