# Product search

A product catalog searchable by full text, with filters, sorting and facets. Covers creating, building and attaching a [search index](../search.md) with the Loupe engine.

Requires `composer require loupe/loupe` and the PHP extensions `intl` and `pdo_sqlite`.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Search\Loupe\LoupeEngine;
use melia\ObjectStorage\Search\Request;

final class Category
{
    public function __construct(public string $title) {}
}

final class Product
{
    public function __construct(
        public string $name,
        public string $description,
        public float $price,
        public array $tags,
        public Category $category,
        public bool $available = true,
    ) {}
}

$storage = new ObjectStorage(__DIR__ . '/var/storage');

// 1) the index manager with the engines it may use; the first one is the default
$search = new Manager($storage, [new LoupeEngine()]);

// 2) define and create the index once, e.g. in a setup script (or in the viewer)
if (!$search->has('products')) {
    $search->create(new Definition(
        name: 'products',
        className: Product::class,
        fields: [
            new Field('name', 'name'),
            new Field('description', 'description'),
            new Field('price', 'price', FieldType::NUMBER, searchable: false, filterable: true, sortable: true),
            new Field('tags', 'tags', FieldType::STRING_ARRAY, filterable: true),
            new Field('category', 'category', searchable: false, filterable: true), // the UUID of the category
            new Field('available', 'available', FieldType::BOOLEAN, searchable: false, filterable: true),
        ],
        languages: ['de', 'en'],
    ));
}

// 3) keep the indexes up to date with every store and delete of this storage
$search->attach();

$fruit = new Category('Fruit');
$storage->store(new Product('Apple', 'A crunchy red apple from the region', 0.49, ['organic', 'regional'], $fruit));
$storage->store(new Product('Banana', 'Sweet and yellow', 0.29, ['organic'], $fruit));
$storage->store(new Product('Chair', 'Oak wood chair', 89.0, ['furniture'], new Category('Furniture'), false));

// 4) build the index once: objects stored before are indexed now, later changes follow automatically
$products = $search->get('products');
if (!$products->isSearchable()) {
    $products->build(static function (array $progress): void {
        printf("%d/%d indexed (%d %%)\n", $progress['processed'], $progress['total'], $progress['percent']);
    });
}

// 5) search: full text (typo-tolerant: "aple" finds "apple"), a filter in Loupe's language, sorting, highlighting
$result = $products->search(new Request(
    query: 'crunchy aple',
    filter: "available = true AND price < 1 AND tags IN ('organic')",
    sort: ['price:asc'],
    hitsPerPage: 20,
    highlight: true,
));

printf("%d hits\n", $result->totalHits);
foreach ($result->hits as $hit) {
    printf("%s  %s  %.2f  %s\n", $hit->uuid, $hit->document['name'], $hit->document['price'], $hit->highlighted['description'] ?? '');
}

// 6) or load the objects right away, in the order of the hits
foreach ($products->searchObjects(new Request(filter: "tags = 'organic'", sort: ['price:desc'])) as $uuid => $product) {
    printf("%s: %s\n", $uuid, $product->name);
}

// 7) facets: how often each value occurs among the hits
print_r($products->search(new Request(facets: ['tags', 'available'], hitsPerPage: 0))->facets);
```

Output of the first run (UUIDs differ):

```
0/3 indexed (0 %)
3/3 indexed (100 %)
1 hits
0d5add13-e032-48fc-be27-098ef53b1063  Apple  0.49  A <mark>crunchy</mark> red apple from the region
0d5add13-e032-48fc-be27-098ef53b1063: Apple
9ec16e0e-dd8c-4aa8-9f09-971b2fc486bd: Banana
Array
(
    [tags] => Array ( [furniture] => 1 [organic] => 2 [regional] => 1 )
    [available] => Array ( [false] => 1 [true] => 2 )
)
```

## What happens

- `create()` only stores the definition, in the internal storage `var/storage/indexes/internal/`; the index is empty. Its data goes to `var/storage/indexes/loupe/products/`.
- `attach()` registers listeners on the storage. Each `store()` of a `Product` records its UUID as a pending change of the index; as the index hasn't been built yet, nothing else happens.
- `build()` collects the UUIDs of all products in a [UUID trie](../search.md#uuid-tries), then indexes them in steps in the order of their UUIDs, reading their stored data. The categories are indexed as UUIDs, as they are separate objects.
- From now on, every `store()` and `delete()` of a product updates the index before it returns.

Only the filters are written in Loupe's language. Everything else, including this code, stays the same with [another engine](../search.md#connecting-another-engine).

## Variations

**Products of a category.** The field `category` holds the UUID of the referenced category:

```php
$fruitUuid = $storage->store($fruit); // store() returns the UUID; products referencing $fruit use the same one
$products->search(new Request(filter: sprintf("category = '%s'", $fruitUuid)));
```

To search by the category's title, store it in the product as well (e.g. a property `categoryTitle`), or index it from an array.

**Restricting a search to known objects.** `uuid` is always filterable:

```php
$products->search(new Request(query: 'apple', filter: "uuid IN ('…', '…')"));
```

**Indexing in the background.** With `attach(autoSync: false)`, `store()` only records the change, and a cron job applies it:

```php
// cron.php, every minute
(new Manager($storage, [new LoupeEngine()]))->sync();
```

**Adding a field later.** Update the definition and rebuild; searches use the previous definition meanwhile:

```php
$search->update(new Definition('products', Product::class, [/* fields, now with 'origin' */], ['de', 'en']));
$search->get('products')->build();
```

**Loupe's own parameters.** For features beyond `Request`, pass Loupe's `SearchParameters`; `$result->raw` holds Loupe's result:

```php
use Loupe\Loupe\SearchParameters;

$result = $products->search(SearchParameters::create()->withQuery('apple')->withAttributesToRetrieve(['uuid', 'price']));
```
