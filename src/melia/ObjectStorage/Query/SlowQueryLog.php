<?php

namespace melia\ObjectStorage\Query;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

/**
 * Logs slow statements of OQL, as an observer of Oql: one JSON object per line (JSON Lines), with how long the
 * statement ran, how many objects it read for how many rows and how it found them — usually enough to see which
 * index is missing.
 *
 *     $oql = new Oql($storage, observer: new SlowQueryLog(__DIR__ . '/var/slow-queries.log', milliseconds: 500));
 *
 * A statement is logged if it ran at least $milliseconds, read at least $objectsRead objects (if set), or was stopped
 * (maximum runtime, cancelled). The statement is logged as written, with ? and :name but not the values of its
 * parameters; values written into it are logged, so pass personal data as parameters. A statement with the comment
 * no-slow-log in it (e.g. a maintenance script) isn't logged.
 *
 * The file is rotated at $maxBytes: the full file becomes "….1" (replacing the one before), so the log takes at
 * most about twice that. Writing the log never fails a statement: if it can't be written, the entry is lost.
 *
 * @experimental OQL is experimental in 1.x: its syntax, semantics and this API may change in a minor release (see docs/query.md).
 */
final readonly class SlowQueryLog
{
    public const float DEFAULT_MILLISECONDS = 1000.0;

    public const int DEFAULT_MAX_BYTES = 10 * 1024 * 1024;

    /**
     * @param string $file The log; its directory must exist.
     * @param float $milliseconds Log statements running at least this long.
     * @param int|null $objectsRead Also log statements reading at least this many objects, however fast; null: not.
     * @param int $maxBytes Rotate the file when it is this large.
     * @param array<string, mixed> $context Added to every entry, e.g. ['storage' => $directory].
     */
    public function __construct(
        public string $file,
        public float  $milliseconds = self::DEFAULT_MILLISECONDS,
        public ?int   $objectsRead = null,
        public int    $maxBytes = self::DEFAULT_MAX_BYTES,
        public array  $context = [],
    )
    {
    }

    public function __invoke(Statistics $statistics): void
    {
        if (!$this->isSlow($statistics)) {
            return;
        }
        try {
            $line = json_encode($this->entry($statistics), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        } catch (JsonException) {
            return;
        }
        $this->rotate();
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Whether a statement is logged.
     */
    public function isSlow(Statistics $statistics): bool
    {
        if (1 === preg_match('/no-slow-log/i', $statistics->query)) {
            return false;
        }
        return $statistics->milliseconds >= $this->milliseconds
            || (null !== $this->objectsRead && $statistics->objectsRead >= $this->objectsRead)
            || $statistics->interrupted;
    }

    /**
     * The entry of a statement, as logged.
     *
     * @return array<string, mixed>
     */
    public function entry(Statistics $statistics): array
    {
        return $this->context + [
                'time' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
                'milliseconds' => round($statistics->milliseconds, 1),
                'statement' => $statistics->statement,
                'objectsRead' => $statistics->objectsRead,
                'rows' => $statistics->rows,
                'fullScans' => (object)$statistics->fullScans(),
                'access' => $statistics->access,
                'dryRun' => $statistics->dryRun,
                'inTransaction' => $statistics->inTransaction,
                'error' => $statistics->error,
                'interrupted' => $statistics->interrupted,
                'pattern' => $statistics->pattern(),
                'query' => $statistics->query,
            ];
    }

    /**
     * The entries of a log, the oldest first (also those of the rotated file); lines that can't be read are left out.
     *
     * @return list<array<string, mixed>>
     */
    public static function read(string $file): array
    {
        $entries = [];
        foreach ([$file . '.1', $file] as $path) {
            if (!is_file($path)) {
                continue;
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $entry = json_decode($line, true);
                if (is_array($entry)) {
                    $entries[] = $entry;
                }
            }
        }
        return $entries;
    }

    private function rotate(): void
    {
        clearstatcache(true, $this->file);
        if (is_file($this->file) && (int)@filesize($this->file) >= $this->maxBytes) {
            @rename($this->file, $this->file . '.1');
        }
    }
}
