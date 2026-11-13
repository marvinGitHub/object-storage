<?php

namespace melia\ObjectStorage\Search;

/**
 * An index of a string field for patterns (LIKE, CONTAINS), chosen per field when an index is created: which parts
 * of the values are indexed, and so which patterns it narrows down.
 *
 *   new Field('name', 'name', filterable: true, patterns: [PatternIndex::TRIE, PatternIndex::NGRAM])
 *
 * - TRIE ("trie"): the beginnings of the values (up to TRIE_MAX_LENGTH characters), for a prefix:
 *   LIKE 'Ho%' (exactly), LIKE 'Ho%a' (narrowed down);
 * - REVERSE_TRIE ("reverse-trie"): the ends of the values, for a suffix: LIKE '%son' (exactly), LIKE 'J%son';
 * - NGRAM ("ngram"): the parts of NGRAM_SIZE characters (trigrams), for a part anywhere: LIKE '%test%',
 *   CONTAINS 'test', LIKE '%a_test%b'; a pattern needs NGRAM_SIZE characters in a row without wildcards.
 *
 * The parts are stored, in lower case (patterns ignore case), as an additional filterable list of strings of the
 * document, see attribute(). A pattern names the documents having all parts of it (more than match where the parts
 * don't prove it, e.g. "tes" and "est" for "test", which the pattern itself is checked against then).
 */
enum PatternIndex: string
{
    case TRIE = 'trie';
    case REVERSE_TRIE = 'reverse-trie';
    case NGRAM = 'ngram';

    /** the longest beginning (end) stored by a (reverse) trie; longer prefixes are narrowed down by it */
    public const int TRIE_MAX_LENGTH = 32;

    /** the length of the parts stored by NGRAM */
    public const int NGRAM_SIZE = 3;

    /** the token of a wildcard of any characters, see tokenize(); no text of UTF-8 has the byte 0xFF */
    public const string ANY = "\xFF%";

    /** the token of a wildcard of one character, see tokenize() */
    public const string ONE = "\xFF_";

    /**
     * The name of the attribute with the parts of a field, e.g. "name__ngram".
     */
    public function attribute(string $field): string
    {
        return $field . '__' . match ($this) {
            self::TRIE => 'trie',
            self::REVERSE_TRIE => 'rtrie',
            self::NGRAM => 'ngram',
        };
    }

    /**
     * The parts of a value as stored, in lower case: of a text, or of each text of a list.
     *
     * @return list<string>
     */
    public function terms(mixed $value): array
    {
        $terms = [];
        foreach (is_array($value) ? $value : [$value] as $text) {
            if (!is_string($text) || '' === $text) {
                continue;
            }
            $characters = mb_str_split(self::fold($text), 1, 'UTF-8');
            $count = count($characters);
            switch ($this) {
                case self::TRIE:
                    for ($length = 1, $max = min($count, self::TRIE_MAX_LENGTH); $length <= $max; $length++) {
                        $terms[implode('', array_slice($characters, 0, $length))] = true;
                    }
                    break;
                case self::REVERSE_TRIE:
                    for ($length = 1, $max = min($count, self::TRIE_MAX_LENGTH); $length <= $max; $length++) {
                        $terms[implode('', array_slice($characters, -$length))] = true;
                    }
                    break;
                case self::NGRAM:
                    for ($i = 0; $i + self::NGRAM_SIZE <= $count; $i++) {
                        $terms[implode('', array_slice($characters, $i, self::NGRAM_SIZE))] = true;
                    }
                    break;
            }
        }
        // keys that are numbers are integers
        return array_map('strval', array_keys($terms));
    }

    /**
     * The parts every value matching a pattern has, as tokenize() reads it; null if this index can't tell (e.g. a
     * trie for '%son').
     *
     * @param list<string> $tokens
     * @return array{terms: list<string>, exact: bool}|null terms: the parts in lower case; exact: whether having
     *         the part is the same as matching the pattern (a single part, e.g. "ho" of a trie for 'Ho%')
     */
    public function candidates(array $tokens): ?array
    {
        $literals = array_values(array_filter($tokens, static fn(string $token): bool => self::ANY !== $token && self::ONE !== $token));
        switch ($this) {
            case self::TRIE:
            case self::REVERSE_TRIE:
                $trie = self::TRIE === $this;
                $edge = $trie ? ($tokens[0] ?? null) : ($tokens[count($tokens) - 1] ?? null);
                if (null === $edge || self::ANY === $edge || self::ONE === $edge) {
                    return null;
                }
                $characters = mb_str_split(self::fold($edge), 1, 'UTF-8');
                $term = implode('', $trie ? array_slice($characters, 0, self::TRIE_MAX_LENGTH) : array_slice($characters, -self::TRIE_MAX_LENGTH));
                $exact = count($characters) <= self::TRIE_MAX_LENGTH && ($trie ? [$edge, self::ANY] : [self::ANY, $edge]) === $tokens;
                return ['terms' => [$term], 'exact' => $exact];
            case self::NGRAM:
                $terms = [];
                foreach ($literals as $literal) {
                    foreach ($this->terms($literal) as $term) {
                        $terms[$term] = true;
                    }
                }
                if ([] === $terms) {
                    return null;
                }
                $terms = array_map('strval', array_keys($terms));
                $exact = 1 === count($literals) && [self::ANY, $literals[0], self::ANY] === $tokens && self::NGRAM_SIZE === mb_strlen(self::fold($literals[0]), 'UTF-8');
                return ['terms' => $terms, 'exact' => $exact];
        }
        return null;
    }

    /**
     * A LIKE pattern as tokens: the characters in a row without wildcards as they are (a backslash takes the next
     * character as it is), ANY for %, ONE for _; several % in a row are one.
     *
     * @return list<string>
     */
    public static function tokenize(string $pattern): array
    {
        $tokens = [];
        $literal = '';
        $characters = mb_str_split($pattern, 1, 'UTF-8');
        for ($i = 0, $count = count($characters); $i < $count; $i++) {
            $character = $characters[$i];
            if ('\\' === $character && $i + 1 < $count) {
                $literal .= $characters[++$i];
                continue;
            }
            if ('%' !== $character && '_' !== $character) {
                $literal .= $character;
                continue;
            }
            if ('' !== $literal) {
                $tokens[] = $literal;
                $literal = '';
            }
            $token = '%' === $character ? self::ANY : self::ONE;
            if (self::ANY !== $token || self::ANY !== ($tokens[count($tokens) - 1] ?? null)) {
                $tokens[] = $token;
            }
        }
        if ('' !== $literal) {
            $tokens[] = $literal;
        }
        return $tokens;
    }

    /**
     * The tokens of CONTAINS 'text': the text anywhere.
     *
     * @return list<string>
     */
    public static function tokenizeContains(string $text): array
    {
        return '' === $text ? [self::ANY] : [self::ANY, $text, self::ANY];
    }

    /**
     * A text in lower case, as patterns compare it (OQL's LIKE does the same).
     */
    public static function fold(string $text): string
    {
        return mb_strtolower($text, 'UTF-8');
    }
}
