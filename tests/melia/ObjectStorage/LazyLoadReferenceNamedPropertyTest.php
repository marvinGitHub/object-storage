<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\LazyLoadReference;
use stdClass;

class Test
{
    public stdClass $childStdClass;
    public object $childAnyObject;

    public mixed $childMixed = null;

    public object|null $childUnionAnyObjectOrNull = null;
    public stdClass|null $childUnionStdClassOrNull = null;
}

class LazyLoadReferenceNamedPropertyTest extends TestCase
{

    public function testUnwrapLazyLoadReferenceOnNamedPropertyWhichDoesNotAllowLazyLoadReferences()
    {
        $child = new stdClass();
        $child->name = 'test';
        $a = new Test();
        $a->childStdClass = $child;
        $a->childAnyObject = $child;
        $a->childUnionAnyObjectOrNull = $child;
        $a->childUnionStdClassOrNull = $child;
        $a->childMixed = $child;

        $uuid = $this->storage->store($a);
        $this->storage->clearCache();
        $a = $this->storage->load($uuid);

        $this->assertEquals('test', $a->childStdClass->name);
        $this->assertFalse($a->childStdClass instanceof LazyLoadReference);
        $this->assertTrue($a->childStdClass instanceof stdClass);

        $this->assertTrue($a->childAnyObject instanceof LazyLoadReference);
        $this->assertEquals('test', $a->childAnyObject->name);

        $this->assertTrue($a->childUnionAnyObjectOrNull instanceof LazyLoadReference);
        $this->assertEquals('test', $a->childUnionAnyObjectOrNull->name);

        $this->assertFalse($a->childUnionStdClassOrNull instanceof LazyLoadReference);
        $this->assertTrue($a->childUnionStdClassOrNull instanceof stdClass);

        $this->assertTrue($a->childMixed instanceof LazyLoadReference);
    }
}