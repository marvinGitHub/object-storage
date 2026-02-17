<?php

declare(strict_types=1);

namespace melia\ObjectStorage;

use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\File\IO\AdapterInterface;
use melia\ObjectStorage\File\Writer;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;

final class WriterRecoveryTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    public function testRecoveryOnWriteFailureDeletesFile(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $writer = new Writer();

        // Attach the adapter (works whether the trait exposes a setter or not).
        if (method_exists($writer, 'setIOAdapter')) {
            $writer->setIOAdapter($adapter);
        } else {
            $rp = new ReflectionProperty($writer, 'ioAdapter');
            $rp->setAccessible(true);
            $rp->setValue($writer, $adapter);
        }

        $stagingPath = null;

        $adapter->expects($this->once())
            ->method('filePutContents')
            ->with($this->callback(static function (string $path) use (&$stagingPath): bool {
                $stagingPath = $path;
                return str_contains($path, Writer::SUFFIX_STAGING);
            }), 'payload')
            ->willReturn(false); // Simulate write failure

        $adapter->expects($this->never())
            ->method('rename');

        $adapter->expects($this->once())
            ->method('isFile')
            ->with($this->callback(static function (string $path) use (&$stagingPath): bool {
                return null !== $stagingPath && $path === $stagingPath;
            }))
            ->willReturn(true);

        $adapter->expects($this->once())
            ->method('unlink')
            ->with($this->callback(static function (string $path) use (&$stagingPath): bool {
                return null !== $stagingPath && $path === $stagingPath;
            }))
            ->willReturn(true);

        $this->expectException(IOException::class);
        $this->expectExceptionMessage('Failed to write to file');

        $writer->atomicWrite('some/target/file.dat', 'payload', false);
    }

    public function testRecoverySkipsUnlinkWhenFileDoesNotExist(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $writer = new Writer();

        // Attach the adapter (works whether the trait exposes a setter or not).
        if (method_exists($writer, 'setIOAdapter')) {
            $writer->setIOAdapter($adapter);
        } else {
            $rp = new ReflectionProperty($writer, 'ioAdapter');
            $rp->setAccessible(true);
            $rp->setValue($writer, $adapter);
        }

        $stagingPath = null;

        $adapter->expects($this->once())
            ->method('filePutContents')
            ->with($this->callback(static function (string $path) use (&$stagingPath): bool {
                $stagingPath = $path;
                return str_contains($path, Writer::SUFFIX_STAGING);
            }), 'payload')
            ->willReturn(false); // Simulate write failure

        $adapter->expects($this->never())
            ->method('rename');

        $adapter->expects($this->once())
            ->method('isFile')
            ->with($this->callback(static function (string $path) use (&$stagingPath): bool {
                return null !== $stagingPath && $path === $stagingPath;
            }))
            ->willReturn(false); // File does not exist => recovery should skip unlink

        $adapter->expects($this->never())
            ->method('unlink');

        $this->expectException(IOException::class);
        $this->expectExceptionMessage('Failed to write to file');

        $writer->atomicWrite('some/target/file.dat', 'payload', false);
    }
}