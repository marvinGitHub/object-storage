<?php

namespace Tests\melia\ObjectStorage;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Error;
use Generator as PHPInternalGenerator;
use IteratorAggregate;
use melia\ObjectStorage\Exception\DanglingReferenceException;
use melia\ObjectStorage\Exception\Exception;
use melia\ObjectStorage\Exception\LockException;
use melia\ObjectStorage\Exception\MaxNestingLevelExceededException;
use melia\ObjectStorage\Exception\MetadataSavingFailureException;
use melia\ObjectStorage\Exception\ObjectNotFoundException;
use melia\ObjectStorage\Exception\ObjectSavingFailureException;
use melia\ObjectStorage\File\WriterInterface;
use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\Runtime\ClassRenameMap;
use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;
use melia\ObjectStorage\UUID\Generator\Generator;
use stdClass;
use Throwable;

class ObjectStorageTest extends TestCase
{
    public function testStoreWithDynamicAttributes()
    {
        $object = new stdClass();
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
        $object = new stdClass();
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
        $object = new stdClass();
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
        $this->assertTrue($loaded->self instanceof LazyLoadReference);
        $this->assertEquals($uuid, $loaded->self->getUUID());


        $hashB = spl_object_hash($loaded->self->getObject());
        $hashA = spl_object_hash($loaded);
        $this->assertEquals($hashA, $hashB);
    }

    public function testDelete()
    {
        $uuid = $this->storage->store(new stdClass());
        $this->assertTrue($this->storage->exists($uuid));
        $this->storage->delete($uuid);
        $this->assertFalse($this->storage->exists($uuid));
    }

    public function testExists()
    {
        $this->assertFalse($this->storage->exists((new Generator())->generate()));
        $this->assertFalse($this->storage->exists('foo'));
    }

    public function testStoreWithExclusiveLock()
    {
        $uuid = $this->storage->store(new stdClass());
        $this->storage->getLockAdapter()->acquireSharedLock($uuid);
        $this->assertTrue($this->storage->getLockAdapter()->isLockedByThisProcess($uuid));
        $this->assertTrue($this->storage->getLockAdapter()->hasActiveSharedLock($uuid));

        try {
            $this->storage->store(new stdClass(), $uuid);
        } catch (Throwable $e) {
            $this->assertInstanceOf(LockException::class, $e->getPrevious());;
        }
    }

    public function testDeleteWithExclusiveLock()
    {
        $uuid = $this->storage->store(new stdClass());
        $this->storage->getLockAdapter()->acquireSharedLock($uuid);
        $this->assertTrue($this->storage->getLockAdapter()->isLockedByThisProcess($uuid));
        $this->assertTrue($this->storage->getLockAdapter()->hasActiveSharedLock($uuid));

        try {
            $this->storage->delete($uuid);
        } catch (Throwable $e) {
            $this->assertInstanceOf(LockException::class, $e->getPrevious());
        }
    }

    public function testSharedLock()
    {
        $uuid = $this->storage->store(new stdClass());
        $this->assertFalse($this->storage->getLockAdapter()->isLockedByThisProcess($uuid));
        $this->assertCount(0, $this->storage->getLockAdapter()->getActiveLocks());
        $this->storage->getLockAdapter()->acquireSharedLock($uuid);
        $this->assertTrue($this->storage->getLockAdapter()->isLockedByThisProcess($uuid));
        $this->assertCount(1, $this->storage->getLockAdapter()->getActiveLocks());
        $this->assertTrue($this->storage->getLockAdapter()->hasActiveSharedLock($uuid));
    }

    public function testStoreWithConcurrentLock()
    {
        $uuid = $this->storage->store(new stdClass());
        $this->storage->getLockAdapter()->acquireExclusiveLock($uuid);

        $anotherStorage = new ObjectStorage($this->storage->getStorageDir());

        try {
            $anotherStorage->store(new stdClass(), $uuid);
        } catch (Throwable $e) {
            $this->assertInstanceOf(LockException::class, $e->getPrevious());
        }
    }

    public function testUnlock()
    {
        $uuid = $this->storage->store(new stdClass());
        $this->storage->getLockAdapter()->acquireExclusiveLock($uuid);
        $this->assertTrue($this->storage->getLockAdapter()->isLockedByThisProcess($uuid));
        $this->storage->getLockAdapter()->releaseLock($uuid);
        $this->assertFalse($this->storage->getLockAdapter()->isLockedByThisProcess($uuid));
    }

