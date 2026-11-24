<?php

namespace Tests\melia\ObjectStorage\Storage;

use melia\ObjectStorage\Cache\InMemoryCache;
use melia\ObjectStorage\Event\Dispatcher;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Exception\ObjectDeletionFailureException;
use melia\ObjectStorage\Exception\ObjectNotFoundException;
use melia\ObjectStorage\File\Files;
use melia\ObjectStorage\File\IO\RealAdapter;
use melia\ObjectStorage\File\JsonFiles;
use melia\ObjectStorage\Locking\Backends\SymfonyLockAdapter;
use melia\ObjectStorage\Locking\LockAdapterInterface;
use melia\ObjectStorage\Locking\ObjectLocks;
use melia\ObjectStorage\Locking\RetainedLocks;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\State\StateHandler;
use melia\ObjectStorage\Storage\ClassNameRegistry;
use melia\ObjectStorage\Storage\JsonStore;
use melia\ObjectStorage\Storage\MetadataStore;
use melia\ObjectStorage\Storage\ObjectCache;
use melia\ObjectStorage\Storage\ObjectDeleter;
use melia\ObjectStorage\Storage\ObjectFiles;
use melia\ObjectStorage\Storage\ObjectPaths;
use melia\ObjectStorage\Storage\StubIndex;
use melia\ObjectStorage\Strategy\Policy\ChildDeletion;
use melia\ObjectStorage\Strategy\Standard;
use Tests\melia\ObjectStorage\TestCase;

class ObjectDeleterTest extends TestCase
{
    private const string PARENT = 'ab12cd34-0000-4000-8000-000000000001';
    private const string CHILD = 'ab12cd34-0000-4000-8000-000000000002';
    private const string MISSING = 'ab12cd34-0000-4000-8000-000000000003';

    private string $directory;
    private ObjectPaths $paths;
    private InMemoryCache $objects;
    private Standard $strategy;
    private StateHandler $stateHandler;
    private LockAdapterInterface $lockAdapter;
    private Dispatcher $dispatcher;
    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = $this->createTemporaryDirectory();
        $this->storageDirs[] = $this->directory;
        $this->paths = new ObjectPaths($this->directory, 2);
        $this->objects = new InMemoryCache();
        $this->strategy = new Standard();
        $this->stateHandler = new StateHandler($this->directory);
        $this->lockAdapter = SymfonyLockAdapter::createFileLockAdapter($this->paths->lockDirectory());

        $this->dispatcher = new Dispatcher();
        foreach ([Events::BEFORE_DELETE, Events::AFTER_DELETE, Events::OBJECT_DELETION_FAILURE] as $event) {
            $this->dispatcher->addListener($event, function () use ($event): void {
                $this->events[] = $event;
            });
        }
    }

    /**
     * @return array{ObjectDeleter, ObjectFiles, StubIndex}
     */
    private function components(): array
    {
        $io = new RealAdapter();
        $files = new Files(sync: false);
        $files->setIOAdapter($io);
        $metadata = new MetadataStore($this->paths, $io, new JsonStore(new JsonFiles($files)), new InMemoryCache());
        $objectFiles = new ObjectFiles($this->paths, $files, $io, $metadata, $this->strategy, $this->stateHandler);
        $stubs = new StubIndex($this->paths, $io, new ClassNameRegistry($this->paths, $io, new JsonStore(new JsonFiles($files))));
        $locks = new ObjectLocks($this->lockAdapter, new RetainedLocks());

        $deleter = new ObjectDeleter($this->paths, $io, $files, $objectFiles, $metadata, $stubs, new ObjectCache($this->objects), $locks, $this->strategy, $this->stateHandler, $this->dispatcher);

        return [$deleter, $objectFiles, $stubs];
    }

    private function store(string $uuid, array $data): void
    {
        [, $objectFiles, $stubs] = $this->components();
        $json = (string)json_encode($data);
        $objectFiles->write($uuid, $json, Metadata::createFromArray([
            'uuid' => $uuid,
            'className' => 'App\Node',
            'checksum' => ObjectFiles::checksum($json, 'sha256'),
            'checksumAlgorithm' => 'sha256',
            'timestampCreation' => 1000.0,
            'timestampModification' => 2000.0,
        ]));
        $stubs->add('App\Node', $uuid);
    }

    public function testTheFilesStubAndCacheEntryOfAnObjectAreDeleted(): void
    {
        $this->store(self::PARENT, ['name' => 'parent']);
        $this->objects->set(self::PARENT, (object)['name' => 'parent']);
        [$deleter, $objectFiles, $stubs] = $this->components();

        $deleter->delete(self::PARENT);

        $this->assertFalse($objectFiles->exists(self::PARENT));
        $this->assertFileDoesNotExist($this->paths->metadataFile(self::PARENT));
        $this->assertFileDoesNotExist($this->paths->stubFile('App\Node', self::PARENT));
        $this->assertSame([], iterator_to_array($stubs->uuidsOf('App\Node')));
        $this->assertNull($this->objects->get(self::PARENT));
        $this->assertFalse($this->lockAdapter->isLockedByThisProcess(self::PARENT), 'the lock is released');
        $this->assertSame([Events::BEFORE_DELETE, Events::AFTER_DELETE], $this->events);
    }

    public function testAMissingObjectIsAFailure(): void
    {
        [$deleter] = $this->components();

        try {
            $deleter->delete(self::MISSING);
            $this->fail('Expected ObjectDeletionFailureException');
        } catch (ObjectDeletionFailureException $e) {
            $this->assertInstanceOf(ObjectNotFoundException::class, $e->getPrevious());
        }
        $this->assertSame([Events::BEFORE_DELETE, Events::OBJECT_DELETION_FAILURE], $this->events);
    }

    public function testNothingIsDeletedInSafeMode(): void
    {
        $this->store(self::PARENT, ['name' => 'parent']);
        $this->stateHandler->enableSafeMode();
        [$deleter, $objectFiles] = $this->components();

        $this->expectException(ObjectDeletionFailureException::class);
        try {
            $deleter->delete(self::PARENT);
        } finally {
            $this->assertTrue($objectFiles->exists(self::PARENT));
        }
    }

    public function testReferencedObjectsAreDeletedByThePolicy(): void
    {
        $this->strategy->setPolicyChildDeletion(ChildDeletion::ALWAYS);
        // a cycle, and a reference to an object that is gone already
        $this->store(self::PARENT, ['child' => ['__reference' => self::CHILD], 'gone' => ['__reference' => self::MISSING]]);
        $this->store(self::CHILD, ['parent' => ['__reference' => self::PARENT]]);
        [$deleter, $objectFiles] = $this->components();

        $deleter->delete(self::PARENT);

        $this->assertFalse($objectFiles->exists(self::PARENT));
        $this->assertFalse($objectFiles->exists(self::CHILD));
    }

    public function testOnlyTheObjectItselfIfAsked(): void
    {
        $this->strategy->setPolicyChildDeletion(ChildDeletion::ALWAYS);
        $this->store(self::PARENT, ['child' => ['__reference' => self::CHILD]]);
        $this->store(self::CHILD, ['name' => 'child']);
        [$deleter, $objectFiles] = $this->components();

        $deleter->delete(self::PARENT, applyChildDeletionPolicy: false);

        $this->assertTrue($objectFiles->exists(self::CHILD));
    }
}
