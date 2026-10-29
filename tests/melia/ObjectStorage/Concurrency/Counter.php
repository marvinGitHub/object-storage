<?php

namespace Tests\melia\ObjectStorage\Concurrency;

use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;

class Counter implements AwareInterface
{
    use AwareTrait;

    public function __construct(public int $value = 0, public string $lastWriter = '')
    {
    }
}
