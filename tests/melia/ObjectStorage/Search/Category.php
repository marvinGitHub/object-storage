<?php

namespace Tests\melia\ObjectStorage\Search;

class Category
{
    public function __construct(public string $title, public ?Category $parent = null)
    {
    }
}
