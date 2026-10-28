# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added
- `SymfonyLockAdapter`: locking based on symfony/lock, usable with any of its stores (Flock, Redis, Memcached, PDO, Semaphore, …). Stores like Redis keep no lock files and free locks of crashed processes after a TTL.

### Changed
- The default lock adapter is now `SymfonyLockAdapter` with file locks in `<storage dir>/locks` (previously `<uuid>.lock` files directly in the storage directory). The CLI uses the same location.
- New dependency: `symfony/lock`.
- Stores are crash-safe: data and metadata are written as a group by the writer (as pending files `<uuid>.obj.pending`, `<uuid>.metadata.pending` first, then committed by atomic renames, the data before the metadata). A store interrupted between both commits is completed when loading or deleting the object, instead of enabling safe mode.
- **Breaking:** `ObjectStorage::buildShardedDirectory()` only builds the path and no longer creates the directory; the writer creates it when the first file is written. Looking up unknown UUIDs (`exists()`, `load()`, `loadMetadata()`) no longer leaves empty shard directories behind. Code writing into shard directories itself has to create them.
- `Writer` always creates a missing directory when writing; the parameter `$createDirectoryIfNotExist` of `Writer::atomicWrite()` has been removed (passing it has no effect anymore).
- `Writer` flushes every written file and the rename of it to disk (`fsync`). This makes stores considerably slower; disable it with `$storage->getWriter()->setSyncEnabled(false)` (or `new Writer(sync: false)`) where durability doesn't matter.
- The default writer and reader of a storage are created once and kept, so their configuration applies to later operations (previously `getWriter()`/`getReader()` returned a new instance on every call).
- **Breaking:** `WriterInterface` has new methods `atomicWriteAll()` (writes several files as a group) and `recover()` (completes an interrupted group write), used for the crash-safe store, as well as `syncEnabled()` and `setSyncEnabled()`. Custom writer implementations have to implement them (see `Writer`). `createEmptyFile()` has been removed from `WriterInterface` and `Writer`; the storage creates its index files through the IO adapter.
- **Breaking:** `AdapterInterface` has new methods `fsyncFile()`, `fsyncDirectory()`, `renameSync()` and `moveFileSync()`, used to flush files, directories, renames and moves to disk. Custom IO adapters have to implement them (see `RealAdapter`).
- Safe mode only blocks modifications: loading, listing and matching objects keep working, only exclusive locks (store, delete, lifetime changes, exclusive loads) are rejected. Previously every lock was rejected, so a single corrupted object made the whole storage unreadable. Loads no longer check the safe mode file, which saves a file lookup per load.

### Removed
- `Locking\Backends\FileSystem`, use `SymfonyLockAdapter::createFileLockAdapter()` instead. It deleted lock files on release, which allowed two processes to hold the same lock at once.
- `Locking\Backends\CachedLockAdapter`, use `SymfonyLockAdapter` with e.g. `RedisStore` or `MemcachedStore` instead. PSR-16 has no atomic "add", so two processes could acquire the same exclusive lock at once; with its default prefix `lock:` it didn't work with `Psr16Cache` at all. There is no replacement for APCu-based locking, since symfony/lock has no APCu store.

### Important Note
- **Stop all processes using the old version before upgrading.** Old and new versions lock different files, so they don't exclude each other while running side by side.
- Old `<uuid>.lock` files in the storage directory are no longer used and can be deleted once no process of the old version runs.
- Code creating `FileSystem` or `CachedLockAdapter` explicitly has to be migrated to `SymfonyLockAdapter`, see "Lock backends" in the Readme.

### Fixed
- Loading a UUID under which nothing is stored (never stored or deleted) enabled safe mode for the whole storage and threw an exception. `load()` now returns `null` in this case; safe mode is only enabled for data without metadata. Missing metadata is no longer logged or reported as `IO_READ_FAILURE` / `METADATA_LOADING_FAILURE` either.
- `ObjectPersistenceContext::getPreviousObject()` of the `OBJECT_SAVED` event returned the object being stored itself, taken from the cache, instead of its previous state. It now returns the previously stored state, read from disk before the data file is overwritten and without replacing the cached object.
- An exclusive load (`load($uuid, true)`) returned the cached object if the process had one, which doesn't know about changes of other processes. A read-modify-write in a long-running process could therefore lose updates. An exclusive load now always reads the stored state and replaces the cached copy.
- The default writer and reader of a storage ignored the IO adapter given to the storage and always accessed the real filesystem. They now use the adapter of the storage, and follow it when it is changed by `setIOAdapter()`; an explicitly set writer or reader keeps its own adapter.
- Viewer: duplicating a record copied the metadata including the UUID of the original, so later metadata updates of the duplicate (e.g. its lifetime) overwrote the metadata of the original. The duplicate is now written with its own UUID, data and metadata as a crash-safe group.
- Loading an object that another process had updated in the meantime could enable safe mode for the whole storage: the data was validated against outdated cached metadata. On a checksum mismatch the metadata is now reloaded from disk once; safe mode is only enabled if the data doesn't match the metadata on disk either.
- `load()` no longer releases a lock that the caller acquired before (e.g. by a previous exclusive load or a running `store()`).
- `store()` of a loaded `LazyLoadReference` failed for objects not implementing the UUID `AwareInterface`.
- `BEFORE_UPDATE` / `AFTER_UPDATE` were not dispatched when updating an object without passing its UUID explicitly.
- Scalar type conversion during hydration never took effect. Values are now converted to the declared scalar type, and lossy conversions (e.g. `"abc"` or `2.5` to `int`) throw a `TypeConversionFailureException`.
- `LazyLoadReference` kept the first loaded reference and its root object alive for the lifetime of the process. Expired references are now reported as expired instead of "could not be loaded".

## [0.0.4] - Support for Cached Locking (Psr16 Cache)

### Added
- Cached Locking Backend see melia\ObjectStorage\Locking\Backends\CachedLockingAdapter

## [0.0.3] - Support for Sharding Depth

### Added
- Migration script for transitioning from version 0.0.2 to 0.0.3.
- Added policy which determines how child objects are stored. See StrategyInterface::POLICY_CHILD_WRITE_* constants.

### Important Note
- **Migration Required**: You must execute the migration script `002_003.php`. This script **must be executed from inside the `migrations/` directory** to ensure paths are resolved correctly.

## [0.0.2] - Support for Sharding

### Added
- Migration script for transitioning from version 0.0.1 to 0.0.2.

### Important Note
- **Migration Required**: You must execute the migration script `001_002.php`. This script **must be executed from inside the `migrations/` directory** to ensure paths are resolved correctly.

## [0.0.1] - Initial Release

### Added
- Initial release of the Object Storage