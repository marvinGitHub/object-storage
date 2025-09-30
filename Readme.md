# ObjectStorage

A lightweight, file-based object store for PHP that persists object graphs by UUID, supports lazy loading, parent auto-updates, and handles deeply nested structures. Includes a simple viewer UI for exploring stored objects.

- Persistent storage by UUID (JSON + metadata)
- Lazy loading of references with transparent replacement in parents
- Safe mode, locking, in-memory caching
- Class stubs for fast listing, class registry
- Automatic class aliasing if a class is unknown at load time
- Simple object storage viewer (view.php)

## Installation

- Copy the library or require it via Composer in your project.
- Ensure the storage directory is writable.
- PHP 8.0+ recommended.

## Quick Start

```php
<?php
use melia\ObjectStorage\ObjectStorage;

$storage = new ObjectStorage(__DIR__ . '/var/object-storage');

// Build a small graph
$child  = new ChildObject('child-1');
$parent = new ParentObject('parent-1', $child);

// Store graph (references are auto-managed)
$uuid = $storage->store($parent);

// Load later
$loaded = $storage->load($uuid);
echo $loaded->name;         // "parent-1"
echo $loaded->child->name;  // Lazy loads "child-1"
```


## How It Works

- Graphs are serialized deterministically. When an object references another object, the reference is stored as: { "__reference": "<uuid>" }.
- On load, references become lazy until accessed; then the referenced object is loaded and the parent structure is updated to hold the real object.
- Arrays are traversed recursively; nested objects within arrays are also references and benefit from lazy loading and parent replacement.

### Lazy Loading

- Properties whose declared type allows LazyLoadReference (or object/mixed) are loaded on demand.
- Accessing a property or method on a lazy reference triggers a read from storage.
- After the first access, the placeholder is replaced in the parent object/array so subsequent access is direct.

Tip: Use union types to enable lazy loading where desired, e.g.:
- public LazyLoadReference|ChildObject $child;

If the property type is a concrete class without LazyLoadReference, the loader eagerly resolves and sets the real object.

### Parent Auto-Updates

When a lazy reference loads, it:
- Replaces itself inside its parent (object property or array cell).
- Prevents repeat loads and keeps code simple (you work with the real object thereafter).

### Nested Objects

- Objects are stored once; all occurrences become references.
- Deeply nested arrays/objects are handled uniformly.

## Viewer UI

- A simple read-only viewer is included to browse stored objects.
- Open object-storage/view.php in the browser (ensure it can access your storage directory).
## API Highlights

### General
- store(object $obj, ?string $uuid = null, ?int $ttl = null): string
    - Persists object and its referenced children; returns UUID. Optional lifetime in seconds.
- load(string $uuid, bool $exclusive = false): ?object
    - Loads object with locking (shared when $exclusive=false). Returns null if expired.
- exists(string $uuid): bool
    - Checks if object data file exists.
- delete(string $uuid, bool $force = false): bool
    - Deletes object and its metadata; returns true on success. With $force=true, returns false if not found.
- list(?string $class = null): Traversable
    - Iterates UUIDs; optionally filtered by class (via stubs).
- loadMetadata(string $uuid): ?array
    - Returns metadata (className, checksum, timestamp, etc.) or null.
- getClassName(string $uuid): ?string
    - Returns stored class name for the UUID.
- clearCache(): void
    - Clears in-memory caches.
- rebuildStubs(): void
    - Rebuilds class stub index.

### Locking (use LockAdapter)
Use when you need explicit control over concurrent access (e.g., long-running writes, cross-process coordination). ObjectStorage uses the lock adapter internally in store/load/delete; call these for advanced scenarios.

- acquireSharedLock(string $uuid, int $timeout): void
    - Obtain a read/shared lock (multiple readers allowed).
- acquireExclusiveLock(string $uuid, int $timeout): void
    - Obtain a write/exclusive lock (mutually exclusive).
- releaseLock(string $uuid): void
    - Release a held lock (shared or exclusive).
- isLocked(string $uuid): bool
    - Check if a lock exists (by any process).
- isLockedByThisProcess(string $uuid): bool
    - Check if the current process holds the lock.
- getActiveLocks(): array
    - UUIDs currently locked by this process.

When to use:
- Use LockAdapter methods when orchestrating multi-step operations where you must hold a lock across several API calls.
- Rely on internal locking via load/store/delete for single atomic operations.

### State (use StateHandler)
Use when you need to gate operations globally (e.g., fail-safe after corruption) or query/process-wide state.
- safeModeEnabled(): bool
    - Check if safe mode is active.
- enableSafeMode(): bool
    - Activate safe mode to prevent further mutations.

### Lifetime (TTL)
- getLifetime(string $uuid): ?int
    - Remaining seconds (0 at expiry, negative after expiry, null if unlimited).
- setLifetime(string $uuid, int $ttl): void
    - Sets/updates lifetime in seconds.
