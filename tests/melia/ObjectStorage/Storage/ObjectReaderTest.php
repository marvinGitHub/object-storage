<?php

namespace Tests\melia\ObjectStorage\Storage;

use melia\ObjectStorage\Cache\InMemoryCache;
use melia\ObjectStorage\Event\Dispatcher;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\CorruptedFileException;
use melia\ObjectStorage\File\Files;
use melia\ObjectStorage\File\IO\RealAdapter;
use melia\ObjectStorage\File\JsonFiles;
use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\State\StateHandler;
use melia\ObjectStorage\Storage\JsonStore;
use melia\ObjectStorage\Storage\MetadataStore;
use melia\ObjectStorage\Storage\ObjectCache;
use melia\ObjectStorage\Storage\ObjectFiles;
use melia\ObjectStorage\Storage\ObjectPaths;
use melia\ObjectStorage\Storage\ObjectReader;
use melia\ObjectStorage\Storage\ObjectSession;
use melia\ObjectStorage\Strategy\Standard;
use Tests\melia\ObjectStorage\TestCase;

class ObjectReaderTest extends TestCase
{
    private const string UUID = 'ab12cd34-0000-4000-8000-000000000001';
    private const string OTHER = 'ab12cd34-0000-4000-8000-000000000002';

    private string $directory;
    private ObjectPaths $paths;
    private ObjectSession $session;
    private InMemoryCache $objects;
    private StateHandler $stateHandler;
    private Dispatcher $dispatcher;
    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = $this->createTemporaryDirectory();
        $this->storageDirs[] = $this->directory;
        $this->paths = new ObjectPaths($this->directory, (new Standard())->getShardDepth());
        $this->session = new ObjectSession();
        $this->objects = new InMemoryCache();
        $this->stateHandler = new StateHandler($this->directory);

        $this->dispatcher = new Dispatcher();
        foreach ([Events::CLASS_ALIAS_CREATED, Events::JSON_DECODING_FAILURE] as $event) {
            $this->dispatcher->addListener($event, function () use ($event): void {
                $this->events[] = $event;
            });
        }
    }

    /**
     * @return array{ObjectReader, ObjectFiles}
     */
    private function components(): array
    {
        $io = new RealAdapter();
        $files = new Files(sync: false);
        $files->setIOAdapter($io);
        $strategy = new Standard();
        $metadata = new MetadataStore($this->paths, $io, new JsonStore(new JsonFiles($files)));
        $objectFiles = new ObjectFiles($this->paths, $files, $io, $metadata, $strategy, $this->stateHandler);
        // referenced objects are loaded through a storage of the same directory
        $storage = new ObjectStorage($this->directory);

        return [
            new ObjectReader($storage, $objectFiles, $this->session, new ObjectCache($this->objects), $strategy, null, $this->stateHandler, $this->dispatcher),
            $objectFiles,
        ];
    }

    private function write(string $uuid, string $className, string $data, int $revision = 1): void
    {
        [, $objectFiles] = $this->components();
        $objectFiles->write($uuid, $data, Metadata::createFromArray([
            'uuid' => $uuid,
            'className' => $className,
            'checksum' => ObjectFiles::checksum($data, 'sha256'),
            'checksumAlgorithm' => 'sha256',
            'revision' => $revision,
            'timestampCreation' => 1000.0,
            'timestampModification' => 2000.0,
        ]));
    }

    public function testNothingStored(): void
    {
        [$reader] = $this->components();

        $this->assertNull($reader->read(self::UUID));
    }

    public function testAnObjectIsBuiltFromItsFiles(): void
    {
        $this->write(self::UUID, ReaderTestNode::class, '{"name":"root","child":{"__reference":"' . self::OTHER . '"}}', 3);
        [$reader] = $this->components();

        $object = $reader->read(self::UUID);

        $this->assertInstanceOf(ReaderTestNode::class, $object);
        $this->assertSame('root', $object->name);
        $this->assertInstanceOf(LazyLoadReference::class, $object->child, 'references are loaded lazily');
        $this->assertSame(3, $this->session->revisionOf($object), 'the revision read, for optimistic locking');
        $this->assertSame($object, $this->objects->get(self::UUID));
    }

    public function testAStoredStateIsReadWithoutReplacingTheCachedObject(): void
    {
        $this->write(self::UUID, ReaderTestNode::class, '{"name":"stored"}');
        $cached = new ReaderTestNode();
        $this->objects->set(self::UUID, $cached);
        [$reader] = $this->components();

        $reader->read(self::UUID, addToCache: false);

        $this->assertSame($cached, $this->objects->get(self::UUID));
    }

    public function testAnUnknownClassGetsAPlaceholder(): void
    {
        $this->write(self::UUID, 'App\Gone\ReaderTestUnknown', '{"name":"kept"}');
        [$reader] = $this->components();

        $object = $reader->read(self::UUID);

        $this->assertSame('kept', $object?->name);
        $this->assertSame([Events::CLASS_ALIAS_CREATED], $this->events);
    }

    public function testDataThatCantBeDecodedIsADamagedFile(): void
    {
        $this->write(self::UUID, ReaderTestNode::class, '{damaged');
        [$reader] = $this->components();

        $this->expectException(CorruptedFileException::class);
        try {
            $reader->read(self::UUID);
        } finally {
            $this->assertTrue($this->stateHandler->safeModeEnabled());
            $this->assertSame([Events::JSON_DECODING_FAILURE], $this->events);
        }
    }
}

class ReaderTestNode
{
    public string $name = '';
    public mixed $child = null;
}
