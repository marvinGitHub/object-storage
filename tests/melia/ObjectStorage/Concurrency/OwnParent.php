<?php

namespace Tests\melia\ObjectStorage\Concurrency;

use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;

class OwnParent implements AwareInterface
{
    use AwareTrait;

    public function __construct(public string $name = '', public ?SharedChild $child = null)
    {
    }
}
