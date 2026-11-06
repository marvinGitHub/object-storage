<?php

namespace melia\ObjectStorage\Search\Loupe;

use Loupe\Loupe\Internal\Filter\Lexer;
use melia\ObjectStorage\Search\Exception\SearchException;

/**
 * NOT before a condition or a group, which Loupe's filters lack (Loupe rejects it at the start of a filter and ignores
 * it after AND or OR): "NOT (a = 1 OR b IN ('x'))" is rewritten into the negated conditions, here
 * "(a != 1 AND b NOT IN ('x'))", following De Morgan's laws. NOT of IS NULL, IN, LIKE … (operators of a condition)
 * stays as it is.
 *
 * Negated: = and !=, IN and NOT IN, IS [NOT] NULL, IS [NOT] EMPTY, [NOT] LIKE, [NOT] CONTAINS; "x > 1" becomes
 * "(x <= 1 OR x IS NULL)" and "x BETWEEN 1 AND 2" "(x < 1 OR x > 2 OR x IS NULL)", as comparisons are false for
 * missing values in Loupe (= and IN too, but != and NOT IN are true for them). So NOT always matches exactly the
 * documents the condition doesn't match. The geo filters can't be negated.
 *
 * @internal Not part of the public API; may change in any release.
 */
final class FilterNegation
{
    /**
     * @throws SearchException If a condition can't be negated or the filter isn't valid.
     */
    public static function rewrite(string $filter): string
    {
        $tokens = static::tokenize($filter);
        if (!static::hasPrefixNot($tokens)) {
            return $filter;
        }

        self::$source = $filter;
        $position = 0;
        $node = static::parseOr($tokens, $position, $filter);
        if ($position < count($tokens)) {
            throw new SearchException(sprintf('Unexpected "%s" in the filter.', $tokens[$position]['value']));
        }
        return static::serialize($node, true);
    }

    /**
     * The tokens with their text in the filter: from their position up to the next token.
     *
     * @return list<array{type: int, value: string, text: string}>
     */
    private static function tokenize(string $filter): array
    {
        $lexer = new Lexer();
        $lexer->setInput($filter);
        $lexer->moveNext();
        $raw = [];
        while (null !== $lexer->lookahead) {
            $lexer->moveNext();
            $raw[] = $lexer->token;
        }

        $tokens = [];
        foreach ($raw as $i => $token) {
            $end = isset($raw[$i + 1]) ? $raw[$i + 1]->position : strlen($filter);
            $text = rtrim(substr($filter, $token->position, $end - $token->position));
            $tokens[] = [
                'type' => (int)$token->type,
                'value' => (string)$token->value,
                'text' => $text,
                'start' => $token->position,
                'end' => $token->position + strlen($text),
            ];
        }
        return $tokens;
    }

    /**
     * Whether NOT stands before a condition or a group: at the start, after AND, OR or "(".
     */
    private static function hasPrefixNot(array $tokens): bool
    {
        foreach ($tokens as $i => $token) {
            if (Lexer::T_NOT === $token['type']) {
                $previous = $tokens[$i - 1] ?? null;
                if (null === $previous || in_array($previous['type'], [Lexer::T_AND, Lexer::T_OR], true) || static::is($previous, Lexer::T_OPEN_PARENTHESIS, '(')) {
                    return true;
                }
            }
        }
        return false;
    }

    // T_TRUE and T_CLOSE_PARENTHESIS (as T_FALSE and T_OPEN_PARENTHESIS) share a value: compared with the text
    private static function is(?array $token, int $type, string $value): bool
    {
        return null !== $token && $type === $token['type'] && $value === $token['value'];
    }

    // ---- parsing into ['or'|'and', [nodes]], ['not', node] and ['condition', [tokens]] ----

    private static function parseOr(array $tokens, int &$position, string $filter): array
    {
        $nodes = [static::parseAnd($tokens, $position, $filter)];
        while (Lexer::T_OR === ($tokens[$position]['type'] ?? null)) {
            $position++;
            $nodes[] = static::parseAnd($tokens, $position, $filter);
        }
        return 1 === count($nodes) ? $nodes[0] : ['or', $nodes];
    }

    private static function parseAnd(array $tokens, int &$position, string $filter): array
    {
        $nodes = [static::parseUnary($tokens, $position, $filter)];
        while (Lexer::T_AND === ($tokens[$position]['type'] ?? null)) {
            $position++;
            $nodes[] = static::parseUnary($tokens, $position, $filter);
        }
        return 1 === count($nodes) ? $nodes[0] : ['and', $nodes];
    }

    private static function parseUnary(array $tokens, int &$position, string $filter): array
    {
        $token = $tokens[$position] ?? throw new SearchException('The filter ends too early.');
        if (Lexer::T_NOT === $token['type']) {
            $position++;
            return ['not', static::parseUnary($tokens, $position, $filter)];
        }
        if (static::is($token, Lexer::T_OPEN_PARENTHESIS, '(')) {
            $position++;
            $node = static::parseOr($tokens, $position, $filter);
            if (!static::is($tokens[$position] ?? null, Lexer::T_CLOSE_PARENTHESIS, ')')) {
                throw new SearchException('A ")" is missing in the filter.');
            }
            $position++;
            return $node;
        }
        return ['condition', static::readCondition($tokens, $position)];
    }

