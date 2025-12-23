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
}