<?php

namespace Tests\melia\ObjectStorage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class LifecycleGuardTest extends TestCase
{
    public function testSerializeIsCalledOnStoreAndUnserializeOnLoad(): void
    {
        $obj = new class {
            public int $serializeCalls = 0;
            public int $unserializeCalls = 0;
            public string $state = 'live';

            public function __serialize(): array
            {
                $this->serializeCalls++;
                $this->state = 'prepared';
                return ['serializeCalls' => $this->serializeCalls, 'unserializeCalls' => $this->unserializeCalls, 'state' => $this->state];
            }

            public function __unserialize(array $data): void
            {
                $this->unserializeCalls++;
                $this->state = 'restored';
            }
        };

        $this->assertSame('live', $obj->state);

        // Act: store using ObjectStorage
        $uuid = $this->storage->store($obj);

        // Assert: original object must be untouched (store clones before calling __serialize)
        $this->assertSame(1, $obj->serializeCalls);
        $this->assertSame('prepared', $obj->state);

        // Clear cache to force load path
        $this->storage->clearCache();

        // Act: load using ObjectStorage
        $loaded = $this->storage->load($uuid);

        // Assert: __unserialize called during load
        $this->assertNotNull($loaded);
        $this->assertSame(1, $loaded->unserializeCalls);
        $this->assertSame('restored', $loaded->state);
    }

    public function testSerializeWithIntKeysIsStoredAndLoaded(): void
    {
        // ArrayObject::__serialize() returns a list (int keys)
        $uuid = $this->storage->store(new \ArrayObject(['a' => 1, 'b' => [2, 3]]));
        $this->storage->clearCache();

        $loaded = $this->storage->load($uuid);

        $this->assertInstanceOf(\ArrayObject::class, $loaded);
        $this->assertSame(['a' => 1, 'b' => [2, 3]], $loaded->getArrayCopy());
    }

    public function testUnserializeReceivesReferencedObjectsLoaded(): void
    {
        $child = new ChildObject('child', 1);
        $node = new SerializableNode('parent');
        $node->next = $child;

        $uuid = $this->storage->store($node);
        $this->storage->clearCache();

        $loaded = $this->storage->load($uuid);

        // __unserialize() gets what __serialize() returned: the object, not the stored reference
        $this->assertSame('parent', $loaded->name);
        $this->assertInstanceOf(ChildObject::class, $loaded->next);
        $this->assertSame('child', $loaded->next->title);
        $this->assertSame($child->getUUID(), $loaded->next->getUUID());
    }

    public function testUnserializeReceivesReferencedObjectsInNestedArrays(): void
    {
        $node = new SerializableNode('parent');
        $node->items = ['children' => [new ChildObject('a', 1), new ChildObject('b', 2)], 'count' => 2];

        $uuid = $this->storage->store($node);
        $this->storage->clearCache();

        $loaded = $this->storage->load($uuid);

        $this->assertSame(2, $loaded->items['count']);
        $this->assertContainsOnlyInstancesOf(ChildObject::class, $loaded->items['children']);
        $this->assertSame(['a', 'b'], array_map(fn(ChildObject $c) => $c->title, $loaded->items['children']));
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function provideCacheEnabled(): array
    {
        return ['with cache' => [true], 'without cache' => [false]];
    }

    /**
     * Runs in its own process: without the cycle handling, loading recurses until PHP runs out of memory,
     * which is a fatal error that would abort the whole test run instead of failing this test.
     */
    #[DataProvider('provideCacheEnabled')]
    #[RunInSeparateProcess]
    public function testUnserializeResolvesReferenceCycles(bool $cacheEnabled): void
    {
        $a = new SerializableNode('a');
        $b = new SerializableNode('b');
        $a->next = $b;
        $b->next = $a;

        $uuid = $this->storage->store($a);
        $this->storage->clearCache();
        if (false === $cacheEnabled) {
            $this->storage->setCache(null);
        }

        $loaded = $this->storage->load($uuid);

        // the cycle gets the instance being restored instead of loading it again endlessly
        $this->assertSame('b', $loaded->next->name);
        $this->assertSame($loaded, $loaded->next->next);
    }

    public function testUnserializeReceivesNullForDeletedReferencedObjects(): void
    {
        $child = new ChildObject('child', 1);
        $node = new SerializableNode('parent');
        $node->next = $child;

        $uuid = $this->storage->store($node);
        $this->storage->delete($child->getUUID());
        $this->storage->clearCache();

        $loaded = $this->storage->load($uuid);

        $this->assertSame('parent', $loaded->name);
        $this->assertNull($loaded->next);
    }
}
