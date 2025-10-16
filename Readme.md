![Object Storage](assets/logo.svg)

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
- store(object $obj, ?string $uuid = null, null|int|float $ttl = null): string
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
    - Check if the safe mode is active.
- enableSafeMode(): bool
    - Activate safe mode to prevent further mutations.

### Lifetime (TTL)
- getLifetime(string $uuid): ?float
    - Remaining seconds (0 at expiry, negative after expiry, null if unlimited).
- setLifetime(string $uuid, int|float $ttl): void
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
- CLASSNAME_CHANGED — dispatched when the class name of an object is changed.
- CACHE_HIT — dispatched when an object is loaded from cache.
- CACHE_ENTRY_ADDED — dispatched when an object is added to the cache.
- CACHE_ENTRY_REMOVED — dispatched when an object is removed from the cache.
- BEFORE_TYPE_CONVERSION — dispatched before type conversion.
- LAZY_TYPE_NOT_SUPPORTED — dispatched when a lazy type is not supported.
- BEFORE_INITIAL_STORE — dispatched before initial store.
- BEFORE_UPDATE  — dispatched before update.
- AFTER_UPDATE  — dispatched after update.

Event constants are defined in melia\ObjectStorage\Event\Events.

### Context payloads

Listeners receive a context object implementing melia\ObjectStorage\Event\Context\ContextInterface. Common contexts include:

- Context(uuid) — carries the UUID related to the operation.
- ObjectPersistenceContext(uuid, path) — carries UUID and path for persistence ops.
- StubContext(uuid, className) — carries UUID and class name during stub lifecycle.
- LifetimeContext(uuid, previous, current) — carries lifetime changes (e.g., TTL).

Tip: Some events (like CACHE_CLEARED or SAFE_MODE_* and lock events) may have no contextual UUID.

### Subscribing to events

You can get the dispatcher via the AwareTrait (getEventDispatcher/setEventDispatcher) and register listeners:

## Example Storing and Loading

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

## Example Event Listener

```php
<?php
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Event\Events;
use melia\ObjectStorage\Event\DispatcherInterface;
use melia\ObjectStorage\Event\Context\Context;
use melia\ObjectStorage\Event\Context\ObjectPersistenceContext;
use melia\ObjectStorage\Event\Context\ClassnameChangeContext;
use melia\ObjectStorage\Event\Context\LifetimeContext;
use melia\ObjectStorage\Event\Context\StubContext;
use melia\ObjectStorage\Event\Context\TypeConversionContext;
use melia\ObjectStorage\Event\Context\LazyTypeNotSupportedContext;

// Initialize storage (using defaults)
$storage = new ObjectStorage(__DIR__ . '/var/object-storage');

// Get the dispatcher and register listeners
/** @var DispatcherInterface $dispatcher */
$dispatcher = $storage->getEventDispatcher();

// Called before an object is stored
$dispatcher->addListener(Events::BEFORE_STORE, function (ObjectPersistenceContext $ctx) {
    // $ctx->getUuid()
});

// Called after an object is stored (with previous object if available)
$dispatcher->addListener(Events::OBJECT_SAVED, function (ObjectPersistenceContext $ctx) {
    // $ctx->getUuid(), $ctx->getObject(), $ctx->getPreviousObject()
});

// Called after metadata is saved
$dispatcher->addListener(Events::METADATA_SAVED, function (Context $ctx) {
    // $ctx->getUuid()
});

// Called before and after load
$dispatcher->addListener(Events::BEFORE_LOAD, function (Context $ctx) {});
$dispatcher->addListener(Events::AFTER_LOAD, function (Context $ctx) {});

// Cache hit during load
$dispatcher->addListener(Events::CACHE_HIT, function (Context $ctx) {});

// Object expired during load
$dispatcher->addListener(Events::OBJECT_EXPIRED, function (Context $ctx) {});

// Lifetime changed via setExpiration/setLifetime
$dispatcher->addListener(Events::LIFETIME_CHANGED, function (LifetimeContext $ctx) {
    // $ctx->getUuid(), $ctx->getExpiresAt()
});

// Stub files created/removed
$dispatcher->addListener(Events::STUB_CREATED, function (StubContext $ctx) {
    // $ctx->getUuid(), $ctx->getClassName()
});
$dispatcher->addListener(Events::STUB_REMOVED, function (StubContext $ctx) {});

// Classname changed for existing UUID
$dispatcher->addListener(Events::CLASSNAME_CHANGED, function (ClassnameChangeContext $ctx) {
    // $ctx->getUuid(), $ctx->getPreviousClassname(), $ctx->getNewClassname()
});

// After delete
$dispatcher->addListener(Events::AFTER_DELETE, function (Context $ctx) {});

// Clear cache
$dispatcher->addListener(Events::CACHE_CLEARED, function () {});

// Shared lock acquired
$dispatcher->addListener(Events::SHARED_LOCK_ACQUIRED, function (Context $ctx) {});

// Exclusive lock acquired
$dispatcher->addListener(Events::EXCLUSIVE_LOCK_ACQUIRED, function (Context $ctx) {});

// Lock released
$dispatcher->addListener(Events::LOCK_RELEASED, function (Context $ctx) {});

// Safe mode enabled
$dispatcher->addListener(Events::SAFE_MODE_ENABLED, function () {});

// Safe mode disabled
$dispatcher->addListener(Events::SAFE_MODE_DISABLED, function () {});

// Type conversion
$dispatcher->addListener(Events::BEFORE_TYPE_CONVERSION, function (TypeConversionContext $ctx) {
    // $ctx->getObject(), $ctx->getPropertyName(), $ctx->getGivenType(), $ctx->getExpectedType()
});

// Lazy type isn't supported
$dispatcher->addListener(Events::LAZY_TYPE_NOT_SUPPORTED, function (LazyTypeNotSupportedContext $ctx) {
    // $ctx->getClassName(), $ctx->getPropertyName()
}

// Cache entry added
$dispatcher->addListener(Events::CACHE_ENTRY_ADDED, function (Context $ctx) {});

// Cache entry removed
$dispatcher->addListener(Events::CACHE_ENTRY_REMOVED, function (Context $ctx) {});

// Use storage as usual
$uuid = $storage->store((object)['name' => 'Alice']);
$loaded = $storage->load($uuid);
$storage->delete($uuid);
```

