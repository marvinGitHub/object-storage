# Upgrading

## From 1.0.0-rc.8 to 1.0.0-rc.9

Nothing has to be migrated. OQL *(experimental)*: the constructor of `Oql` takes `interrupt` and `observer` (both optional, after `useIndexes`; pass them by name), and `Result` has `$statistics` (`null` for a `Result` created by hand).

Viewer: the query console runs a query without PHP's `max_execution_time` and sends the page up to the results before it runs; a query ends when the browser leaves the page or **Cancel** is pressed, what it wrote is rolled back. The console writes slow statements to `appData/logs/slow-queries.log` (at most about 20 MB with its rotated file).

## From 1.0.0-rc.7 to 1.0.0-rc.8

Nothing has to be migrated. Check:

- **Own IO adapters** implementing `AdapterInterface` (instead of extending `RealAdapter`) implement `fileMTime()` and `fileCTime()`: the times of a file, `false` if unknown.
- **`Metadata::getTimestampCreation()` and `getTimestampModification()`** return `?float`, and the setters take `null`: `null` for metadata that wasn't loaded through the storage and doesn't tell. Metadata loaded by `ObjectStorage::loadMetadata()` always has both: missing ones are taken from the files.
- **Metadata written before 1.0.0-rc.3** (without a modification time) is modified when its data file was written last, not when it was created.
- **Metadata without a checksum** fails with a `MissingChecksumException` (an `InvalidFileFormatException`) instead of a PHP `Error`; storing the object, or saving it in the viewer, writes the checksum.
- **The message of `readRaw()`** ends with the cause: "Object with uuid … could not be read: …". Code comparing the whole message checks its beginning.
- **Viewer**: **Duplicate** refuses a record whose data doesn't match its checksum, and **Save** refuses a record changed since its page was loaded.

To use pattern indexes, add `patterns` to the fields of a search index (`docs/search.md`, "Pattern indexes"); the index is built again.

## From 1.0.0-rc.6 to 1.0.0-rc.7

Nothing has to be migrated. If you use `Transaction`, check:

- **Transaction logs**: transactions now keep a journal directory `transactions/<id>/` while they run, instead of a file `transactions/<id>.txn`. A `.txn` file that remained comes from a process that died during a transaction; it holds no states to roll back to, and the storage ignores it. With all processes stopped, run `php migrations/003_004.php --dir=/path/to/storage` (`--dry-run` first): it lists the objects each log names, so you can check them, and removes the logs.
- **`getTransactionLogPath()`** is replaced by `getJournalDirectory()`.
- **`rollback()` after `commit()`** throws a `TransactionException`; a committed transaction can't be rolled back anymore.
- **A rollback that can't restore an object** throws a `TransactionRollbackException` (and enables safe mode); before, the object was only logged. `commit()` throws it instead of a `TransactionCommitException` when rolling back after a failed change fails as well.
- **Crash recovery needs a shared lock backend on several hosts**: with file locks on a network file system, a transaction running on another host can't be told from a crashed one. See [Transactions](docs/transactions.md#crashes).
- **One transaction per storage instance**: a second `begin()` on the same `ObjectStorage` instance while one is active throws a `TransactionAlreadyActiveException`. Use another instance for a parallel transaction.
- **Everything through the instance belongs to the transaction**: `$storage->store()`, `delete()`, `setLifetime()`, `touch()` called while a transaction is active on the instance are rolled back with it, and the objects stay locked until it ends. Code that wrote "beside" a transaction on the same instance and expected those writes to stay must use another instance.

If you write with OQL *(experimental)*: a writing statement that fails no longer leaves the objects written before it; they are rolled back. Code relying on partial writes (`Stopped after N of M …`) must handle the whole statement as failed.

## From 1.0.0-rc.5 to 1.0.0-rc.6

Nothing has to be migrated. `Migration\AttributeToReference` reads an attribute that isn't a plain name (with dots, brackets or quotes) as a path now: `new AttributeToReference('a.b')` links `b` within `a`, not an attribute named `a.b`; write such a name as a key, `['a.b']`. An invalid path fails in the constructor with an `InvalidPathException`.

