<?php

namespace melia\ObjectStorage\Search\Loupe;

use Loupe\Loupe\Internal\Filter\Lexer;

/**
 * Numbers in filters as Loupe takes them (loupe/loupe 1.1):
 * - negative numbers, which Loupe's filters reject (except in the geo filters), are written as strings, which Loupe
 *   compares as numbers with numeric attributes: "price > -7.5" becomes "price > '-7.5'", "price IN (-1, 2)"
 *   "price IN ('-1', 2)";
 * - BETWEEN, which takes no negative numbers and swaps bounds in the wrong order, is written as comparisons, as in
 *   SQL: "x BETWEEN 1 AND 5" becomes "(x >= 1 AND x <= 5)" (nothing for "BETWEEN 5 AND 1"), "x NOT BETWEEN 1 AND 5"
 *   "(x < 1 OR x > 5 OR x IS NULL)", true for missing values as in Loupe.
 * Geo filters stay as they are.
 */
final class FilterNumbers
{
    public static function rewrite(string $filter): string
    {
        if (!str_contains($filter, '-') && !str_contains($filter, 'BETWEEN')) {
            return $filter;
        }

        $lexer = new Lexer();
        $lexer->setInput($filter);
        $lexer->moveNext();
        $tokens = [];
        while (null !== $lexer->lookahead) {
            $lexer->moveNext();
            $tokens[] = ['type' => (int)$lexer->token->type, 'value' => (string)$lexer->token->value, 'position' => $lexer->token->position];
        }

        // the end of a token in the filter: the next one starts after it (strings with quotes doubled)
        $end = static function (int $i) use ($tokens, $filter): int {
            $start = $tokens[$i]['position'];
            if (Lexer::T_STRING === $tokens[$i]['type']) {
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
            return $start + strlen($tokens[$i]['value']);
        };
        $isNumber = static fn(int $i): bool => Lexer::T_FLOAT === ($tokens[$i]['type'] ?? null);
        $isMinus = static fn(int $i): bool => Lexer::T_MINUS === ($tokens[$i]['type'] ?? null) && Lexer::T_FLOAT === ($tokens[$i + 1]['type'] ?? null);
        // a number at $i: [text, index after it]; negative ones quoted
        $number = static fn(int $i): array => $isMinus($i)
            ? ["'-" . $tokens[$i + 1]['value'] . "'", $i + 2]
            : [$tokens[$i]['value'], $i + 1];

        $replacements = [];
        $geo = 0; // the depth of parentheses inside a geo filter
        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            if (in_array($token['type'], [Lexer::T_GEO_RADIUS, Lexer::T_GEO_BOUNDING_BOX], true)) {
                $geo = 1;
                $i++; // its "("
                continue;
            }
            if ($geo > 0) {
                // T_OPEN_PARENTHESIS and T_CLOSE_PARENTHESIS share their values with T_FALSE and T_TRUE
                $geo += '(' === $token['value'] ? 1 : (')' === $token['value'] ? -1 : 0);
                continue;
            }

            // [NOT] BETWEEN: as comparisons, which take negative numbers and keep their order
            if (Lexer::T_BETWEEN === $token['type']) {
                $negated = Lexer::T_NOT === ($tokens[$i - 1]['type'] ?? null);
                // the attribute: a field name (paths are resolved by the index before)
                $attributeAt = $i - ($negated ? 2 : 1);
                if (Lexer::T_ATTRIBUTE_NAME !== ($tokens[$attributeAt]['type'] ?? null)) {
                    continue; // not valid: Loupe reports it
                }
                $attribute = $tokens[$attributeAt]['value'];
                if (!($isNumber($i + 1) || $isMinus($i + 1))) {
                    continue; // not valid: Loupe reports it
                }
                [$from, $next] = $number($i + 1);
                if (Lexer::T_AND !== ($tokens[$next]['type'] ?? null) || !($isNumber($next + 1) || $isMinus($next + 1))) {
                    continue;
                }
                [$to, $after] = $number($next + 1);
                // NOT BETWEEN is true for missing values in Loupe, the comparisons aren't
                $condition = $negated
                    ? sprintf('(%1$s < %2$s OR %1$s > %3$s OR %1$s IS NULL)', $attribute, $from, $to)
                    : sprintf('(%1$s >= %2$s AND %1$s <= %3$s)', $attribute, $from, $to);
                $replacements[] = [$tokens[$attributeAt]['position'], $end($after - 1), $condition];
                $i = $after - 1;
                continue;
            }

            if ($isMinus($i)) {
                $replacements[] = [$token['position'], $end($i + 1), "'-" . $tokens[$i + 1]['value'] . "'"];
                $i++;
            }
        }

        foreach (array_reverse($replacements) as [$start, $stop, $text]) {
            $filter = substr_replace($filter, $text, $start, $stop - $start);
        }
        return $filter;
    }
}
