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

    public function testExclusiveLoadReturnsCurrentStateInsteadOfCachedCopy(): void
    {
        $uuid = $this->storage->store(new ChildObject('stock', 100));
        $this->storage->load($uuid); // cached in this "process"

        $otherProcess = new ObjectStorage($this->storage->getStorageDir());
        $product = $otherProcess->load($uuid, true);
        $product->value = 99;
        $otherProcess->store($product, $uuid);

        $loaded = $this->storage->load($uuid, true);

        $this->assertSame(99, $loaded->value, 'an exclusive load must not return an outdated cached copy');
        $this->assertTrue($this->storage->getLockAdapter()->hasActiveExclusiveLock($uuid));
        $this->assertSame($loaded, $this->storage->load($uuid), 'the fresh object replaces the cached copy');

        $this->storage->getLockAdapter()->releaseLock($uuid);
    }

    public function testReadModifyWriteWithExclusiveLoadLosesNoUpdates(): void
    {
        $uuid = $this->storage->store(new ChildObject('stock', 10));
        $otherProcess = new ObjectStorage($this->storage->getStorageDir());

        // both "processes" have the object cached, then update it alternately
        $this->storage->load($uuid);
        $otherProcess->load($uuid);

        foreach ([$this->storage, $otherProcess, $this->storage, $otherProcess] as $storage) {
            $product = $storage->load($uuid, true);
            $product->value--;
            $storage->store($product, $uuid);
        }

        $this->assertSame(6, (new ObjectStorage($this->storage->getStorageDir()))->load($uuid)->value);
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

    public function testPreviousObjectIsTheStoredStateWhenTheObjectIsCached(): void
    {
        $contexts = [];
        $this->storage->getEventDispatcher()->addListener(Events::OBJECT_SAVED, function (ObjectPersistenceContext $context) use (&$contexts) {
            $contexts[] = $context;
        });

        $object = new ChildObject('before', 1);
        $uuid = $this->storage->store($object);

        // the object is cached: update the same instance
        $object->title = 'after';
        $this->storage->store($object, $uuid);

        $this->assertCount(2, $contexts);
        $this->assertNull($contexts[0]->getPreviousObject(), 'a new object has no previous state');

        $previous = $contexts[1]->getPreviousObject();
        $this->assertNotSame($object, $previous, 'the previous state must not be the object being stored');
        $this->assertSame('before', $previous->title);
        $this->assertSame('after', $contexts[1]->getObject()->title);

        // the previous state doesn't replace the current object in the cache
        $this->assertSame($object, $this->storage->load($uuid));
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
