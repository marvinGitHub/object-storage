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
| `Storage\ClassNameRegistry` | `classnames.json`: the classes of stored objects, under its lock | registry part of `StubsTrait` | planned |
| `Storage\StubIndex` | the stubs: objects by class | stub part of `StubsTrait`, `ListingTrait` | planned |
| `Storage\ObjectFiles` | data and metadata files as a group: checksums, interrupted writes, JSON | `DurabilityTrait`, `JsonTrait` | planned |
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
- **Immutable components.** The facade has setters (`setStorageDir()`, `setIOAdapter()`, `setStrategy()`, …), and the
  shard depth of a strategy can change at runtime (maintenance, configuration). A component therefore doesn't follow
  changes: the facade creates it when first needed and creates it again when one of its inputs has changed.
- **The order of the layers:** paths → files → metadata, stubs, registry → reader, writer, deleter → facade.
- **Same behavior while splitting:** every step is checked by the whole test suite, PHPStan (level 5, see
  `phpstan.neon`) and the assertions per test, which must stay the same.

## Quality gates

- PHPStan level 5 for `src` and `tests`, with `treatPhpDocTypesAsCertain: false`: checks of documented types at
  runtime (`is_string()` on a parameter documented as string) are validation, not dead code.
- `phpstan-baseline.neon` holds the findings in tests that are intentional (invalid input on purpose, fixtures whose
  properties are only written). New findings fail the build; the baseline isn't extended for code in `src`.
