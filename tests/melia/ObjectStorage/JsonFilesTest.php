<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\CorruptedFileException;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\Exception\SerializationFailureException;
use melia\ObjectStorage\File\Files;
use melia\ObjectStorage\File\Json;
use melia\ObjectStorage\File\JsonFiles;
use melia\ObjectStorage\Logger\LoggerInterface;
use melia\ObjectStorage\ObjectStorage;
use Throwable;

/**
 * JSON files: JsonFiles reads and decodes, writes and encodes; the storage reports damaged files.
 */
final class JsonFilesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->storageDirs[] = $this->createTemporaryDirectory();
    }

    public function testWrittenValuesAreReadBack(): void
    {
        $file = $this->dir . '/nested/dir/file.json';

        (new JsonFiles())->write($file, ['a' => 1, 'b' => ['c' => true]]);

        $this->assertSame(['a' => 1, 'b' => ['c' => true]], (new JsonFiles())->read($file), 'the directory is created');
        $this->assertSame([], glob($this->dir . '/nested/dir/*.tmp*') ?: [], 'no staging file left');
    }

    public function testAMissingFileIsNull(): void
    {
        $this->assertNull((new JsonFiles())->read($this->dir . '/missing.json'));
    }

    public function testFlagsAndMaxDepth(): void
    {
        $file = $this->dir . '/pretty.json';
        (new JsonFiles(flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))->write($file, ['path' => 'a/b']);
        $this->assertStringEqualsFile($file, "{\n    \"path\": \"a/b\"\n}");

        $this->expectException(SerializationFailureException::class);
        (new JsonFiles(maxDepth: 2))->write($this->dir . '/deep.json', [[['too deep']]]);
    }

    public function testValuesJsonCannotHoldAreNotWritten(): void
    {
        $file = $this->dir . '/invalid.json';
        try {
            (new JsonFiles())->write($file, ['name' => "M\xFCller"]);
            $this->fail('invalid UTF-8 must fail');
        } catch (SerializationFailureException) {
        }
        $this->assertFileDoesNotExist($file);
    }

    public function testInvalidJsonIsACorruptedFile(): void
    {
        $file = $this->dir . '/broken.json';
        file_put_contents($file, '{"a": ');

        try {
            (new JsonFiles())->read($file);
            $this->fail('invalid JSON must throw');
        } catch (CorruptedFileException $e) {
            $this->assertSame($file, $e->path);
        }

        file_put_contents($file, '"a string"');
        $this->expectException(CorruptedFileException::class);
        (new JsonFiles())->read($file);
    }

    public function testJsonDecodesObjectsAndArraysOnly(): void
    {
        $this->assertSame(['a' => 1], (new Json())->decode('{"a":1}'));
        $this->expectException(SerializationFailureException::class);
        (new Json())->decode('1');
    }

    public function testInvalidJsonInAStorageFileIsReportedAndEnablesSafeMode(): void
    {
        $uuid = $this->storage->store(new ChildObject('a', 1));
        file_put_contents($this->storage->getFilePathMetadata($uuid), '{broken');

        [$logger, $logged] = $this->logger();
        $storage = new ObjectStorage($this->storage->getStorageDir(), logger: $logger);
        $events = [];
        $storage->getEventDispatcher()->addListener(Events::JSON_DECODING_FAILURE, function () use (&$events) {
            $events[] = Events::JSON_DECODING_FAILURE;
        });

        $this->assertNull($storage->loadMetadata($uuid), 'unreadable metadata is no metadata');
        $this->assertSame([Events::JSON_DECODING_FAILURE], $events);
        $this->assertNotEmpty($logged());
        $this->assertTrue($storage->getStateHandler()->safeModeEnabled());
    }

    public function testInvalidDataInAStorageFileEnablesSafeMode(): void
    {
        $uuid = $this->storage->store(new ChildObject('a', 1));
        $this->strategy()->disableChecksumValidation();
        file_put_contents($this->storage->getFilePathData($uuid), '{broken');
        $this->storage->clearCache();

        try {
            $this->storage->load($uuid);
            $this->fail('invalid data must fail the load');
        } catch (Throwable $e) {
            $this->assertInstanceOf(CorruptedFileException::class, $e->getPrevious());
        }
        $this->assertTrue($this->storage->getStateHandler()->safeModeEnabled());
    }

    public function testAnUnreadableStorageFileIsReported(): void
    {
        $uuid = $this->storage->store(new ChildObject('a', 1));
        $path = $this->storage->getFilePathMetadata($uuid);

        [$logger, $logged] = $this->logger();
        $storage = new ObjectStorage($this->storage->getStorageDir(), logger: $logger);
        // the file exists, but can't be read (e.g. held open by another program)
        $storage->setFiles(new class extends Files {
            public function read(string $path): ?string
            {
                throw new IOException('Unable to read file: ' . $path);
            }
        });
        $events = [];
        $storage->getEventDispatcher()->addListener(Events::IO_READ_FAILURE, function () use (&$events) {
            $events[] = Events::IO_READ_FAILURE;
        });

        try {
            (fn() => $this->readJson($path))->call($storage);
            $this->fail('an unreadable file must fail');
        } catch (IOException) {
        }
        $this->assertSame([Events::IO_READ_FAILURE], $events);
        $this->assertNotEmpty($logged());
        $this->assertFalse($storage->getStateHandler()->safeModeEnabled(), 'not a damaged file');
    }

    /**
     * @return array{LoggerInterface, callable(): array}
     */
    private function logger(): array
    {
        $logger = new class implements LoggerInterface {
            public array $messages = [];

            public function log(Throwable|string $message): void
            {
                $this->messages[] = $message;
            }
        };
        return [$logger, static fn(): array => $logger->messages];
    }
}
