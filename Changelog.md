# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Fixed
- `CachedLockAdapter` used the default key prefix `lock:`, which contains a reserved PSR-16 character and made every lock operation fail with `Psr16Cache`. The default prefix is now `lock_`.
- `CachedLockAdapter` is now reentrant: acquiring a lock already held by the same process no longer waits for the timeout (e.g. `load($uuid, true)` followed by `store()`).
- Requesting a shared lock while holding an exclusive lock no longer blocks until the timeout (`FileSystem`, `CachedLockAdapter`).
- `load()` no longer releases a lock that the caller acquired before (e.g. by a previous exclusive load or a running `store()`).
- The previous object passed to `OBJECT_SAVED` / `OBJECT_WRITE_FAILED` listeners is now loaded before the data file is overwritten.
- `store()` of a loaded `LazyLoadReference` failed for objects not implementing the UUID `AwareInterface`.
- `BEFORE_UPDATE` / `AFTER_UPDATE` were not dispatched when updating an object without passing its UUID explicitly.

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