Records holding an attribute created by the **Link** of the viewer from a path (e.g. `newlist['somebody']`) keep it; remove it in the JSON of the record and link the path again.

## From 1.0.0-rc.4 to 1.0.0-rc.5

Nothing has to be migrated. OQL *(experimental)*: `UPDATE` takes paths as targets and a `REMOVE` clause; `REMOVE` isn't reserved, so properties and aliases named so keep working. An `UPDATE` without `SET` now says "SET or REMOVE expected".

## From 1.0.0-rc.3 to 1.0.0-rc.4

Nothing has to be migrated. Check:

- **Subclasses of `ObjectStorage`**: the protected property `$lazyloadReferenceSupportCache` is removed; the results are cached by `Reflection::acceptsLazyReference()`. Override `supportsLazyReference()` to change the check.

## From 1.0.0-rc.2 to 1.0.0-rc.3

Nothing has to be migrated: metadata without a modification time is read with its creation time instead. Check:

- **Paths with `[`, `]` or `*`**: in the paths of search index fields and unique indexes, `friends[0]`, `friends['alex']` and a segment `*` are syntax now (see [Paths into lists and keys](docs/search.md#paths-into-lists-and-keys)). A path naming a key that contains `[`, `]` or quotes, or a key that is exactly `*`, is written in brackets: `['a[1]']`, `['*']`. Rebuild indexes whose paths changed.
- **Creation times**: `timestampCreation` was the time of the last write of an object, now it is that of its first `store()`. Objects stored before keep the value they have (the time of their last write) as their creation time; it doesn't change anymore. Their modification time (`getModifiedAt()`, OQL `MODIFIED()`) is the same until they are written or touched.
- **OQL** *(experimental)*: a property named `regexp` is written in backticks. Queries on number, date and boolean fields of Loupe search indexes may now use those indexes; the results are the same, except for a number field holding dates or a date field holding numbers (see [Indexes](docs/query.md#performance-and-memory)) — pass `useIndexes: false` for such data.

## From 1.0.0-rc.1 to 1.0.0-rc.2

**Dates are stored inline.** `DateTimeInterface` values are no longer records of their own, but stored in the object holding them (see [Serialization](docs/serialization.md#dates)). Nothing has to be migrated: dates stored as records still load, and the object holding them stores them inline the next time it is stored (in a property without type, a date that wasn't accessed is still a `LazyLoadReference` and keeps its reference until it is loaded). Check:

- **Paths into dates**: OQL queries, search index fields and unique indexes reading the inner properties of the old date records (`createdAt.date`, `createdAt.timezone`) don't find them in objects stored from now on. The point in time in UTC is `createdAt.__datetime`.
- **Leftover date records**: the old records of dates are not deleted when the object holding them stores them inline, nor later with that object, as it no longer references them. They are records of the class `DateTimeImmutable` or `DateTime` and can be listed with `$storage->list(DateTimeImmutable::class)`; delete those no other object references anymore.
- **Shared `DateTime` instances** are no longer the same instance after loading, see [Serialization](docs/serialization.md#dates).

## From 0.0.x to 1.0

1.0 contains all changes since 0.0.4, see the [Changelog](Changelog.md). Some of them were already published with 0.0.10; they are marked *(already in 0.0.10)* below and only concern you when coming from 0.0.9 or older. No migration of the stored data is needed when coming from 0.0.3 or later: old metadata (format version 1, without a revision) is still read as it is.

### Before upgrading

1. **Stop every process using the storage** (web workers, CLI commands, cron jobs, queue consumers). Old and new versions lock different files (`<uuid>.lock` in the storage directory before, `<storage dir>/locks` now), so they don't exclude each other while running side by side. Coming from 0.0.10, the lock files are the same, but stopping all processes is still recommended.
2. **Back up the storage directory.**
3. Coming from **0.0.1 or 0.0.2**: run the migrations in `migrations/` first (`001_002.php`, then `002_003.php`), from inside that directory, as described in the Changelog of 0.0.2 and 0.0.3.

### Requirements

- PHP **8.5** or newer.
- Symfony **8** (`symfony/console`, `symfony/cache`, `symfony/lock`). Applications still on Symfony 6 or 7 can't install 1.0.
- Dependency `symfony/lock`, installed with the package *(already in 0.0.10)*.

### After upgrading

- Old `<uuid>.lock` files in the storage directory are no longer used and can be deleted *(already in 0.0.10)*.
- Stores flush every file to disk (`fsync`) and are noticeably slower. Where durability doesn't matter (e.g. caches), disable it: `$storage->getWriter()->setSyncEnabled(false)`.
- Optional: create unique and search indexes (`docs/unique.md`, `docs/search.md`); OQL uses them where it can.

### Breaking changes

Check your code for the following; most applications using only `store()`, `load()`, `delete()` and the query methods aren't affected.

**Lock backends** *(already in 0.0.10)*
- `Locking\Backends\FileSystem` was removed: use `SymfonyLockAdapter::createFileLockAdapter()`.
- `Locking\Backends\CachedLockAdapter` was removed: use `SymfonyLockAdapter` with a Redis or Memcached store. There is no replacement for APCu-based locking. See "Lock backends" in `docs/concurrency.md`.

**Interfaces you may implement**
- `Strategy\StrategyInterface`: new methods `getPolicyChildDeletion()`, `shouldDeleteChild()`, `optimisticLockingEnabled()`. Strategies extending `Strategy\Standard` already have them.
- `File\WriterInterface`: new methods `atomicWriteAll()`, `recover()`, `syncEnabled()`, `setSyncEnabled()`; `createEmptyFile()` was removed. See `File\Writer`. *(already in 0.0.10)*
- `File\IO\AdapterInterface`: new methods `fsyncFile()`, `fsyncDirectory()`, `renameSync()`, `moveFileSync()`. See `File\IO\RealAdapter`. *(already in 0.0.10)*

**Subclasses of `ObjectStorage`** — protected methods were renamed:

| Before | Now |
|---|---|
| `loadFromStorage()` | `loadFromFiles()` |
| `buildLoadedObject()` | `hydrateObject()` |
| `processLoadedData()` | `hydrateProperties()` |
| `processLoadedArray()` | `hydrateArray()` |
| `serializeAndStore()` | `storeObject()` |
| `createGraphAndStoreReferencedChildren()` | `serializeGraph()` |
| `saveMetadata()` | `writeMetadata()` |
| `readFile()`, `loadFromJsonFile()`, `decodeJson()`, `serializeMetadata()` | `File\JsonFileReader` / `File\JsonFileWriter` (`getJsonFileReader()`, `getJsonFileWriter()`) |

**Stored data**
- Metadata is written in format version 2 (with a `revision`). Metadata written by 0.0.x (version 1) is read as it is. Going back to 0.0.x after 1.0 has written to a storage is not supported, so keep the backup until you are sure you won't downgrade.

**Behavior**
- `__unserialize()` receives the referenced objects as objects (as returned by `__serialize()`), no longer as `['__reference' => '<uuid>']` arrays. Code evaluating these arrays has to be adapted.
- `ObjectStorage::buildShardedDirectory()` no longer creates the directory. Code writing into shard directories itself has to create them. *(already in 0.0.10)*
- `load()` of a UUID under which nothing is stored returns `null` instead of throwing and enabling safe mode.
- Scalar values are converted to the declared property type when loading; lossy conversions (e.g. `"abc"` to `int`) throw a `TypeConversionFailureException`. *(already in 0.0.10)*
- Safe mode only blocks writes; loading and listing keep working.
- `getWriter()` and `getReader()` return the same instance on every call, so their configuration applies to later operations.
- `Writer::atomicWrite()` no longer takes `$createDirectoryIfNotExist`; directories are always created. *(already in 0.0.10)*

### Experimental: OQL

OQL (`Query\Oql`, the CLI command `query`, the query console of the viewer) is new and **experimental**: its syntax, semantics and API may change in a minor 1.x release. Pin a minor version (`~1.0.0`) if you depend on it in production, and watch the Changelog for **Breaking (experimental)**.
