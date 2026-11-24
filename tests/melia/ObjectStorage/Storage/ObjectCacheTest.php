<?php

namespace Tests\melia\ObjectStorage\Storage;

use melia\ObjectStorage\Cache\InMemoryCache;
use melia\ObjectStorage\Event\Dispatcher;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Storage\ObjectCache;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Tests\melia\ObjectStorage\TestCase;

class ObjectCacheTest extends TestCase
{
    private const string UUID = 'ab12cd34-0000-4000-8000-000000000001';

    private Dispatcher $dispatcher;
    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = new Dispatcher();
        foreach ([Events::CACHE_HIT, Events::CACHE_ENTRY_ADDED, Events::CACHE_ENTRY_REMOVED, Events::CACHE_WRITE_FAILED] as $event) {
            $this->dispatcher->addListener($event, function () use ($event): void {
                $this->events[] = $event;
            });
        }
    }

    public function testCachedObjectsAreHits(): void
    {
        $objects = new ObjectCache(new InMemoryCache(), $this->dispatcher);
        $object = (object)['a' => 1];

        $this->assertNull($objects->get(self::UUID));
        $objects->add(self::UUID, $object);

        $this->assertSame($object, $objects->get(self::UUID));
        $this->assertSame([Events::CACHE_ENTRY_ADDED, Events::CACHE_HIT], $this->events);
    }

    public function testAnExpiredObjectIsRemoved(): void
    {
        $objects = new ObjectCache(new InMemoryCache(), $this->dispatcher);
        $objects->add(self::UUID, (object)['a' => 1]);

        $objects->add(self::UUID, (object)['a' => 2], 0);

        $this->assertNull($objects->get(self::UUID));
        $this->assertSame([Events::CACHE_ENTRY_ADDED, Events::CACHE_ENTRY_REMOVED], $this->events);
    }

    public function testAFailingCacheIsReportedNotThrown(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('set')->willThrowException(new RuntimeException('cache unavailable'));

        (new ObjectCache($cache, $this->dispatcher))->add(self::UUID, (object)['a' => 1], 60);

        $this->assertSame([Events::CACHE_WRITE_FAILED], $this->events);
    }

    public function testWithoutACacheNothingIsCached(): void
    {
        $objects = new ObjectCache(null, $this->dispatcher);
        $objects->add(self::UUID, (object)['a' => 1]);
        $objects->remove(self::UUID);
        $objects->clear();

        $this->assertNull($objects->get(self::UUID));
        $this->assertSame([Events::CACHE_ENTRY_REMOVED], $this->events, 'removing is reported all the same');
    }
}
