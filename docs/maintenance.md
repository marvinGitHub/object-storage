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

## Changing the shard depth

The shard depth determines the directory levels object files are stored in (see [Concepts](concepts.md#what-is-stored-on-disk)). A deeper level keeps directories small with many objects. After changing it in the [strategy](configuration.md#strategy), move the existing files:

```bash
vendor/bin/object-storage maintenance:rebuild-shards --dir var/storage
```

## Upgrading

Some releases change the storage format and ship a migration script in `migrations/`; the [Changelog](../Changelog.md) states when one has to be run and how. Stop all processes using the storage before upgrading.

## The web viewer

`view.php` is a small web interface to browse, view, edit, duplicate and delete stored objects during development.

> **Never make the viewer reachable in production.** It has no authentication, accepts any storage directory as a parameter and can modify and delete data. Exclude it from your deployment, or protect it with authentication and restrict it to trusted networks.
