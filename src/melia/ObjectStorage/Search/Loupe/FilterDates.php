<?php

namespace melia\ObjectStorage\Search\Loupe;

use Loupe\Loupe\Internal\Filter\Lexer;
use melia\ObjectStorage\Serialization\DateTimeValue;

/**
 * Dates in filters: date fields are indexed as the seconds since 1970-01-01 UTC, so a text that is a date compared
 * with one is written as that number: "createdAt >= '2026-01-01'" becomes "createdAt >= 1767225600", also in IN
 * lists and BETWEEN. Texts are read as in OQL (see DateTimeValue::parse()): '2026-01-01', '2026-01-01 10:00',
 * '2026-01-01T10:00:00.5Z', '2026-01-01T10:00:00+01:00'; without Z or offset in UTC. Numbers stay as they are.
 *
 * @internal Not part of the public API; may change in any release.
 */
final class FilterDates
{
    /** attribute-like words of the filter that aren't attributes (written by LoupeEngineIndex::expandPatterns()) */
    private const array OPERATORS = ['LIKE', 'CONTAINS'];

    /**
     * @param string[] $dateFields The names of the date fields.
     */
    public static function rewrite(string $filter, array $dateFields): string
    {
        if ([] === $dateFields || !str_contains($filter, "'")) {
            return $filter;
        }

        $lexer = new Lexer();
        $lexer->setInput($filter);
        $lexer->moveNext();

        $replacements = [];
        $attribute = null; // the attribute of the condition being read
        while (null !== $lexer->lookahead) {
            $lexer->moveNext();
            $token = $lexer->token;
            if (Lexer::T_ATTRIBUTE_NAME === $token->type && !in_array($token->value, self::OPERATORS, true)) {
                $attribute = (string)$token->value;
                continue;
            }
            if (Lexer::T_STRING !== $token->type || !in_array($attribute, $dateFields, true)) {
                continue;
            }
            $date = DateTimeValue::parse(trim((string)$token->value));
            if (null === $date) {
                continue; // not a date: Loupe compares it as it is
            }
            $replacements[] = [$token->position, self::endOfString($filter, $token->position), self::number(DateTimeValue::timestamp($date))];
        }

        foreach (array_reverse($replacements) as [$start, $end, $text]) {
            $filter = substr_replace($filter, $text, $start, $end - $start);
        }
        return $filter;
    }

    /** a number as written in a filter (negative ones are quoted by FilterNumbers after) */
    private static function number(int|float $number): string
    {
        return is_int($number) ? (string)$number : rtrim(rtrim(sprintf('%.6F', $number), '0'), '.');
    }

    /** the position after the string starting at $start (quotes doubled inside) */
    private static function endOfString(string $filter, int $start): int
    {
        for ($at = $start + 1, $length = strlen($filter); $at < $length; $at++) {
            if ("'" === $filter[$at]) {
                if ("'" === ($filter[$at + 1] ?? null)) {
                    $at++;
                    continue;
                }
                return $at + 1;
            }
        }
        return $length;
    }
}
