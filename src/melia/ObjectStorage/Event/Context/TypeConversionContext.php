<?php

namespace melia\ObjectStorage\Event\Context;

class TypeConversionContext implements ContextInterface
{
    public function __construct(protected object $object, protected string $propertyName, protected mixed $value, protected string $givenType, protected string $expectedType)
    {
    }

    public function getObject(): object
    {
        return $this->object;
    }

    public function getPropertyName(): string
    {
        return $this->propertyName;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getGivenType(): string
    {
        return $this->givenType;
    }

    public function getExpectedType(): string
    {
        return $this->expectedType;
    }
}