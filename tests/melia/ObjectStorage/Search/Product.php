<?php

namespace Tests\melia\ObjectStorage\Search;

class Product
{
    public function __construct(
        public string     $name,
        public string     $description = '',
        public mixed      $price = null,
        public array      $tags = [],
        public ?Category  $category = null,
        public bool       $active = true,
        /** @var Tag[] */
        public array      $labels = [],
    )
    {
    }
}
