<?php

namespace Tests\melia\ObjectStorage\Upgrade\Fixture;

use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;

/**
 * Part of the storage written by 0.0.10 (tests/fixtures/upgrade): keep its name and properties, or regenerate it.
 */
class Product implements AwareInterface
{
    use AwareTrait;

    /** @var list<string> */
    public array $tags = [];

    /** @var list<mixed> other products, by reference */
    public array $related = [];

    /** @var array<string, mixed> nested values */
    public array $dimensions = [];

    public mixed $category = null;

    public mixed $author = null;

    public ?string $description = null;

    public function __construct(
        public string $name = '',
        public float  $price = 0.0,
        public int    $stock = 0,
        public bool   $active = true,
    ) {
    }
}
