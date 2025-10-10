<?php

namespace Tests\melia\ObjectStorage;

class ObjectStorageLifecycleTest extends TestCase
{
    public function testSleepIsCalledOnStoreAndWakeupOnLoad(): void
    {
        $obj = new class {
            public int $sleepCalls = 0;
            public int $wakeupCalls = 0;
            public string $state = 'live';

            public function __sleep(): array
            {
                $this->sleepCalls++;
                $this->state = 'prepared';
                return ['sleepCalls', 'wakeupCalls', 'state'];
            }

            public function __wakeup(): void
            {
                $this->wakeupCalls++;
                $this->state = 'restored';
            }
        };

        // Act: store using ObjectStorage
        $uuid = $this->storage->store($obj);

        // Assert: original object must be untouched (store clones before calling __sleep)
        $this->assertSame(0, $obj->sleepCalls);
        $this->assertSame('live', $obj->state);

        // Clear cache to force load path
        $this->storage->clearCache();

        // Act: load using ObjectStorage
        $loaded = $this->storage->load($uuid);

        // Assert: wakeup called during load
        $this->assertNotNull($loaded);
        $this->assertSame(1, $loaded->wakeupCalls);
        $this->assertSame('restored', $loaded->state);
    }
}