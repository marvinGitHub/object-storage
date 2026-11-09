<?php

namespace Tests\melia\ObjectStorage\Journal;

use melia\ObjectStorage\File\IO\RealAdapter;
use melia\ObjectStorage\Journal\Entry;
use melia\ObjectStorage\Journal\Exception\CorruptedJournalException;
use melia\ObjectStorage\Journal\Exception\JournalException;
use melia\ObjectStorage\Journal\Journal;
use PHPUnit\Framework\TestCase;

final class JournalTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'JournalTest_' . uniqid('', true) . DIRECTORY_SEPARATOR . 'journal';
    }

    protected function tearDown(): void
    {
        $root = dirname($this->directory);
        if (is_dir($root)) {
            exec(sprintf('rm -rf %s', escapeshellarg($root)));
        }
    }

    /**
     * @return list<Entry>
     */
    private function entries(Journal $journal, int $after = 0): array
    {
        return iterator_to_array($journal->read($after), false);
    }

    /**
     * @return list<string>
     */
    private function segmentFiles(): array
    {
        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*' . Journal::SEGMENT_SUFFIX) ?: [];
        sort($files);

        return $files;
    }

    public function testAnEmptyJournalHasNoEntriesAndNoDirectory(): void
    {
        $journal = new Journal($this->directory);

        $this->assertFalse($journal->exists());
        $this->assertSame(0, $journal->lastSequence());
        $this->assertSame([], $this->entries($journal));
        $this->assertDirectoryDoesNotExist($this->directory);
    }

    public function testAppendedEntriesAreReadInOrderWithGaplessSequences(): void
    {
        $journal = new Journal($this->directory);

        $this->assertSame(1, $journal->append('begin', ['id' => 'tx']));
        $this->assertSame(2, $journal->append('before', ['uuid' => 'a', 'raw' => null]));
        $this->assertSame(3, $journal->append('commit'));

        $entries = $this->entries($journal);
        $this->assertSame([1, 2, 3], array_map(static fn(Entry $e) => $e->sequence, $entries));
        $this->assertSame(['begin', 'before', 'commit'], array_map(static fn(Entry $e) => $e->type, $entries));
        $this->assertSame(['uuid' => 'a', 'raw' => null], $entries[1]->payload);
        $this->assertSame([], $entries[2]->payload);
        $this->assertEqualsWithDelta(microtime(true), $entries[0]->time, 60);
        $this->assertTrue($journal->exists());
    }

    public function testPayloadsKeepTheirJsonTypes(): void
    {
        $journal = new Journal($this->directory);
        $payload = ['float' => 1.0, 'int' => 2, 'text' => "ä\"\n{}", 'list' => [1, 2], 'map' => ['k' => true], 'null' => null];

        $journal->append('data', $payload);

        $this->assertSame($payload, $this->entries($journal)[0]->payload);
    }

    public function testReadingAfterASequenceSkipsTheEarlierEntries(): void
    {
        $journal = new Journal($this->directory);
        foreach (range(1, 5) as $i) {
            $journal->append('e', ['i' => $i]);
        }

        $this->assertSame([4, 5], array_map(static fn(Entry $e) => $e->sequence, $this->entries($journal, 3)));
        $this->assertSame([], $this->entries($journal, 5));
    }

    public function testAnotherInstanceContinuesTheSequence(): void
    {
        (new Journal($this->directory))->append('a');
        (new Journal($this->directory))->append('b');

        $reopened = new Journal($this->directory);
        $this->assertSame(2, $reopened->lastSequence());
        $this->assertSame(3, $reopened->append('c'));
        $this->assertSame(['a', 'b', 'c'], array_map(static fn(Entry $e) => $e->type, $this->entries($reopened)));
    }

    public function testTwoInstancesAppendingAlternatelyDontReuseSequences(): void
    {
        $first = new Journal($this->directory);
        $second = new Journal($this->directory);

        $this->assertSame(1, $first->append('a'));
        $this->assertSame(2, $second->append('b'));
        $this->assertSame(3, $first->append('c'));
        $this->assertSame(3, $second->lastSequence());
    }

    public function testSegmentsAreStartedWhenTheSegmentSizeIsReached(): void
    {
        $journal = new Journal($this->directory, segmentSize: 200);
        foreach (range(1, 10) as $i) {
            $journal->append('entry', ['text' => str_repeat('x', 60)]);
        }

        $files = $this->segmentFiles();
        $this->assertGreaterThan(1, count($files));
        $this->assertSame(sprintf('%020d', 1) . Journal::SEGMENT_SUFFIX, basename($files[0]));
        $this->assertSame(range(1, 10), array_map(static fn(Entry $e) => $e->sequence, $this->entries($journal)));
        $this->assertSame([9, 10], array_map(static fn(Entry $e) => $e->sequence, $this->entries($journal, 8)));
        $this->assertSame(10, (new Journal($this->directory, segmentSize: 200))->lastSequence());
    }

    public function testAnEntryLargerThanTheSegmentSizeGetsASegmentOfItsOwn(): void
    {
        $journal = new Journal($this->directory, segmentSize: 50);
        $journal->append('big', ['text' => str_repeat('y', 500)]);
        $journal->append('small');

        $this->assertCount(2, $this->segmentFiles());
        $this->assertSame(['big', 'small'], array_map(static fn(Entry $e) => $e->type, $this->entries($journal)));
    }

    public function testATornLastEntryIsIgnoredAndCutOffByTheNextAppend(): void
    {
        $journal = new Journal($this->directory);
        $journal->append('a');
        $journal->append('b');

        // a crash in the middle of an append: half of a line without its line break
        $segment = $this->segmentFiles()[0];
        $intact = file_get_contents($segment);
        file_put_contents($segment, $intact . substr("00000000 {\"s\":3,\"t\":\"c\"", 0, 15));

        $reopened = new Journal($this->directory);
        $this->assertSame(2, $reopened->lastSequence());
        $this->assertSame(['a', 'b'], array_map(static fn(Entry $e) => $e->type, $this->entries($reopened)));

        $this->assertSame(3, $reopened->append('c'));
        $this->assertSame(['a', 'b', 'c'], array_map(static fn(Entry $e) => $e->type, $this->entries(new Journal($this->directory))));
        $this->assertStringStartsWith($intact, file_get_contents($segment));
    }

    public function testATornLastLineWithLineBreakIsIgnoredToo(): void
    {
        $journal = new Journal($this->directory);
        $journal->append('a');
        file_put_contents($this->segmentFiles()[0], "garbage\n", FILE_APPEND);

        $this->assertSame(1, (new Journal($this->directory))->lastSequence());
        $this->assertSame(2, (new Journal($this->directory))->append('b'));
    }

    public function testADamagedEntryBeforeIntactOnesIsReportedAsCorruption(): void
    {
        $journal = new Journal($this->directory);
        $journal->append('a', ['v' => 'original']);
        $journal->append('b');

        $segment = $this->segmentFiles()[0];
        file_put_contents($segment, str_replace('original', 'modified', file_get_contents($segment)));

        $this->expectException(CorruptedJournalException::class);
        $this->entries(new Journal($this->directory));
    }

    public function testAMissingEntryIsReportedAsCorruption(): void
    {
        $journal = new Journal($this->directory);
        $journal->append('a');
        $journal->append('b');
        $journal->append('c');

        $segment = $this->segmentFiles()[0];
        $lines = file($segment);
        unset($lines[1]);
        file_put_contents($segment, implode('', $lines));

        $this->expectException(CorruptedJournalException::class);
        $this->entries(new Journal($this->directory));
    }

    public function testPruneRemovesOnlyWholeSegmentsAndKeepsTheLast(): void
    {
        $journal = new Journal($this->directory, segmentSize: 120);
        foreach (range(1, 6) as $i) {
            $journal->append('entry', ['text' => str_repeat('z', 60)]);
        }
        $segmentsBefore = count($this->segmentFiles());
        $this->assertGreaterThan(2, $segmentsBefore);

        $this->assertSame(0, $journal->prune(0));

        $removed = $journal->prune(6);
        $this->assertSame($segmentsBefore - 1, $removed);
        $this->assertCount(1, $this->segmentFiles());
        $this->assertSame(6, (new Journal($this->directory))->lastSequence());
        $this->assertSame(7, $journal->append('next'));
        foreach ($this->entries($journal) as $entry) {
            $this->assertGreaterThan(1, $entry->sequence);
        }
    }

    public function testRemoveDeletesTheJournal(): void
    {
        $journal = new Journal($this->directory);
        $journal->append('a');

        $journal->remove();

        $this->assertDirectoryDoesNotExist($this->directory);
        $this->assertFalse($journal->exists());
        $this->assertSame(0, $journal->lastSequence());
        $journal->remove(); // removing again does nothing
    }

    public function testEveryAppendIsFlushedWithSync(): void
    {
        $io = new FsyncCountingAdapter();
        $journal = new Journal($this->directory, $io);
        $journal->append('a');
        $journal->append('b');

        $this->assertSame(2, $io->fileSyncs);
    }

    public function testNothingIsFlushedWithoutSync(): void
    {
        $io = new FsyncCountingAdapter();
        $journal = new Journal($this->directory, $io, sync: false);
        $journal->append('a');

        $this->assertSame(0, $io->fileSyncs);
    }

    public function testAFailedFlushIsReported(): void
    {
        $io = new FsyncCountingAdapter();
        $io->failSync = true;

        $this->expectException(JournalException::class);
        (new Journal($this->directory, $io))->append('a');
    }

    public function testPayloadsThatArentJsonAreRefused(): void
    {
        $this->expectException(JournalException::class);
        (new Journal($this->directory))->append('a', ['bad' => "\xB1\x31"]);
    }
}

/**
 * Counts the files flushed to disk, and can fail flushing.
 */
class FsyncCountingAdapter extends RealAdapter
{
    public int $fileSyncs = 0;
    public bool $failSync = false;

    public function fsyncFile(string $filename): bool
    {
        if ($this->failSync) {
            return false;
        }
        $this->fileSyncs++;
        return parent::fsyncFile($filename);
    }
}
