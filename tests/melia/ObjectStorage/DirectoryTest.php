<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\File\Directory;

class DirectoryTest extends TestCase
{
    public function testTeardown()
    {
        $path = $this->reserveRandomDirectory();

        $directory = new Directory($path);
        $result = $directory->tearDown();
        $this->assertTrue($result);
    }

    public function testTeardownNonExistentDirectory()
    {
        $directory = new Directory('/some/non/existent/directory');
        $this->assertFalse($directory->tearDown());
    }

    public function testGetMaxDepth()
    {
        $path = $this->reserveRandomDirectory();
        $directory = new Directory($path);

        $this->assertSame(0, $directory->getMaxDirDepth());

        // Build a directory tree:
        // $path/
        //   a/           (depth 0)
        //     b/         (depth 1)
        //       c/       (depth 2)
        //         file   (depth 3)
        $a = $path . DIRECTORY_SEPARATOR . 'a';

        $this->assertTrue(mkdir($a, 0777, true), 'Failed to create nested directory structure.');
        $this->assertSame(1, $directory->getMaxDirDepth());

        $b = $a . DIRECTORY_SEPARATOR . 'b';

        $this->assertTrue(mkdir($b, 0777, true), 'Failed to create nested directory structure.');
        $this->assertSame(2, $directory->getMaxDirDepth());

        $c = $b . DIRECTORY_SEPARATOR . 'c';

        $this->assertTrue(mkdir($c, 0777, true), 'Failed to create nested directory structure.');
        $this->assertSame(3, $directory->getMaxDirDepth());

        touch($leaf = $c . DIRECTORY_SEPARATOR . 'file');
        $this->assertTrue(file_exists($leaf));

        $this->assertSame(3, $directory->getMaxDirDepth());
    }
}