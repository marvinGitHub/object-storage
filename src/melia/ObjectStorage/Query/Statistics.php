<?php

namespace melia\ObjectStorage\Query;

use Throwable;

/**
 * What a statement did and cost (Result::$statistics, and given to the observer of Oql after every statement, also a
 * failed one): how long it ran, how many objects it read, how the objects of each source were found.
 *
 * Many objects read for few rows, or all objects of a large class read (fullScans()), usually mean an index is
 * missing; EXPLAIN tells which.
 *
 * @experimental OQL is experimental in 1.x: its syntax, semantics and this API may change in a minor release (see docs/query.md).
 */
final readonly class Statistics
{
    /** how the objects of a source are found when no index can: Access reads all objects of the class */
    public const string ALL_OBJECTS = 'all objects of the class';

    /**
     * @param string $query The statement as written (with ? and :name, without the values of the parameters).
     * @param string|null $statement SELECT, EXPLAIN, INSERT, UPDATE, DELETE, ALTER, START TRANSACTION, COMMIT or
     *        ROLLBACK; null if it couldn't be read.
     * @param float $milliseconds How long it ran.
     * @param int $objectsRead The objects read from the storage: those of the sources and joins, and referenced ones
     *        read for paths (each once per statement, as long as it is kept).
     * @param int $rows The rows of a SELECT, the objects written by INSERT, UPDATE, DELETE and ALTER.
     * @param list<array{source: string, class: string, access: string}> $access How the objects of each source were
     *        found (see AccessChoice), in the order they were read; joins and UPDATE/DELETE included.
     * @param string|null $error Why it failed; null if it didn't.
     * @param bool $interrupted Whether it was stopped (QueryInterruptedException: maximum runtime, cancelled).
     */
    public function __construct(
        public string  $query,
        public ?string $statement,
        public float   $milliseconds,
        public int     $objectsRead,
        public int     $rows,
        public array   $access = [],
        public bool    $dryRun = false,
        public bool    $inTransaction = false,
        public ?string $error = null,
        public bool    $interrupted = false,
    )
    {
    }

    /**
     * The sources whose objects were all read (no index could find them), e.g. ['p' => 'App\Product'].
     *
     * @return array<string, string>
     */
    public function fullScans(): array
    {
        $scans = [];
        foreach ($this->access as $access) {
            if (self::ALL_OBJECTS === $access['access']) {
                $scans[$access['source']] = $access['class'];
            }
        }
        return $scans;
    }

    /**
     * The statement without its values: texts and numbers are ?, a list of them (?, …), comments and whitespace go,
     * keywords are in capitals. Statements differing only in their values have the same pattern, e.g. to group them
     * in a log. The statement itself if it can't be read.
     */
    public function pattern(): string
    {
        try {
            $tokens = Lexer::tokenize($this->query);
        } catch (Throwable) {
            return trim((string)preg_replace('/\s+/', ' ', $this->query));
        }
        $pattern = '';
        $previous = null;
        $previousFunction = false;
        foreach ($tokens as $i => $token) {
            if ($token->is(Lexer::END)) {
                break;
            }
            // a function: its name followed by "(" (not the class of INSERT INTO Class (…))
            $function = $token->is(Lexer::IDENTIFIER)
                && ($tokens[$i + 1] ?? null)?->is(Lexer::SYMBOL, '(')
                && !($tokens[$i - 1] ?? null)?->is(Lexer::KEYWORD, 'INTO');
            $text = match ($token->type) {
                Lexer::STRING, Lexer::NUMBER => '?',
                Lexer::PARAMETER => ctype_digit($token->value) ? '?' : ':' . $token->value,
                Lexer::IDENTIFIER => 1 !== preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $token->value)
                    ? '`' . str_replace('`', '``', $token->value) . '`'
                    // in capitals like the keywords
                    : ($function ? strtoupper($token->value) : $token->value),
                default => $token->value,
            };
            $glued = null === $previous
                || in_array($text, ['.', ',', ')', ']', ';', '['], true)
                || in_array($previous, ['.', '(', '['], true)
                || ('(' === $text && $previousFunction);
            $pattern .= ($glued ? '' : ' ') . $text;
            $previous = $text;
            $previousFunction = $function;
        }
        // a list of values is one value
        return (string)preg_replace('/\(\?(?:, \?)+\)/', '(?)', $pattern);
    }
}