## Why use melia\ObjectStorage\UUID\AwareInterface

Implementing melia\ObjectStorage\UUID\AwareInterface (getUUID/setUUID) makes object identity explicit and stable. This enables:

- Reliable persistence: Objects keep the same UUID across store/load cycles.
- Correct graph handling: Related objects are referenced by UUID (no duplication, supports cycles).
- Better performance: Caching and locking are keyed by UUID.
- Lifecycle control: TTL/expiration and metadata are tracked per UUID.
- Lazy loading: Properties can hold UUID-based references and load on demand.
- Interop and portability: UUIDs work across processes and systems.

## Why resources and closures are not serialized

Object graphs can include values that aren’t portable or safely reconstructible outside the current PHP process. Two notable cases:

### Resources
Examples: file handles, sockets, database connections.

- What they are: Opaque handles to OS/engine state that only make sense within the current process and moment in time.
- Why not serializable: Their identity/state cannot be meaningfully captured in JSON (or any durable format). Even with metadata (path, mode), you cannot restore the same live handle state (cursor position, locks, permissions).
- Correct approach: Persist minimal, reconstructible data (e.g., file path, intended open mode) and re-open the resource when needed by application code.

### Closures (anonymous functions)
- What they are: Executable code bound to a dynamic environment (scope variables, $this, static state).
- Why not serializable: Built-in serialization cannot safely capture code + bound context portably. Round-tripping across processes, versions, or deployments is brittle and a security risk.
- Correct approach: Persist a symbolic reference instead (e.g., class + method name, or a handler key), and resolve to a callable at runtime. For workflows/queues, store commands/events (DTOs), not raw callables.

### Library behavior
- Attempting to serialize such values will be skipped and logged, or will raise:
    - ResourceSerializationNotSupportedException for resources
    - ClosureSerializationNotSupportedException for closures

### Design guideline
Persist data, not live process artifacts. Keep serialization deterministic and reconstructible from plain values.

## Generators and Iterables

- Generators and other iterables (Generator, Iterator, IteratorAggregate, Traversable) are materialized during storage. The library walks the iterator and stores the yielded keys and values as a regular array-like structure. On load, you’ll get a traversable/array structure with the same keys/values, not a live generator.

- Why: generators are stateful, one-shot, and tied to the runtime; persisting them as-is is neither portable nor reproducible. Materialization ensures deterministic storage and retrieval.

- Important warning about infinite or very large generators:
    - Infinite generators will never finish materializing and will hang or exhaust resources.
    - Very large generators can consume excessive memory/time when materialized.

- Recommendation: only store finite, bounded generators/iterables. If you need streaming or lazy sequences, persist a bounded snapshot (e.g., a page) or a descriptor (query params, offsets) and reconstruct the stream at runtime.

## Object Sleep and Wakeup

- On store: a clone is put to sleep via `__sleep()` before serialization; the original instance stays unchanged.
- On load: after reconstruction, `__wakeup()` restores transient state.

### Implement
- `__sleep()`: return properties to persist and prepare state.
- `__wakeup()`: reinitialize resources, caches, or derived fields.

### Notes
- Hooks are optional; persistence works without them.
- Don’t serialize raw resources; recreate them in `__wakeup()`.
- Lazy references may load on demand unless the property type requires a concrete object.

## Command Line Interface
See [CLI Documentation](docs/cli.md)

## License

AGPL-3.0