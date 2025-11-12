<?php

namespace Tests\melia\ObjectStorage;

use DateInterval;
use melia\ObjectStorage\Cache\InMemoryCache;

class InMemoryCacheTest extends TestCase
{
    public function testSetAndGetWithoutTtl(): void
    {
        $cache = new InMemoryCache();
        $this->assertTrue($cache->set('a', 123));
        $this->assertSame(123, $cache->get('a'));
        $this->assertTrue($cache->has('a'));
    }

    public function testGetReturnsDefaultWhenMissing(): void
    {
        $cache = new InMemoryCache();
        $this->assertSame('x', $cache->get('missing', 'x'));
        $this->assertFalse($cache->has('missing'));
    }

    public function testDelete(): void
    {
        $cache = new InMemoryCache();
        $cache->set('k', 'v');
        $this->assertTrue($cache->delete('k'));
        $this->assertNull($cache->get('k'));
        $this->assertFalse($cache->has('k'));
    }

    public function testClear(): void
    {
        $cache = new InMemoryCache(['x' => 1, 'y' => 2]);
        $this->assertTrue($cache->clear());
        $this->assertFalse($cache->has('x'));
        $this->assertFalse($cache->has('y'));
    }

    public function testSetMultipleAndGetMultiple(): void
    {
        $cache = new InMemoryCache();
        $this->assertTrue($cache->setMultiple(['a' => 1, 'b' => 2]));
        $values = $cache->getMultiple(['a', 'b', 'c'], 'd');

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 'd'], $values);
    }

    public function testDeleteMultiple(): void
    {
        $cache = new InMemoryCache();
        $cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);
        $this->assertTrue($cache->deleteMultiple(['a', 'c']));

        $this->assertFalse($cache->has('a'));
        $this->assertTrue($cache->has('b'));
        $this->assertFalse($cache->has('c'));
    }

    public function testTtlPositiveExpiresLater(): void
    {
        $cache = new InMemoryCache();
        $this->assertTrue($cache->set('t', 'v', 2)); // 2 seconds
        $this->assertTrue($cache->has('t'));
        $this->assertSame('v', $cache->get('t'));
    }

    public function testTtlZeroRemovesEntry(): void
    {
        $cache = new InMemoryCache();
        // ttl=0 means already expired; set should behave like delete/no-op
        $this->assertTrue($cache->set('e', 'v', 0));
        $this->assertFalse($cache->has('e'));
        $this->assertNull($cache->get('e'));
    }

    public function testTtlNegativeRemovesEntry(): void
    {
        $cache = new InMemoryCache();
        $this->assertTrue($cache->set('e', 'v', -10));
        $this->assertFalse($cache->has('e'));
        $this->assertNull($cache->get('e'));
    }

    public function testExpiredEntryIsEvictedOnAccess(): void
    {
        $cache = new InMemoryCache();
        $this->assertTrue($cache->set('k', 'v', 1));
        // Simulate time passing by waiting slightly over 1 second
        usleep(1100000);
        $this->assertFalse($cache->has('k'));
        $this->assertSame('d', $cache->get('k', 'd'));
        // After eviction, key should be gone
        $this->assertFalse($cache->has('k'));
    }

    public function testDateIntervalTtl(): void
    {
        $cache = new InMemoryCache();
        $ttl = new DateInterval('PT1S'); // 1 second
        $this->assertTrue($cache->set('di', 'v', $ttl));
        $this->assertTrue($cache->has('di'));
        $this->assertSame('v', $cache->get('di'));
        usleep(1100000);
        $this->assertFalse($cache->has('di'));
    }

    public function testConstructorInitialValuesAreStoredWithoutExpiry(): void
    {
        $cache = new InMemoryCache(['x' => 10, 'y' => 20]);
        $this->assertSame(10, $cache->get('x'));
        $this->assertSame(20, $cache->get('y'));
        $this->assertTrue($cache->has('x'));
        $this->assertTrue($cache->has('y'));
    }
}