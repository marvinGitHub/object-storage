# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added
- `SymfonyLockAdapter`: locking based on symfony/lock, usable with any of its stores (Flock, Redis, Memcached, PDO, Semaphore, …). Stores like Redis keep no lock files and free locks of crashed processes after a TTL.

### Changed
- The default lock adapter is now `SymfonyLockAdapter` with file locks in `<storage dir>/locks` (previously `<uuid>.lock` files directly in the storage directory). The CLI uses the same location.
- New dependency: `symfony/lock`.

### Removed
- `Locking\Backends\FileSystem`, use `SymfonyLockAdapter::createFileLockAdapter()` instead. It deleted lock files on release, which allowed two processes to hold the same lock at once.
- `Locking\Backends\CachedLockAdapter`, use `SymfonyLockAdapter` with e.g. `RedisStore` or `MemcachedStore` instead. PSR-16 has no atomic "add", so two processes could acquire the same exclusive lock at once; with its default prefix `lock:` it didn't work with `Psr16Cache` at all. There is no replacement for APCu-based locking, since symfony/lock has no APCu store.

### Important Note
- **Stop all processes using the old version before upgrading.** Old and new versions lock different files, so they don't exclude each other while running side by side.
- Old `<uuid>.lock` files in the storage directory are no longer used and can be deleted once no process of the old version runs.
- Code creating `FileSystem` or `CachedLockAdapter` explicitly has to be migrated to `SymfonyLockAdapter`, see "Lock backends" in the Readme.

### Fixed
- Loading an object that another process had updated in the meantime could enable safe mode for the whole storage: the data was validated against outdated cached metadata. On a checksum mismatch the metadata is now reloaded from disk once; safe mode is only enabled if the data doesn't match the metadata on disk either.
- `load()` no longer releases a lock that the caller acquired before (e.g. by a previous exclusive load or a running `store()`).
- The previous object passed to `OBJECT_SAVED` / `OBJECT_WRITE_FAILED` listeners is now loaded before the data file is overwritten.
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