- expired(string $uuid): bool
    - Indicates whether the object is expired (load() returns null for expired objects).

## Locking, Caching, Safe Mode

- Locking: shared on load, exclusive on store; cleaned up automatically.
- Caching: in-memory cache avoids repeated deserialization; use clearCache() to reset.
- Safe Mode: if corrupted data is detected, safe mode is enabled to prevent writes until resolved. Disable via disableSafeMode() after fixing.

## Unknown Classes: Automatic Aliasing

If a class recorded in metadata does not exist at load time, the storage creates a class alias dynamically so the object can still be instantiated. This keeps old data readable even when types moved or were renamed (ensure you reintroduce real classes or map aliases as part of migrations when possible).

## Best Practices

- Prefer union types for properties that should be lazily loaded.
- Keep the storage directory on a fast, reliable disk with proper permissions.
- Use exclusive load/store for read-modify-write sequences.
- Monitor safe mode and logs; rebuild stubs if you reorganize storage.

## Object Storage Lifecycle Events

The object storage emits lifecycle events around critical operations. You can subscribe to these events to implement indexing, auditing, metrics, cache invalidation, background jobs, etc.

### Event names

- BEFORE_STORE — dispatched before an object is persisted.
- AFTER_STORE — dispatched after an object has been persisted (and locks released).
- OBJECT_SAVED — dispatched when the main object data file is written (before metadata/stub).
- METADATA_SAVED — dispatched after metadata has been written.
- STUB_CREATED — dispatched after a class stub has been created.
- STUB_REMOVED — dispatched after a class stub has been removed.
- BEFORE_LOAD — dispatched before an object is loaded.
- AFTER_LOAD — dispatched after an object has been loaded.
- BEFORE_DELETE — dispatched before an object is deleted.
- AFTER_DELETE — dispatched after an object has been deleted.
- CACHE_CLEARED — dispatched when the in-memory cache is cleared.
- SHARED_LOCK_ACQUIRED — dispatched when a shared lock is acquired.
- EXCLUSIVE_LOCK_ACQUIRED — dispatched when an exclusive lock is acquired.
- LOCK_RELEASED — dispatched when a lock is released.
- SAFE_MODE_ENABLED — dispatched when safe mode is enabled.
- SAFE_MODE_DISABLED — dispatched when safe mode is disabled.
- LIFETIME_CHANGED — dispatched when an object’s lifetime value changes.
- OBJECT_EXPIRED — dispatched when an object reaches/exceeds its lifetime and expires.
- CLASS_ALIAS_CREATED — dispatched when an alias is created for an unknown class.

Event constants are defined in melia\ObjectStorage\Event\Events.

### Context payloads

Listeners receive a context object implementing melia\ObjectStorage\Event\Context\ContextInterface. Common contexts include:

- Context(uuid) — carries the UUID related to the operation.
- ObjectPersistenceContext(uuid, path) — carries UUID and path for persistence ops.
- StubContext(uuid, className) — carries UUID and class name during stub lifecycle.
- LifetimeContext(uuid, previous, current) — carries lifetime changes (e.g., TTL).

Tip: Some events (like CACHE_CLEARED or SAFE_MODE_* and lock events) may have no contextual UUID.

### Subscribing to events

You can obtain the dispatcher via the AwareTrait (getEventDispatcher/setEventDispatcher) and register listeners:


## Example End-to-End

```php
<?php
$storage = new ObjectStorage(__DIR__.'/var/objects');

$child  = new ChildObject('child-X');
$parent = new ParentObject('parent-X', $child);

$uuid = $storage->store($parent);

$p = $storage->load($uuid);        // child is lazy
echo $p->child->name;              // triggers load and parent replacement
$p->child->name = 'child-X2';
$storage->store($p);               // persists changes
```

## Example Locking

```php
<?php
use melia\ObjectStorage\ObjectStorage;

$storage = new ObjectStorage(__DIR__ . '/var/object-storage');
$uuid = '...'; // existing object id

// Read-Modify-Write with exclusive lock + simple retry
$attempts = 0;
$maxAttempts = 3;

do {
    try {
        // 1) Acquire exclusive lock for this object
        $obj = $storage->load($uuid, true); // exclusive
        
        // 2) Modify under lock
        $obj->title = 'Updated Title ' . time();

        // 3) Persist while still holding the lock
        $storage->store($obj);

        // 4) Done: lock is released after operations finish
        break;
    } catch (\Throwable $e) {
        $attempts++;
        if ($attempts >= $maxAttempts) {
            // Could log and rethrow or return a 423 Locked in an API context
            throw $e;
        }
        // Back off briefly before retrying to avoid lock contention
        usleep(200_000 * $attempts); // 200ms, 400ms, 600ms
    }
} while ($storage->getLockAdapter()->isLockedByOtherProcess($uuid));
```


## License

AGPL-3.0