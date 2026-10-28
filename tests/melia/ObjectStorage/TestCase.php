<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\ObjectStorage;
use PHPUnit\Framework\MockObject\MockObject;

class TestCase extends \PHPUnit\Framework\TestCase
{
    protected WriterSpy $writerSpy;

    protected ObjectStorage|MockObject $storage;

    protected array $storageDirs = [];

    public function tearDown(): void
    {
        foreach ($this->storageDirs as $dir) {
            $this->tearDownDirectory($dir);
        }
    }

    protected function tearDownDirectory(string $path): void
    {
        if (is_dir($path)) {
            exec(sprintf('rm -rf %s', escapeshellarg($path)));
        }
    }

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->storage = $this->createStorage();

        // Create spy that wraps the real writer
        $this->writerSpy = new WriterSpy($this->storage->getWriter());
        $this->storage->setWriter($this->writerSpy);
    }

    protected function createTemporaryDirectory(): string
    {
        $tempDir = sys_get_temp_dir() . '/ObjectStorageTest_' . uniqid();
        mkdir($tempDir, 0777, true);
        return $tempDir;
    }

    protected function createTemporaryFile(): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'ObjectStorageTest');
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
        return $tempFile;
    }

    protected function reserveRandomDirectory(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'RandomDirectory');
        if (file_exists($path)) {
            unlink($path);
        }
        mkdir($path);
        return $path;
    }

    /**
     * Places a file directly (bypassing the storage, e.g. to simulate corrupted data), creating its directory.
     */
    protected function putFile(string $filename, string $content): void
    {
        if (!is_dir(dirname($filename))) {
            mkdir(dirname($filename), 0777, true);
        }
        file_put_contents($filename, $content);
    }

    protected function createStorage(): ObjectStorage
    {
        $dir = $this->createTemporaryDirectory();
        $this->storageDirs[] = $dir;
        return new ObjectStorage($dir);
    }
}
