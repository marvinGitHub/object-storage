<?php

namespace Tests\melia\ObjectStorage\Upgrade\Fixture;

use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;

/**
 * Part of the storage written by 0.0.10 (tests/fixtures/upgrade): keep its name and properties, or regenerate it.
 */
class Category implements AwareInterface
{
    use AwareTrait;

    public function __construct(public string $title = '', public mixed $parent = null)
    {
    }
}
