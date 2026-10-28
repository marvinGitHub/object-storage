<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Strategy\Standard;

class ObjectStorageShardingTest extends TestCase
{
    public function testCreateShardingPath()
    {
        $uuid = '877d51df-aebd-4807-8701-95e007e9b701';
        $strategy = new Standard();
        $strategy->setShardDepth(2);
        $this->storage->setStrategy($strategy);

        $this->assertEquals($this->storage->getShardDir() . '/8/7/877d51df-aebd-4807-8701-95e007e9b701.obj', $this->storage->getFilePathData($uuid));
    }

    public function testSyncCanBeDisabledOnTheDefaultWriter(): void
    {
        $storage = new ObjectStorage($this->storage->getStorageDir());

        $storage->getWriter()->setSyncEnabled(false);

        // the default writer is kept, so the setting applies to later writes
        $this->assertSame($storage->getWriter(), $storage->getWriter());
        $this->assertFalse($storage->getWriter()->syncEnabled());

        $uuid = $storage->store(new ChildObject('child', 1));
        $this->assertSame('child', (new ObjectStorage($this->storage->getStorageDir()))->load($uuid)->title);
    }

    /**
     * @dataProvider provideSyncSettings
     */
    public function testRebuildingStubsMovesObjectsWithoutClassNameAside(bool $sync): void
    {
        $this->storage->getWriter()->setSyncEnabled($sync);
        $uuid = $this->storage->store(new ChildObject('child', 1));

        // corrupt the metadata: no class name
        $metadata = json_decode(file_get_contents($this->storage->getFilePathMetadata($uuid)), true);
        $metadata['className'] = '';
        file_put_contents($this->storage->getFilePathMetadata($uuid), json_encode($metadata));
        $this->storage->clearCache();

        $this->storage->rebuildStubs();

        $this->assertFileDoesNotExist($this->storage->getFilePathData($uuid));
        $this->assertFileDoesNotExist($this->storage->getFilePathMetadata($uuid));

        $moved = array_filter(
            iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storage->getStorageDir(), \FilesystemIterator::SKIP_DOTS))),
            static fn(\SplFileInfo $file) => str_starts_with($file->getFilename(), $uuid),
        );
        $this->assertCount(2, $moved, 'data and metadata are moved aside, not deleted');
    }

    public static function provideSyncSettings(): array
    {
        return ['with sync' => [true], 'without sync' => [false]];
    }

    public function testLookingUpUnknownObjectCreatesNoDirectories(): void
    {
        $uuid = '877d51df-aebd-4807-8701-95e007e9b701';
        $shardDirectory = $this->storage->buildShardedDirectory($uuid);

        $this->assertFalse($this->storage->exists($uuid));
        $this->assertNull($this->storage->loadMetadata($uuid));
        $this->storage->getFilePathData($uuid);
        $this->storage->getFilePathMetadata($uuid);

        $this->assertDirectoryDoesNotExist($shardDirectory);
    }

    public function testStoringCreatesShardDirectory(): void
    {
        $uuid = '877d51df-aebd-4807-8701-95e007e9b701';

        $this->storage->store(new ChildObject('child', 1), $uuid);

        $this->assertDirectoryExists($this->storage->buildShardedDirectory($uuid));
        $this->assertFileExists($this->storage->getFilePathData($uuid));
        $this->assertFileExists($this->storage->getFilePathMetadata($uuid));
    }
}