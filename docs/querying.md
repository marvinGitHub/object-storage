# Querying

ObjectStorage has no query language. Instead, you list the UUIDs of a class and filter objects with PHP callbacks. This keeps queries flexible and type-safe, but every filter loads the objects it looks at, so it scans all objects of the given class.

## Listing objects

`list()` iterates over UUIDs, as keys and values:

```php
foreach ($storage->list(Product::class) as $uuid) {
    // ...
}

$uuids = iterator_to_array($storage->list(Product::class), false);
```

With a class name, the UUIDs come from the class index ("stubs"), without reading any object. Without a class name, `list()` iterates over all stored objects.

The classes that have been stored can be listed as well:

```php
$storage->getClassNames(); // ['App\Product' => 'App\Product', ...]
```

## Counting objects

```php
$count = iterator_count($storage->list(Product::class)); // cheap: reads the class index only
```

`$storage->count(Product::class)` returns the same number but loads every object, so prefer `iterator_count()` on `list()` for counting.

## Filtering objects

`match()` loads the objects and returns those for which the callback returns `true`, as `UUID => object`:

```php
$expensive = $storage->match(
    static fn(Product $product) => $product->price > 100,
    Product::class,
);

foreach ($expensive as $uuid => $product) {
    echo $product->name, PHP_EOL;
}
```

Parameters:

| Parameter | Purpose |
|---|---|
| `$matcher` | `callable(object): bool`, receives the loaded object |
| `$className` | only consider objects of this class; strongly recommended, otherwise all objects are loaded |
| `$limit` | stop after this many matches |
| `$subSet` | only consider these UUIDs, see below |

`match()` is a generator: objects are loaded one by one while you iterate, and nothing is loaded if you don't iterate.

Referenced objects inside the matched objects are lazy, see [Concepts](concepts.md#lazy-loading). Accessing them in the callback loads them, which is convenient, but costs a read per referenced object.

### The first match only

```php
foreach ($storage->match(static fn(User $user) => $user->email === $email, User::class, limit: 1) as $user) {
    return $user;
}
```

### Refining results step by step

Pass the result of a previous `match()` (or any array of UUIDs) as `$subSet` to filter only those objects further:

```php
$inStock = iterator_to_array($storage->match(
    static fn(Product $product) => $product->stock > 0,
    Product::class,
));

$cheapInStock = iterator_to_array($storage->match(
    static fn(Product $product) => $product->price < 20,
    subSet: $inStock,
));
```

Keys and values of `$subSet` are both accepted, so the `UUID => object` result of `match()` works as well as a plain list of UUIDs.

## Performance

- Filtering costs one read per object of the class, cached objects excepted. A few thousand objects are fine; for large data sets with frequent queries, keep your own index (e.g. maintained by [events](events.md)) or use a database.
- Always pass the class name to `match()` and `list()`.
- The class index is updated by `store()` and `delete()`. If files were copied or moved outside the storage, rebuild it, see [Maintenance](maintenance.md#rebuilding-the-class-index).
