<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\File\IO\AdapterInterface;
use melia\ObjectStorage\File\Files;
use RuntimeException;

class FilesTest extends TestCase
{
    public function testWriteAndRead()
    {
        $filename = $this->createTemporaryFile();

        $files = new Files();
        $files->write($filename, 'test');
        $this->assertSame('test', $files->read($filename));

        $files->write($filename, '');
        $this->assertSame('', $files->read($filename));
    }

    public function testReadingAMissingFileGivesNull(): void
    {
        $this->assertNull((new Files())->read($this->createTemporaryDirectory() . '/missing'));
    }

    public function testReadingAnUnreadableFileFails(): void
    {
        // the file exists, but can't be read (e.g. held open by another program)
        $adapter = $this->createStub(AdapterInterface::class);
        $adapter->method('fileGetContents')->willReturn(false);
        $adapter->method('isFile')->willReturn(true);
        $files = new Files();
        $files->setIOAdapter($adapter);

        $this->expectException(IOException::class);
        $files->read('/data/a.obj');
    }

    public function testAtomicWriteWithTruncate()
    {
        $filename = $this->createTemporaryFile();
        $writer = new Files();

        $writer->write($filename, 'ABCDEFG');
        $this->assertStringEqualsFile($filename, 'ABCDEFG');

        $writer->write($filename, 'XYZ');
        $this->assertStringEqualsFile($filename, 'XYZ');
    }

    public function testMissingDirectoriesAreCreated(): void
    {
        $directory = $this->createTemporaryDirectory();
        $single = $directory . '/one/two/single.json';
        $groupData = $directory . '/three/a.obj';
        $groupMetadata = $directory . '/four/a.metadata';
        $writer = new Files();

        $writer->write($single, 'single');
        $writer->writeGroup([$groupData => 'data', $groupMetadata => 'metadata']);

        $this->assertStringEqualsFile($single, 'single');
        $this->assertStringEqualsFile($groupData, 'data');
        $this->assertStringEqualsFile($groupMetadata, 'metadata');
    }

