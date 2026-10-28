# Concurrency

Several processes can use the same storage at the same time, e.g. the requests of a web application or parallel workers. ObjectStorage locks objects while they are read or written, so no process reads half-written data.

## Automatic locking

| Operation | Lock |
|---|---|
| `load($uuid)` | shared lock while reading; several processes can read at once |
| `store($object)` | exclusive lock while writing |
| `delete($uuid)` | exclusive lock while deleting |

Locks wait up to 10 seconds for another process; then the operation fails with an exception.

## Read-modify-write: avoiding lost updates

Automatic locking protects every single operation, but not a sequence of them. If two processes load the same object, change it and store it, the second `store()` overwrites the change of the first:

```
process A: load    → stock = 10
process B: load    → stock = 10
process A: stock - 1, store → 9
process B: stock - 1, store → 9   (should be 8)
```

To prevent this, load the object **exclusively**. The exclusive lock is held until the object is stored again, so a second process waits at its own exclusive load:

```php
$product = $storage->load($uuid, exclusive: true); // waits while another process holds the object
$product->stock--;
$storage->store($product);                         // writes and releases the lock
```

An exclusive load always reads the stored state, never a cached copy, so it sees changes of other processes even in long-running processes. It returns a new instance and replaces the cached one; changes made to the cached instance that weren't stored are not part of it.

If you decide not to store the object, release the lock yourself:

```php
$product = $storage->load($uuid, exclusive: true);

if ($product->stock === 0) {
    $storage->getLockAdapter()->releaseLock($uuid);
    return;
}
```

### Creating an object only once

If nothing is stored under the UUID, an exclusive load returns `null` but keeps the lock. This allows creating an object without another process creating it at the same time:

```php
$counter = $storage->load($uuid, exclusive: true) ?? new Counter();
$counter->value++;
$storage->store($counter, $uuid); // creates or updates, and releases the lock
```

See [Inventory with concurrent updates](examples/inventory-concurrent-updates.md) for a complete example including error handling.

### Referenced objects are not locked

`store()` locks the object you store, not the objects it references and writes along with it. If several processes update the same referenced object through different parents, load and store that object exclusively on its own.

## Locking manually

The lock adapter can also be used directly, e.g. to hold a lock over several operations:

```php
$locks = $storage->getLockAdapter();

$locks->acquireExclusiveLock($uuid, timeout: 5);
try {
    // ...
} finally {
    $locks->releaseLock($uuid);
}
```

| Method | Purpose |
|---|---|
| `acquireSharedLock($uuid, $timeout)` | read lock, several holders at once |
| `acquireExclusiveLock($uuid, $timeout)` | write lock, one holder |
| `releaseLock($uuid)` | release the lock of this process |
| `isLockedByThisProcess($uuid)` | whether this process holds a lock |
| `isLockedByOtherProcess($uuid)` | whether another process holds a lock |
| `getActiveLocks()` | UUIDs locked by this process |

## Lock backends

Locking is based on [symfony/lock](https://symfony.com/doc/current/components/lock.html). By default, locks are file locks in `<storage>/locks`, which works for processes on the same machine. Any symfony/lock store can be used with the `SymfonyLockAdapter`, e.g. Redis when several machines share the storage:

```php
use melia\ObjectStorage\Locking\Backends\SymfonyLockAdapter;
use melia\ObjectStorage\ObjectStorage;
use Symfony\Component\Lock\Store\RedisStore;

$redis = new Redis();
$redis->connect('localhost', 6379);

$storage = new ObjectStorage(
    __DIR__ . '/var/storage',
    lockAdapter: new SymfonyLockAdapter(new RedisStore($redis), prefix: 'my-storage.'),
);
```

| Store | Lock files | Shared locks | Notes |
|---|---|---|---|
| `FlockStore` (default) | yes, one small file per object | yes | one machine only |
| `RedisStore`, `PostgreSqlStore` | no | yes | |
| `MemcachedStore`, `PdoStore`, `DoctrineDbalStore` | no | no | readers wait for each other |
| `SemaphoreStore` | no | no | Linux only, `ext-sysvsem` |

- Lock files of the default store remain after the lock is released. This is intended: deleting them is not race-free.
- Stores with an expiry (e.g. Redis) free the locks of crashed processes after the TTL of the adapter (300 seconds by default, constructor argument `ttl`).
- Use a distinct `prefix` per storage when several storages share one store.

## Multiple processes

- **Caches are per process.** The default in-memory cache doesn't notice when another process changes an object. In short-lived processes (web requests) this doesn't matter; in long-running ones (workers, daemons), call `$storage->clearCache()` regularly, e.g. per job, or before reading data another process may have changed.
- **Safe mode is shared.** If one process detects corrupted data, all processes using the storage switch to read-only mode, see [Durability](durability.md#safe-mode).
