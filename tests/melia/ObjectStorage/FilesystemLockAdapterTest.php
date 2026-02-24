<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Locking\Backends\FileSystem;
use melia\ObjectStorage\Locking\LockAdapterInterface;
use ReflectionClass;

final class FilesystemLockAdapterTest extends LockAdapterTestCase
{
    private string $tmpDir;

    protected function skipIfUnavailable(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'objstore_lock_tests_' . uniqid('', true);
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_dir($this->tmpDir)) {
            $files = glob($this->tmpDir . DIRECTORY_SEPARATOR . '*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($this->tmpDir);
        }
    }

    protected function createAdapter(): LockAdapterInterface
    {
        $adapter = new FileSystem($this->tmpDir);

        $ref = new ReflectionClass($adapter);
        $prop = $ref->getProperty('lockDir');
        $prop->setAccessible(true);
        $this->assertSame($this->tmpDir, $prop->getValue($adapter));

        return $adapter;
    }
}