    public function testUnlockOfLockedObjectFromOtherProcess()
    {
        $uuid = $this->storage->store(new stdClass());
        $this->storage->getLockAdapter()->acquireExclusiveLock($uuid);

        $anotherStorage = new ObjectStorage($this->storage->getStorageDir());
        $this->assertTrue($anotherStorage->getLockAdapter()->isLockedByOtherProcess($uuid));
        $this->assertFalse($anotherStorage->getLockAdapter()->isLockedByThisProcess($uuid));
        $this->assertFalse($anotherStorage->getLockAdapter()->hasActiveSharedLock($uuid));
        $this->assertFalse($anotherStorage->getLockAdapter()->hasActiveExclusiveLock($uuid));

        $this->expectException(LockException::class);
        $anotherStorage->getLockAdapter()->releaseLock($uuid);
    }

    public function testStoreWithLockFromOtherProcess()
    {
        $uuid = $this->storage->store(new stdClass());
        $this->storage->getLockAdapter()->acquireExclusiveLock($uuid);

        try {
            $anotherStorage = new ObjectStorage($this->storage->getStorageDir());
            $anotherStorage->store(new stdClass(), $uuid);
        } catch (Throwable $e) {
            $this->assertInstanceOf(LockException::class, $e->getPrevious());;
        }
    }

    public function testList()
    {
        $someStorage = new ObjectStorage($someDir = $this->reserveRandomStorageDirectory());
        $someUUID = $someStorage->store(new stdClass());
        $anotherUUID = $someStorage->store(new stdClass());

        $list = $someStorage->list();

        $this->assertCount(2, $list);

        $this->assertContains($someUUID, $list);
        $this->assertContains($anotherUUID, $list);

        $this->tearDownDirectory($someDir);
    }

    public function testUUIDsNotEqual()
    {
        $uuidA = $this->storage->store(new stdClass());
        $uuidB = $this->storage->store(new stdClass());

        $this->assertNotEquals($uuidA, $uuidB);
    }

    public function testAssume()
    {
        $someStorage = new ObjectStorage($someDir = $this->reserveRandomStorageDirectory());
        $someOtherStorage = new ObjectStorage($someOtherDir = $this->reserveRandomStorageDirectory());

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
        $someStorage = new ObjectStorage($someDir = $this->reserveRandomStorageDirectory());

        $someObject = new stdClass();
        $someObject->foo = ['test' => new stdClass()];

        $someUUID = $someStorage->store($someObject);

        $this->assertEquals(2, $someStorage->count());

        $this->tearDownDirectory($someDir);
    }

    /**
     * @throws Throwable
     * @throws Exception|Throwable
     */
    public function testLazyLoadReferenceUpdatesParent()
    {
        $someStorage = new ObjectStorage($someDir = $this->reserveRandomStorageDirectory());

        $someObject = new stdClass();
        $test = new stdClass();
        $test->someAttribute = 'test';

        $someObject->foo = ['test' => $test];
        $someObject->test = $test;

        $someUUID = $someStorage->store($someObject);

        $someStorage->clearCache();

        $someObject = $someStorage->load($someUUID);

        $this->assertInstanceOf(LazyLoadReference::class, $someObject->foo['test']);

        /* access some attribute to resolve lazy load reference and update parent */
        $someObject->test->someAttribute;

        $this->assertInstanceOf('stdClass', $someObject->test);

        $this->assertInstanceOf(LazyLoadReference::class, $someObject->foo['test']);
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

        $uuid = $this->storage->store($object);

        $this->storage->clearCache();
        $loaded = $this->storage->load($uuid);

        $json = json_encode($loaded);
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
        $this->assertLessThanOrEqual(1, $this->storage->getLifetime($uuid));
        $this->assertGreaterThan(0, $this->storage->getLifetime($uuid));
        $this->assertFalse($this->storage->expired($uuid));

        sleep(1);
        $this->assertLessThanOrEqual(0, $this->storage->getLifetime($uuid));;
        sleep(1);
        $this->assertLessThan(-1, $this->storage->getLifetime($uuid)); // negative means time since expiration

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
        $this->assertLessThanOrEqual(0, $this->storage->getLifetime($childUuid));;


        $this->assertEquals($childUuid, $reloadedParent->child->getUUID());

        // Act + Assert: any access should attempt load and throw DanglingReferenceException
        $this->expectException(DanglingReferenceException::class);
        // Accessing property triggers loadIfNeeded()
        $unused = $reloadedParent->child->name;
    }

