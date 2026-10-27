<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Event\Context\ObjectPersistenceContext;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\Locking\Backends\SymfonyLockAdapter;
use melia\ObjectStorage\ObjectStorage;
use Symfony\Component\Lock\Store\InMemoryStore;

final class ObjectStorageLockingRegressionTest extends TestCase
{
    public function testExclusiveLoadFollowedByStoreWithNonFileLockStore(): void
    {
        $dir = $this->createTemporaryDirectory();
        $this->storageDirs[] = $dir;
        $storage = new ObjectStorage($dir, lockAdapter: new SymfonyLockAdapter(new InMemoryStore()));

        $uuid = $storage->store(new ChildObject('before', 1));

        $loaded = $storage->load($uuid, true);
        $loaded->title = 'after';
        $storage->store($loaded, $uuid);

        $storage->clearCache();
        $this->assertSame('after', $storage->load($uuid)->title);
        $this->assertFalse($storage->getLockAdapter()->isLockedByThisProcess($uuid));
    }

    public function testSharedLoadDoesNotReleaseExclusiveLockOfCaller(): void
    {
        $uuid = $this->storage->store(new ChildObject('title', 1));

        $this->storage->load($uuid, true);
        $this->storage->clearCache();

        $start = microtime(true);
        $loaded = $this->storage->load($uuid);

        $this->assertSame('title', $loaded->title);
        $this->assertLessThan(1, microtime(true) - $start, 'shared load must not wait for the own exclusive lock');
        $this->assertTrue($this->storage->getLockAdapter()->hasActiveExclusiveLock($uuid));

        $this->storage->getLockAdapter()->releaseLock($uuid);
    }

    public function testObjectSavedListenerReceivesPreviousObject(): void
    {
        $uuid = $this->storage->store(new ChildObject('before', 1));

        $previous = null;
        $this->storage->getEventDispatcher()->addListener(Events::OBJECT_SAVED, function (ObjectPersistenceContext $context) use (&$previous) {
            $previous = $context->getPreviousObject();
        });

        $this->storage->clearCache();
        $object = $this->storage->load($uuid);
        $this->storage->clearCache();

        $object->title = 'after';

        $start = microtime(true);
        $this->storage->store($object, $uuid);

        $this->assertLessThan(1, microtime(true) - $start, 'loading the previous object must not wait for the own exclusive lock');
        $this->assertNotNull($previous);
        $this->assertSame('before', $previous->title);
        $this->assertFalse($this->storage->getStateHandler()->safeModeEnabled());

        $this->storage->clearCache();
        $this->assertSame('after', $this->storage->load($uuid)->title);
    }

    public function testStoreLoadedLazyLoadReferenceOfObjectWithoutUuidAwareness(): void
    {
        $parent = new PlainParent();
        $parent->child = new PlainChild();
        $uuid = $this->storage->store($parent);

        $this->storage->clearCache();
        $loaded = $this->storage->load($uuid);

        $reference = $loaded->child;
        $this->assertInstanceOf(LazyLoadReference::class, $reference);
        $childUuid = $reference->getUUID();

        $reference->name = 'changed';

        $this->assertSame($childUuid, $this->storage->store($reference));

        $this->storage->clearCache();
        $this->assertSame('changed', $this->storage->load($childUuid)->name);
    }

    public function testBeforeAndAfterUpdateAreDispatchedWithoutExplicitUuid(): void
    {
        $dispatched = [];
        $dispatcher = $this->storage->getEventDispatcher();
        foreach ([Events::BEFORE_UPDATE, Events::AFTER_UPDATE] as $event) {
            $dispatcher->addListener($event, function () use (&$dispatched, $event) {
                $dispatched[] = $event;
            });
        }

        $object = new PlainChild();
        $this->storage->store($object);
        $this->assertSame([], $dispatched, 'initial store is not an update');

        $object->name = 'changed';
        $this->storage->store($object);

        $this->assertSame([Events::BEFORE_UPDATE, Events::AFTER_UPDATE], $dispatched);
    }
}

class PlainChild
{
    public string $name = 'child';
}

class PlainParent
{
    public mixed $child = null;
}
