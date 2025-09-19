<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;

class TestUser implements AwareInterface
{
    use AwareTrait;

    public string $name;
    public int $age;

    public function __construct(string $name, int $age)
    {
        $this->name = $name;
        $this->age = $age;
    }
}