    public function testLoadWithExclusiveLock()
    {
        $uuid = $this->storage->store(new stdClass());
        $this->storage->clearCache();
        $loaded = $this->storage->load($uuid, true);
        $this->assertInstanceOf(stdClass::class, $loaded);
        $this->assertFalse($this->storage->getLockAdapter()->isLockedByOtherProcess($uuid));;
        $this->assertTrue($this->storage->getLockAdapter()->isLockedByThisProcess($uuid));
        $this->assertTrue($this->storage->getLockAdapter()->hasActiveExclusiveLock($uuid));
        $this->assertFalse($this->storage->getLockAdapter()->hasActiveSharedLock($uuid));
    }

    public function testDeletionOfFiles()
    {
        $uuid = $this->storage->store(new stdClass());
        $this->storage->delete($uuid);
        $this->assertFalse($this->storage->exists($uuid));
        $this->assertFalse(file_exists($this->storage->getFilePathData($uuid)));
        $this->assertFalse(file_exists($this->storage->getFilePathMetadata($uuid)));
        $this->assertFalse(file_exists($this->storage->getFilePathStub(stdClass::class, $uuid)));
    }

    public function testStoreWithReservedReferenceName()
    {
        $a = new stdClass();
        $b = new stdClass();
        $b->{Metadata::RESERVED_REFERENCE_NAME_DEFAULT} = 'someOtherValueThenUUID';
        $a->b = $b;

        $uuidB = $this->storage->store($b);
        $uuidA = $this->storage->store($a);
        $loaded = $this->storage->load($uuidA);

        $metadata = $this->storage->loadMetadata($uuidB);
        $this->assertNotEquals(Metadata::RESERVED_REFERENCE_NAME_DEFAULT, $metadata->getReservedReferenceName());

        $metadata = $this->storage->loadMetadata($uuidA);
        $this->assertEquals(Metadata::RESERVED_REFERENCE_NAME_DEFAULT, $metadata->getReservedReferenceName());
    }

    public function testStubRegenerationAfterClassChange()
    {
        $uuid = $this->storage->store(new stdClass());
        $this->assertFileExists($this->storage->getFilePathStub(stdClass::class, $uuid));

        $this->storage->store(new TestObjectWithReference(), $uuid);
        $this->assertFalse(file_exists($this->storage->getFilePathStub(stdClass::class, $uuid)));
        $this->assertEquals(TestObjectWithReference::class, $this->storage->getClassName($uuid));
        $this->assertFileExists($this->storage->getFilePathStub(TestObjectWithReference::class, $uuid));
    }

    public function testRedundantDeletion()
    {
        $uuid = $this->storage->store(new stdClass());
        $this->storage->delete($uuid);
        $this->assertFalse($this->storage->exists($uuid));

        try {
            $this->storage->delete($uuid);
        } catch (Throwable $e) {
            $this->assertInstanceOf(ObjectNotFoundException::class, $e->getPrevious());
        }
    }

    public function testStoreWithClosure()
    {
        $object = new stdClass();
        $object->foo = function () {
            return 'bar';
        };

        $this->assertInstanceOf(Closure::class, $object->foo);
        $uuid = $this->storage->store($object);
        $this->assertNotEmpty($uuid);

        $this->storage->clearCache();
        $loaded = $this->storage->load($uuid);

        /* since closures are not serializable, they are not stored */
        $reflection = new Reflection($loaded);
        $this->assertArrayNotHasKey('foo', $reflection->getPropertyNames());
    }

    private function makeGenerator(): PHPInternalGenerator
    {
        yield 'a' => 1;
        yield 'b' => 2;
        yield 'c' => 3;
    }

