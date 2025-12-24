<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\File\IO\RealAdapter;

class RealAdapterTest extends TestCase
{
    public function testTouch()
    {
        $adapter = new RealAdapter();
        $filename = 'testfile.txt';
        $adapter->touch($filename);
        $this->assertFileExists($filename);
    }

    public function testMoveFile()
    {
        $adapter = new RealAdapter();
        $filename = 'testfile.txt';
        $path = $this->reserveRandomDirectory();
        $filenameNew = $path . '/' . $filename;

        $adapter->touch($filename);

        $this->assertFileExists($filename);
        $this->assertFalse(file_exists($filenameNew));

        $adapter->moveFile($filename, $path);

        $this->assertFileExists($filenameNew);
        $this->assertFalse(file_exists($filename));
    }
}