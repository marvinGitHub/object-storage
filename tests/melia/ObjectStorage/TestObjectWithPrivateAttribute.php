<?php

namespace Tests\melia\ObjectStorage;

class TestObjectWithPrivateAttribute
{
    private string $somePrivateAttribute = 'Hello World!';
    private ?string $someNullablePrivateAttribute = 'Here wo go again!';
    private string $someAttributeWithoutDefaultValue;
}