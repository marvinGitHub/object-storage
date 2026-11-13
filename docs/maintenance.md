# Maintenance and tools

## Command line interface

The CLI inspects and maintains a storage without writing code. It is installed as `vendor/bin/object-storage`; every command takes the storage directory with `--dir` and supports `--json` for machine-readable output.

```bash
vendor/bin/object-storage list --dir var/storage --class 'App\Customer'
vendor/bin/object-storage get <uuid> --dir var/storage --pretty
vendor/bin/object-storage stats --dir var/storage
vendor/bin/object-storage check --dir var/storage
```

| Command | Purpose |
|---|---|
| `list` | list UUIDs, optionally of one class |
| `get` | show an object and/or its metadata |
| `put` | store an object from JSON |
| `delete` | delete an object |
| `check` | scan the storage for problems such as missing files |
| `stats` | show statistics |
| `ttl` | show or change the lifetime of an object |
| `safemode` | show, enable or disable [safe mode](durability.md#safe-mode) |
| `maintenance:purge-expired` | delete expired objects, see [Lifetime](lifetime.md) |
| `maintenance:rebuild-stubs` | rebuild the class index |
| `maintenance:rebuild-shards` | move object files to the current shard depth |
| `config:set-shard-depth` | set the shard depth used by the CLI for a storage |

All options are described in the [CLI documentation](cli.md).

## Rebuilding the class index

The class index ("stubs") lists the objects of every class and is used by `list()`, `match()` and counting. It is updated by `store()` and `delete()`. If files were copied, moved or deleted outside the storage, rebuild it:

```bash
vendor/bin/object-storage maintenance:rebuild-stubs --dir var/storage
```

or in code with `$storage->rebuildStubs()`. Objects whose class can't be determined are moved aside and reported with the event `OBJECT_CORRUPTION_DETECTED`.

The index is rebuilt in place, in two phases: a stub is created for every object that misses one, then stubs of objects that don't exist or are of another class are removed; at the end, the list of classes (`getClassNames()`) is rebuilt from the stubs. So the index stays usable meanwhile, no safe mode is needed, and objects stored or deleted meanwhile keep their stubs right.

The command shows a progress bar with the created and removed stubs; in the [viewer](#the-web-viewer), **Rebuild Stubs** shows the progress the same way. The rebuild runs step by step (see [Stepped maintenance](#stepped-maintenance)): an interrupted rebuild is continued by running the command again or opening the viewer page.

In PHP:

```php
use melia\ObjectStorage\Util\Maintenance\StubRebuilder;

$progress = $storage->rebuildStubs(static function (array $progress): void {
    printf("%s: %d/%d, %d created, %d removed\n", $progress['phase'] ?? 'done', $progress['processed'], $progress['total'], $progress['created'], $progress['removed']);
});

// or step by step, e.g. one step per request
$rebuilder = new StubRebuilder();
$rebuilder->setStorage($storage);
$progress = $rebuilder->isRunning() ? $rebuilder->step() : $rebuilder->start();
```

## Changing the shard depth

The shard depth determines the directory levels object files are stored in (see [Concepts](concepts.md#what-is-stored-on-disk)). A deeper level keeps directories small with many objects. After changing it in the [strategy](configuration.md#strategy), move the existing files:

```bash
vendor/bin/object-storage maintenance:rebuild-shards --dir var/storage
```

The command shows a progress bar with the objects processed, the files moved and the estimated remaining time. In the [viewer](#the-web-viewer), **Rebuild Shards** asks for the depth and shows the progress the same way.

The storage is in **safe mode** while the files move: objects can be read, but not stored or deleted, as they can't be found while their files are in directories of different depths. The objects are moved step by step, see [Stepped maintenance](#stepped-maintenance). If a rebuild is interrupted (the command is stopped, the viewer page closed, a step fails), the storage stays in safe mode; running the command again, or opening the viewer page, continues the rebuild with the depth it was started with, also finding the files already moved. Safe mode that was enabled before the rebuild stays enabled after it.

In PHP:

```php
use melia\ObjectStorage\Util\Maintenance\ShardRebuilder;

$storage->getStrategy()->setShardDepth(3);

$rebuilder = new ShardRebuilder();
$rebuilder->setStorage($storage);

$rebuilder->rebuildShards(static function (array $progress): void {
    printf("%d/%d objects, %d files moved (%d %%)\n", $progress['processed'], $progress['total'], $progress['moved'], $progress['percent']);
});
```

`rebuildShards()` runs `start()` and then `step()` until the progress reports `done`; call them yourself to spread a rebuild over several requests. `isRunning()` and `getProgress()` tell whether a rebuild is waiting to be continued.

## Stepped maintenance

Rebuilding the stubs and the shards run step by step (`Util\Maintenance\SteppedMaintenance`), so the progress can be shown, and a rebuild can be spread over several requests or continued after an interruption, also by another process.

A task keeps its state and its snapshots as objects of an internal object storage in `<storage dir>/maintenance/internal/`, one record each, written crash-safe like any stored object: the state (a `TaskState`: counters and the last processed UUID), and the UUIDs still to process as [UUID tries](search.md#uuid-tries), e.g. the UUIDs of the stored objects for a stub rebuild. When the task is complete, they are removed. Steps of all processes run one after the other, under a lock of the internal storage.

## Upgrading

Some releases change the storage format and ship a migration script in `migrations/`; the [Changelog](../Changelog.md) states when one has to be run and how. Stop all processes using the storage before upgrading. The steps from 0.0.x to 1.0 are in [UPGRADE.md](../UPGRADE.md).

## The web viewer

`view.php` is a small web interface to browse, view, edit, duplicate and delete stored objects during development. It also creates, builds and drops [search indexes](search.md#the-viewer) and searches them.

**Saving a record** writes it as storing does (`writeRaw()`): data and metadata as a group, so a crash leaves no half-written record; the unique indexes checked; a new revision, modified now, the checksum algorithm of the object kept. If the object was changed since the page was loaded (another revision), it isn't saved: reload it and apply the changes again. Metadata the object can't be read with (e.g. without a checksum) is repaired by saving (`tests/e2e/record-save.spec.js`).

**Editing dates**: a record is edited as JSON; its [dates](serialization.md#dates) (also nested and in lists) get date widgets above the JSON: the date and time in the time zone of the date, as loading the object gives it, and the time zone (an identifier like `Europe/Berlin`, an offset like `+05:45`, or the abbreviation it has, like `CEST`). A change is written into the JSON as the storage stores dates, replacing only that date; changing the time zone keeps the time, so the point in time changes. The widgets follow the JSON when it is typed; **Cancel** drops all changes. Dates whose time zone the browser doesn't know, or with years before 1 or after 9999, are edited in the JSON. The widgets show milliseconds; a date changed by them keeps no further microseconds (`assets/date-fields.js`, tested by `tests/js/date-fields.test.js` and `tests/e2e/date-widgets.spec.js`).

> **Never make the viewer reachable in production.** It has no authentication, accepts any storage directory as a parameter and can modify and delete data. Exclude it from your deployment, or protect it with authentication and restrict it to trusted networks.
