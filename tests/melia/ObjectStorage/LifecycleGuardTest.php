<?php

namespace Tests\melia\ObjectStorage;

class ObjectStorageLifecycleTest extends TestCase
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
}