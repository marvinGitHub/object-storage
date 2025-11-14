<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\File\IO\RealAdapter;

class RealAdapterTest extends TestCase {
    public function testTouch()
    {
        $adapter = new RealAdapter();
        $filename = 'testfile.txt';
        $adapter->touch($filename);
        $this->assertFileExists($filename);
    }
}