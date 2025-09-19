<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;

class ChildObject implements AwareInterface
{
    use AwareTrait;

    public string $title;
    public int $value;

    public function __construct(string $title, int $value)
    {
        $this->title = $title;
        $this->value = $value;
    }
}