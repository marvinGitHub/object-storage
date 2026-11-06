<?php

namespace Tests\melia\ObjectStorage\Upgrade\Fixture;

use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;

/**
 * Part of the storage written by 0.0.10 (tests/fixtures/upgrade): keep its name and properties, or regenerate it.
 */
class Author implements AwareInterface
{
    use AwareTrait;

    /** a product, which references this author back: a cycle */
    public mixed $favorite = null;

    public function __construct(public string $name = '', public string $email = '')
    {
    }
}
