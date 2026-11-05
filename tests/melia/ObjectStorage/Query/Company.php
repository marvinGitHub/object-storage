<?php

namespace Tests\melia\ObjectStorage\Query;

class Company
{
    public function __construct(
        public ?string $name = null,
        public ?string $country = null,
        public ?int    $size = null,
    )
    {
    }
}
