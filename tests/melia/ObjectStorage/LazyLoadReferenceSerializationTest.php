<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\LazyLoadReference;
use stdClass;

class LazyLoadReferenceSerializationTest extends TestCase
{
    public function testSerialize(): void
    {
        $parent = new \stdClass();
        $child = new \stdClass();
        $child->name = 'Child';
        $child->age = 30;
        $parent->child = $child;

        $uuid = $this->storage->store($parent);
        $this->assertNotEmpty($uuid);
        $this->assertTrue($this->storage->exists($uuid));
        $this->storage->clearCache();

        $parent = $this->storage->load($uuid);
        $this->assertInstanceOf(\stdClass::class, $parent);
        $this->assertInstanceOf(LazyLoadReference::class, $parent->child);

        $uuidChild = $parent->child->getUUID();
        $serialized = str_replace(':uuidChild', $uuidChild, 'O:37:"melia\ObjectStorage\LazyLoadReference":3:{s:4:"uuid";s:36:":uuidChild";s:4:"root";N;s:4:"path";a:1:{i:0;s:5:"child";}}');
        $this->assertEquals($serialized, serialize($parent->child));

        $unserialized = unserialize($serialized);
        $this->assertInstanceOf(LazyLoadReference::class, $unserialized);
        $this->assertEquals($uuidChild, $unserialized->getUUID());
        $this->assertNull($unserialized->getRoot());
        $this->assertFalse($unserialized->isLoaded());
    }

    public function testIsset()
    {
        $parent = new \stdClass();
        $child = new \stdClass();
        $child->name = 'Child';
        $child->age = 30;
        $parent->child = $child;

        $uuid = $this->storage->store($parent);
        $this->storage->clearCache();
        $parent = $this->storage->load($uuid);

        $child = $parent->child;
        $this->assertInstanceOf(LazyLoadReference::class, $parent->child);
        $this->assertFalse($child->isLoaded());

        $this->assertTrue(isset($parent->child->age));
        $this->assertFalse(isset($parent->child->unknownProperty));
        $this->assertTrue($child->isLoaded());
        $this->assertInstanceOf(stdClass::class, $parent->child);
    }
}