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

    public function testTeardownRemovesNestedContents(): void
    {
        $path = $this->reserveRandomDirectory();
        mkdir($path . '/a/b/c', 0777, true);
        file_put_contents($path . '/a/file.txt', 'x');
        file_put_contents($path . '/a/b/c/.hidden', 'x');
        mkdir($path . '/empty');

        $this->assertTrue(Directory::remove($path));
        $this->assertDirectoryDoesNotExist($path);
        $this->assertFalse(Directory::remove($path), 'nothing left to remove');
    }

    public function testSizeCountsTheFilesOfAllSubdirectories(): void
    {
        $path = $this->reserveRandomDirectory();
        mkdir($path . '/a/b', 0777, true);
        mkdir($path . '/empty');
        file_put_contents($path . '/top.txt', str_repeat('x', 100));
        file_put_contents($path . '/a/b/deep.bin', str_repeat('y', 2048));

        $this->assertSame(2148, Directory::size($path));
        $this->assertSame(0, Directory::size($path . '/empty'));
        $this->assertSame(0, Directory::size($path . '/missing'), 'no directory, no size');

        Directory::remove($path);
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