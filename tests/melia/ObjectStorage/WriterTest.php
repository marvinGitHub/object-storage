<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\File\IO\AdapterInterface;
use melia\ObjectStorage\File\Reader;
use melia\ObjectStorage\File\Writer;
use RuntimeException;

class WriterTest extends TestCase
{
    public function testWriteNull()
    {
        $filename = $this->createTemporaryFile();

        $writer = new Writer();
        $writer->atomicWrite($filename, 'test');

        $reader = new Reader();
        $this->assertEquals('test', $reader->read($filename));

        $writer->atomicWrite($filename, null);
        $this->assertEquals('', $reader->read($filename));
    }

    public function testAtomicWriteWithTruncate()
    {
        $filename = $this->createTemporaryFile();
        $writer = new Writer();

        $writer->atomicWrite($filename, 'ABCDEFG');
        $this->assertStringEqualsFile($filename, 'ABCDEFG');

        $writer->atomicWrite($filename, 'XYZ');
        $this->assertStringEqualsFile($filename, 'XYZ');
    }

    public function testMissingDirectoriesAreCreated(): void
    {
        $directory = $this->createTemporaryDirectory();
        $single = $directory . '/one/two/single.json';
        $groupData = $directory . '/three/a.obj';
        $groupMetadata = $directory . '/four/a.metadata';
        $writer = new Writer();

        $writer->atomicWrite($single, 'single');
        $writer->atomicWriteAll([$groupData => 'data', $groupMetadata => 'metadata']);

        $this->assertStringEqualsFile($single, 'single');
        $this->assertStringEqualsFile($groupData, 'data');
        $this->assertStringEqualsFile($groupMetadata, 'metadata');
    }

    /**
     * A writer that "crashes" (throws without cleaning up) at the given commit of a group write.
     */
    private function createWriterCrashingAtCommit(int $crashAt): Writer
    {
        return new class ($crashAt) extends Writer {
            private int $commits = 0;

            public function __construct(private readonly int $crashAt)
            {
                parent::__construct();
            }

            protected function commit(string $filename): void
            {
                if (++$this->commits === $this->crashAt) {
                    throw new RuntimeException('simulated crash');
                }
                parent::commit($filename);
            }
        };
    }

    /**
     * @return array{string, string} Two files in a fresh directory, holding "old a" and "old b".
     */
    private function createFilePair(): array
    {
        $directory = $this->createTemporaryDirectory();
        $a = $directory . DIRECTORY_SEPARATOR . 'a.obj';
        $b = $directory . DIRECTORY_SEPARATOR . 'a.metadata';
        file_put_contents($a, 'old a');
        file_put_contents($b, 'old b');

        return [$a, $b];
    }

    public function testAtomicWriteAllWritesAllFilesWithoutLeftovers(): void
    {
        [$a, $b] = $this->createFilePair();

        (new Writer())->atomicWriteAll([$a => 'new a', $b => 'new b']);

        $this->assertStringEqualsFile($a, 'new a');
        $this->assertStringEqualsFile($b, 'new b');
        $this->assertSame(['a.metadata', 'a.obj'], array_values(array_diff(scandir(dirname($a)), ['.', '..'])));
    }

    public function testAtomicWriteAllCreatesFilesThatDidNotExist(): void
    {
        $directory = $this->createTemporaryDirectory();
        $a = $directory . DIRECTORY_SEPARATOR . 'new.obj';

        (new Writer())->atomicWriteAll([$a => 'content']);

        $this->assertStringEqualsFile($a, 'content');
    }

    public function testAtomicWriteAllOverwritesLeftoverPendingFiles(): void
    {
        [$a, $b] = $this->createFilePair();

        // left over by an earlier interrupted write
        file_put_contents($a . Writer::SUFFIX_PENDING, 'a much longer leftover content');

        (new Writer())->atomicWriteAll([$a => 'short', $b => 'new b']);

        $this->assertStringEqualsFile($a, 'short');
    }

