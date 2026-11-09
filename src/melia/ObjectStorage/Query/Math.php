<?php

namespace melia\ObjectStorage\Query;

/**
 * The math functions and operators of OQL, as in MySQL: arguments are numbers (texts and dates as Values::number()
 * reads them), NULL gives NULL, and so does a result that isn't a finite number (SQRT(-1), LN(0), a division by 0).
 *
 * @internal Not part of the public API; may change in any release.
 */
final class Math
{
    /** name => [least, most arguments] */
    public const array FUNCTIONS = [
        'CEIL' => [1, 1], 'CEILING' => [1, 1], 'FLOOR' => [1, 1], 'TRUNCATE' => [2, 2], 'MOD' => [2, 2],
        'SIGN' => [1, 1], 'POW' => [2, 2], 'POWER' => [2, 2], 'SQRT' => [1, 1], 'EXP' => [1, 1],
        'LN' => [1, 1], 'LOG' => [1, 2], 'LOG10' => [1, 1], 'LOG2' => [1, 1],
        'PI' => [0, 0], 'RAND' => [0, 0],
        'SIN' => [1, 1], 'COS' => [1, 1], 'TAN' => [1, 1], 'COT' => [1, 1],
        'ASIN' => [1, 1], 'ACOS' => [1, 1], 'ATAN' => [1, 2], 'ATAN2' => [2, 2],
        'DEGREES' => [1, 1], 'RADIANS' => [1, 1], 'CRC32' => [1, 1], 'CONV' => [3, 3],
    ];

    /**
     * @param list<mixed> $arguments The values of the arguments.
     */
    public static function call(string $name, array $arguments): int|float|string|null
    {
        if ('PI' === $name) {
            return M_PI;
        }
        if ('RAND' === $name) {
            return mt_rand() / (mt_getrandmax() + 1);
        }
        if ('CRC32' === $name) {
            $text = Values::text($arguments[0]);
            return null === $text ? null : crc32($text);
        }
        if ('CONV' === $name) {
            return self::conv($arguments[0], $arguments[1], $arguments[2]);
        }

        $numbers = array_map([Values::class, 'number'], $arguments);
        if (in_array(null, $numbers, true)) {
            return null;
        }
        [$x, $y] = $numbers + [null, null];

        return self::finite(match ($name) {
            'CEIL', 'CEILING' => self::integral(ceil($x), $x),
            'FLOOR' => self::integral(floor($x), $x),
            'TRUNCATE' => self::truncate($x, (int)$y),
            'MOD' => self::mod($x, $y),
            'SIGN' => $x <=> 0,
            'POW', 'POWER' => self::pow($x, $y),
            'SQRT' => $x < 0 ? null : sqrt($x),
            'EXP' => exp($x),
            'LN' => $x <= 0 ? null : log($x),
            // LOG(x) is the natural logarithm, LOG(base, x) of a base (> 0, not 1)
            'LOG' => null === $y ? ($x <= 0 ? null : log($x)) : ($x <= 0 || 1 == $x || $y <= 0 ? null : log($y) / log($x)),
            'LOG10' => $x <= 0 ? null : log10($x),
            'LOG2' => $x <= 0 ? null : log($x, 2),
            'SIN' => sin($x),
            'COS' => cos($x),
            'TAN' => tan($x),
            'COT' => 0 == tan($x) ? null : 1 / tan($x),
            'ASIN' => $x < -1 || $x > 1 ? null : asin($x),
            'ACOS' => $x < -1 || $x > 1 ? null : acos($x),
            // ATAN(y, x) is ATAN2(y, x)
            'ATAN' => null === $y ? atan($x) : atan2($x, $y),
            'ATAN2' => atan2($x, $y),
            'DEGREES' => rad2deg($x),
            'RADIANS' => deg2rad($x),
            default => throw new Exception\QueryException('Unknown function ' . $name),
        });
    }

