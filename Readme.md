AI Assistant

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

- store(object $obj, ?string $uuid = null): string
    - Persists object and sub-objects; returns UUID.
- load(string $uuid, bool $exclusive = false): ?object
    - Loads object; acquires lock during read.
- list(?string $class = null): Traversable
    - Iterates UUIDs; optionally filtered by class.
- loadMetadata(string $uuid): ?array
    - Returns metadata (className, checksum, timestamp).
- getClassname(string $uuid): ?string
- clearCache(): void
- rebuildStubs(): void

Utility:
- createJSONSchema(object $obj): string
    - Shows what would be stored (with __reference markers).

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


## License

AGPL-3.0