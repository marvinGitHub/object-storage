<?php

namespace Tests\melia\ObjectStorage\Concurrency;

use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;

class SharedChild implements AwareInterface
{
    use AwareTrait;

    public function __construct(public string $writer = '', public int $round = 0, public string $payload = '')
    {
    }
}
