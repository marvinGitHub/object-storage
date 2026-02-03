<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\File\Reader;
use melia\ObjectStorage\File\Writer;

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
}