    public function testStoreObjectWithGeneratorProperty(): void
    {
        $instance = new stdClass();
        $instance->items = $this->makeGenerator();

        $uuid = $this->storage->store($instance);
        $this->assertNotEmpty($uuid, 'UUID should be returned after storing');

        $this->storage->clearCache();

        $loaded = $this->storage->load($uuid);
        $this->assertIsObject($loaded);

        // Verify the generator was materialized as an array-equivalent structure with preserved keys/values
        $this->assertTrue(isset($loaded->items), 'Property items should exist on loaded object');

        // Materialize iterable to array for assertions
        $materialized = [];
        foreach ($loaded->items as $k => $v) {
            $materialized[$k] = $v;
        }

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $materialized);
    }

    public function testStoreObjectWithIteratorAggregate(): void
    {
        // IteratorAggregate that yields key/value pairs
        $iterableObject = new class implements IteratorAggregate {
            public function getIterator(): PHPInternalGenerator
            {
                yield 10 => 'x';
                yield 20 => 'y';
            }
        };

        $obj = new class($iterableObject) {
            public iterable $items;

            public function __construct(iterable $items)
            {
                $this->items = $items;
            }
        };

        $uuid = $this->storage->store($obj);
        $this->assertNotEmpty($uuid);

        $this->storage->clearCache();

        $loaded = $this->storage->load($uuid);
        $this->assertIsObject($loaded);

        $materialized = [];
        foreach ($loaded->items as $k => $v) {
            $materialized[$k] = $v;
        }
        $this->assertSame([10 => 'x', 20 => 'y'], $materialized);
    }

    public function testStoreObjectWithSerializeMethod()
    {
        $object = new class () {
            public function __serialize()
            {
                return ['a' => $this->a ?? null];
            }
        };
        $object->a = 'test';
        $object->nested = [
            10
        ];

        $uuid = $this->storage->store($object);
        $this->assertNotEmpty($uuid);
        $this->storage->clearCache();
        $loaded = (array)$this->storage->load($uuid);
        $this->assertIsArray($loaded);
        $this->assertArrayHasKey('a', $loaded);
        $this->assertArrayNotHasKey('nested', $loaded);
    }

    public function testUUIDRemainsAfterSubsequentStore()
    {
        $object = new class() implements AwareInterface {
            public ?string $uuid = null;

            public function getUUID(): ?string
            {
                return $this->uuid;
            }

            public function setUUID(string|null $uuid): void
            {
                $this->uuid = $uuid;
            }
        };
        $uuid = $this->storage->store($object);
        $this->assertNotEmpty($uuid);
        $this->storage->clearCache();
        $loaded = $this->storage->load($uuid);
        $this->assertEquals($uuid, $loaded->uuid);
        $this->storage->clearCache();
        $this->storage->store($loaded);
        $this->assertEquals($uuid, $loaded->uuid);
    }

    public function testStoreThrowsWhenWriterMockFailsOnDataWrite(): void
    {
        // Arrange: a mock writer that throws on first atomicWrite (data), then no-op
        $failingWriter = new class implements WriterInterface {
            private int $call = 0;

            public function atomicWrite(string $filename, ?string $data = null): void
            {
                $this->call++;
                if ($this->call === 1) {
                    throw new \RuntimeException('Simulated write failure');
                }
                // subsequent calls are ignored
            }

            public function createEmptyFile(string $filename): void
            {
                // TODO: Implement createEmptyFile() method.
            }
        };

        $this->storage->setWriter($failingWriter);

        $obj = new stdClass();
        $obj->foo = 'bar';

        // Assert
        $this->expectException(ObjectSavingFailureException::class);

        // Act
        $this->storage->store($obj);
    }

    public function testStoreThrowsWhenWriterMockFailsOnMetadataWrite(): void
    {
        // Arrange: mock writer throws on 2nd atomicWrite (metadata), other calls no-op
        // Expected call order on initial store: data(.obj), metadata(.metadata), classnames.json, stub(.stub)
        $writerThatFailsOnSecondCall = new class implements WriterInterface {
            private int $calls = 0;

            public function atomicWrite(string $filename, ?string $data = null): void
            {
                $this->calls++;
                if ($this->calls === 2) {
                    throw new \RuntimeException('Simulated metadata write failure');
                }
                // all other calls succeed (no-op here)
            }

            public function createEmptyFile(string $filename): void
            {
                // TODO: Implement createEmptyFile() method.
            }
        };

        $this->storage->setWriter($writerThatFailsOnSecondCall);

        $obj = new stdClass();
        $obj->foo = 'bar';

        // Act
        try {
            $this->storage->store($obj);
        } catch (Throwable $e) {
            $this->assertInstanceOf(MetadataSavingFailureException::class, $e->getPrevious());;
        }
    }

    public function testSetAndGetExpirationWithConcreteDateTime(): void
    {
        $uuid = $this->storage->store(new stdClass());

        // choose a future time ~5 seconds from "now" to avoid flakiness
        $target = (new DateTimeImmutable())->add(new DateInterval('PT5S'));
        $this->storage->setExpiration($uuid, $target);

        $expiresAt = $this->storage->getExpiration($uuid);
        $this->assertInstanceOf(DateTimeInterface::class, $expiresAt);

        // The returned DateTime should be close to the target (second precision in metadata)
        $this->assertEquals($target->getTimestamp(), $expiresAt->getTimestamp());
        $this->assertFalse($this->storage->expired($uuid));
    }

    public function testSetExpirationToNullRemovesExpiration(): void
    {
        $uuid = $this->storage->store(new stdClass(), null, 1);

        // ensure it currently has an expiration
        $this->assertNotNull($this->storage->getExpiration($uuid));

        // remove expiration
        $this->storage->setExpiration($uuid, null);

        $this->assertNull($this->storage->getExpiration($uuid));
        $this->assertFalse($this->storage->expired($uuid));
        $this->assertNull($this->storage->getLifetime($uuid));
    }

    public function testGetExpirationReflectsUpdatedExpiration(): void
    {
        $uuid = $this->storage->store(new stdClass());

        $first = (new DateTimeImmutable())->add(new DateInterval('PT2S'));
        $this->storage->setExpiration($uuid, $first);
        $this->assertEquals($first->getTimestamp(), $this->storage->getExpiration($uuid)?->getTimestamp());

        $second = (new DateTimeImmutable())->add(new DateInterval('PT10S'));
        $this->storage->setExpiration($uuid, $second);
        $this->assertEquals($second->getTimestamp(), $this->storage->getExpiration($uuid)?->getTimestamp());
    }

    public function testClassRenameMapIsAppliedOnLoad(): void
    {
        $old = new TestObject();
        $uuid = $this->storage->store($old);

        $this->storage->clearCache();
        $old = $this->storage->load($uuid);
        $this->assertInstanceOf(TestObject::class, $old);

        $this->storage->clearCache();
        $this->storage->getClassRenameMap()->createAlias(TestObject::class, stdClass::class);
        $new = $this->storage->load($uuid);
        $this->assertInstanceOf(stdClass::class, $new);
    }

    public function testStoreWithStaticAttribute()
    {
        $a = new class {
            public static $foo = 'bar';
        };
        $uuid = $this->storage->store($a);
        $this->assertNotEmpty($uuid);
        $this->storage->clearCache();
        $loaded = $this->storage->load($uuid);
        $this->assertEquals('bar', $loaded::$foo);
    }

    public function testMaxNestingLevelExceeded()
    {
        // Instantiate storage with a low nesting limit (e.g., 2)
        $storage = new ObjectStorage($this->reserveRandomStorageDirectory(), maxNestingLevel: 2);

        $root = new stdClass();
        $level1 = new stdClass();
        $level2 = new stdClass();
        $level3 = new stdClass();

        $root->child = $level1;
        $level1->child = $level2;
        $level2->child = $level3;

        try {
            $storage->store($root);
        }catch (Exception $e) {
            $this->assertInstanceOf(MaxNestingLevelExceededException::class, $e->getPrevious());
        }

    }

    public function testLazyReferenceSkippedForIncompatibleType()
    {
        // Class with a strictly typed property that cannot hold a LazyLoadReference
        $parent = new class {
            public stdClass $child;
        };
        $parent->child = new stdClass();
        $parent->child->foo = 'bar';

        $uuid = $this->storage->store($parent);
        $this->storage->clearCache();

        $loaded = $this->storage->load($uuid);

        // Since $child is typed as stdClass, we expect the real object, not a LazyLoadReference proxy
        $this->assertInstanceOf(stdClass::class, $loaded->child);
        $this->assertNotInstanceOf(LazyLoadReference::class, $loaded->child);
        $this->assertEquals('bar', $loaded->child->foo);
    }
}