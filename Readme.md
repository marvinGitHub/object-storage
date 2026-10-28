![Object Storage](assets/logo.svg)

# ObjectStorage

**Store PHP objects as they are — whole object graphs, by UUID, in plain files. No database, no schema, no mapping.**

```php
$uuid = $storage->store($order);          // the order, its customer and all its lines
$order = $storage->load($uuid);           // related objects are loaded lazily when you touch them
echo $order->customer->name;
```

ObjectStorage is a file-based object store for PHP. It persists your objects including everything they reference, loads related objects only when they are accessed, and keeps the data safe against crashes and concurrent access.

## When to use it

- **Prototypes and small to medium applications** that want to persist domain objects without designing tables first.
- **Caches and snapshots** of computed or fetched data, with a lifetime per object.
- **Tools, CLI applications and background jobs** that need durable state without running a database server.
- **Document-like data** (configurations, drafts, workflow state) that is mostly loaded and stored as a whole.

It is not a replacement for a database when you need indexes, joins or ad-hoc queries over large amounts of data: queries are PHP callbacks that scan the stored objects of a class.

## Features

| | |
|---|---|
| **Object graphs** | Stores an object and everything it references; every object gets its own UUID, shared and circular references are kept. |
| **Lazy loading** | Referenced objects are loaded on first access and then replace their placeholder transparently. |
| **Querying** | List and count objects per class, filter them with PHP callbacks, refine results step by step. |
| **Lifetime** | Optional time-to-live per object, e.g. for caches. |
| **Concurrency** | Shared and exclusive locks per object, based on [symfony/lock](https://symfony.com/doc/current/components/lock.html) (files, Redis, Memcached, databases, …). |
| **Durability** | Crash-safe writes with checksums; interrupted writes are recovered, corrupted data puts the storage into a read-only safe mode. |
| **Caching** | In-memory cache by default, any PSR-16 cache (APCu, Redis, …) can be plugged in. |
| **Events** | Hooks for every operation, e.g. for audit logs, indexes or metrics. |
| **Refactoring support** | Renamed classes can be mapped, unknown classes still load. |
| **Tooling** | A CLI for inspection and maintenance, and a small web viewer for development. |

## Requirements

- PHP 8.5 or newer with `ext-json`
- A writable directory for the storage

## Installation

```bash
composer require melia/object-storage
```

## Quick start

```php
<?php

use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;

require __DIR__ . '/vendor/autoload.php';

class Customer implements AwareInterface
{
    use AwareTrait; // lets the storage write the UUID back into the object

    public function __construct(public string $name) {}
}

class Order implements AwareInterface
{
    use AwareTrait;

    /** @var string[] */
    public array $items = [];

    public function __construct(public mixed $customer) {}
}

$storage = new ObjectStorage(__DIR__ . '/var/storage');

// store: the order and the customer are stored as two objects
$order = new Order(new Customer('Ada'));
$order->items = ['keyboard', 'mouse'];
$uuid = $storage->store($order);

// load: the customer is loaded on first access
$order = $storage->load($uuid);
echo $order->customer->name; // Ada

// update and delete
$order->items[] = 'monitor';
$storage->store($order);
$storage->delete($uuid);
```

Continue with [Getting started](docs/getting-started.md) for the details of storing, loading and identifying objects.

## Documentation

| Topic | What you will find |
|---|---|
| [Getting started](docs/getting-started.md) | Creating a storage, storing, loading, updating and deleting objects, UUIDs |
| [Concepts](docs/concepts.md) | Object graphs, lazy loading, caching and what is stored on disk |
| [Querying](docs/querying.md) | Listing, counting and filtering objects |
| [Lifetime](docs/lifetime.md) | Expiring objects, e.g. for caches |
| [Concurrency](docs/concurrency.md) | Locks, read-modify-write, lock backends, multiple processes |
| [Durability and safe mode](docs/durability.md) | Crash-safe writes, checksums, recovery and safe mode |
| [Configuration](docs/configuration.md) | Constructor options, caches, strategy and persistence policies |
| [Events](docs/events.md) | Reacting to storage operations |
| [Serialization](docs/serialization.md) | Which objects can be stored, hooks, renamed classes |
| [Maintenance and tools](docs/maintenance.md) | CLI, viewer, rebuilding indexes, migrations |

## Examples

Complete scenarios, each runnable as is:

- [A blog with lazily loaded comments](docs/examples/blog-with-lazy-comments.md) — object graphs, lazy loading, querying
- [Caching API responses](docs/examples/api-response-cache.md) — lifetime, a fast setup without fsync, Redis locks
- [Inventory with concurrent updates](docs/examples/inventory-concurrent-updates.md) — exclusive loads, read-modify-write
- [An audit log from events](docs/examples/audit-log-with-events.md) — events with the previous and the new state
- [Renaming classes](docs/examples/renaming-classes.md) — keeping stored data loadable after a refactoring

## Changelog and license

Changes are documented in the [Changelog](Changelog.md). The library is licensed under the Apache License 2.0.