    /**
     * The tokens of a condition: up to AND, OR or ")" outside of parentheses; the AND of BETWEEN belongs to it.
     */
    private static function readCondition(array $tokens, int &$position): array
    {
        $condition = [];
        $depth = 0;
        $between = false;
        while (isset($tokens[$position])) {
            $token = $tokens[$position];
            if (0 === $depth) {
                if (Lexer::T_OR === $token['type'] || static::is($token, Lexer::T_CLOSE_PARENTHESIS, ')')) {
                    break;
                }
                if (Lexer::T_AND === $token['type']) {
                    if (!$between) {
                        break;
                    }
                    $between = false;
                }
            }
            if (Lexer::T_BETWEEN === $token['type']) {
                $between = true;
            } elseif (static::is($token, Lexer::T_OPEN_PARENTHESIS, '(')) {
                $depth++;
            } elseif (static::is($token, Lexer::T_CLOSE_PARENTHESIS, ')')) {
                $depth--;
            }
            $condition[] = $token;
            $position++;
        }
        if ([] === $condition) {
            throw new SearchException('A condition is missing in the filter.');
        }
        return $condition;
    }

    // ---- negating and writing ----

    private static function serialize(array $node, bool $top = false): string
    {
        return match ($node[0]) {
            'condition' => static::text($node[1]),
            'not' => static::serialize(static::negate($node[1]), $top),
            'and', 'or' => (function () use ($node, $top): string {
                $text = implode(' ' . strtoupper($node[0]) . ' ', array_map(static fn(array $child): string => static::serialize($child), $node[1]));
                return $top ? $text : '(' . $text . ')';
            })(),
        };
    }

    private static function negate(array $node): array
    {
        return match ($node[0]) {
            'not' => $node[1],
            'and' => ['or', array_map(static fn(array $child): array => static::negate($child), $node[1])],
            'or' => ['and', array_map(static fn(array $child): array => static::negate($child), $node[1])],
            'condition' => static::negateCondition($node[1]),
        };
    }

    /**
     * The text of tokens as written in the filter; a negated condition is one token of its own.
     */
    private static function text(array $tokens): string
    {
        if (1 === count($tokens) || !isset($tokens[0]['start'])) {
            return $tokens[0]['text'];
        }
        $last = $tokens[count($tokens) - 1];
        return substr(self::$source, $tokens[0]['start'], $last['end'] - $tokens[0]['start']);
    }

    /** the filter being rewritten */
    private static string $source = '';

    /**
     * @throws SearchException
     */
    private static function negateCondition(array $tokens): array
    {
        if (in_array($tokens[0]['type'], [Lexer::T_GEO_RADIUS, Lexer::T_GEO_BOUNDING_BOX], true)) {
            throw new SearchException(sprintf('NOT can\'t negate %s.', $tokens[0]['value']));
        }

        // the attribute, a path of names and dots
        $i = 0;
        while (isset($tokens[$i]) && (Lexer::T_ATTRIBUTE_NAME === $tokens[$i]['type'] || '.' === $tokens[$i]['value']) && !in_array($tokens[$i]['value'], LoupeEngineIndex::PATTERN_OPERATORS, true)) {
            $i++;
        }
        if (0 === $i) {
            throw new SearchException(sprintf('NOT can\'t negate the condition "%s".', static::text($tokens)));
        }
        $attribute = static::text(array_slice($tokens, 0, $i));
        $value = static fn(int $from): string => static::text(array_slice($tokens, $from));
        $at = static fn(int $offset): string => strtoupper($tokens[$i + $offset]['value'] ?? '');

        // comparisons and BETWEEN are false for missing values, so their negations are true for them: IS NULL;
        // = and IN are false for them too, and their negations != and NOT IN true already
        $orNull = static fn(string $condition): string => sprintf('(%s OR %s IS NULL)', $condition, $attribute);
        $condition = match (true) {
            '!' === $at(0) && '=' === $at(1) => "$attribute = " . $value($i + 2),
            '>' === $at(0) && '=' === $at(1) => $orNull("$attribute < " . $value($i + 2)),
            '<' === $at(0) && '=' === $at(1) => $orNull("$attribute > " . $value($i + 2)),
            '=' === $at(0) => "$attribute != " . $value($i + 1),
            '>' === $at(0) => $orNull("$attribute <= " . $value($i + 1)),
            '<' === $at(0) => $orNull("$attribute >= " . $value($i + 1)),
            'IN' === $at(0) => "$attribute NOT IN " . $value($i + 1),
            'NOT' === $at(0) && in_array($at(1), ['IN', ...LoupeEngineIndex::PATTERN_OPERATORS], true) => "$attribute " . $at(1) . ' ' . $value($i + 2),
            in_array($at(0), LoupeEngineIndex::PATTERN_OPERATORS, true) => "$attribute NOT " . $at(0) . ' ' . $value($i + 1),
            'IS' === $at(0) && 'NOT' === $at(1) => "$attribute IS " . $value($i + 2),
            'IS' === $at(0) => "$attribute IS NOT " . $value($i + 1),
            'BETWEEN' === $at(0) => static::negateBetween($attribute, array_slice($tokens, $i + 1)),
            default => throw new SearchException(sprintf('NOT can\'t negate the condition "%s".', static::text($tokens))),
        };

        return ['condition', [['type' => 0, 'value' => '', 'text' => $condition]]];
    }

    private static function negateBetween(string $attribute, array $tokens): string
    {
        foreach ($tokens as $i => $token) {
            if (Lexer::T_AND === $token['type']) {
                return sprintf('(%1$s < %2$s OR %1$s > %3$s OR %1$s IS NULL)', $attribute, static::text(array_slice($tokens, 0, $i)), static::text(array_slice($tokens, $i + 1)));
            }
        }
        throw new SearchException('BETWEEN needs AND.');
    }
}
