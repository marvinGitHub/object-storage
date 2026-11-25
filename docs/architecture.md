# Architecture

How `ObjectStorage` is built from components, and the rules that keep it that way. For the details of each part
(files, locks, journal) see [Internals](internals.md).

## From traits to components

Up to 1.0.0-rc.12, `ObjectStorage` was one class assembled from traits by concern (`Storage\*Trait`). The traits
kept the code readable, but they shared every property and method through `$this`: no part had dependencies of its
own, none could be tested or replaced alone.

The storage was split into components, one concern each, step by step and with the same behavior:

| Component | Concern | Replaces | State |
|---|---|---|---|
| `Storage\ObjectPaths` | the paths of all files of a storage, sharded by UUID | `PathsTrait` | done |
| `Storage\JsonStore` | JSON files with the rules of the storage for failures (damaged JSON enables safe mode) | `JsonTrait` | done |
| `Storage\ClassNameRegistry` | `classnames.json`: the classes of stored objects, under its lock, kept in memory | registry part of `StubsTrait` | done |
| `Storage\StubIndex` | the stubs: objects by class | stub part of `StubsTrait`, listing by class of `ListingTrait` | done |
| `Storage\MetadataStore` | metadata through its cache, completed from its files (UUID, times) | `MetadataTrait` | done |
| `Storage\ObjectFiles` | data and metadata files as a group: checksums, interrupted writes | `DurabilityTrait` | done |
| `Storage\ObjectCache` | the cache of loaded objects, with its events | cache methods of `ObjectStorage` | done |
| `Locking\ObjectLocks` | the locks of objects as operations take them: only if not held, released only if taken here and not retained by a transaction (`Locking\RetainedLocks`) | the copies of that pattern, the locks of `RetainedLocksTrait` | done |
| `Storage\ObjectSession` | what the storage instance knows of the objects it loaded and stored: their UUIDs, the revisions read, the objects being stored | `identityMap`, `revisions`, `processingStack` of `ObjectStorage` | done |
| `Storage\ObjectDeleter` | deleting objects, with the objects they reference by the child deletion policy (`Storage\References`) | `DeletingTrait` | done |
| `Storage\ObjectReader` | reading objects: built from their files, properties hydrated, references lazy (or loaded for `__unserialize()`), dates restored; `load()` with cache, locks and events stays the facade's | `LoadingTrait` | done |
| `Storage\ObjectWriter` | writing objects: serialized into their graph (referenced objects first, by the child persistence policy), written under their lock if changed, with optimistic locking, constraints, stub and cache; `store()` with UUID, lock and events stays the facade's | `StoringTrait` | done |
| `Constraint\ConstraintChecker` | checking an object about to be written against the registered constraints (`Constraint\ConstraintRegistry`) | `ConstraintsTrait` | done |

`ObjectStorage` stays the facade: its public methods and their behavior don't change, they delegate to the
components. Code using the storage doesn't notice the split. The traits remain as the place of the public methods
by concern, and of the methods creating the components (`objectFiles()`, `objectWriter()`, …); what is left in
them is orchestration: `load()`, `store()`, `delete()` with their events, and the like.

State of a storage instance is kept by the facade in objects of its own, created once: `Storage\ObjectSession`
(the objects loaded and stored), `Locking\RetainedLocks` (the locks retained by transactions),
`Constraint\ConstraintRegistry` (the registered constraints). Components get them like any other dependency.

## The facade

The public methods of `ObjectStorage`, by role:

| Role | Methods |
|---|---|
| Objects | `store()`, `load()`, `delete()`, `deleteIfExpired()`, `exists()`, `list()`, `count()`, `match()`, `assume()`, `getUuidOf()`, `getNextAvailableUuid()` |
| Lifetime | `setLifetime()`, `getLifetime()`, `setExpiration()`, `getExpiration()`, `expired()`, `touch()` |
| Metadata | `loadMetadata()`, `getClassName()`, `getClassNames()`, `getRevision()`, `getCreatedAt()`, `getModifiedAt()` |
| Raw records (export, import, OQL) | `findRaw()`, `readRaw()`, `writeRaw()` |
| Constraints | `addConstraint()`, `removeConstraint()`, `getConstraints()`, `checkConstraints()` |
| Cache | `clearCache()`, `removeFromCache()`, `getMemoryConsumption()` |
| Maintenance | `rebuildStubs()` |
| Files | `getStorageDir()`, `getShardDir()`, `getFilePathData()`, `getFilePathMetadata()` |
| Configuration | `getConfiguration()` and the getters and setters of the storage directory, IO adapter, files, cache, metadata cache, lock adapter, strategy, UUID generator, event dispatcher, logger, state handler and class rename map |
| Transactions | `getActiveTransaction()` |

Other public methods are marked `@internal`: they are for the library itself (transactions, OQL, maintenance, the
viewer) and may change in any release. They hand out components instead of repeating their methods on the facade:

- `objectLocks()`: the locks of objects, retained by transactions (`retain()`, `releaseRetained()`, …);
- `objectPaths()`: the paths of all files (`stubFile()`, `stubDirectory()`, `shardedDirectory()`, …);
- `stubIndex()`: the stubs (`add()`, `remove()`, …);
- `setActiveTransaction()`, `markAsCorruptedIfClassless()`, `rebuildClassnameRegistry()`.

A method that only passes a call on to a component doesn't belong on the facade: callers inside the library use the
component, so the facade keeps what users of the storage need.

## Rules

- **One concern per component**, named after it. A component knows only the components below it, never the facade
  (with one exception: `LazyLoadReference` and `__unserialize()` load referenced objects through the facade, which
  is what they need; `ObjectReader` gets the facade for that).
- **Dependencies through the constructor.** A component gets what it uses as constructor arguments: no `$this` of
  another class, no static state, no service locator.
- **Fixed dependencies.** The facade has setters (`setStorageDir()`, `setIOAdapter()`, `setStrategy()`, …), and the
  shard depth of a strategy can change at runtime (maintenance, configuration). A component therefore never changes
  its dependencies: the facade creates it when first needed and creates it again when one of its inputs has changed
  (`Storage\Components`: the inputs kept and compared with `===`, objects by identity, other values by value).
  State that is the component's own concern stays with it, e.g. the class names the registry keeps in memory; it is
  lost when the component is created again, which is always safe. State that must survive (the locks retained by a
  transaction) is kept by the facade in an object of its own and passed in like any other dependency.
- **The order of the layers:** paths → JSON files → metadata, registry → object files, stubs, locks → reader,
  writer, deleter → facade. The active transaction is passed with each call (e.g. `ObjectDeleter::delete()`): it
  changes with every transaction, so it is no dependency of a component. The session (`ObjectSession`) is state of
  the facade, created once and cleared with `clearCache()`.
- **Each component has a unit test of its own** (`tests/melia/ObjectStorage/Storage/`), without a storage: what the
  facade tests can't show (e.g. that the registry is read again under its lock) is tested there.
- **Same behavior while splitting:** every step is checked by the whole test suite, PHPStan (level 5, see
  `phpstan.neon`) and the assertions per test, which must stay the same.

## Quality gates

- PHPStan level 5 for `src` and `tests`, with `treatPhpDocTypesAsCertain: false`: checks of documented types at
  runtime (`is_string()` on a parameter documented as string) are validation, not dead code.
- `phpstan-baseline.neon` holds the findings in tests that are intentional (invalid input on purpose, fixtures whose
  properties are only written). New findings fail the build; the baseline isn't extended for code in `src`.
