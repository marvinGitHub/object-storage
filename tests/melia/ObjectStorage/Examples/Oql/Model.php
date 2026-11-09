<?php

/**
 * The classes of docs/examples/oql-by-example.md (there without namespace).
 */

namespace Tests\melia\ObjectStorage\Examples\Oql;

use DateTimeImmutable;

final class Category
{
    public function __construct(
        public string    $title,
        public ?Category $parent = null,
    ) {}
}

final class Product
{
    public function __construct(
        public string   $name,
        public float    $price,
        public Category $category,
        public bool     $active = true,
        public array    $labels = [],   // list of strings
    ) {}
}

final class Customer
{
    public function __construct(
        public string  $name,
        public string  $email,
        public ?string $city = null,
        public array   $tags = [],      // list of strings
        public array   $phones = [],    // map: kind => number
    ) {}
}

final class PurchaseLine
{
    public function __construct(
        public Product $product,
        public int     $quantity,
        public float   $price,
    ) {}
}

final class Purchase
{
    // added later, see "Adding a property": not promoted, so objects stored before load with null
    public ?DateTimeImmutable $paidAt = null;

    /**
     * @param list<PurchaseLine> $lines
     */
    public function __construct(
        public string             $number,
        public Customer           $customer,
        public DateTimeImmutable  $createdAt,
        public array              $lines = [],
        public string             $status = 'open',
        public ?DateTimeImmutable $shippedAt = null,
    ) {}
}
