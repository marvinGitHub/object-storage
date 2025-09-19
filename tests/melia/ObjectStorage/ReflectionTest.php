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
        $this->assertTrue($reflection->isset('bar'));
        $this->assertFalse($reflection->isset('baz'));
    }

    public function testUnsetAttribute()
    {
        $foo = new stdClass();
        $foo->bar = 'baz';

        $reflection = new Reflection($foo);

        $this->assertTrue($reflection->isset('bar'));
        $reflection->unset('bar');
        $this->assertFalse($reflection->isset('bar'));
    }

    public function testGetAttribute()
    {
        $testObject = new TestObjectWithPrivateAttribute();

        $reflection = new Reflection($testObject);
        $this->assertEquals('Hello World!', $reflection->get('somePrivateAttribute'));
    }

    public function testSetAttribute()
    {
        $testObject = new TestObjectWithPrivateAttribute();

        $reflection = new Reflection($testObject);
        $reflection->set('somePrivateAttribute', 'some new value');

        $this->assertEquals('some new value', $reflection->get('somePrivateAttribute'));
    }

    public function testHasAttributeAfterRemovingNonNullablePrivateAttributeWithDefaultValue()
    {
        $testObject = new TestObjectWithPrivateAttribute();

        $reflection = new Reflection($testObject);
        $this->assertTrue($reflection->isset('somePrivateAttribute'));
        $reflection->unset('somePrivateAttribute');
        $this->assertTrue($reflection->isset('somePrivateAttribute'));
        $this->assertEquals('Hello World!', $reflection->get('somePrivateAttribute')); /* will be default value of class */
    }

    public function testHasAttributeAfterRemovingNonNullablePrivateAttributeWithoutDefaultValue()
    {
        $testObject = new TestObjectWithPrivateAttribute();

        $reflection = new Reflection($testObject);
        $this->assertFalse($reflection->isset('someAttributeWithoutDefaultValue'));
        $reflection->unset('someAttributeWithoutDefaultValue');
        $this->assertFalse($reflection->isset('someAttributeWithoutDefaultValue'));
        $this->assertEquals('', $reflection->get('someAttributeWithoutDefaultValue')); /* will be the default value based on type */
    }

    public function testHasAttributeAfterRemovingNullablePrivateAttribute()
    {
        $testObject = new TestObjectWithPrivateAttribute();

        $reflection = new Reflection($testObject);
        $this->assertTrue($reflection->isset('someNullablePrivateAttribute'));
        $reflection->unset('someNullablePrivateAttribute');
        $this->assertTrue($reflection->isset('someNullablePrivateAttribute'));
        $this->assertEquals(null, $reflection->get('someNullablePrivateAttribute'));
    }

    public function testIssetOfNonExistingAttribute()
    {
        $testObject = new TestObjectWithPrivateAttribute();

        $reflection = new Reflection($testObject);
        $this->assertFalse($reflection->isset('someNonExistingAttribute'));
    }
}