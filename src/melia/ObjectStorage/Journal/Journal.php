<?php

namespace melia\ObjectStorage\Journal;

use JsonException;
use melia\ObjectStorage\File\IO\AdapterInterface;
use melia\ObjectStorage\File\IO\RealAdapter;
use melia\ObjectStorage\Journal\Exception\CorruptedJournalException;
use melia\ObjectStorage\Journal\Exception\JournalException;

/**
 * An append-only journal in a directory of its own, kept as segment files of entries.
 *
 * Every entry is one line, "<crc32 of the JSON, 8 hex digits> <JSON>\n", the JSON being
 * {"s": sequence, "t": type, "ts": time, "p": payload}. A segment is named after the sequence number of its first
 * entry (20 digits, ".journal"); a new one is started once a segment would grow beyond the segment size.
 *
 * Crashes: entries are only ever appended, so an interrupted append can only damage the end of the last segment.
 * Such a torn end is ignored when reading and cut off by the next append. A damaged entry followed by intact ones
 * can't come from a crash and is reported as a CorruptedJournalException.
 *
 * Appends of several processes on the same host are serialized by a file lock (flock) on "journal.lock" in the
 * directory. File locks aren't reliable on network file systems: a journal written by processes of several hosts
 * needs a lock of its own around append().
 */
class Journal implements JournalInterface
{
    public const string SEGMENT_SUFFIX = '.journal';
    public const string LOCK_FILE = 'journal.lock';
    public const int DEFAULT_SEGMENT_SIZE = 16 * 1024 * 1024;

    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    private AdapterInterface $io;

    /** the first sequence number of the last segment, null without segments */
    private ?int $tailSegment = null;

    /** the size of the last segment when it was last read or written */
    private int $tailSize = 0;

    /** the length of the complete entries at the start of the last segment; less than $tailSize after a torn append */
    private int $tailValidLength = 0;

    private int $lastSequence = 0;