    public function testCrashBetweenCommitsIsCompletedByRecover(): void
    {
        [$a, $b] = $this->createFilePair();

        try {
            $this->createWriterCrashingAtCommit(2)->atomicWriteAll([$a => 'new a', $b => 'new b']);
            $this->fail('the simulated crash must interrupt the write');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertStringEqualsFile($a, 'new a');
        $this->assertStringEqualsFile($b, 'old b', 'the crash leaves the group inconsistent');

        $this->assertTrue((new Writer())->recover([$a, $b]));

        $this->assertStringEqualsFile($a, 'new a');
        $this->assertStringEqualsFile($b, 'new b');
        $this->assertFileDoesNotExist($b . Writer::SUFFIX_PENDING);
    }

    public function testCrashBeforeFirstCommitIsDiscardedByRecover(): void
    {
        [$a, $b] = $this->createFilePair();

        try {
            $this->createWriterCrashingAtCommit(1)->atomicWriteAll([$a => 'new a', $b => 'new b']);
            $this->fail('the simulated crash must interrupt the write');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFileExists($a . Writer::SUFFIX_PENDING);

        $this->assertFalse((new Writer())->recover([$a, $b]));

        $this->assertStringEqualsFile($a, 'old a');
        $this->assertStringEqualsFile($b, 'old b');
        $this->assertFileDoesNotExist($a . Writer::SUFFIX_PENDING);
        $this->assertFileDoesNotExist($b . Writer::SUFFIX_PENDING);
    }

    public function testRecoverWithoutInterruptedWriteChangesNothing(): void
    {
        [$a, $b] = $this->createFilePair();

        $this->assertFalse((new Writer())->recover([$a, $b]));

        $this->assertStringEqualsFile($a, 'old a');
        $this->assertStringEqualsFile($b, 'old b');
    }

    public function testCommitWithoutPendingFileThrowsAndKeepsFile(): void
    {
        $filename = $this->createTemporaryFile();
        $writer = new class extends Writer {
            public function commitFile(string $filename): void
            {
                $this->commit($filename);
            }
        };
        $writer->atomicWrite($filename, 'committed');

        try {
            $writer->commitFile($filename);
            $this->fail('committing without a pending file must fail');
        } catch (IOException) {
            // expected
        }

        $this->assertStringEqualsFile($filename, 'committed', 'a commit without pending file must not touch the file');
    }

    /**
     * Records the order of the adapter calls relevant for durability.
     */
    private function createRecordingAdapter(array &$calls, bool $fsyncSucceeds = true, ?string $failWriteOf = null): AdapterInterface
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('isDir')->willReturn(true); // the target directory exists
        $adapter->method('filePutContents')->willReturnCallback(static function (string $filename, string $data) use (&$calls, $failWriteOf) {
            $calls[] = 'write ' . $filename;
            return $filename === $failWriteOf ? 0 : strlen($data);
        });
        $adapter->method('fsyncFile')->willReturnCallback(static function () use (&$calls, $fsyncSucceeds) {
            $calls[] = 'fsync';
            return $fsyncSucceeds;
        });
        $adapter->method('fsyncDirectory')->willReturnCallback(static function (string $directory) use (&$calls) {
            $calls[] = 'fsyncDirectory ' . $directory;
            return true;
        });
        $adapter->method('isFile')->willReturn(true);
        $adapter->method('rename')->willReturnCallback(static function (string $from, string $to) use (&$calls) {
            $calls[] = 'rename ' . $from . ' > ' . $to;
            return true;
        });
        // like RealAdapter::renameSync(): rename, then flush the directory
        $adapter->method('renameSync')->willReturnCallback(static function (string $from, string $to) use (&$calls) {
            $calls[] = 'rename ' . $from . ' > ' . $to;
            $calls[] = 'fsyncDirectory ' . dirname($to);
            return true;
        });
        $adapter->method('unlink')->willReturnCallback(static function (string $filename) use (&$calls) {
            $calls[] = 'unlink ' . $filename;
            return true;
        });

        return $adapter;
    }

    public function testAtomicWriteAllWritesAllPendingFilesBeforeCommittingInOrder(): void
    {
        $calls = [];
        $writer = new Writer();
        $writer->setIOAdapter($this->createRecordingAdapter($calls));

        $writer->atomicWriteAll(['/data/a.obj' => 'data', '/data/a.metadata' => 'metadata']);

        // pending files are written directly (no staging file) and flushed before anything is committed;
        // their directory is flushed once for both, each commit is flushed before the next one
        $this->assertSame([
            'write /data/a.obj.pending',
            'fsync',
            'write /data/a.metadata.pending',
            'fsync',
            'fsyncDirectory /data',
            'rename /data/a.obj.pending > /data/a.obj',
            'fsyncDirectory /data',
            'rename /data/a.metadata.pending > /data/a.metadata',
            'fsyncDirectory /data',
        ], $calls);
    }

    public function testAtomicWriteAllFlushesEachDirectoryOfPendingFilesOnce(): void
    {
        $calls = [];
        $writer = new Writer();
        $writer->setIOAdapter($this->createRecordingAdapter($calls));

        $writer->atomicWriteAll(['/one/a' => 'a', '/two/b' => 'b', '/one/c' => 'c']);

        $firstCommit = array_search('rename /one/a.pending > /one/a', $calls, true);
        $beforeCommits = array_slice($calls, 0, $firstCommit);

        $this->assertSame(
            ['fsyncDirectory /one', 'fsyncDirectory /two'],
            array_values(array_filter($beforeCommits, static fn(string $call) => str_starts_with($call, 'fsyncDirectory')))
        );
    }

    public function testFailedWriteBeforeCommitDiscardsAllPendingFiles(): void
    {
        $calls = [];
        $writer = new Writer();
        $writer->setIOAdapter($this->createRecordingAdapter($calls, failWriteOf: '/data/a.metadata.pending'));

        try {
            $writer->atomicWriteAll(['/data/a.obj' => 'data', '/data/a.metadata' => 'metadata']);
            $this->fail('the failed write must fail the group');
        } catch (IOException) {
            // expected
        }

        $this->assertContains('unlink /data/a.obj.pending', $calls);
        $this->assertContains('unlink /data/a.metadata.pending', $calls);
        $this->assertSame([], array_values(array_filter($calls, static fn(string $call) => str_starts_with($call, 'rename'))));
    }

    public function testFailedFsyncFailsTheWriteWithoutCommitting(): void
    {
        $calls = [];
        $writer = new Writer();
        $writer->setIOAdapter($this->createRecordingAdapter($calls, fsyncSucceeds: false));

        try {
            $writer->atomicWriteAll(['/data/a.obj' => 'data']);
            $this->fail('a failed fsync must fail the write');
        } catch (IOException) {
            // expected
        }

        $this->assertContains('unlink /data/a.obj.pending', $calls);
        $this->assertSame([], array_values(array_filter($calls, static fn(string $call) => str_starts_with($call, 'rename'))));
    }

    public function testAtomicWriteIsFlushedBeforeRename(): void
    {
        $calls = [];
        $writer = new Writer();
        $writer->setIOAdapter($this->createRecordingAdapter($calls));

        $writer->atomicWrite('/data/classnames.json', '{}');

        $this->assertCount(4, $calls);
        $this->assertStringStartsWith('write /data/classnames.json' . Writer::SUFFIX_STAGING, $calls[0]);
        $this->assertSame('fsync', $calls[1]);
        $this->assertStringEndsWith(' > /data/classnames.json', $calls[2]);
        $this->assertSame('fsyncDirectory /data', $calls[3]);
    }

    public function testNothingIsFlushedWithoutSync(): void
    {
        $calls = [];
        $writer = new Writer(sync: false);
        $writer->setIOAdapter($this->createRecordingAdapter($calls));

        $writer->atomicWriteAll(['/data/a.obj' => 'data', '/data/a.metadata' => 'metadata']);
        $writer->atomicWrite('/data/classnames.json', '{}');

        $this->assertSame([], array_values(array_filter($calls, static fn(string $call) => str_starts_with($call, 'fsync'))));
    }

    public function testSyncCanBeDisabledAndEnabledBySetter(): void
    {
        $calls = [];
        $writer = new Writer();
        $writer->setIOAdapter($this->createRecordingAdapter($calls));

        $this->assertTrue($writer->syncEnabled());

        $writer->setSyncEnabled(false);
        $this->assertFalse($writer->syncEnabled());
        $writer->atomicWriteAll(['/data/a.obj' => 'data']);
        $this->assertNotContains('fsync', $calls);

        $writer->setSyncEnabled(true);
        $writer->atomicWriteAll(['/data/a.obj' => 'data']);
        $this->assertContains('fsync', $calls);
    }

    public function testWritingWithoutSyncProducesSameResult(): void
    {
        [$a, $b] = $this->createFilePair();
        $writer = new Writer(sync: false);

        $this->assertFalse($writer->syncEnabled());

        $writer->atomicWriteAll([$a => 'new a', $b => 'new b']);

        $this->assertStringEqualsFile($a, 'new a');
        $this->assertStringEqualsFile($b, 'new b');
    }
}
