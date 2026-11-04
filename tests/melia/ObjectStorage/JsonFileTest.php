<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Event\Dispatcher;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\SerializationFailureException;
use melia\ObjectStorage\File\JsonFileReader;
use melia\ObjectStorage\File\JsonFileWriter;
use melia\ObjectStorage\Logger\LoggerInterface;
use Throwable;

/**
 * Reading and writing JSON files the way the storage does.
 */
final class JsonFileTest extends TestCase
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

        (new JsonFileWriter())->write($file, ['a' => 1, 'b' => ['c' => true]]);

        $this->assertSame(['a' => 1, 'b' => ['c' => true]], (new JsonFileReader())->readJson($file), 'the directory is created');
        $this->assertSame([], glob($this->dir . '/nested/dir/*.tmp*') ?: [], 'no staging file left');
    }

    public function testFlagsAndMaxDepth(): void
    {
        $pretty = new JsonFileWriter(flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->assertSame("{\n    \"path\": \"a/b\"\n}", $pretty->encode(['path' => 'a/b']));

        $this->expectException(SerializationFailureException::class);
        (new JsonFileWriter(maxDepth: 2))->encode([[['too deep']]]);
    }

    public function testAMissingFileIsReportedAndReadAsNull(): void
    {
        [$logger, $logged] = $this->logger();
        $dispatcher = new Dispatcher();
        $events = [];
        $dispatcher->addListener(Events::IO_READ_FAILURE, function () use (&$events) {
            $events[] = Events::IO_READ_FAILURE;
        });

        $reader = new JsonFileReader(logger: $logger, eventDispatcher: $dispatcher);

        $this->assertNull($reader->readJson($this->dir . '/missing.json'));
        $this->assertSame([Events::IO_READ_FAILURE], $events);
        $this->assertCount(1, $logged());
    }

    public function testInvalidJsonIsReportedAsCorruptionAndThrows(): void
    {
        $file = $this->dir . '/broken.json';
        file_put_contents($file, '{"a": ');
        $corrupted = [];
        $dispatcher = new Dispatcher();
        $events = [];
        $dispatcher->addListener(Events::JSON_DECODING_FAILURE, function () use (&$events) {
            $events[] = Events::JSON_DECODING_FAILURE;
        });

        $reader = new JsonFileReader(eventDispatcher: $dispatcher, onCorruption: function (string $filename) use (&$corrupted) {
            $corrupted[] = $filename;
        });

        try {
            $reader->readJson($file);
            $this->fail('invalid JSON must throw');
        } catch (SerializationFailureException) {
        }
        $this->assertSame([$file], $corrupted);
        $this->assertSame([Events::JSON_DECODING_FAILURE], $events);

        $this->assertNull($reader->tryDecode('{"a": '), 'tryDecode reports nothing as a damaged file');
        $this->assertNull($reader->tryDecode('"a string"'), 'only objects and arrays');
        $this->assertSame([$file], $corrupted);
    }

    public function testInvalidJsonInAStorageFileEnablesSafeMode(): void
    {
        $uuid = $this->storage->store(new ChildObject('a', 1));
        file_put_contents($this->storage->getFilePathMetadata($uuid), '{broken');
        file_put_contents($this->storage->getFilePathData($uuid), '{broken');
        $this->storage->clearCache();

        try {
            $this->storage->load($uuid);
        } catch (Throwable) {
        }

        $this->assertTrue($this->storage->getStateHandler()->safeModeEnabled());
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
