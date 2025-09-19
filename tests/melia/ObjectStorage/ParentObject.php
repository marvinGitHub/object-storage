<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;

class ParentObject implements AwareInterface
{
    use AwareTrait;

    public string $name;
    public LazyLoadReference|ChildObject|ParentObject $child;

    public function __construct(string $name, ChildObject|LazyLoadReference|ParentObject $child)
    {
        $this->name = $name;
        $this->child = $child;
    }
}