    /**
     * @param string $directory The directory of the journal; created by the first append.
     * @param AdapterInterface|null $io The file system access, by default the real one.
     * @param bool $sync Whether every append is flushed to disk (fsync), so it survives a power loss, not only a
     *        crash of the process.
     * @param int $segmentSize The size in bytes from which a new segment is started.
     */
    public function __construct(
        private readonly string $directory,
        ?AdapterInterface       $io = null,
        private readonly bool   $sync = true,
        private readonly int    $segmentSize = self::DEFAULT_SEGMENT_SIZE,
    )
    {
        $this->io = $io ?? new RealAdapter();
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    public function exists(): bool
    {
        return $this->io->isDir($this->directory);
    }

    public function append(string $type, array $payload = []): int
    {
        $this->ensureDirectory();

        return $this->withLock(function () use ($type, $payload): int {
            $this->refreshTail();

            // a torn append of a crashed process is cut off before appending behind it
            if ($this->tailValidLength < $this->tailSize && null !== $this->tailSegment) {
                $this->truncate($this->segmentPath($this->tailSegment), $this->tailValidLength);
                $this->tailSize = $this->tailValidLength;
            }

            $sequence = $this->lastSequence + 1;
            $line = $this->encodeLine($sequence, $type, $payload);

            $newSegment = null === $this->tailSegment
                || ($this->tailSize > 0 && $this->tailSize + strlen($line) > $this->segmentSize);
            $segment = $newSegment ? $sequence : $this->tailSegment;
            $path = $this->segmentPath($segment);

            $this->write($path, $line);

            if ($this->sync) {
                if (!$this->io->fsyncFile($path)) {
                    $this->forgetTail();
                    throw new JournalException('Unable to flush the journal to disk: ' . $path);
                }
                if ($newSegment) {
                    $this->io->fsyncDirectory($this->directory);
                }
            }

            if ($newSegment) {
                $this->tailSegment = $segment;
                $this->tailSize = 0;
            }
            $this->tailSize += strlen($line);
            $this->tailValidLength = $this->tailSize;
            $this->lastSequence = $sequence;

            return $sequence;
        });
    }

    public function read(int $afterSequence = 0): iterable
    {
        $segments = $this->segments();
        $firsts = array_keys($segments);
        $count = count($firsts);

        for ($i = 0; $i < $count; $i++) {
            // a segment whose entries all precede the requested ones is skipped unread
            if ($i + 1 < $count && $firsts[$i + 1] <= $afterSequence + 1) {
                continue;
            }

            [$entries] = $this->parseSegment($segments[$firsts[$i]], $firsts[$i], $i === $count - 1);
            foreach ($entries as $entry) {
                if ($entry->sequence > $afterSequence) {
                    yield $entry;
                }
            }
        }
    }

    public function lastSequence(): int
    {
        $this->refreshTail();

        return $this->lastSequence;
    }

    public function prune(int $upToSequence): int
    {
        if (!$this->exists()) {
            return 0;
        }

        return $this->withLock(function () use ($upToSequence): int {
            $segments = $this->segments();
            $firsts = array_keys($segments);
            $removed = 0;

            // the last segment is kept, it holds the last sequence number
            for ($i = 0; $i < count($firsts) - 1; $i++) {
                if ($firsts[$i + 1] - 1 > $upToSequence) {
                    break;
                }
                if (!$this->io->unlink($segments[$firsts[$i]])) {
                    throw new JournalException('Unable to remove the journal segment ' . $segments[$firsts[$i]]);
                }
                $removed++;
            }

            if ($removed > 0 && $this->sync) {
                $this->io->fsyncDirectory($this->directory);
            }

            return $removed;
        });
    }

    public function remove(): void
    {
        if (!$this->exists()) {
            $this->forgetTail();
            return;
        }

        foreach ($this->segments() as $path) {
            if ($this->io->fileExists($path) && !$this->io->unlink($path)) {
                throw new JournalException('Unable to remove the journal segment ' . $path);
            }
        }

        $lockFile = $this->directory . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        if ($this->io->fileExists($lockFile)) {
            $this->io->unlink($lockFile);
        }

        if (!@rmdir($this->directory) && $this->io->isDir($this->directory)) {
            throw new JournalException('Unable to remove the journal directory ' . $this->directory);
        }

        if ($this->sync) {
            $this->io->fsyncDirectory(dirname($this->directory));
        }

        $this->forgetTail();
    }

    /**
     * Encodes an entry as a line of a segment.
     *
     * @throws JournalException
     */
    private function encodeLine(int $sequence, string $type, array $payload): string
    {
        try {
            $json = json_encode(['s' => $sequence, 't' => $type, 'ts' => microtime(true), 'p' => $payload], self::JSON_FLAGS);
        } catch (JsonException $e) {
            throw new JournalException(sprintf('The entry "%s" can\'t be encoded as JSON: %s', $type, $e->getMessage()), 0, $e);
        }

        return hash('crc32b', $json) . ' ' . $json . "\n";
    }

    /**
     * Decodes a line of a segment (without its line break), null if it is damaged.
     */
    private function decodeLine(string $line): ?Entry
    {
        if (strlen($line) < 10 || ' ' !== $line[8]) {
            return null;
        }

        $json = substr($line, 9);
        if (!hash_equals(substr($line, 0, 8), hash('crc32b', $json))) {
            return null;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($data) || !is_int($data['s'] ?? null) || !is_string($data['t'] ?? null)
            || !is_array($data['p'] ?? null) || !is_numeric($data['ts'] ?? null)) {
            return null;
        }

        return new Entry($data['s'], $data['t'], $data['p'], (float)$data['ts']);
    }

    /**
     * Reads the entries of a segment.
     *
     * @param string $path The segment file.
     * @param int $firstSequence The sequence number of its first entry (its name).
     * @param bool $isLast Whether it is the last segment, the only one that may end torn.
     * @return array{0: list<Entry>, 1: int} The entries and the length of the complete entries in bytes.
     * @throws CorruptedJournalException
     * @throws JournalException
     */
    private function parseSegment(string $path, int $firstSequence, bool $isLast): array
    {
        $content = $this->io->fileGetContents($path);
        if (false === $content) {
            if (!$this->io->fileExists($path)) {
                return [[], 0]; // removed meanwhile, e.g. by prune()
            }
            throw new JournalException('Unable to read the journal segment ' . $path);
        }

        $entries = [];
        $expected = $firstSequence;
        $offset = 0;
        $length = strlen($content);

        while ($offset < $length) {
            $end = strpos($content, "\n", $offset);
            $entry = false === $end ? null : $this->decodeLine(substr($content, $offset, $end - $offset));

            if (null === $entry || $entry->sequence !== $expected) {
                // only a damaged line can be torn; an intact one out of sequence means entries were lost
                if (null === $entry && $isLast && !$this->hasIntactLineAfter($content, false === $end ? $length : $end + 1)) {
                    break; // a torn append at the end
                }
                throw new CorruptedJournalException(sprintf('The journal segment %s is damaged at byte %d (expected entry %d).', $path, $offset, $expected));
            }

            $entries[] = $entry;
            $expected++;
            $offset = $end + 1;
        }

        return [$entries, $offset];
    }

    /**
     * Whether an intact entry follows a position: then a damaged entry before it can't be a torn append.
     */
    private function hasIntactLineAfter(string $content, int $offset): bool
    {
        $length = strlen($content);
        while ($offset < $length) {
            $end = strpos($content, "\n", $offset);
            if (false === $end) {
                return false;
            }
            if (null !== $this->decodeLine(substr($content, $offset, $end - $offset))) {
                return true;
            }
            $offset = $end + 1;
        }

        return false;
    }

    /**
     * The segment files, by the sequence number of their first entry, in ascending order.
     *
     * @return array<int, string>
     */
    private function segments(): array
    {
        if (!$this->io->isDir($this->directory)) {
            return [];
        }

        $names = @scandir($this->directory);
        if (false === $names) {
            throw new JournalException('Unable to list the journal directory ' . $this->directory);
        }

        $segments = [];
        foreach ($names as $name) {
            if (1 === preg_match('/^(\d{20})' . preg_quote(self::SEGMENT_SUFFIX, '/') . '$/', $name, $match)) {
                $segments[(int)$match[1]] = $this->directory . DIRECTORY_SEPARATOR . $name;
            }
        }
        ksort($segments);

        return $segments;
    }

    private function segmentPath(int $firstSequence): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . sprintf('%020d', $firstSequence) . self::SEGMENT_SUFFIX;
    }

