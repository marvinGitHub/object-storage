# ObjectStorage

A lightweight, file-based object store with:
- Persistent storage by UUID
- Lazy loading of referenced objects
- Automatic parent updates when lazy references resolve
- Safe-mode, locking, caching, and class stubs
- Support for nested arrays and nested objects

## Features

- Store/load any PHP object by UUID
- Serialize graphs without recursion via reference markers
- LazyLoadReference defers loading until access
- On first access, the parent structure is updated transparently
- Locking (shared/exclusive) to prevent corruption
- Safe mode activation on corrupted data
- Optional in-memory cache
- Class registry and per-class stubs for fast listing

## Installation

- Require the library (copy sources) and ensure your storage directory is writable.
- PHP 8.0+ recommended.

## Quick Start

```php
<?php
$storage = new ObjectStorage(__DIR__ . '/var/object-storage');

// Create your objects
$child = new ChildObject('child-1');
$parent = new ParentObject('parent-1', $child);

// Store
$parentUuid = $storage->store($parent);

// Load later
$loaded = $storage->load($parentUuid);

// Access
echo $loaded->name;        // "parent-1"
echo $loaded->child->name; // "child-1"
```


## Referencing and Lazy Loading

Object graphs are stored without duplicating sub-objects. References are persisted as special markers:
- When an object has another object as a property, it’s stored as { "__reference": "<uuid>" }.
- On load, references are represented as LazyLoadReference (unless the property type disallows it).

Example: Parent with a referenced child

```php
<?php
$child = new ChildObject('child-A');
$childUuid = $storage->store($child);

$parent = new ParentObject('parent-A', $child);
$parentUuid = $storage->store($parent);

// Later: load parent
$parentLoaded = $storage->load($parentUuid);

// The child is a LazyLoadReference until you access it
$isLazy = $parentLoaded->child instanceof LazyLoadReference; // true
// Access triggers load
$childName = $parentLoaded->child->name; // Loads child transparently
```


### How Lazy Loading Works

- On deserialization, properties referring to other objects become LazyLoadReference if the declared property type allows it (e.g., LazyLoadReference|ChildObject|object|mixed).
- Accessing a property or method on the reference triggers loading from storage.
- After loading, the LazyLoadReference updates the parent structure so subsequent accesses hit the real object (no repeated loading).

## Parent Updates (Transparent Replacement)

When a LazyLoadReference loads its target:
- It replaces itself inside the parent object (or array) at the exact path where it lived.
- This makes future reads/writes operate on the actual object.
- Works for nested paths (objects or arrays), not just direct properties.

You can observe:
- Before first access: parent->child is LazyLoadReference
- After first access: parent->child is the real ChildObject

## Nested Objects and Arrays

Nested structures are handled recursively:
- Objects are stored once and referenced everywhere else via "__reference".
- Arrays are traversed deeply; nested objects inside arrays are stored as references too.
- On load, nested references inside arrays become LazyLoadReference instances and are also transparently replaced on first access.

Example: Nested arrays

```php
<?php
$engine = new Engine('E1');
$car = new Car('C1', ['engine' => $engine]);

$carUuid = $storage->store($car);
$carLoaded = $storage->load($carUuid);

// engine is lazy initially
$engineType = $carLoaded->parts['engine']->type; // triggers load and updates array cell
```


## Property Type Compatibility

- If a property type includes LazyLoadReference (or is object/mixed), it may be lazily loaded.
- If a property type is concrete (e.g., ChildObject only) and does not allow LazyLoadReference, the storage layer eagerly resolves the reference during load and sets the concrete object.

Tip: Use union types to allow lazy loading:
- Example: public LazyLoadReference|ChildObject $child;

## Caching

- In-memory cache can be enabled (default true) to avoid repeated deserialization for the same UUIDs.
- Clear via:
```php
$storage->clearCache();
```


## Locking

- load($uuid, $exclusive = false) acquires a lock (shared by default, exclusive if requested) for the duration of load.
- store() acquires an exclusive lock until write completes.
- Locks are cleaned up automatically; unlocks happen even on exceptions.

## Safe Mode

- If corrupted data is detected, safe mode is enabled:
    - A safe-mode flag prevents further writes until investigated.
- Disable manually once fixed:
```php
$storage->disableSafeMode();
```


## Listing and Metadata

- List objects (optionally by class):
```php
foreach ($storage->list() as $uuid) { /* ... */ }
foreach ($storage->list(MyClass::class) as $uuid) { /* ... */ }
```


- Get metadata or class name:
```php
$meta = $storage->loadMetadata($uuid); // ['className' => ..., 'checksum' => ...]
$class = $storage->getClassname($uuid);
```


- Rebuild stubs (class indexes):
```php
$storage->rebuildStubs();
```


## JSON Schema/Preview

- See what will be persisted (references included):
```php
echo $storage->createJSONSchema($object);
```


## Error Handling

- Exceptions are thrown for locking issues, missing objects, invalid UUIDs, serialization errors, safe-mode activation, and IO failures.
- Ensure try/finally or higher-level handling around store/load when appropriate.

## Best Practices

- Declare properties as unions to enable lazy loading where beneficial.
- Keep storageDir on a fast disk; ensure proper permissions.
- Use exclusive locks for sequences of read-modify-write.
- Monitor safe mode and logs for integrity issues.
- For large graphs, rely on lazy loading to reduce memory footprint.

## Example: End-to-End

```php
<?php
$storage = new ObjectStorage(__DIR__.'/var/objects');

// Build a graph
$child = new ChildObject('child-X');
$parent = new ParentObject('parent-X', $child);

// Store all (references created automatically)
$uuid = $storage->store($parent);

// Load later
$p = $storage->load($uuid);

// Lazy access triggers child load and parent update
echo $p->child->name;

// Modify and persist
$p->child->name = 'child-X2';
$storage->store($p);
```


## License

Choose a license that fits your distribution needs. If you require noncommercial-only terms, note that such licenses are not OSI-approved “open source.” For true OSS, consider MIT/Apache-2.0/GPL/AGPL depending on your goals.