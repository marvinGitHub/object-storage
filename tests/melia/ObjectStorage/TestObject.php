<?php

namespace Tests\melia\ObjectStorage;

class TestObject
{
    public ?string $somePublicAttributeWhichDefaultsToNull = null;
    public string $somePublicAttributeWithoutDefaultValue;
    private string $somePrivateAttribute = 'Hello World!';
    private ?string $someNullablePrivateAttribute = 'Here wo go again!';
    private string $someAttributeWithoutDefaultValue;
}