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

    public function testGetDynamicallyDeclaredProperty()
    {
        $testObject = new TestObject();
        $testObject->dynamicProp = 'test';

        $reflection = new Reflection($testObject);

        $this->assertEquals('test', $reflection->get('dynamicProp'));
    }

    public function testSetAttribute()
    {
        $testObject = new TestObject();

        $reflection = new Reflection($testObject);
        $reflection->set('somePrivateAttribute', 'some new value');

        $this->assertEquals('some new value', $reflection->get('somePrivateAttribute'));
    }

    public function testSetDynamicallyDeclaredProperty()
    {
        $testObject = new TestObject();
        $reflection = new Reflection($testObject);
        $reflection->set('dynamicProp', 'test');
        $this->assertEquals('test', $reflection->get('dynamicProp'));
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

        $names = $reflection->getPropertyNames();
        $this->assertContains('someNullablePrivateAttribute', $names);
    }

    public function testIssetOfNonExistingAttribute()
    {
        $testObject = new TestObject();

        $reflection = new Reflection($testObject);
        $this->assertFalse($reflection->initialized('someNonExistingAttribute'));

        $names = $reflection->getPropertyNames();
        $this->assertNotContains('someNonExistingAttribute', $names);
    }

    public function testReturnsDeclaredProperties(): void
    {
        $obj = new TestObject();
        $collector = new Reflection($obj);

        $names = $collector->getPropertyNames();

        $this->assertContains('somePublicAttributeWhichDefaultsToNull', $names);
        $this->assertContains('somePublicAttributeWithoutDefaultValue', $names);
        $this->assertContains('somePrivateAttribute', $names);
        $this->assertContains('someNullablePrivateAttribute', $names);
        $this->assertContains('someAttributeWithoutDefaultValue', $names);
    }

    public function testReturnsDynamicProperties(): void
    {
        $obj = new TestObject();
        $obj->dynamicProp = 'value';

        $collector = new Reflection($obj);

        $names = $collector->getPropertyNames();

        $this->assertContains('dynamicProp', $names);
    }

    public function testReturnsDeclaredAndDynamicProperties(): void
    {
        $obj = new TestObject();
        $obj->dynamicProp = 'value';

        $collector = new Reflection($obj);

        $names = $collector->getPropertyNames();

        $this->assertEqualsCanonicalizing(
            [
                'somePublicAttributeWhichDefaultsToNull',
                'somePublicAttributeWithoutDefaultValue',
                'somePrivateAttribute',
                'someNullablePrivateAttribute',
                'someAttributeWithoutDefaultValue',
                'dynamicProp'
            ],
            $names
        );
    }

    public function testDoesNotDuplicatePropertyNames(): void
    {
        $obj = new TestObject();
        $obj->somePublicAttributeWhichDefaultsToNull = 'test'; // already declared

        $collector = new Reflection($obj);

        $names = $collector->getPropertyNames();

        $this->assertCount(
            count(array_unique($names)),
            $names
        );
    }
}