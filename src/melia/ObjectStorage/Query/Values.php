<?php

namespace melia\ObjectStorage\Query;

/**
 * The values of queries as in SQL (SQLite): NULL is unknown, so comparisons with it are unknown (null) and logic is
 * three-valued; booleans are the numbers 1 and 0; numbers are compared as numbers (1 = 1.0), strings by their bytes,
 * and numbers sort before strings. A number and a string that is a number ('97.65') are compared as numbers, as in
 * MySQL: objects have no schema, a property may hold numbers as text. Lists and objects (arrays) can't be compared,
 * only sorted (after strings).
 */
final class Values
{
    private const int NUMBER = 1;
    private const int STRING = 2;
    private const int ARRAY = 3;

    /** booleans as the numbers 1 and 0 */
    public static function normalize(mixed $value): mixed
    {
        return is_bool($value) ? (int)$value : $value;
    }

    private static function rank(mixed $value): int
    {
        return match (true) {
            is_int($value), is_float($value) => self::NUMBER,
            is_string($value) => self::STRING,
            default => self::ARRAY,
        };
    }

    /**
     * Compares two values: -1, 0 or 1, null if one of them is null or an array (unknown).
     */
    public static function compare(mixed $a, mixed $b): ?int
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        if (null === $a || null === $b || is_array($a) || is_array($b) || is_object($a) || is_object($b)) {
            return null;
        }
        return self::order($a, $b);
    }

    /**
     * The order of two values for sorting: null first, then numbers, strings, arrays.
     */
    public static function sortOrder(mixed $a, mixed $b): int
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        if (null === $a || null === $b) {
            return (null === $a ? 0 : 1) <=> (null === $b ? 0 : 1);
        }
        return self::order($a, $b);
    }

    private static function order(mixed $a, mixed $b): int
    {
        // a number and a number written as text: as numbers
        if (self::NUMBER === self::rank($a) && is_string($b) && is_numeric($b)) {
            $b = self::numberOf(trim($b));
        } elseif (self::NUMBER === self::rank($b) && is_string($a) && is_numeric($a)) {
            $a = self::numberOf(trim($a));
        }
        $rank = self::rank($a) <=> self::rank($b);
        if (0 !== $rank) {
            return $rank;
        }
        return match (self::rank($a)) {
            self::NUMBER => $a <=> $b,
            self::STRING => strcmp($a, $b) <=> 0,
            default => strcmp(json_encode($a), json_encode($b)) <=> 0,
        };
    }

    /**
     * A key of a value for grouping and DISTINCT: equal values (1 and 1.0, true and 1) have the same key; null has
     * its own (NULLs are grouped together, as in SQL).
     */
    public static function key(mixed $value): string
    {
        $value = self::normalize($value);
        return match (true) {
            null === $value => 'n',
            is_int($value) => 'd' . $value,
            is_float($value) => is_finite($value) && floor($value) === $value && abs($value) < 1e18
                ? 'd' . (int)$value
                : 'd' . sprintf('%.17g', $value),
            is_string($value) => 's' . $value,
            default => 'a' . json_encode($value),
        };
    }

    /**
     * A key of a value that equal values have for sure: numbers written as text like the numbers they are equal to
     * ('1.0' like 1). Different values may have the same key ('01' and '1'): to find candidates for = by hashing,
     * which are compared after.
     */
    public static function equalityKey(mixed $value): string
    {
        return is_string($value) && is_numeric($value) ? self::key(self::numberOf(trim($value))) : self::key($value);
    }

    /**
     * The truth of a value: null if unknown; numbers are true if not 0, strings by the number they start with.
     */
    public static function truth(mixed $value): ?bool
    {
        return match (true) {
            null === $value, is_array($value), is_object($value) => null,
            is_bool($value) => $value,
            is_int($value), is_float($value) => 0 != $value,
            default => 0.0 !== (float)$value,
        };
    }

    /**
     * A value as a number for arithmetic: strings by the number they start with (0 if none), null for null and
     * arrays.
     */
    public static function number(mixed $value): int|float|null
    {
        $value = self::normalize($value);
        return match (true) {
            is_int($value), is_float($value) => $value,
            is_string($value) => preg_match('/^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?/', $value, $match) ? self::numberOf(trim($match[0])) : 0,
            default => null,
        };
    }

    /** a number written as text: an integer if it is one and fits */
    private static function numberOf(string $text): int|float
    {
        $float = (float)$text;
        return preg_match('/^[+-]?\d+$/', $text) && abs($float) < PHP_INT_MAX ? (int)$text : $float;
    }

    /**
     * A value as text, e.g. for || and LIKE: numbers as written, floats with a fraction (2.0), booleans as 1 and 0.
     */
    public static function text(mixed $value): ?string
    {
        $value = self::normalize($value);
        return match (true) {
            null === $value => null,
            is_string($value) => $value,
            is_int($value) => (string)$value,
            is_float($value) => json_encode($value, JSON_PRESERVE_ZERO_FRACTION),
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    // ---- three-valued logic ----

    public static function and(?bool $a, ?bool $b): ?bool
    {
        if (false === $a || false === $b) {
            return false;
        }
        return null === $a || null === $b ? null : true;
    }

    public static function or(?bool $a, ?bool $b): ?bool
    {
        if (true === $a || true === $b) {
            return true;
        }
        return null === $a || null === $b ? null : false;
    }

    public static function not(?bool $a): ?bool
    {
        return null === $a ? null : !$a;
    }

    /**
     * Whether a value matches a LIKE pattern: % any characters, _ one, a backslash takes the next one as it is;
     * ignoring case (also of letters beyond ASCII).
     */
    public static function like(string $value, string $pattern): bool
    {
        static $regexes = [];
        if (!isset($regexes[$pattern])) {
            $regex = '';
            // ignoring case: pattern and value in lower case
            $characters = mb_str_split(mb_strtolower($pattern, 'UTF-8'));
            for ($i = 0, $count = count($characters); $i < $count; $i++) {
                $character = $characters[$i];
                if ('\\' === $character && $i + 1 < $count) {
                    $regex .= preg_quote($characters[++$i], '/');
                } else {
                    $regex .= match ($character) {
                        '%' => '.*',
                        '_' => '.',
                        default => preg_quote($character, '/'),
                    };
                }
            }
            if (count($regexes) > 1000) {
                $regexes = [];
            }
            $regexes[$pattern] = '/^' . $regex . '$/su';
        }
        return 1 === preg_match($regexes[$pattern], mb_strtolower($value, 'UTF-8'));
    }
}
