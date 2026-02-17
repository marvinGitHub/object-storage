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

    public function testRenameFile()
    {
        $adapter = new RealAdapter();

        $dir = $this->reserveRandomDirectory();
        $from = $dir . '/from.txt';
        $to = $dir . '/to.txt';

        file_put_contents($from, 'hello');

        $this->assertFileExists($from);
        $this->assertFileDoesNotExist($to);

        $result = $adapter->rename($from, $to);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($from);
        $this->assertFileExists($to);
        $this->assertSame('hello', file_get_contents($to));
    }

    public function testRenameFileDoesNotFailWhenTargetAlreadyExists()
    {
        $adapter = new RealAdapter();

        $dir = $this->reserveRandomDirectory();
        $from = $dir . '/from.txt';
        $to = $dir . '/to.txt';

        file_put_contents($from, 'source');
        file_put_contents($to, 'target');

        $this->assertFileExists($from);
        $this->assertFileExists($to);

        $result = $adapter->rename($from, $to);

        $this->assertTrue($result);

        $this->assertFileDoesNotExist($from);
        $this->assertFileExists($to);
        $this->assertSame('source', file_get_contents($to));
    }
}