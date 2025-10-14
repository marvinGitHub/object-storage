<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Event\Context\ContextInterface;
use melia\ObjectStorage\Event\Context\LifetimeContext;
use melia\ObjectStorage\Event\Dispatcher;
use melia\ObjectStorage\Event\DispatcherInterface;
use melia\ObjectStorage\Event\Events;

final class ObjectStorageEventDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $dispatcher = new class extends Dispatcher {
            public array $events = [];

            public function dispatch(string $event, ?ContextInterface $context = null): void
            {
                $this->events[] = $event;
                parent::dispatch($event, $context);
            }
        };
        $this->storage->setEventDispatcher($dispatcher);
    }

    public function testStoreFiresExpectedEvents(): void
    {
        $obj = new class {
            public string $foo = 'bar';
        };

        $uuid = $this->storage->store($obj);

        $this->assertNotEmpty($uuid);

        // Order-sensitive subset: BEFORE_STORE -> OBJECT_SAVED -> METADATA_SAVED -> STUB_CREATED -> AFTER_STORE
        $this->assertContains(Events::BEFORE_STORE, $this->storage->getEventDispatcher()->events);
        $this->assertContains(Events::OBJECT_SAVED, $this->storage->getEventDispatcher()->events);
        $this->assertContains(Events::METADATA_SAVED, $this->storage->getEventDispatcher()->events);
        $this->assertContains(Events::STUB_CREATED, $this->storage->getEventDispatcher()->events);
        $this->assertContains(Events::AFTER_STORE, $this->storage->getEventDispatcher()->events);

        // Ensure sequence (index ordering)
        $beforeIdx = array_search(Events::BEFORE_STORE, $this->storage->getEventDispatcher()->events, true);
        $savedIdx = array_search(Events::OBJECT_SAVED, $this->storage->getEventDispatcher()->events, true);
        $metaIdx = array_search(Events::METADATA_SAVED, $this->storage->getEventDispatcher()->events, true);
        $stubIdx = array_search(Events::STUB_CREATED, $this->storage->getEventDispatcher()->events, true);
        $afterIdx = array_search(Events::AFTER_STORE, $this->storage->getEventDispatcher()->events, true);

        $this->assertIsInt($beforeIdx);
        $this->assertIsInt($savedIdx);
        $this->assertIsInt($metaIdx);
        $this->assertIsInt($stubIdx);
        $this->assertIsInt($afterIdx);

        $this->assertTrue($beforeIdx < $savedIdx);
        $this->assertTrue($savedIdx < $metaIdx);
        $this->assertTrue($metaIdx <= $stubIdx); // stub after metadata
        $this->assertTrue($stubIdx <= $afterIdx);
    }

    public function testLoadFiresBeforeAfterLoadAndCacheHit(): void
    {
        $obj = new class {
            public int $n = 1;
        };
        $uuid = $this->storage->store($obj);

        // First load triggers BEFORE_LOAD, AFTER_LOAD
        $this->storage->getEventDispatcher()->events = [];
        $this->storage->clearCache();
        $loaded = $this->storage->load($uuid);

        $this->assertNotNull($loaded);
        $this->assertContains(Events::BEFORE_LOAD, $this->storage->getEventDispatcher()->events);
        $this->assertContains(Events::AFTER_LOAD, $this->storage->getEventDispatcher()->events);
        $this->assertNotContains(Events::CACHE_HIT, $this->storage->getEventDispatcher()->events);

        // Second load should hit cache and fire CACHE_HIT (and may still fire BEFORE/AFTER_LOAD around it depending on implementation)
        $this->storage->getEventDispatcher()->events = [];
        $loaded2 = $this->storage->load($uuid);
        $this->assertNotNull($loaded2);
        $this->assertContains(Events::CACHE_HIT, $this->storage->getEventDispatcher()->events);
    }

    public function testClearCacheFiresEvent(): void
    {
        $this->storage->clearCache();

        $this->assertContains(Events::CACHE_CLEARED, $this->storage->getEventDispatcher()->events);
    }

    public function testSetLifetimeFiresLifetimeChanged(): void
    {
        $obj = new class {
            public string $x = 'y';
        };
        $uuid = $this->storage->store($obj);

        $this->storage->getEventDispatcher()->events = [];
        $this->storage->setLifetime($uuid, 60);

        $this->assertContains(Events::LIFETIME_CHANGED, $this->storage->getEventDispatcher()->events);
    }

    public function testDeleteFiresBeforeAfterAndStubRemoved(): void
    {
        $obj = new class {
            public string $a = 'b';
        };
        $uuid = $this->storage->store($obj);

        $this->storage->getEventDispatcher()->events = [];
        $this->storage->delete($uuid);

        $this->assertContains(Events::BEFORE_DELETE, $this->storage->getEventDispatcher()->events);
        $this->assertContains(Events::AFTER_DELETE, $this->storage->getEventDispatcher()->events);
        $this->assertContains(Events::STUB_REMOVED, $this->storage->getEventDispatcher()->events);
    }

    public function testExpiredFiresObjectExpiredOnLoad(): void
    {
        $obj = new class {
            public string $a = 'b';
        };
        $uuid = $this->storage->store($obj, null, 1); // ttl 1s

        // Make sure it expires
        sleep(2);

        $this->storage->getEventDispatcher()->events = [];
        $loaded = $this->storage->load($uuid);

        $this->assertNull($loaded);
        $this->assertContains(Events::OBJECT_EXPIRED, $this->storage->getEventDispatcher()->events);
    }

    public function testClassnameChangedFiresEvent(): void
    {
        // First store as ClassA-like
        $obj1 = new class {
            public string $v = '1';
        };
        $uuid = $this->storage->store($obj1);

        // Now store a different class under same UUID by reusing assignment
        // Assign the same UUID to a new object of a different anonymous class:
        $obj2 = new class {
            public string $v = '2';
        };
        // Assign UUID by storing with explicit UUID
        $this->storage->getEventDispatcher()->events = [];
        $this->storage->store($obj2, $uuid);

        $this->assertContains(Events::CLASSNAME_CHANGED, $this->storage->getEventDispatcher()->events);
    }

    public function testLifetimeChangedFiresEvent(): void
    {
        $obj = new class {
            public string $v = '1';
        };


        $calls = 0;
        $this->storage->getEventDispatcher()->addListener(Events::LIFETIME_CHANGED, function(LifetimeContext $context) use (&$calls)  {
            $calls++;
        });

        $uuid = $this->storage->store($obj);
        $this->assertEquals(0, $calls);
        $this->storage->setLifetime($uuid, 10);
        $this->assertEquals(1, $calls);
    }
}