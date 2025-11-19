<?php

declare(strict_types=1);

namespace Tests\melia\ObjectStorage\File;

use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\File\Writer;
use melia\ObjectStorage\File\IO\AdapterInterface;
use PHPUnit\Framework\TestCase;

final class WriterRecoveryTest extends TestCase
{
    public function testRecoveryOnWriteFailureClosesHandleAndDeletesFile(): void
    {
        $filename = sys_get_temp_dir() . '/writer-recovery-test-' . uniqid('', true) . '.txt';
        $data = 'payload';

        $handle = fopen('php://temp', 'w+');
        self::assertIsResource($handle, 'Failed to create temp handle for test');

        $adapter = $this->createMock(AdapterInterface::class);

        // fopen returns a valid resource
        $adapter->expects(self::once())
            ->method('fopen')
            ->with($filename, 'w+')
            ->willReturn($handle);

        // rewind succeeds
        $adapter->expects(self::once())
            ->method('rewind')
            ->with($handle)
            ->willReturn(true);

        // fwrite fails -> triggers recovery
        $adapter->expects(self::once())
            ->method('fwrite')
            ->with($handle, $data)
            ->willReturn(false);

        // recovery: fclose called for open resource
        $adapter->expects(self::once())
            ->method('fclose')
            ->with($handle)
            ->willReturn(true);

        // recovery: isFile checked
        $adapter->expects(self::once())
            ->method('isFile')
            ->with($filename)
            ->willReturn(true);

        // recovery: unlink must be called and succeed
        $adapter->expects(self::once())
            ->method('unlink')
            ->with($filename)
            ->willReturn(true);

        // Other adapter calls should not be reached after fwrite failure
        $adapter->expects(self::never())->method('fflush');
        $adapter->expects(self::never())->method('ftell');
        $adapter->expects(self::never())->method('ftruncate');

        $writer = new Writer();

        // Inject mocked adapter via AwareTrait's setter (ioAdapter property)
        // Use reflection to set protected/trait property if no setter is available
        $refClass = new \ReflectionClass($writer);
        $prop = $refClass->getProperty('ioAdapter');
        $prop->setAccessible(true);
        $prop->setValue($writer, $adapter);

        $this->expectException(IOException::class);
        $this->expectExceptionMessage('Unable to write to file: ' . $filename);

        $writer->atomicWrite($filename, $data, false);

        // Cleanup temp handle if recovery didn't close (should be closed already)
        if (is_resource($handle)) {
            fclose($handle);
        }
    }

    public function testRecoverySkipsUnlinkWhenFileDoesNotExist(): void
    {
        $filename = sys_get_temp_dir() . '/writer-recovery-test-' . uniqid('', true) . '.txt';
        $data = 'payload';

        $handle = fopen('php://temp', 'w+');
        self::assertIsResource($handle);

        $adapter = $this->createMock(AdapterInterface::class);

        $adapter->expects(self::once())
            ->method('fopen')
            ->with($filename, 'w+')
            ->willReturn($handle);

        $adapter->expects(self::once())
            ->method('rewind')
            ->with($handle)
            ->willReturn(true);

        $adapter->expects(self::once())
            ->method('fwrite')
            ->with($handle, $data)
            ->willReturn(false);

        // fclose during recovery
        $adapter->expects(self::once())
            ->method('fclose')
            ->with($handle)
            ->willReturn(true);

        // file does not exist -> unlink must not be called
        $adapter->expects(self::once())
            ->method('isFile')
            ->with($filename)
            ->willReturn(false);

        $adapter->expects(self::never())->method('unlink');
        $adapter->expects(self::never())->method('fflush');
        $adapter->expects(self::never())->method('ftell');
        $adapter->expects(self::never())->method('ftruncate');

        $writer = new Writer();
        $refClass = new \ReflectionClass($writer);
        $prop = $refClass->getProperty('ioAdapter');
        $prop->setAccessible(true);
        $prop->setValue($writer, $adapter);

        $this->expectException(IOException::class);
        $this->expectExceptionMessage('Unable to write to file: ' . $filename);

        $writer->atomicWrite($filename, $data, false);

        if (is_resource($handle)) {
            fclose($handle);
        }
    }
}