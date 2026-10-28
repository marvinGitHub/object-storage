# Concepts

## Objects reference each other: object graphs

When an object references other objects, `store()` stores all of them. Every object becomes a record of its own with its own UUID; the reference itself is stored as the UUID of the referenced object.

```php
class Author
{
    public function __construct(public string $name) {}
}

class Book
{
    public function __construct(
        public string $title,
        public mixed $author,
    ) {}
}

$author = new Author('Ursula K. Le Guin');
$uuid = $storage->store(new Book('The Dispossessed', $author));

// two records: the book and the author
```

This holds for objects anywhere in the graph: in properties, in arrays, in nested arrays.

- **Shared objects are stored once.** If two books reference the same author instance, both reference the same record.
- **Circular references are supported.** An object may reference itself or an object that references it back.
- **References to referenced objects are followed**, up to a maximum depth (512 by default, see [Configuration](configuration.md#strategy)).

Whether referenced objects are written again when you store the referencing object again is controlled by the [child persistence policy](configuration.md#persistence-policies): by default, a referenced object is written only if it doesn't exist yet.

## Lazy loading

When an object is loaded, the objects it references are not loaded right away. A placeholder, a `LazyLoadReference`, takes their place and loads the object on the first access:

```php
$book = $storage->load($uuid);

$book->author;        // a LazyLoadReference, nothing loaded yet
$book->author->name;  // loads the author, then returns its name
$book->author;        // now the Author object itself
```

After loading, the placeholder replaces itself with the real object, in the property or the array cell where it was. Property access, method calls and `isset()` are passed on to the loaded object, so most code doesn't notice the placeholder at all.

### Which properties are loaded lazily

A placeholder can only be put into a property whose type allows it:

| Property type | Behavior |
|---|---|
| no type, `mixed` or `object` | lazy |
| union with `LazyLoadReference`, e.g. `LazyLoadReference\|Author` | lazy |
| a concrete class, e.g. `Author` | loaded immediately (the event `LAZY_TYPE_NOT_SUPPORTED` is dispatched) |
| `array` | the array elements become placeholders |

To get both lazy loading and type information, use a union type:

```php
use melia\ObjectStorage\LazyLoadReference;

class Book
{
    public LazyLoadReference|Author $author;
}
```

Keep in mind that a placeholder is not an instance of the referenced class: `$book->author instanceof Author` is `false` until the author has been loaded, and a function with a parameter `Author $author` won't accept the placeholder. Call `getObject()` on the placeholder to load the object explicitly:

```php
$author = $book->author instanceof LazyLoadReference ? $book->author->getObject() : $book->author;
```

## Caching

Loaded and stored objects are kept in a cache, so loading an object again returns the same instance without reading files. The default cache lives in memory for the duration of the process; any PSR-16 cache can be used instead (see [Configuration](configuration.md#caches)).

```php
$storage->clearCache();        // forget all cached objects
$storage->removeFromCache($uuid);
```

In long-running processes (workers, daemons), the cache doesn't notice changes made by other processes; clear it regularly, e.g. per job. See [Concurrency](concurrency.md#multiple-processes).

## What is stored on disk

```
var/storage/
├── shards/            the objects, distributed over sub-directories by the first characters of their UUID
│   └── 0/b/
│       ├── 0b7e5b6e-….obj        the object's data (JSON)
│       └── 0b7e5b6e-….metadata   class name, checksum, timestamps, expiration
├── stubs/             one directory per class, with an empty file per object: the index for listing by class
│   └── classnames.json            the registry of all stored classes
├── locks/             lock files (only with the default file locks)
└── safeMode           exists while the storage is in safe mode
```

- The data is JSON, so it can be inspected with any tool; references appear as `{"__reference": "<uuid>"}`.
- The metadata holds a checksum of the data. It is verified on every load, see [Durability](durability.md).
- The stubs make listing and counting objects of a class fast, without reading any object; see [Querying](querying.md).
- Sharding keeps directories small with many objects; the depth is configurable, see [Configuration](configuration.md#strategy).
