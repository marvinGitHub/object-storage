# Architecture

How `ObjectStorage` is built from components, and the rules that keep it that way. For the details of each part
(files, locks, journal) see [Internals](internals.md).

## From traits to components

Up to 1.0.0-rc.12, `ObjectStorage` was one class assembled from traits by concern (`Storage\*Trait`). The traits
kept the code readable, but they shared every property and method through `$this`: no part had dependencies of its
own, none could be tested or replaced alone.

The storage is being split into components, one concern each, step by step and with the same behavior:

| Component | Concern | Replaces | State |
|---|---|---|---|
| `Storage\ObjectPaths` | the paths of all files of a storage, sharded by UUID | `PathsTrait` | done |
| `Storage\JsonStore` | JSON files with the rules of the storage for failures (damaged JSON enables safe mode) | `JsonTrait` | done |
| `Storage\ClassNameRegistry` | `classnames.json`: the classes of stored objects, under its lock, kept in memory | registry part of `StubsTrait` | done |
| `Storage\StubIndex` | the stubs: objects by class | stub part of `StubsTrait`, listing by class of `ListingTrait` | done |
| `Storage\ObjectFiles` | data and metadata files as a group: checksums, interrupted writes | `DurabilityTrait` | planned |
| `Storage\MetadataStore` | metadata through its cache | `MetadataTrait` | planned |
| `Storage\ObjectCache` | the cache of loaded objects | cache methods of `ObjectStorage` | planned |
| `Storage\ObjectReader`, `ObjectWriter`, `ObjectDeleter` | load, store, delete | `LoadingTrait`, `StoringTrait`, `DeletingTrait` | planned |

`ObjectStorage` stays the facade: its public methods and their behavior don't change, they delegate to the
components. Code using the storage doesn't notice the split.

## Rules

- **One concern per component**, named after it. A component knows only the components below it, never the facade
  (with one exception: `LazyLoadReference` and `__unserialize()` load referenced objects through the facade, which
  is what they need).
- **Dependencies through the constructor.** A component gets what it uses as constructor arguments: no `$this` of
  another class, no static state, no service locator.
- **Fixed dependencies.** The facade has setters (`setStorageDir()`, `setIOAdapter()`, `setStrategy()`, …), and the
  shard depth of a strategy can change at runtime (maintenance, configuration). A component therefore never changes
  its dependencies: the facade creates it when first needed and creates it again when one of its inputs has changed
  (`ObjectStorage::component()`: objects compared by identity, other values by value). State that is the
  component's own concern stays with it, e.g. the class names the registry keeps in memory; it is lost when the
  component is created again, which is always safe.
- **The order of the layers:** paths → JSON files, object files → metadata, registry → stubs → reader, writer,
  deleter → facade.
- **Each component has a unit test of its own** (`tests/melia/ObjectStorage/Storage/`), without a storage: what the
  facade tests can't show (e.g. that the registry is read again under its lock) is tested there.
- **Same behavior while splitting:** every step is checked by the whole test suite, PHPStan (level 5, see
  `phpstan.neon`) and the assertions per test, which must stay the same.

## Quality gates

- PHPStan level 5 for `src` and `tests`, with `treatPhpDocTypesAsCertain: false`: checks of documented types at
  runtime (`is_string()` on a parameter documented as string) are validation, not dead code.
- `phpstan-baseline.neon` holds the findings in tests that are intentional (invalid input on purpose, fixtures whose
  properties are only written). New findings fail the build; the baseline isn't extended for code in `src`.