    /**
     * a % b, MOD(a, b): the remainder, with the sign of the dividend (MOD(-7, 3) is -1); NULL for a divisor of 0.
     */
    public static function mod(int|float $a, int|float $b): int|float|null
    {
        if (0 == $b) {
            return null;
        }
        if (-1 == $b && is_int($a)) {
            return 0; // PHP_INT_MIN % -1 overflows
        }
        return is_int($a) && is_int($b) ? $a % $b : fmod($a, $b);
    }

    /**
     * a DIV b: the integer quotient, the fraction cut off (-7 DIV 2 is -3); NULL for a divisor of 0.
     */
    public static function div(int|float $a, int|float $b): ?int
    {
        if (0 == $b) {
            return null;
        }
        $quotient = is_int($a) && is_int($b) && -1 !== $b ? intdiv($a, $b) : $a / $b;
        if (!is_finite((float)$quotient) || abs($quotient) >= PHP_INT_MAX) {
            return null;
        }
        return (int)$quotient;
    }

    /** an integral float as an int (CEIL(1.5) is 2, not 2.0), if it fits */
    private static function integral(float $value, int|float $input): int|float
    {
        return is_int($input) ? $input : (is_finite($value) && abs($value) < PHP_INT_MAX ? (int)$value : $value);
    }

    /**
     * TRUNCATE(x, d): x with d digits after the point, cut off, not rounded (negative d: digits before the point
     * set to 0). The digits of the number as written (0.29, not 0.28999…).
     */
    private static function truncate(int|float $x, int $digits): int|float
    {
        if (is_int($x) && $digits >= 0) {
            return $x;
        }
        if ($digits < 0) {
            $factor = 10 ** min(-$digits, 18);
            $cut = $x < 0 ? -floor(-$x / $factor) * $factor : floor($x / $factor) * $factor;
            return is_int($x) ? (int)$cut : (float)$cut;
        }
        $text = (string)json_encode((float)$x);
        if (!preg_match('/^(-?\d+)(?:\.(\d+))?$/', $text, $match)) {
            // with an exponent: as a float
            $factor = 10 ** $digits;
            return ($x < 0 ? -floor(-$x * $factor) : floor($x * $factor)) / $factor;
        }
        $fraction = substr($match[2] ?? '', 0, $digits);
        return (float)($match[1] . ('' === $fraction ? '' : '.' . $fraction));
    }

    private static function pow(int|float $x, int|float $y): int|float|null
    {
        if (0 == $x && $y < 0) {
            return null;
        }
        $result = $x ** $y;
        return is_int($result) || is_finite($result) ? $result : null;
    }

    /**
     * CONV(number, from base, to base): a number written in one base (2 to 36) written in another, in capitals;
     * NULL for digits the base hasn't.
     */
    private static function conv(mixed $value, mixed $from, mixed $to): ?string
    {
        $text = Values::text($value);
        $fromBase = Values::number($from);
        $toBase = Values::number($to);
        if (null === $text || null === $fromBase || null === $toBase) {
            return null;
        }
        $fromBase = (int)$fromBase;
        $toBase = (int)$toBase;
        if ($fromBase < 2 || $fromBase > 36 || $toBase < 2 || $toBase > 36) {
            return null;
        }
        $digits = strtolower(trim($text));
        $negative = str_starts_with($digits, '-');
        $digits = ltrim($digits, '-');
        $allowed = substr('0123456789abcdefghijklmnopqrstuvwxyz', 0, $fromBase);
        if ('' === $digits || strspn($digits, $allowed) !== strlen($digits)) {
            return null;
        }
        return ($negative ? '-' : '') . strtoupper(base_convert($digits, $fromBase, $toBase));
    }

    /** NULL for a result that isn't a finite number */
    private static function finite(int|float|null $value): int|float|null
    {
        return is_float($value) && !is_finite($value) ? null : $value;
    }
}
