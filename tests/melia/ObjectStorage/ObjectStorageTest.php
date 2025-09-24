<?php

namespace Tests\melia\ObjectStorage;

use Error;
use melia\ObjectStorage\Exception\DanglingReferenceException;
use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;
use stdClass;
use Throwable;

class ObjectStorageTest extends TestCase
{
    public function testStoreWithDynamicAttributes()
    {
        $object = new \stdClass();
        $object->foo = 'bar';
        $object->bar = 'baz';

        $uuid = $this->storage->store($object);
        $this->assertCount(1, $this->storage->list());
        $this->assertTrue($this->storage->exists($uuid));
        $this->assertEquals(1, $this->storage->count('stdClass'));

        $this->storage->clearCache();
        $loaded = $this->storage->load($uuid);
        $this->assertEquals('bar', $loaded->foo);
        $this->assertEquals('baz', $loaded->bar);
        $this->assertEquals(get_class($object), get_class($loaded));
    }

    public function testStoreWithSelfReference()
    {
        // Erstelle ein Test-Objekt und speichere es
        $originalObject = new TestObjectWithReference();
        $originalObject->self = $originalObject; // Zirkuläre Referenz

        $uuid = $this->storage->store($originalObject);

        // Lade das Objekt erneut - dies sollte eine LazyLoadReference für die self-Property erstellen
        $loadedObject = $this->storage->load($uuid);

        // Verifiziere, dass die self-Property eine TestObjectWithReference ist da diese aus dem cache kommt
        $this->assertInstanceOf(TestObjectWithReference::class, $loadedObject->self);
    }

    public function testStoreWithCircularReference()
    {
        // Erstelle zwei verknüpfte Objekte
        $object1 = new TestObjectWithReference();
        $object2 = new TestObjectWithReference();

        $object1->self = $object2;
        $object2->self = $object1; // Zirkuläre Referenz

        $uuid1 = $this->storage->store($object1);
        $uuid2 = $this->storage->store($object2);
        $this->assertCount(2, $this->storage->list());
        $this->assertTrue($this->storage->exists($uuid1));
        $this->assertTrue($this->storage->exists($uuid2));
    }

    public function testStoredUnmodified()
    {
        $object = new \stdClass();
        $object->foo = 'bar';
        $uuid = $this->storage->store($object);
        $this->assertCount(1, $this->storage->list());

        $this->storage->clearCache();
        $this->assertTrue($this->storage->exists($uuid));

        $this->writerSpy->clearMethodCalls();
        $this->storage->store($object, $uuid);
        $this->assertCount(0, $this->writerSpy->getCallsForUuid($uuid)); // 1 means only metadata has been written
    }

    public function testStore()
    {
        $object = new \stdClass();
        $object->foo = 'bar';
        $uuid = $this->storage->store($object);

        $this->assertNotEmpty($uuid);
        $this->assertEquals(1, $this->storage->count('stdClass'));

        $loaded = $this->storage->load($uuid);
        $this->assertEquals($object->foo, $loaded->foo);
        $this->assertEquals(get_class($object), get_class($loaded));
        $this->assertTrue($this->storage->exists($uuid));
    }

    public function testStoreWithReference()
    {
        $object = new TestObjectWithReference();
        $object->self = $object;

        $uuid = $this->storage->store($object);
        $this->storage->clearCache();
        $this->assertNotEmpty($uuid);
        $this->assertTrue($this->storage->exists($uuid));

        $loaded = $this->storage->load($uuid);

        $this->assertEquals(get_class($object), get_class($loaded));
        $this->assertTrue($loaded->self instanceof \melia\ObjectStorage\LazyLoadReference);
        $this->assertEquals(spl_object_hash($loaded), spl_object_hash($loaded->self->getObject()));
    }

    public function testDelete()
    {
        $uuid = $this->storage->store(new \stdClass());
        $this->assertTrue($this->storage->exists($uuid));
        $this->storage->delete($uuid);
        $this->assertFalse($this->storage->exists($uuid));
    }

    public function testExists()
    {
        $this->assertFalse($this->storage->exists(\melia\ObjectStorage\UUID\Generator::generate()));
        $this->assertFalse($this->storage->exists('foo'));
    }

    public function testStoreWithExclusiveLock()
    {
        $uuid = $this->storage->store(new stdClass());
        $this->storage->lock($uuid, true);
        $this->assertTrue($this->storage->hasActiveLock($uuid));
        $this->assertTrue($this->storage->hasActiveSharedLock($uuid));

        $this->expectException(\melia\ObjectStorage\Exception\LockException::class);
        $this->storage->store(new stdClass(), $uuid);
    }

    public function testDeleteWithExclusiveLock()
    {
        $uuid = $this->storage->store(new stdClass());
        $this->storage->lock($uuid, true);
        $this->assertTrue($this->storage->hasActiveLock($uuid));
        $this->assertTrue($this->storage->hasActiveSharedLock($uuid));

        $this->expectException(\melia\ObjectStorage\Exception\LockException::class);
        $this->storage->delete($uuid);
    }

