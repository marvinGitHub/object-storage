<?php

namespace melia\ObjectStorage\Event\Context;

class LazyTypeNotSupportedContext implements ContextInterface
{

    public function __construct(protected string $className, protected string $propertyName)
    {
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getPropertyName(): string
    {
        return $this->propertyName;
    }
}