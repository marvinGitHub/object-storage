<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\SPL\SplObjectStorage;
use stdClass;

class SplObjectStorageTest extends TestCase
{
    public function testClear()
    {
        $storage = new SplObjectStorage();
        $storage->attach(new stdClass());
        $storage->attach(new stdClass());
        $storage->clear();
        $this->assertCount(0, $storage);
    }
}