    public function testSharedLock()
    {
        $uuid = $this->storage->store(new stdClass());
        $this->assertFalse($this->storage->hasActiveLock($uuid));
        $this->assertCount(0, $this->storage->getActiveLocks());
        $this->storage->lock($uuid, true);
        $this->assertTrue($this->storage->hasActiveLock($uuid));
        $this->assertCount(1, $this->storage->getActiveLocks());
        $this->assertTrue($this->storage->hasActiveSharedLock($uuid));
    }

    public function testStoreWithConcurrentLock()
    {
        $uuid = $this->storage->store(new stdClass());
        $this->storage->lock($uuid);

        $anotherStorage = new \melia\ObjectStorage\ObjectStorage($this->storageDir);

        $this->expectException(\melia\ObjectStorage\Exception\LockException::class);
        $anotherStorage->store(new stdClass(), $uuid);
    }

    public function testUnlock()
    {
        $uuid = $this->storage->store(new stdClass());
        $this->storage->lock($uuid);
        $this->assertTrue($this->storage->hasActiveLock($uuid));
        $this->storage->unlock($uuid);
        $this->assertFalse($this->storage->hasActiveLock($uuid));
    }

    public function testUnlockOfLockedObjectFromOtherProcess()
    {
        $uuid = $this->storage->store(new stdClass());
        $this->storage->lock($uuid);

        $anotherStorage = new \melia\ObjectStorage\ObjectStorage($this->storageDir);
        $this->assertTrue($anotherStorage->isLocked($uuid));
        $this->assertFalse($anotherStorage->hasActiveLock($uuid));
        $this->assertFalse($anotherStorage->hasActiveSharedLock($uuid));
        $this->assertFalse($anotherStorage->hasActiveExclusiveLock($uuid));

        $this->expectException(\melia\ObjectStorage\Exception\LockException::class);
        $anotherStorage->unlock($uuid);
    }

    public function testStoreWithLockFromOtherProcess()
    {
        $uuid = $this->storage->store(new stdClass());
        $this->storage->lock($uuid);

        $this->expectException(\melia\ObjectStorage\Exception\LockException::class);
        $anotherStorage = new \melia\ObjectStorage\ObjectStorage($this->storageDir);
        $anotherStorage->store(new stdClass(), $uuid);
    }

    public function testList()
    {
        $someStorage = new \melia\ObjectStorage\ObjectStorage($someDir = $this->reserveRandomStorageDirectory());
        $someUUID = $someStorage->store(new stdClass());
        $anotherUUID = $someStorage->store(new stdClass());

        $list = $someStorage->list();

        $this->assertCount(2, $list);

        $this->assertContains($someUUID, $list);
        $this->assertContains($anotherUUID, $list);

        $this->tearDownDirectory($someDir);
    }

    public function testAssume()
    {
        $someStorage = new \melia\ObjectStorage\ObjectStorage($someDir = $this->reserveRandomStorageDirectory());
        $someOtherStorage = new \melia\ObjectStorage\ObjectStorage($someOtherDir = $this->reserveRandomStorageDirectory());

        $this->assertEquals(0, $someStorage->count());
        $this->assertEquals(0, $someOtherStorage->count());

        $someStorage->store(new stdClass());
        $someStorage->store(new stdClass());

        $this->assertEquals(2, $someStorage->count());
        $someOtherStorage->assume($someStorage);
        $this->assertEquals(2, $someOtherStorage->count());

        $this->tearDownDirectory($someDir);
        $this->tearDownDirectory($someOtherDir);
    }

    public function testStoreWithArrayReference()
    {
        $someStorage = new \melia\ObjectStorage\ObjectStorage($someDir = $this->reserveRandomStorageDirectory());

        $someObject = new stdClass();
        $someObject->foo = ['test' => new stdClass()];

        $someUUID = $someStorage->store($someObject);

        $this->assertEquals(2, $someStorage->count());

        $this->tearDownDirectory($someDir);
    }

    /**
     * @throws Throwable
     * @throws \melia\ObjectStorage\Exception\Exception|\Throwable
     */
    public function testLazyLoadReferenceUpdatesParent()
    {
        $someStorage = new \melia\ObjectStorage\ObjectStorage($someDir = $this->reserveRandomStorageDirectory());

        $someObject = new stdClass();
        $test = new stdClass();
        $test->someAttribute = 'test';

        $someObject->foo = ['test' => $test];
        $someObject->test = $test;

        $someUUID = $someStorage->store($someObject);

        $someStorage->clearCache();

        $someObject = $someStorage->load($someUUID);

        $this->assertInstanceOf(\melia\ObjectStorage\LazyLoadReference::class, $someObject->foo['test']);

        /* access some attribute to resolve lazy load reference and update parent */
        $someObject->test->someAttribute;

        $this->assertInstanceOf('stdClass', $someObject->test);

        $this->assertInstanceOf(\melia\ObjectStorage\LazyLoadReference::class, $someObject->foo['test']);
        $lazyLoadReference = $someObject->foo['test'];
        $lazyLoadReference->someAttribute;

        $this->assertTrue($lazyLoadReference->isLoaded());

        $this->assertInstanceOf('stdClass', $lazyLoadReference->getObject());
        $this->assertInstanceOf('stdClass', $someObject->foo['test']);


        $this->tearDownDirectory($someDir);
    }