    /**
     * A writer that "crashes" (throws without cleaning up) at the given commit of a group write.
     */
    private function createWriterCrashingAtCommit(int $crashAt): Files
    {
        return new class ($crashAt) extends Files {
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

        (new Files())->writeGroup([$a => 'new a', $b => 'new b']);

        $this->assertStringEqualsFile($a, 'new a');
        $this->assertStringEqualsFile($b, 'new b');
        $this->assertSame(['a.metadata', 'a.obj'], array_values(array_diff(scandir(dirname($a)), ['.', '..'])));
    }

    public function testAtomicWriteAllCreatesFilesThatDidNotExist(): void
    {
        $directory = $this->createTemporaryDirectory();
        $a = $directory . DIRECTORY_SEPARATOR . 'new.obj';

        (new Files())->writeGroup([$a => 'content']);

        $this->assertStringEqualsFile($a, 'content');
    }

    public function testAtomicWriteAllOverwritesLeftoverPendingFiles(): void
    {
        [$a, $b] = $this->createFilePair();

        // left over by an earlier interrupted write
        file_put_contents($a . Files::SUFFIX_PENDING, 'a much longer leftover content');

        (new Files())->writeGroup([$a => 'short', $b => 'new b']);

        $this->assertStringEqualsFile($a, 'short');
    }

    public function testCrashBetweenCommitsIsCompletedByRecover(): void
    {
        [$a, $b] = $this->createFilePair();

        try {
            $this->createWriterCrashingAtCommit(2)->writeGroup([$a => 'new a', $b => 'new b']);
            $this->fail('the simulated crash must interrupt the write');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertStringEqualsFile($a, 'new a');
        $this->assertStringEqualsFile($b, 'old b', 'the crash leaves the group inconsistent');

        $this->assertTrue((new Files())->recover([$a, $b]));

        $this->assertStringEqualsFile($a, 'new a');
        $this->assertStringEqualsFile($b, 'new b');
        $this->assertFileDoesNotExist($b . Files::SUFFIX_PENDING);
    }

    public function testCrashBeforeFirstCommitIsDiscardedByRecover(): void
    {
        [$a, $b] = $this->createFilePair();

        try {
            $this->createWriterCrashingAtCommit(1)->writeGroup([$a => 'new a', $b => 'new b']);
            $this->fail('the simulated crash must interrupt the write');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFileExists($a . Files::SUFFIX_PENDING);

        $this->assertFalse((new Files())->recover([$a, $b]));

        $this->assertStringEqualsFile($a, 'old a');
        $this->assertStringEqualsFile($b, 'old b');
        $this->assertFileDoesNotExist($a . Files::SUFFIX_PENDING);
        $this->assertFileDoesNotExist($b . Files::SUFFIX_PENDING);
    }

    public function testRecoverWithoutInterruptedWriteChangesNothing(): void
    {
        [$a, $b] = $this->createFilePair();

        $this->assertFalse((new Files())->recover([$a, $b]));

        $this->assertStringEqualsFile($a, 'old a');
        $this->assertStringEqualsFile($b, 'old b');
    }

    public function testCommitWithoutPendingFileThrowsAndKeepsFile(): void
    {
        $filename = $this->createTemporaryFile();
        $writer = new class extends Files {
            public function commitFile(string $filename): void
            {
                $this->commit($filename);
            }
        };
        $writer->write($filename, 'committed');

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
        $adapter = $this->createStub(AdapterInterface::class);
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
        $writer = new Files();
        $writer->setIOAdapter($this->createRecordingAdapter($calls));

        $writer->writeGroup(['/data/a.obj' => 'data', '/data/a.metadata' => 'metadata']);

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
        $writer = new Files();
        $writer->setIOAdapter($this->createRecordingAdapter($calls));

        $writer->writeGroup(['/one/a' => 'a', '/two/b' => 'b', '/one/c' => 'c']);

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
        $writer = new Files();
        $writer->setIOAdapter($this->createRecordingAdapter($calls, failWriteOf: '/data/a.metadata.pending'));

        try {
            $writer->writeGroup(['/data/a.obj' => 'data', '/data/a.metadata' => 'metadata']);
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
        $writer = new Files();
        $writer->setIOAdapter($this->createRecordingAdapter($calls, fsyncSucceeds: false));

        try {
            $writer->writeGroup(['/data/a.obj' => 'data']);
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
        $writer = new Files();
        $writer->setIOAdapter($this->createRecordingAdapter($calls));

        $writer->write('/data/classnames.json', '{}');

        $this->assertCount(4, $calls);
        $this->assertStringStartsWith('write /data/classnames.json' . Files::SUFFIX_STAGING, $calls[0]);
        $this->assertSame('fsync', $calls[1]);
        $this->assertStringEndsWith(' > /data/classnames.json', $calls[2]);
        $this->assertSame('fsyncDirectory /data', $calls[3]);
    }

    public function testNothingIsFlushedWithoutSync(): void
    {
        $calls = [];
        $writer = new Files(sync: false);
        $writer->setIOAdapter($this->createRecordingAdapter($calls));

        $writer->writeGroup(['/data/a.obj' => 'data', '/data/a.metadata' => 'metadata']);
        $writer->write('/data/classnames.json', '{}');

        $this->assertSame([], array_values(array_filter($calls, static fn(string $call) => str_starts_with($call, 'fsync'))));
    }

    public function testSyncCanBeDisabledAndEnabledBySetter(): void
    {
        $calls = [];
        $writer = new Files();
        $writer->setIOAdapter($this->createRecordingAdapter($calls));

        $this->assertTrue($writer->syncEnabled());

        $writer->setSyncEnabled(false);
        $this->assertFalse($writer->syncEnabled());
        $writer->writeGroup(['/data/a.obj' => 'data']);
        $this->assertNotContains('fsync', $calls);

        $writer->setSyncEnabled(true);
        $writer->writeGroup(['/data/a.obj' => 'data']);
        $this->assertContains('fsync', $calls);
    }

    public function testWritingWithoutSyncProducesSameResult(): void
    {
        [$a, $b] = $this->createFilePair();
        $writer = new Files(sync: false);

        $this->assertFalse($writer->syncEnabled());

        $writer->writeGroup([$a => 'new a', $b => 'new b']);

        $this->assertStringEqualsFile($a, 'new a');
        $this->assertStringEqualsFile($b, 'new b');
    }
}
