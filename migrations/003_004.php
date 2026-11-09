<?php
/**
 * Removes the transaction logs of the former format.
 *
 * Up to 1.0.0-rc.6, a Transaction wrote a log <storageDir>/transactions/<id>.txn (a serialize()d array with the
 * transaction ID, a status and the type and UUID of each operation) and removed it after the commit or rollback.
 * Since then, a transaction keeps a journal directory <storageDir>/transactions/<id>/ instead, with the state of
 * every object before the transaction, and a storage rolls back transactions interrupted by a crash when it is
 * opened (docs/transactions.md).
 *
 * A .txn file that remained comes from a process that died during a transaction. It holds no states to roll back
 * to: the objects it names may have been written partly. The storage ignores these files; this script lists the
 * objects they name, so they can be checked, and removes the files.
 *
 * Stop all processes using the storage first: a process of the former version still running a transaction has a
 * .txn file as well.
 *
 * Usage:
 *   php 003_004.php --dir=/path/to/storage [--dry-run]
 *
 *   --dry-run  only lists the logs and the objects they name, removes nothing
 */

declare(strict_types=1);

$options = getopt('', ['dir:', 'dry-run']);

$storageDir = isset($options['dir']) ? rtrim((string)$options['dir'], "/\\") : '';
$dryRun = array_key_exists('dry-run', $options);

if ('' === $storageDir || !is_dir($storageDir)) {
    fwrite(STDERR, "Error: Please provide a valid storage directory using --dir=/path/to/storage\n");
    exit(2);
}

$transactionsDir = $storageDir . DIRECTORY_SEPARATOR . 'transactions';
if (!is_dir($transactionsDir)) {
    fwrite(STDOUT, "Nothing to do: {$transactionsDir} doesn't exist.\n");
    exit(0);
}

$logs = glob($transactionsDir . DIRECTORY_SEPARATOR . '*.txn') ?: [];
if ([] === $logs) {
    fwrite(STDOUT, "Nothing to do: no transaction logs of the former format.\n");
    exit(0);
}

$failed = 0;
foreach ($logs as $log) {
    if (!is_file($log)) {
        continue;
    }

    fwrite(STDOUT, "Transaction log {$log}\n");

    // only arrays and scalars: the log never held objects
    $contents = @file_get_contents($log);
    $data = false === $contents ? false : @unserialize($contents, ['allowed_classes' => false]);
    if (!is_array($data)) {
        fwrite(STDOUT, "  unreadable, the objects it names are unknown\n");
    } else {
        fwrite(STDOUT, sprintf("  transaction %s, status %s\n", $data['transaction_id'] ?? '?', $data['status'] ?? '?'));
        foreach ((array)($data['operations'] ?? []) as $operation) {
            if (is_array($operation)) {
                fwrite(STDOUT, sprintf("  %s %s — check this object\n", $operation['type'] ?? '?', $operation['uuid'] ?? '?'));
            }
        }
    }

    if ($dryRun) {
        fwrite(STDOUT, "  [dry-run] would remove it\n");
        continue;
    }

    if (@unlink($log)) {
        fwrite(STDOUT, "  removed\n");
    } else {
        fwrite(STDERR, "  failed to remove {$log}\n");
        $failed++;
    }
}

fwrite(STDOUT, sprintf("Done. Logs found: %d, failed to remove: %d\n", count($logs), $failed));
exit($failed > 0 ? 1 : 0);
