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

    public function testTempnam()
    {
        $adapter = new RealAdapter();
        $this->assertNotFalse($path = $adapter->tempnam(sys_get_temp_dir(), 'test'));
        $this->assertFileExists($path);
        $this->assertTrue(is_file($path));
    }

    public function testFsyncFile(): void
    {
        $adapter = new RealAdapter();
        $filename = $this->createTemporaryFile();
        file_put_contents($filename, 'content');

        $this->assertTrue($adapter->fsyncFile($filename));
        $this->assertStringEqualsFile($filename, 'content', 'flushing must not change the content');
    }

    public function testFsyncFileFailsForMissingFile(): void
    {
        $adapter = new RealAdapter();

        $this->assertFalse($adapter->fsyncFile(sys_get_temp_dir() . '/does-not-exist-' . uniqid()));
    }

    public function testFsyncDirectory(): void
    {
        $adapter = new RealAdapter();

        $this->assertTrue($adapter->fsyncDirectory($this->reserveRandomDirectory()));
    }

    public function testMoveFileSyncCreatesTheDirectoryAndRenames(): void
    {
        $adapter = new RealAdapter();
        $directory = $this->reserveRandomDirectory();
        file_put_contents($directory . '/source', 'content');

        $this->assertTrue($adapter->moveFileSync($directory . '/source', $directory . '/new/dir', 'renamed'));

        $this->assertFileDoesNotExist($directory . '/source');
        $this->assertStringEqualsFile($directory . '/new/dir/renamed', 'content');
    }

    public function testMoveFileKeepsTheNameByDefault(): void
    {
        $adapter = new RealAdapter();
        $directory = $this->reserveRandomDirectory();
        file_put_contents($directory . '/source', 'content');

        $this->assertTrue($adapter->moveFile($directory . '/source', $directory . '/target/'));

        $this->assertStringEqualsFile($directory . '/target/source', 'content');
    }

    public function testRenameSyncReplacesTheTarget(): void
    {
        $adapter = new RealAdapter();
        $directory = $this->reserveRandomDirectory();
        file_put_contents($directory . '/new', 'new');
        file_put_contents($directory . '/target', 'old');

        $this->assertTrue($adapter->renameSync($directory . '/new', $directory . '/target'));

        $this->assertFileDoesNotExist($directory . '/new');
        $this->assertStringEqualsFile($directory . '/target', 'new');
    }

    public function testRenameSyncFailsForMissingSource(): void
    {
        $adapter = new RealAdapter();
        $directory = $this->reserveRandomDirectory();

        $this->assertFalse(@$adapter->renameSync($directory . '/missing', $directory . '/target'));
    }

    public function testFsyncDirectoryFailsForMissingDirectory(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Directories are not flushed explicitly on Windows');
        }

        $adapter = new RealAdapter();

        $this->assertFalse($adapter->fsyncDirectory(sys_get_temp_dir() . '/does-not-exist-' . uniqid()));
    }
}