# Internals

How `ObjectStorage` loads and stores objects, for developers working on the library. Users of the library need [Getting started](getting-started.md) and [Concepts](concepts.md) instead.

## Files of an object

Every object is two files in the shard directory of its UUID (`shards/<a>/<b>/…`, see `buildShardedDirectory()`):

| File | Contents |
|---|---|
| `<uuid>.obj` | the data: the serialized object graph as JSON, references to other objects as `{"__reference": "<uuid>"}` |
| `<uuid>.metadata` | the metadata: class, checksum of the data and its algorithm, revision, created (`timestampCreation`), modified (`timestampModification`: the last write of the data or `touch()`), expiration, UUID |

The two files are written together as a group (`writeObjectFiles()`), so a crash can't leave data without matching metadata; `recoverInterruptedWrite()` completes or discards an interrupted group. Besides them, a stub per object in `stubs/<md5 of the class>/` lists the objects of a class (`createStub()`), and `stubs/classnames.json` the classes.

## Verbs

`ObjectStorage` is grouped in sections (creating, paths, storing, loading, files, metadata, lifetime, deleting, listing, stubs, cache). Each verb has one meaning:

| Verb | Level | Examples |
|---|---|---|
| `load` / `store` / `delete` | objects, with caches, locks and events — the public API | `load()`, `store()`, `delete()`, `loadMetadata()` |
| `readRaw` / `writeRaw` | objects as stored, unchanged (`Transfer\RawAccessTrait`) | copying into another storage |
| `read…` / `write…` | files, without caches | `readObjectFiles()`, `writeObjectFiles()`, `writeMetadata()`; JSON files through `File\JsonFileReader` and `File\JsonFileWriter` (`getJsonFileReader()`, `getJsonFileWriter()`) |
| `hydrate…` | stored data → object | `hydrateObject()`, `hydrateProperties()`, `hydrateArray()` |
| `serialize…` | object → stored data | `serializeGraph()` |

`loadMetadata()` reads metadata through the metadata cache, `reloadMetadata()` from the file (the cache may be outdated when another process changed the object).

## Loading

```mermaid
flowchart TD
    load["load($uuid)<br/>cache hit? expired?<br/>shared or exclusive lock"] --> fromFiles["loadFromFiles()"]
    fromFiles --> readFiles["readObjectFiles()<br/>metadata + data,<br/>checksum verified,<br/>interrupted write completed"]
    readRaw["readRaw($uuid)<br/>shared lock"] --> readFiles
    readFiles --> decode["JsonFileReader::decode()<br/>invalid JSON: safe mode"]
    decode --> hydrate["hydrateObject()"]
    hydrate --> unserialize["__unserialize() of the class<br/>(references resolved first)"]
    hydrate --> properties["hydrateProperties()<br/>→ hydrateArray():<br/>references become lazy load references"]
```

- `readObjectFiles()` is the one place that reads the files of an object; `load()` and `readRaw()` share it. A checksum mismatch enables safe mode when loading, not when reading raw (copying many objects goes on).
- References aren't loaded with the object: they become `LazyLoadReference`s, loaded when used.

## Storing

```mermaid
flowchart TD
    store["store($object)"] --> storeObject["storeObject()<br/>one object of the graph"]
    storeObject --> graph["serializeGraph()<br/>properties → data"]
    graph -->|"referenced objects,<br/>child persistence policy"| storeObject
    storeObject --> lock["exclusive lock<br/>(after the referenced objects)<br/>revision + optimistic locking"]
    lock --> files["writeObjectFiles()<br/>data + metadata as a group"]
    lock --> meta["writeMetadata()<br/>only the metadata changed"]
    files --> stub["createStub(), cache, events"]
    writeRaw["writeRaw($rawObject)<br/>exclusive lock"] --> files
```

- `storeObject()` is called once per object of the graph; referenced objects are stored first, so an object never refers to one that isn't stored. Its lock is taken only then, so no more than two locks are held at once.
- The data file is only written if the data changed (checksum); otherwise only the metadata, e.g. a new lifetime.
- Before the data is written, `checkConstraints()` (`Constraint\ConstraintsTrait`) asks every registered constraint, e.g. a unique index, with the data as it will be stored; a violation stops the write. The callback it returns runs after the write, successful or not, so a constraint can keep or release what it reserved. `writeRaw()` does the same; code writing object files itself (like the viewer when editing a record) has to call it as well.

## Deleting

`delete()` → `deleteObject()` per object: the files and the stub are removed under the lock of the object; `findChildrenToDelete()` decides by the child deletion policy which referenced objects follow (`delete($uuid, applyChildDeletionPolicy: false)` deletes only the object itself).

## JSON files

`File\JsonFileReader` and `File\JsonFileWriter` read and write all JSON files of the storage — metadata, the registry of class names — and the assignments of configurations:

- The reader reports a file it can't read (logged, `IO_READ_FAILURE`) and returns `null`; invalid JSON is reported (`JSON_DECODING_FAILURE`) and throws a `SerializationFailureException`. `tryDecode()` only looks into JSON, e.g. for references, without reporting a damaged file.
- What a damaged file means is the caller's decision: the storage passes `onCorruption`, which enables safe mode (`getJsonFileReader()`); `readRaw()` and deleting don't.
- The writer encodes with the maximum depth of the strategy and writes atomically with the writer of the storage, so readers see the previous or the new contents.
- `getJsonFileReader()` and `getJsonFileWriter()` create them per use, so a reader, writer or strategy set later is used.

## Where to change what

| To change … | look at |
|---|---|
| how properties are written | `serializeGraph()`, `transformValueForGraph()`, the strategy (`Strategy\Standard`) |
| how properties are restored | `hydrateProperties()`, `hydrateArray()`, `resolveReferences()` |
| checksums, crash recovery | `readObjectFiles()`, `findMatchingMetadata()`, `recoverInterruptedWrite()`, `File\Writer` |
| what the metadata holds | `Metadata\Metadata`, `writeMetadata()`, `loadMetadata()` |
| locking | `load()`, `storeObject()`, `deleteObject()`, `Locking\LockAdapterInterface` |
