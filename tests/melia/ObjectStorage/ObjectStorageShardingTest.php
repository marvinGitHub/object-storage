<?php

namespace Tests\melia\ObjectStorage;

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
}