    public function testStoreOverridesUUID()
    {
        $object = new class() extends stdClass implements AwareInterface {
            use AwareTrait;
        };
        $this->assertNull($object->getUUID());
        $uuid = $this->storage->store($object);
        $this->assertNotEmpty($uuid);
        $this->assertEquals($uuid, $object->getUUID());
    }

    public function testStoreOfAttributesWhichAreNotSet()
    {
        $object = new TestObject();

        $uuid = $this->storage->store($object);
        $this->assertNotEmpty($uuid);

        $test = $this->storage->load($uuid);
        $this->assertNotNull($test);

        /* dont save non initialized attributes */
        $data = file_get_contents($this->storage->getFilePathData($uuid));
        $this->assertNotFalse($data);
        $data = json_decode($data, true);
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('someAttributeWithoutDefaultValue', $data);

        /* keep null values */
        $this->assertInstanceOf(TestObject::class, $test);
        $this->assertNull($test->somePublicAttributeWhichDefaultsToNull);

        $this->expectException(Error::class);
        $this->assertNull($test->somePublicAttributeWithoutDefaultValue);


    }

    public function testResourcesSkippedDuringStorage()
    {
        $object = new TestObject();
        $object->a = 'test';
        $resource = fopen(__FILE__, 'r');
        $object->someResource = $resource;
        $object->nested = [
            $resource, 10
        ];

        $json = $this->storage->exportObjectGraph($object);
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('a', $data);
        $this->assertArrayNotHasKey('someResource', $data);
        $this->assertArrayHasKey('nested', $data);
        $this->assertIsArray($data['nested']);
        $this->assertCount(1, $data['nested']);
    }

    public function testStoreWithExpiration()
    {
        $object = new TestObject();
        $uuid = $this->storage->store($object, null, 1);
        $this->assertCount(1, $this->storage->list());
        $this->assertFalse($this->storage->expired($uuid));
        sleep(2);
        $this->assertCount(1, $this->storage->list());
        $this->assertTrue($this->storage->expired($uuid));

        $loaded = $this->storage->load($uuid);
        $this->assertNull($loaded);
        $this->assertTrue($this->storage->exists($uuid));
        $this->assertCount(1, $this->storage->list());
        $this->assertTrue($this->storage->expired($uuid));
    }

    public function testLifetime()
    {
        $object = new TestObject();
        $uuid = $this->storage->store($object, null, 1);
        $this->assertEquals(1, $this->storage->getLifetime($uuid));
        $this->assertFalse($this->storage->expired($uuid));
        sleep(1);
        $this->assertEquals(0, $this->storage->getLifetime($uuid));
        sleep(1);
        $this->assertEquals(-1, $this->storage->getLifetime($uuid)); // negative means time since expiration

        $uuid = $this->storage->store(new stdClass(), null, null);
        $this->assertNull($this->storage->getLifetime($uuid));
        $this->assertFalse($this->storage->expired($uuid));
    }

    public function testExpiredLazyLoadReferenceThrowsDanglingReference(): void
    {
        // Arrange: create parent with child
        $parent = new stdClass();
        $child = new stdClass();
        $child->name = 'Child';
        $parent->child = $child;

        $parentUuid = $this->storage->store($parent);

        $this->assertCount(2, $this->storage->list());

        // Force lazy loading on next read
        $this->storage->clearCache();
        $reloadedParent = $this->storage->load($parentUuid);

        $this->assertInstanceOf(LazyLoadReference::class, $reloadedParent->child);
        $lazy = $reloadedParent->child; // not loaded yet
        $childUuid = $lazy->getUUID();

        $metadata = $this->storage->loadMetadata($childUuid);


        $this->assertFalse($lazy->isLoaded());
        $this->assertTrue($this->storage->exists($childUuid));

        // Mark child as expired by writing expiresAt in its metadata and remove data to simulate purge
        $this->storage->setLifetime($childUuid, 0);

        $this->assertTrue($this->storage->exists($childUuid));
        $this->assertEquals(0, $this->storage->getLifetime($childUuid));;


        $this->assertEquals($childUuid, $reloadedParent->child->getUUID());

        // Act + Assert: any access should attempt load and throw DanglingReferenceException
        $this->expectException(DanglingReferenceException::class);
        // Accessing property triggers loadIfNeeded()
        $unused = $reloadedParent->child->name;
    }
}