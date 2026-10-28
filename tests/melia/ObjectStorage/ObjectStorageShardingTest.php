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