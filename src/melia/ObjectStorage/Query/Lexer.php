<?php

namespace melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\Exception\QueryException;

/**
 * Splits a query into tokens: keywords (case-insensitive), identifiers (letters, digits, _ and \ for class names;
 * `quoted` or "quoted" for any other), strings ('…', '' for a quote), numbers, parameters (? and :name), operators
 * and punctuation. Comments (-- to the end of the line, and block comments) and whitespace are skipped.
 *
 * @internal Not part of the public API; may change in any release.
 */
final class Lexer
{
    public const string KEYWORD = 'keyword';
    public const string IDENTIFIER = 'identifier';
    public const string STRING = 'string';
    public const string NUMBER = 'number';
    public const string PARAMETER = 'parameter';
    public const string SYMBOL = 'symbol';
    public const string END = 'end';

    public const array KEYWORDS = [
        'SELECT', 'DISTINCT', 'FROM', 'AS', 'JOIN', 'INNER', 'LEFT', 'OUTER', 'ON', 'WHERE', 'GROUP', 'BY', 'HAVING',
        'ORDER', 'ASC', 'DESC', 'LIMIT', 'OFFSET', 'AND', 'OR', 'NOT', 'IS', 'NULL', 'IN', 'BETWEEN', 'LIKE', 'REGEXP', 'TRUE',
        'FALSE', 'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE', 'UUID',
    ];

    private const array SYMBOLS = ['<>', '!=', '<=', '>=', '||', '=', '<', '>', '+', '-', '*', '/', '%', '(', ')', '[', ']', ',', '.', ';'];

    /**
     * @return list<Token>
     * @throws QueryException
     */
    public static function tokenize(string $query): array
    {
        $tokens = [];
        $length = strlen($query);
        $i = 0;
        $positional = 0;
        while ($i < $length) {
            $c = $query[$i];

            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ('-' === $c && '-' === ($query[$i + 1] ?? '')) {
                $end = strpos($query, "\n", $i);
                $i = false === $end ? $length : $end + 1;
                continue;
            }
            if ('/' === $c && '*' === ($query[$i + 1] ?? '')) {
                $end = strpos($query, '*/', $i + 2);
                if (false === $end) {
                    throw QueryException::at('Unterminated comment', $query, $i);
                }
                $i = $end + 2;
                continue;
            }

            $start = $i;
            if ("'" === $c) {
                $value = '';
                $i++;
                while (true) {
                    if ($i >= $length) {
                        throw QueryException::at('Unterminated string', $query, $start);
                    }
                    if ("'" === $query[$i]) {
                        if ("'" === ($query[$i + 1] ?? '')) {
                            $value .= "'";
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $value .= $query[$i++];
                }
                $tokens[] = new Token(self::STRING, $value, $start);
                continue;
            }
            if ('`' === $c || '"' === $c) {
                $value = '';
                $i++;
                while (true) {
                    if ($i >= $length) {
                        throw QueryException::at('Unterminated quoted identifier', $query, $start);
                    }
                    if ($c === $query[$i]) {
                        if ($c === ($query[$i + 1] ?? '')) {
                            $value .= $c;
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $value .= $query[$i++];
                }
                if ('' === $value) {
                    throw QueryException::at('Empty identifier', $query, $start);
                }
                $tokens[] = new Token(self::IDENTIFIER, $value, $start);
                continue;
            }
            if (ctype_digit($c) || ('.' === $c && ctype_digit($query[$i + 1] ?? ''))) {
                if (!preg_match('/\G(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/', $query, $match, 0, $i)) {
                    throw QueryException::at('Invalid number', $query, $i);
                }
                $i += strlen($match[0]);
                if ($i < $length && (ctype_alpha($query[$i]) || '_' === $query[$i])) {
                    throw QueryException::at('Invalid number', $query, $start);
                }
                $tokens[] = new Token(self::NUMBER, $match[0], $start);
                continue;
            }
            if (ctype_alpha($c) || '_' === $c || '\\' === $c) {
                preg_match('/\G[A-Za-z0-9_\\\\]+/', $query, $match, 0, $i);
                $i += strlen($match[0]);
                $upper = strtoupper($match[0]);
                $tokens[] = in_array($upper, self::KEYWORDS, true)
                    ? new Token(self::KEYWORD, $upper, $start)
                    : new Token(self::IDENTIFIER, $match[0], $start);
                continue;
            }
            if ('?' === $c) {
                $tokens[] = new Token(self::PARAMETER, (string)$positional++, $start);
                $i++;
                continue;
            }
            if (':' === $c && preg_match('/\G:([A-Za-z_][A-Za-z0-9_]*)/', $query, $match, 0, $i)) {
                $tokens[] = new Token(self::PARAMETER, $match[1], $start);
                $i += strlen($match[0]);
                continue;
            }
            foreach (self::SYMBOLS as $symbol) {
                if (substr_compare($query, $symbol, $i, strlen($symbol)) === 0) {
                    $tokens[] = new Token(self::SYMBOL, '<>' === $symbol ? '!=' : $symbol, $start);
                    $i += strlen($symbol);
                    continue 2;
                }
            }
            throw QueryException::at(sprintf('Unexpected character "%s"', $c), $query, $i);
        }
        $tokens[] = new Token(self::END, '', $length);
        return $tokens;
    }
}
