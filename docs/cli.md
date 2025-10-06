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

Commands:
- list
- get
- put
- delete
- check
- stats
- safemode

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