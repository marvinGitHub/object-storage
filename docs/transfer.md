# Copying and moving objects between storages

Objects are copied or moved into another storage as they are stored: their data and metadata unchanged, so the UUID, the revision, the expiration and the checksum are kept. The objects aren't loaded, so their classes don't need to be available.

```php
use melia\ObjectStorage\Transfer\ObjectTransfer;

$transfer = new ObjectTransfer($source, $target);

$result = $transfer->copy([$uuid]);
$result = $transfer->copy([$uuid], withReferences: true);                        // with the objects it references
$result = $transfer->move($source->list(Order::class), onConflict: ObjectTransfer::CONFLICT_SKIP);

$result->transferred; // the UUIDs copied (or moved)
$result->references;  // referenced objects copied along
$result->skipped;     // UUID => reason: 'exists in the target', 'expired'
$result->failed;      // UUID => error
```

| Option | Meaning |
|---|---|
| `withReferences` | Copies the objects the chosen ones reference, recursively, so the copies don't refer to objects missing in the target. Referenced objects existing in the target are kept. |
| `onConflict` | An object of the same UUID in the target: `CONFLICT_FAIL` (default) reports it as failed, `CONFLICT_SKIP` keeps the target's, `CONFLICT_OVERWRITE` replaces it. |
| `onProgress` | Called per object with its UUID and `'transferred'`, `'skipped'` or `'failed'`. |

Things to know:

- **Moving** copies an object and then deletes it in the source, only the object itself, whatever the child deletion policy of the source says. Referenced objects copied along stay in the source, since other objects may reference them. A skipped or failed object stays in the source.
- **Each object on its own**: one failing doesn't stop the others. While an object is moved, it is locked in the source, so nobody changes it between copying and deleting.
- **Checksums** are verified as when loading: an interrupted write (a crash between writing the data and the metadata) is completed first, and with checksum validation disabled in the strategy the data is copied as it is. Otherwise data not matching its checksum isn't copied, and the error names the algorithm, the expected and the actual checksum, e.g. for a data file edited by hand. **Expired objects** are skipped.
- **The target** registers the classes and stubs and dispatches the events of storing (`METADATA_SAVED`, `AFTER_STORE`), so search indexes attached to it learn the objects; in the source, moving dispatches the events of deleting.
- **Source and target** must be different storages. Each uses its own configuration (locks, caches, strategy); the shard depths may differ.
- The building blocks are `ObjectStorage::readRaw($uuid)` (in `TransferRawAccessTrait`), which reads an object as stored the way loading does (same recovery and checksum verification, but a damaged object doesn't enable safe mode), `writeRaw($rawObject, $overwrite)`, which writes the files like `store()`, and `delete($uuid, applyChildDeletionPolicy: false)`.
- **Why not `load()` and `store()`?** They would change what is copied: `store()` increments the revision, the classes of the objects would have to be available (the viewer usually doesn't have them), hooks and the property policies of the strategy may change the data, referenced objects would follow the child persistence policy of the target, and every graph would be deserialized and serialized again.

## CLI

```bash
# chosen objects, all objects of a class, or all objects
./object-storage/bin/object-storage copy 6f9619ff-8b86-4011-b42d-00c04fc964ff --dir var/storage --to var/archive --with-references
./object-storage/bin/object-storage move --class 'App\Model\Order' --dir var/storage --to var/archive --on-conflict skip
./object-storage/bin/object-storage copy --all --dir var/storage --to var/backup --create --json
```

`--create` creates a missing target, `--to-configuration` opens the target with another [configuration](configuration.md#named-configurations) than the one assigned to it, `--json` prints the result. The exit code is 1 if an object failed.

## Viewer

**Copy / Move** on a record and **Copy / Move selected** (check the objects in the list of a class) open a page to choose copy or move, the target storage (storages opened before are suggested), what happens to objects existing in the target, whether to copy referenced objects, and to create a missing target with a configuration. The objects are transferred in batches, showing the progress and the skipped and failed objects. Afterwards, **Retry** runs the transfer again for the failed or the skipped objects, with the settings of the form, which can be changed first, e.g. to overwrite objects existing in the target. To transfer all objects of a class, use the CLI.
