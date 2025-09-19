<?php

namespace Tests\melia\ObjectStorage;

class TestObject
{
    private string $somePrivateAttribute = 'Hello World!';
    private ?string $someNullablePrivateAttribute = 'Here wo go again!';
    private string $someAttributeWithoutDefaultValue;
    public ?string $somePublicAttributeWhichDefaultsToNull = null;
    public string $somePublicAttributeWithoutDefaultValue;
}