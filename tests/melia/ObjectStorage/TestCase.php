<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\ObjectStorage;

class TestCase extends \PHPUnit\Framework\TestCase
{

    protected WriterSpy $writerSpy;

    protected ObjectStorage $storage;
    protected string $storageDir;

    public function tearDown(): void
    {
        $this->tearDownDirectory($this->storageDir);
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
        $this->storageDir = $this->createTemporaryDirectory();
        $this->storage = new ObjectStorage($this->storageDir);

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

    protected function reserveRandomStorageDirectory(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ObjectStorageSearchTest');
        if (file_exists($path)) {
            unlink($path);
        }
        mkdir($path);
        return $path;
    }
}
