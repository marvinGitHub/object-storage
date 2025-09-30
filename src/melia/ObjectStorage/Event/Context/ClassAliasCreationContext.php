<?php

namespace melia\ObjectStorage\Event\Context;

class ClassAliasCreationContext implements ContextInterface
{
    public function __construct(protected string $alias)
    {
    }

    public function getAlias(): string
    {
        return $this->alias;
    }
}