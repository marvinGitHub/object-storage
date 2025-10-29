<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\File\Reader;

class ReaderTest extends TestCase
{
    public function testReadOnNonExistingFile()
    {
        $filename = '/tmp/non-existing-file';
        $this->expectException(\melia\ObjectStorage\Exception\IOException::class);
        $this->expectExceptionMessage(sprintf('File does not exist: %s', $filename));

        $reader = new Reader();
        $reader->read($filename);
    }

    public function testRead()
    {
        $filename = __FILE__;
        $reader = new Reader();
        $this->assertEquals(file_get_contents($filename), $reader->read($filename));
    }
}