<?php

namespace Tests\melia\ObjectStorage;

use FilesystemIterator;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Util\Maintenance\ShardRebuilder;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class ShardRebuilderTest extends TestCase
{
    /**
     * @return string[] UUIDs of the stored objects
     */
    private function storeObjects(ObjectStorage $storage, int $count): array
    {
        $uuids = [];
        for ($i = 0; $i < $count; $i++) {
            $uuids[] = $storage->store(new ChildObject('Child ' . $i, $i));
        }
        return $uuids;
    }

    private function createRebuilder(ObjectStorage $storage): ShardRebuilder
    {
        $rebuilder = new ShardRebuilder();
        $rebuilder->setStorage($storage);
        return $rebuilder;
    }

    /**
     * @return array<int, int> depth below the shard directory => number of files
     */
    private function fileDepths(ObjectStorage $storage): array
    {
        $depths = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage->getShardDir(), FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $info) {
            $depth = $iterator->getDepth();
            $depths[$depth] = ($depths[$depth] ?? 0) + 1;
        }
        return $depths;
    }

    private function countDirectories(ObjectStorage $storage): int
    {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($storage->getShardDir(), FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $info) {
            $count += $info->isDir() ? 1 : 0;
        }
        return $count;
    }

    public function testRebuildInStepsMovesAllFiles(): void
    {
        $uuids = $this->storeObjects($this->storage, 30);
        $this->assertSame([2 => 60], $this->fileDepths($this->storage), 'data and metadata of 30 objects at depth 2');

        $this->strategy()->setShardDepth(3);
        $rebuilder = $this->createRebuilder($this->storage);

        // the progress counts objects; their data and metadata files are moved together
        $progress = $rebuilder->start();
        $this->assertSame(['running' => true, 'depth' => 3, 'total' => 30, 'processed' => 0, 'moved' => 0, 'percent' => 0, 'done' => false], array_diff_key($progress, ['startedAt' => true]));
        $this->assertTrue($this->storage->getStateHandler()->safeModeEnabled());

        $percents = [];
        while (!$progress['done']) {
            $progress = $rebuilder->step(12);
            $percents[] = $progress['percent'];
        }

        $this->assertSame([40, 80, 100], $percents);
        $this->assertSame(60, $progress['moved'], 'files moved');
        $this->assertFalse($rebuilder->isRunning());
        $this->assertFalse($this->storage->getStateHandler()->safeModeEnabled());

        $this->assertSame([3 => 60], $this->fileDepths($this->storage));
        $this->storage->clearCache();
        foreach ($uuids as $i => $uuid) {
            $this->assertSame($i, $this->storage->load($uuid)->value);
        }
    }

    public function testRebuildToALowerDepthRemovesEmptyDirectories(): void
    {
        $this->strategy()->setShardDepth(4);
        $uuids = $this->storeObjects($this->storage, 20);
        $directoriesBefore = $this->countDirectories($this->storage);

        $this->strategy()->setShardDepth(1);
        $progress = $this->createRebuilder($this->storage)->rebuildShards();

        $this->assertTrue($progress['done']);
        $this->assertSame([1 => 40], $this->fileDepths($this->storage));
        $this->assertLessThanOrEqual(16, $this->countDirectories($this->storage));
        $this->assertLessThan($directoriesBefore, $this->countDirectories($this->storage));

        $this->storage->clearCache();
        foreach ($uuids as $uuid) {
            $this->assertNotNull($this->storage->load($uuid));
        }
    }

    public function testFilesInDifferentDepthsAreFound(): void
    {
        // as left by an interrupted rebuild: some objects at depth 2, others at depth 3
        $uuids = $this->storeObjects($this->storage, 10);
        foreach (array_slice($uuids, 0, 5) as $uuid) {
            $deeper = $this->storage->getShardDir() . '/' . chunk_split(substr(str_replace('-', '', $uuid), 0, 3), 1, '/');
            @mkdir($deeper, 0777, true);
            foreach (glob(dirname($this->storage->getFilePathData($uuid)) . '/' . $uuid . '*') as $file) {
                rename($file, $deeper . basename($file));
            }
        }
        $this->assertEquals([2 => 10, 3 => 10], $this->fileDepths($this->storage));

        $this->strategy()->setShardDepth(1);
        $progress = $this->createRebuilder($this->storage)->rebuildShards(batchSize: 3);

        $this->assertSame(20, $progress['moved']);
        $this->assertSame([1 => 20], $this->fileDepths($this->storage));
        $this->storage->clearCache();
        foreach ($uuids as $uuid) {
            $this->assertNotNull($this->storage->load($uuid));
        }
    }

    public function testRebuildReportsProgressToACallback(): void
    {
        $this->storeObjects($this->storage, 10);
        $this->strategy()->setShardDepth(3);

        $reports = [];
        $this->createRebuilder($this->storage)->rebuildShards(static function (array $progress) use (&$reports): void {
            $reports[] = [$progress['processed'], $progress['total'], $progress['done']];
        }, batchSize: 8);

        $this->assertSame([[0, 10, false], [8, 10, false], [10, 10, true]], $reports);
    }

    public function testInterruptedRebuildIsContinuedByAnotherProcess(): void
    {
        $uuids = $this->storeObjects($this->storage, 10);
        $this->strategy()->setShardDepth(3);

        $first = $this->createRebuilder($this->storage);
        $first->start();
        $first->step(5);

        // meanwhile, the storage is in safe mode: reading works, writing doesn't
        $this->assertTrue($this->storage->getStateHandler()->safeModeEnabled());

        // another process, with the shard depth of its own configuration, continues with the depth of the rebuild
        $other = new ObjectStorage($this->storage->getStorageDir());
        $second = $this->createRebuilder($other);
        $this->assertTrue($second->isRunning());
        $this->assertSame(5, $second->getProgress()['processed']);

        $progress = $second->rebuildShards();

        $this->assertSame(10, $progress['processed']);
        $this->assertSame([3 => 20], $this->fileDepths($this->storage));
        $this->assertFalse($this->storage->getStateHandler()->safeModeEnabled());

        $this->storage->clearCache();
        foreach ($uuids as $uuid) {
            $this->assertNotNull($this->storage->load($uuid));
        }
    }

    public function testSafeModeEnabledBeforeStaysEnabled(): void
    {
        $this->storeObjects($this->storage, 3);
        $this->storage->getStateHandler()->enableSafeMode();
        $this->strategy()->setShardDepth(3);

        $this->createRebuilder($this->storage)->rebuildShards();

        $this->assertTrue($this->storage->getStateHandler()->safeModeEnabled());
    }

    public function testRebuildCantBeStartedTwice(): void
    {
        $this->storeObjects($this->storage, 3);
        $rebuilder = $this->createRebuilder($this->storage);
        $rebuilder->start();

        $this->expectException(RuntimeException::class);
        $rebuilder->start();
    }

    public function testEmptyStorage(): void
    {
        $progress = $this->createRebuilder($this->storage)->rebuildShards();

        $this->assertTrue($progress['done']);
        $this->assertSame(0, $progress['total']);
        $this->assertFalse($this->storage->getStateHandler()->safeModeEnabled());
    }
}
