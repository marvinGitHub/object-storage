# Example: inventory with concurrent updates

An online shop reserves stock when an order is placed. Many requests may reserve the same product at the same time, and the stock must never be counted wrong or drop below zero.

**You will learn:** the lost-update problem, exclusive loads, releasing locks, handling lock timeouts.

## The product

```php
<?php

use melia\ObjectStorage\Exception\ObjectLoadingFailureException;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;

require __DIR__ . '/vendor/autoload.php';

final class Product implements AwareInterface
{
    use AwareTrait;

    public function __construct(
        public string $sku,
        public int $stock,
    ) {}
}

$storage = new ObjectStorage(__DIR__ . '/var/shop');

$keyboard = new Product('KB-01', stock: 5);
$storage->store($keyboard);
$uuid = $keyboard->getUUID();
```

## The problem

The obvious implementation loses updates under load:

```php
// don't do this
$product = $storage->load($uuid);
$product->stock -= 1;
$storage->store($product);
```

Two requests that load the product at the same time both see a stock of 5 and both store 4: one reservation is lost. Locking every single `load()` and `store()` doesn't help, the gap is *between* them. See [Concurrency](../concurrency.md#read-modify-write-avoiding-lost-updates).

## Reserving with an exclusive load

```php
final class OutOfStock extends RuntimeException {}

function reserve(ObjectStorage $storage, string $uuid, int $quantity): Product
{
    // an exclusive load reads the current state and locks the product until it is stored again;
    // other requests wait at their own exclusive load
    $product = $storage->load($uuid, exclusive: true);

    if ($product->stock < $quantity) {
        // not storing: release the lock explicitly
        $storage->getLockAdapter()->releaseLock($uuid);
        throw new OutOfStock(sprintf('Only %d of %s left', $product->stock, $product->sku));
    }

    $product->stock -= $quantity;
    $storage->store($product); // writes and releases the lock

    return $product;
}
```

```php
reserve($storage, $uuid, 2);
reserve($storage, $uuid, 2);

try {
    reserve($storage, $uuid, 2);
} catch (OutOfStock $e) {
    echo $e->getMessage(), PHP_EOL; // Only 1 of KB-01 left
}

echo $storage->load($uuid)->stock, PHP_EOL; // 1
```

## When the product stays locked too long

A request waits up to 10 seconds for the lock. If it gets no lock in time, `load()` throws an `ObjectLoadingFailureException`. Retry a few times, then give up, e.g. with HTTP 503:

```php
function reserveWithRetry(ObjectStorage $storage, string $uuid, int $quantity, int $attempts = 3): Product
{
    for ($attempt = 1; ; $attempt++) {
        try {
            return reserve($storage, $uuid, $quantity);
        } catch (ObjectLoadingFailureException $e) {
            if ($attempt >= $attempts) {
                throw $e;
            }
            usleep(100_000 * $attempt); // back off: 100 ms, 200 ms, …
        }
    }
}

reserveWithRetry($storage, $uuid, 1);
echo $storage->load($uuid)->stock, PHP_EOL; // 0
```

Keep the code between the exclusive load and the store short: every request for the same product waits for it. Don't call external services while holding the lock.

An exclusive load never returns a cached copy: it always reads what is stored, so it also sees the changes of other processes in a long-running worker.

## Trying it with parallel processes

Save this as `reserve.php` and start it several times at once, e.g. `for i in 1 2 3 4; do php reserve.php & done; wait`:

```php
// reserve.php: reserves one item 25 times
$storage = new ObjectStorage(__DIR__ . '/var/shop');
$uuid = $argv[1];

for ($i = 0; $i < 25; $i++) {
    reserveWithRetry($storage, $uuid, 1);
}
```

With a stock of 100 and four processes, the stock is exactly 0 afterwards. With a plain `load()` instead of the exclusive one, it is usually higher: the lost updates.
