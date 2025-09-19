<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Reflection\Reflection;
use stdClass;

class ReflectionTest extends TestCase
{
    public function testHasAttribute()
    {
        $foo = new stdClass();
        $foo->bar = 'baz';

        $reflection = new Reflection($foo);
        $this->assertTrue($reflection->initialized('bar'));
        $this->assertFalse($reflection->initialized('baz'));
    }

    public function testUnsetAttribute()
    {
        $foo = new stdClass();
        $foo->bar = 'baz';

        $reflection = new Reflection($foo);

        $this->assertTrue($reflection->initialized('bar'));
        $reflection->unset('bar');
        $this->assertFalse($reflection->initialized('bar'));
    }

    public function testGetAttribute()
    {
        $testObject = new TestObject();

        $reflection = new Reflection($testObject);
        $this->assertEquals('Hello World!', $reflection->get('somePrivateAttribute'));
    }

    public function testSetAttribute()
    {
        $testObject = new TestObject();

        $reflection = new Reflection($testObject);
        $reflection->set('somePrivateAttribute', 'some new value');

        $this->assertEquals('some new value', $reflection->get('somePrivateAttribute'));
    }

    public function testHasAttributeAfterRemovingNonNullablePrivateAttributeWithDefaultValue()
    {
        $testObject = new TestObject();

        $reflection = new Reflection($testObject);
        $this->assertTrue($reflection->initialized('somePrivateAttribute'));
        $reflection->unset('somePrivateAttribute');
        $this->assertTrue($reflection->initialized('somePrivateAttribute'));
        $this->assertEquals('Hello World!', $reflection->get('somePrivateAttribute')); /* will be default value of class */
    }

    public function testHasAttributeAfterRemovingNonNullablePrivateAttributeWithoutDefaultValue()
    {
        $testObject = new TestObject();

        $reflection = new Reflection($testObject);
        $this->assertFalse($reflection->initialized('someAttributeWithoutDefaultValue'));
        $reflection->unset('someAttributeWithoutDefaultValue');
        $this->assertFalse($reflection->initialized('someAttributeWithoutDefaultValue'));
        $this->assertEquals('', $reflection->get('someAttributeWithoutDefaultValue')); /* will be the default value based on type */
    }

    public function testHasAttributeAfterRemovingNullablePrivateAttribute()
    {
        $testObject = new TestObject();

        $reflection = new Reflection($testObject);
        $this->assertTrue($reflection->initialized('someNullablePrivateAttribute'));
        $reflection->unset('someNullablePrivateAttribute');
        $this->assertTrue($reflection->initialized('someNullablePrivateAttribute'));
        $this->assertEquals(null, $reflection->get('someNullablePrivateAttribute'));
    }

    public function testIssetOfNonExistingAttribute()
    {
        $testObject = new TestObject();

        $reflection = new Reflection($testObject);
        $this->assertFalse($reflection->initialized('someNonExistingAttribute'));
    }
}