# Durability and safe mode

ObjectStorage is built so that a crash — a killed process, a full disk, a power loss — never leaves an object half-written, and corrupted data is detected instead of being loaded silently.

## Checksums

Every object is stored in two files: its data and its metadata. The metadata holds a checksum of the data, which is verified on every load. Data that doesn't match its checksum is never returned.

When another process has updated the object in the meantime, the cached metadata may be outdated; in this case the metadata is reloaded from disk before the data is considered corrupted. Checksum validation can be turned off on the strategy (`$storage->getStrategy()->disableChecksumValidation()`), which is not recommended.

## Crash-safe writes

Data and metadata are written as a group:

1. Both files are first written next to their final name, as `<uuid>.obj.pending` and `<uuid>.metadata.pending`, and flushed to disk.
2. Then they are moved to their final name by atomic renames: first the data, then the metadata.

A crash before step 2 leaves the previous version untouched. A crash during step 2 leaves new data next to old metadata; this is detected on the next load or delete of the object and completed from the pending metadata. Either way, a crash never results in an object that can't be loaded.

Other files, such as the class registry, are written to a temporary file and renamed atomically.

### fsync

To survive a power loss, not only a process crash, every written file and every rename is flushed to disk with `fsync`. This makes a store considerably slower, how much depends on the disk: it takes 5 flushes to store a changed object.

Where durability doesn't matter — caches, test suites, data that can be recreated — turn it off:

```php
$storage->getWriter()->setSyncEnabled(false);
```

Without fsync, writes are still atomic and crash-safe against a killed process; only a power loss or an operating system crash may lose the most recent writes.

## Safe mode

If corrupted data is detected — data that doesn't match its checksum, data without metadata, unreadable JSON — the storage switches to **safe mode**. Loading a UUID under which nothing is stored is not an error: `load()` returns `null`.

In safe mode:

- **Reading keeps working**: `load()`, `list()` and `match()` work as before; the corrupted object itself can't be loaded.
- **Modifications are rejected**: `store()`, `delete()`, `setLifetime()`, `setExpiration()`, exclusive loads and exclusive locks throw an exception.
- **It applies to all processes** using the storage directory, and it persists across restarts: safe mode is a file (`<storage>/safeMode`).

The idea: once data is corrupted, writing more data on top of it can make things worse. Safe mode keeps the application readable until someone has looked at the problem.

### Getting out of safe mode

1. Find the cause: the logs (see [Configuration](configuration.md#logging)) and the `JSON_DECODING_FAILURE` and `OBJECT_LOADING_FAILURE` [events](events.md) name the affected object. `vendor/bin/object-storage check` scans the storage for problems.
2. Repair or delete the affected object.
3. Disable safe mode:

   ```bash
   vendor/bin/object-storage safemode --disable --dir var/storage
   ```

   or in code:

   ```php
   $storage->getStateHandler()->disableSafeMode();
   ```

Watch the `SAFE_MODE_ENABLED` event to be alerted:

```php
use melia\ObjectStorage\Event\Events;

$storage->getEventDispatcher()->addListener(Events::SAFE_MODE_ENABLED, static function (): void {
    // notify someone
});
```
