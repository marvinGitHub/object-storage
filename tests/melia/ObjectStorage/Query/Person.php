<?php

namespace Tests\melia\ObjectStorage\Query;

use DateTimeImmutable;

class Person
{
    public function __construct(
        public ?string  $name = null,
        public ?int     $age = null,
        public int|float|null $score = null,
        public ?bool    $active = null,
        public ?string  $city = null,
        public ?Company $company = null,
        public ?Person  $friend = null,
        public ?DateTimeImmutable $born = null,
    )
    {
    }
}
