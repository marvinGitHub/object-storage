# Object Storage CLI
A lightweight CLI to manage objects stored on disk with metadata, TTL, and safety features.

## Installation
- Ensure PHP CLI is available.
- Make the CLI executable:
- chmod +x object-storage/bin/object-storage
- Optional: add to PATH or create a symlink.

## Usage
- Show help:
- ./object-storage/bin/object-storage list -h

Global options:
- --dir Storage directory (default: ./.object-storage)
- --json Output machine-readable JSON where applicable
- --configuration Name of the [configuration](configuration.md#named-configurations) to open the storage with (default: the one assigned to the storage)
- --configurations Directory of the configurations (default: `$OBJECT_STORAGE_CONFIGURATIONS`, else `configurations` next to the CLI config file, e.g. `~/.config/object-storage/configurations`)

Commands:
- list
- get
- put
- delete
- check
- stats
- safemode
- ttl
- maintenance:rebuild-stubs
- maintenance:rebuild-shards
- config:set-shard-depth
- maintenance:purge-expired
- configuration:list, configuration:assign, configuration:check, configuration:import, configuration:export, configuration:delete
- copy, move — objects into another storage, see [Copying and moving](transfer.md#cli)
- unique:list, unique:create, unique:rebuild, unique:drop, unique:find — see [Unique indexes](unique.md#viewer-and-cli); all commands writing objects are checked against the unique indexes of the storage

## Configurations

Every command opens the storage with the configuration assigned to it (lock backend, caches, strategy), or with the one named by `--configuration`. A storage assigned a configuration that isn't found in the directory of the configurations fails instead of falling back to file locks, since all processes of a storage must use the same locks: point the CLI to the configurations of the application (e.g. those of the viewer, `appData/configurations`) with `--configurations` or `$OBJECT_STORAGE_CONFIGURATIONS`.

```bash
export OBJECT_STORAGE_CONFIGURATIONS=/etc/my-app/object-storage

# create one from JSON (a file, or - for STDIN); missing settings get their defaults
echo '{"name": "shared", "lock": {"type": "redis", "dsn": "redis://redis:6379", "ttl": 60}, "cache": {"type": "apcu"}}' \
  | ./object-storage/bin/object-storage configuration:import -

./object-storage/bin/object-storage configuration:check shared                   # takes a lock and uses the cache
./object-storage/bin/object-storage configuration:assign shared --dir var/storage # the storage uses it from now on
./object-storage/bin/object-storage configuration:list --dir var/storage          # all, * marks the assigned one
./object-storage/bin/object-storage configuration:export shared > shared.json     # JSON to edit or copy elsewhere
./object-storage/bin/object-storage configuration:import shared.json --replace    # replace it
./object-storage/bin/object-storage configuration:assign --none --dir var/storage # back to the defaults
./object-storage/bin/object-storage configuration:delete shared
./object-storage/bin/object-storage stats --dir var/storage --configuration other # once with another one
```

## Commands
1. list

- List UUIDs, optionally filtered by class.
- Examples:
- ./object-storage/bin/object-storage list
- ./object-storage/bin/object-storage list --class App\Model\User
- ./object-storage/bin/object-storage list --limit 10
- JSON output:
- ./object-storage/bin/object-storage list --json

1. get

- Fetch object and/or metadata by UUID.
- Options:
- --raw Only print object graph (JSON)
- --meta Only print metadata (JSON)
- --pretty Pretty-print JSON
- Examples:
- ./object-storage/bin/object-storage get 123e4567-e89b-12d3-a456-426614174000
- ./object-storage/bin/object-storage get UUID --meta --pretty

1. put

- Store or update an object from JSON (file or stdin).
- Options:
- --file, -f JSON file (omit to read from stdin)
- --uuid, -u Overwrite or assign UUID
- --class, -c Required for new objects when UUID is not known
- --ttl, -t Time-to-live in seconds (optional)
- Examples:
- echo '{"name":"Alice"}' | ./object-storage/bin/object-storage put --class App\Model\User
- ./object-storage/bin/object-storage put -f user.json -c App\Model\User
- ./object-storage/bin/object-storage put -f user.json -u 123e... -t 3600

1. delete

- Delete object by UUID.
- Options:
- --force, -f Do not error if not found
- Notes:
- Fails if safemode is enabled.
- Examples:
- ./object-storage/bin/object-storage delete 123e...

1. check

- Scan store for common issues:
- missing files
- decode errors
- expired objects
- Examples:
- ./object-storage/bin/object-storage check
- Non-zero exit code if issues are found.

1. stats

- Show store statistics and memory usage summary.
- Example:
- ./object-storage/bin/object-storage stats

1. safemode

- Enable/disable/toggle or show safemode state. When enabled:
- Storing and deleting objects are blocked.
- Examples:
- ./object-storage/bin/object-storage safemode --enable
- ./object-storage/bin/object-storage safemode --disable
- ./object-storage/bin/object-storage safemode --toggle
- ./object-storage/bin/object-storage safemode --status

1. lifetime

- Get, set, or remove an object’s TTL (time-to-live). When TTL expires:
- The object is treated as expired by the CLI.
- Examples:
- ./object-storage/bin/object-storage ttl
- ./object-storage/bin/object-storage ttl --set 3600
- ./object-storage/bin/object-storage ttl --set 0
- ./object-storage/bin/object-storage ttl --json
- ./object-storage/bin/object-storage ttl --json --pretty

1. touch

- Set when an object was modified — now, or the point in time given — without changing its data, revision or lifetime (`ObjectStorage::touch()`), like touch on a file.
- Examples:
- ./object-storage/bin/object-storage touch <uuid>
- ./object-storage/bin/object-storage touch <uuid> --at 2026-11-09T10:00:00+01:00
- ./object-storage/bin/object-storage touch <uuid> --json

1. query

- *Experimental.* Run an [OQL](query.md) query (`SELECT … FROM Class …`) and print the result as a table, or as JSON with `--json`.
- Options:
- --file, -f Read the query from a file (otherwise the argument, or STDIN)
- --param, -p A parameter: `name=value` for `:name`, `value` for the next `?`; numbers, true, false and null as such, "quoted" as text
- Examples:
- ./object-storage/bin/object-storage query "SELECT name, price FROM Product WHERE price < :max ORDER BY price" --param max=10
- ./object-storage/bin/object-storage query --json --file report.oql

## Exit Codes
- 0 Success
- 1 User or data error (e.g., not found, invalid input)
- 2 Operational error (e.g., invalid option combinations, storage failures)

## Tips
- Shebang: use #!/usr/bin/env php and ensure LF endings.
- Convert line endings on Unix if needed:
- dos2unix object-storage/bin/object-storage
- Set executable bit for the script:
- chmod +x object-storage/bin/object-storage
- Use --json for scripting/automation.