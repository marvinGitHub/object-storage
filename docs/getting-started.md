# Getting started

This guide walks through the basic operations: creating a storage, storing, loading, updating and deleting objects.

## Creating a storage

A storage lives in a directory. It is created if it doesn't exist.

```php
use melia\ObjectStorage\ObjectStorage;

$storage = new ObjectStorage(__DIR__ . '/var/storage');
```

That's all you need. Locking, caching and crash-safe writes work out of the box; see [Configuration](configuration.md) for the options.

## Storing an object

```php
class Note
{
    public function __construct(
        public string $title,
        public string $text,
    ) {}
}

$uuid = $storage->store(new Note('Shopping', 'Milk, bread'));
```

`store()` returns the UUID of the object. All properties are stored, public as well as protected and private ones.

## Loading an object

```php
$note = $storage->load($uuid);
echo $note->title; // Shopping
```

`load()` returns `null` if nothing is stored under the UUID, e.g. because the object was deleted, or if it has expired (see [Lifetime](lifetime.md)). Loading an object twice returns the same instance from the cache, unless the cache was cleared.

## Updating an object

Change the object and store it again under its UUID:

```php
$note->text = 'Milk, bread, butter';
$storage->store($note, $uuid);
```

The storage only writes if something changed: storing an unchanged object costs a checksum comparison, no write.

## Knowing the UUID of an object

Passing the UUID around works, but it is easier when objects know their own UUID. Implement `AwareInterface` with the `AwareTrait`, and the storage writes the UUID into the object on `store()` and `load()`:

```php
use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;

class Task implements AwareInterface
{
    use AwareTrait;

    public function __construct(public string $title) {}
}

$task = new Task('Write docs');
$storage->store($task);

echo $task->getUUID();      // the UUID assigned by the storage

$task->title = 'Write better docs';
$storage->store($task);     // no UUID needed: the object knows it
```

Objects without the interface keep their UUID inside the storage as long as the same instance is used in the same process; across processes, keep the UUID returned by `store()`.

You can also choose the UUID yourself (it has to be a valid UUID v4):

```php
$storage->store($task, '0b7e5b6e-3a5c-4f7e-9d8b-1c2a3b4c5d6e');
```

## Deleting an object

```php
$storage->delete($uuid);
```

Deleting removes the object itself, not the objects it references. Check for existence with `exists()`:

```php
$storage->exists($uuid); // false
```

## Next steps

- [Concepts](concepts.md) explains what happens when objects reference each other.
- [Querying](querying.md) shows how to find objects without knowing their UUID.
- The [examples](../Readme.md#examples) show complete scenarios.
