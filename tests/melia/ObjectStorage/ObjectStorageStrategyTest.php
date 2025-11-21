<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Strategy\Standard;

class ObjectStorageStrategyTest extends TestCase {
    public function testStrategy() {
        $strategy = new Standard();
        $strategy->setChecksumAlgorithm('sha256');

        $this->storage->setStrategy($strategy);
        $uuid = $this->storage->store(new TestObject());
        $this->assertEquals('sha256', $this->storage->loadMetadata($uuid)->getChecksumAlgorithm());
    }
}
