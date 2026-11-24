<?php

namespace Tests\melia\ObjectStorage\Storage;

use melia\ObjectStorage\Cache\InMemoryCache;
use melia\ObjectStorage\Event\Dispatcher;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\File\Files;
use melia\ObjectStorage\File\IO\RealAdapter;
use melia\ObjectStorage\File\JsonFiles;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\Storage\JsonStore;
use melia\ObjectStorage\Storage\MetadataStore;
use melia\ObjectStorage\Storage\ObjectPaths;
use Tests\melia\ObjectStorage\TestCase;

class MetadataStoreTest extends TestCase
{
    private const string UUID = 'ab12cd34-0000-4000-8000-000000000001';
    private const string OTHER = 'ab12cd34-0000-4000-8000-000000000002';

    private ObjectPaths $paths;
    private InMemoryCache $cache;
    private Dispatcher $dispatcher;
    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $directory = $this->createTemporaryDirectory();
        $this->storageDirs[] = $directory;
        $this->paths = new ObjectPaths($directory, 2);
        $this->cache = new InMemoryCache();

        $this->dispatcher = new Dispatcher();
        foreach ([Events::METADATA_SAVED, Events::METADATA_LOADING_FAILURE] as $event) {
            $this->dispatcher->addListener($event, function () use ($event): void {
                $this->events[] = $event;
            });
        }
    }

    private function store(): MetadataStore
    {
        $io = new RealAdapter();
        $files = new Files(sync: false);
        $files->setIOAdapter($io);

        return new MetadataStore($this->paths, $io, new JsonStore(new JsonFiles($files)), $this->cache, $this->dispatcher);
    }

    private static function metadata(string $uuid, string $className = 'App\Customer'): Metadata
    {
        return Metadata::createFromArray([
            'uuid' => $uuid,
            'className' => $className,
            'checksum' => hash('sha256', ''),
            'checksumAlgorithm' => 'sha256',
            'timestampCreation' => 1000.0,
            'timestampModification' => 2000.0,
        ]);
    }

    private function writeFile(string $uuid, array $metadata): void
    {
        $file = $this->paths->metadataFile($uuid);
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, json_encode($metadata));
    }

    public function testNoMetadataIsNoFailure(): void
    {
        $this->assertNull($this->store()->load(self::UUID));
        $this->assertSame([], $this->events);
    }

    public function testWrittenMetadataIsCachedAndRead(): void
    {
        $this->store()->write(self::metadata(self::UUID));

        $this->assertInstanceOf(Metadata::class, $this->cache->get(self::UUID));
        $this->cache->clear();
        $loaded = $this->store()->load(self::UUID);
        $this->assertSame('App\Customer', $loaded?->getClassName());
        $this->assertSame([Events::METADATA_SAVED], $this->events);
    }

    public function testReloadBypassesTheCache(): void
    {
        $store = $this->store();
        $store->write(self::metadata(self::UUID));
        // changed by another process
        $this->writeFile(self::UUID, self::metadata(self::UUID, 'App\Order')->jsonSerialize());

        $this->assertSame('App\Customer', $store->load(self::UUID)?->getClassName(), 'from the cache');
        $this->assertSame('App\Order', $store->reload(self::UUID)?->getClassName());
        $this->assertSame('App\Order', $store->load(self::UUID)?->getClassName(), 'the cache is refreshed');
    }

    public function testTheFileNamesTheObjectAndGivesTheMissingTimes(): void
    {
        // a copy of the metadata of another object, without its times
        $this->writeFile(self::UUID, ['uuid' => self::OTHER, 'className' => 'App\Customer', 'checksum' => 'x', 'checksumAlgorithm' => 'sha256']);
        $modified = filemtime($this->paths->metadataFile(self::UUID));

        $loaded = $this->store()->load(self::UUID);

        $this->assertSame(self::UUID, $loaded?->getUUID());
        $this->assertEquals($modified, $loaded?->getTimestampModification());
        $this->assertNotNull($loaded?->getTimestampCreation());
    }

    public function testDamagedMetadataIsNoMetadata(): void
    {
        $file = $this->paths->metadataFile(self::UUID);
        mkdir(dirname($file), 0777, true);
        file_put_contents($file, '{damaged');

        $this->assertNull($this->store()->load(self::UUID));
        $this->assertSame([Events::METADATA_LOADING_FAILURE], $this->events);
    }
}
