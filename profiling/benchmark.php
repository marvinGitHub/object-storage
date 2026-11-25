<?php

/**
 * The time of the main operations of a storage, without services (no Redis, Memcached or XHGui; see operations.php
 * for those): file locks, no object cache, in a new directory of the temporary directory, removed at the end.
 *
 *     php profiling/benchmark.php [objects] [--no-sync] [--dir=<directory>]
 *
 * --no-sync writes without flushing to disk (fsync), which shows how much of a write is the flushing. The directory
 * decides what is measured: the disk (and file system) it is on, e.g. a Docker volume instead of a bind mount.
 * Compare runs on the same machine only.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use melia\ObjectStorage\File\Directory;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Query\Oql;

// arguments in any order (getopt() stops at the first one that isn't an option)
$count = 1000;
$sync = true;
$parent = sys_get_temp_dir();
foreach (array_slice($argv, 1) as $argument) {
    match (true) {
        '--no-sync' === $argument => $sync = false,
        str_starts_with($argument, '--dir=') => $parent = substr($argument, 6),
        ctype_digit($argument) => $count = max(1, (int)$argument),
        default => exit("Unknown argument: $argument\n"),
    };
}
$dir = rtrim($parent, '/\\') . DIRECTORY_SEPARATOR . 'object-storage-benchmark-' . bin2hex(random_bytes(4));

$open = static function () use ($dir, $sync): ObjectStorage {
    $storage = new ObjectStorage($dir);
    $storage->getFiles()->setSyncEnabled($sync);
    return $storage;
};

$measure = static function (string $label, int $operations, callable $run): mixed {
    $start = hrtime(true);
    $result = $run();
    $milliseconds = (hrtime(true) - $start) / 1e6;
    printf("%-36s %10.1f ms %10.3f ms/op\n", $label, $milliseconds, $milliseconds / $operations);
    return $result;
};

printf("%d objects, %s, PHP %s, %s\n\n", $count, $sync ? 'fsync' : 'no fsync', PHP_VERSION, $dir);

try {
    $storage = $open();
    $uuids = $measure('store new (with a new child)', $count, static function () use ($storage, $count): array {
        $uuids = [];
        for ($i = 0; $i < $count; $i++) {
            $address = new stdClass();
            $address->city = 'City' . ($i % 50);
            $user = new stdClass();
            $user->name = "user$i";
            $user->age = $i % 90;
            $user->address = $address;
            $uuids[] = $storage->store($user);
        }
        return $uuids;
    });

    $storage = $open();
    $users = $measure('load (cold, with the lazy child)', $count, static function () use ($storage, $uuids): array {
        $users = [];
        foreach ($uuids as $uuid) {
            $user = $storage->load($uuid);
            $user->address->city;
            $users[] = $user;
        }
        return $users;
    });
    $measure('load again (same instance)', $count, static function () use ($storage, $uuids): void {
        foreach ($uuids as $uuid) {
            $storage->load($uuid);
        }
    });
    $measure('store unchanged', $count, static function () use ($storage, $users): void {
        foreach ($users as $user) {
            $storage->store($user);
        }
    });
    $measure('store changed', $count, static function () use ($storage, $users): void {
        foreach ($users as $user) {
            $user->age++;
            $storage->store($user);
        }
    });
    $measure('list the objects of a class', 1, static fn(): int => iterator_count($open()->list(stdClass::class)));

    $oql = new Oql($open());
    // the first query loads the classes of OQL: not measured
    $oql->query('SELECT COUNT(*) FROM stdClass WHERE age = -1');
    $measure('OQL: WHERE age = 5 (all objects)', 1, static fn(): int => count($oql->query('SELECT name FROM stdClass WHERE age = 5')->rows));
    $measure('OQL: GROUP BY a referenced path', 1, static fn(): int => count($oql->query("SELECT address.city, COUNT(*) FROM stdClass WHERE name LIKE 'user%' GROUP BY address.city")->rows));

    $storage = $open();
    $half = array_slice($uuids, 0, max(1, intdiv($count, 2)));
    $measure('delete', count($half), static function () use ($storage, $half): void {
        foreach ($half as $uuid) {
            $storage->delete($uuid);
        }
    });

    printf("\npeak memory %.1f MB\n", memory_get_peak_usage(true) / 1048576);
} finally {
    Directory::remove($dir);
}