    /**
     * Brings the known state of the last segment up to date, if it changed since it was last read or written,
     * e.g. by another process.
     *
     * @throws JournalException
     */
    private function refreshTail(): void
    {
        $segments = $this->segments();
        if ([] === $segments) {
            $this->forgetTail();
            return;
        }

        $last = array_key_last($segments);
        $size = $this->io->fileSize($segments[$last]);
        if ($last === $this->tailSegment && $size === $this->tailSize) {
            return;
        }

        [$entries, $validLength] = $this->parseSegment($segments[$last], $last, true);
        $this->tailSegment = $last;
        $this->tailSize = is_int($size) ? $size : $validLength;
        $this->tailValidLength = $validLength;
        $this->lastSequence = [] === $entries ? $last - 1 : $entries[count($entries) - 1]->sequence;
    }

    private function forgetTail(): void
    {
        $this->tailSegment = null;
        $this->tailSize = 0;
        $this->tailValidLength = 0;
        $this->lastSequence = 0;
    }

    /**
     * @throws JournalException
     */
    private function ensureDirectory(): void
    {
        if ($this->io->isDir($this->directory)) {
            return;
        }

        if (!$this->io->mkdir($this->directory) && !$this->io->isDir($this->directory)) {
            throw new JournalException('Unable to create the journal directory ' . $this->directory);
        }

        if ($this->sync) {
            $this->io->fsyncDirectory(dirname($this->directory));
        }
    }

    /**
     * Appends a line to a segment.
     *
     * @throws JournalException
     */
    private function write(string $path, string $line): void
    {
        $handle = $this->io->fopen($path, 'ab');
        if (false === $handle) {
            throw new JournalException('Unable to open the journal segment ' . $path);
        }

        try {
            $written = $this->io->fwrite($handle, $line);
            if ($written !== strlen($line) || !$this->io->fflush($handle)) {
                // what was written is a torn append, cut off by the next append after reading the segment again
                $this->forgetTail();
                throw new JournalException('Unable to write to the journal segment ' . $path);
            }
        } finally {
            $this->io->fclose($handle);
        }
    }

    /**
     * @throws JournalException
     */
    private function truncate(string $path, int $length): void
    {
        $handle = $this->io->fopen($path, 'r+b');
        if (false === $handle) {
            throw new JournalException('Unable to open the journal segment ' . $path);
        }

        try {
            if (!$this->io->ftruncate($handle, $length)) {
                throw new JournalException('Unable to cut off the torn end of the journal segment ' . $path);
            }
        } finally {
            $this->io->fclose($handle);
        }

        if ($this->sync) {
            $this->io->fsyncFile($path);
        }
    }

    /**
     * Runs a callback under the exclusive file lock of the journal.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws JournalException
     */
    private function withLock(callable $callback): mixed
    {
        $lockFile = $this->directory . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        $handle = $this->io->fopen($lockFile, 'c');
        if (false === $handle) {
            throw new JournalException('Unable to open the journal lock ' . $lockFile);
        }

        try {
            if (!$this->io->flock($handle, LOCK_EX)) {
                throw new JournalException('Unable to lock the journal ' . $this->directory);
            }

            try {
                return $callback();
            } finally {
                $this->io->flock($handle, LOCK_UN);
            }
        } finally {
            $this->io->fclose($handle);
        }
    }
}
