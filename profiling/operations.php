<?php

namespace melia\ObjectStorage\Profiling;

require_once __DIR__ . '/../vendor/autoload.php';

use melia\ObjectStorage\File\Directory;
use melia\ObjectStorage\ObjectStorage;
use stdClass;
use Xhgui\Profiler\Profiler;

$profiler = new Profiler(require __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php');
$profiler->enable();

// Complex profiling scenario
$directory = new Directory();
$directory->reserveRandomTemporaryDirectory();
$storage = new ObjectStorage($directory->getPath());

// 1) Generate a heterogeneous dataset (graphs, arrays, self-references)
$rootUuids = [];
for ($i = 0; $i < 1000; $i++) {
    $user = new stdClass();
    $user->type = 'user';
    $user->id = $i + 1;
    $user->profile = new stdClass();
    $user->profile->username = ($i % 2 === 0 ? 'dev_' : 'ops_') . 'user' . $i;
    $user->profile->email = ($i % 3 === 0 ? 'john' : 'alice') . $i . '@example.com';
    $user->roles = $i % 4 === 0 ? ['admin', 'user'] : ($i % 3 === 0 ? ['auditor', 'user'] : ['user']);
    $user->flags = $i % 5 === 0 ? ['featured', 'hot'] : ['cold'];

    // Nested orders with lines
    $orders = [];
    $orderCount = ($i % 5) + 1;
    for ($o = 0; $o < $orderCount; $o++) {
        $order = new stdClass();
        $order->type = 'order';
        $order->orderNo = 'ORD-' . $i . '-' . $o;
        $order->lines = [];
        $lineCount = ($o % 3) + 1;
        for ($l = 0; $l < $lineCount; $l++) {
            $line = new stdClass();
            $line->sku = chr(65 + ($l % 3)); // A/B/C
            $line->qty = ($l + 1) * ($i % 4 + 1);
            $line->price = ($l + 1) * (($i % 7) + 9.99);
            $order->lines[] = $line;
        }
        $orders[] = $order;
    }
    $user->orders = $orders;

    // Circular reference for stress
    $user->self = $user;

    // Variable metadata arrays
    $user->meta = new stdClass();
    $user->meta->tags = [
        ['name' => ($i % 2 === 0 ? 'alpha' : 'beta')],
        ['name' => ($i % 3 === 0 ? 'release' : 'snapshot')],
    ];

    $uuid = $storage->store($user);
    $rootUuids[] = $uuid;
}

// 2) Cold cache loads and random access
$storage->clearCache();
$loadedSubset = [];
foreach (array_slice($rootUuids, 0, 30) as $uuid) {
    $loadedSubset[$uuid] = $storage->load($uuid);
}

// 3) Queries: equality, nested fields, regex-like, array membership
$foundUsers = [];
foreach ($storage->match(function (stdClass $o) {
    // username starts with dev_ and has featured flag
    return isset($o->profile->username, $o->flags)
        && strpos($o->profile->username, 'dev_') === 0
        && in_array('featured', $o->flags);
}, stdClass::class) as $uuid => $obj) {
    $foundUsers[$uuid] = $obj;
}

// 4) Subset refinement: admin role within previous result
$admins = [];
foreach ($storage->match(matcher: function (stdClass $o) {
    return isset($o->roles) && is_array($o->roles) && in_array('admin', $o->roles);
}, subSet: array_keys($foundUsers)) as $uuid => $obj) {
    $admins[$uuid] = $obj;
}

// 5) Price-based order filtering on a subset: any order line price > threshold
$highValue = [];
foreach ($storage->match(matcher: function (stdClass $o) {
    if (!isset($o->orders) || !is_array($o->orders)) return false;
    foreach ($o->orders as $order) {
        if (!isset($order->lines) || !is_array($order->lines)) continue;
        foreach ($order->lines as $line) {
            if (isset($line->price) && $line->price > 50) {
                return true;
            }
        }
    }
    return false;
}, subSet: array_keys($admins)) as $uuid => $obj) {
    $highValue[$uuid] = $obj;
}

// 6) Cache-churn: alternating load/clear to profile cache behavior
for ($r = 0; $r < 5; $r++) {
    foreach (array_slice($rootUuids, $r * 10, 10) as $uuid) {
        $storage->load($uuid);
        $storage->exists($uuid);
        $storage->getMemoryConsumption($uuid);
        $storage->getLifetime($uuid);
        $storage->expired($uuid);
    }
    $storage->clearCache();
}

// 7) Update some objects and re-store to measure write cost and invalidation
foreach (array_slice($highValue, 0, 10, true) as $uuid => $obj) {
    $obj->profile->email = 'updated+' . $obj->profile->email;
    $obj->flags[] = 'touched';
    $storage->store($obj); // overwrite same logical entity
}

// 8) Random deletions: simulate churn
$toDelete = array_slice($rootUuids, 10, 50);
foreach ($toDelete as $uuid) {
    $storage->delete($uuid);
}
$rootUuids = array_diff($rootUuids, $toDelete);
$storage->clearCache();

// 9) Warm-cache batch loads followed by a complex match over a known subset
$warmSet = array_slice($rootUuids, 0, 40);
foreach ($warmSet as $uuid) {
    $storage->load($uuid);
}
$filtered = [];
foreach ($storage->match(function (stdClass $o) {
    // Must be user, have beta tag somewhere, and at least one order with SKU B
    if (!isset($o->type) || $o->type !== 'user') return false;
    if (!isset($o->meta->tags) || !is_array($o->meta->tags)) return false;
    $hasBeta = false;
    foreach ($o->meta->tags as $tag) {
        if (is_array($tag) && isset($tag['name']) && $tag['name'] === 'beta') {
            $hasBeta = true; break;
        }
    }
    if (!$hasBeta) return false;

    if (!isset($o->orders) || !is_array($o->orders)) return false;
    foreach ($o->orders as $order) {
        if (!isset($order->lines) || !is_array($order->lines)) continue;
        foreach ($order->lines as $line) {
            if (isset($line->sku) && $line->sku === 'B') {
                return true;
            }
        }
    }
    return false;
}, stdClass::class) as $uuid => $obj) {
    $filtered[$uuid] = $obj;
}

// 10) Multiple cold/warm cycles to capture time variance
for ($cycle = 0; $cycle < 3; $cycle++) {
    $storage->clearCache();
    foreach (array_slice($rootUuids, 50, 25) as $uuid) {
        $storage->load($uuid);
    }
    foreach (array_slice($rootUuids, 75, 25) as $uuid) {
        $storage->exists($uuid);
        $storage->getMemoryConsumption($uuid);
    }
}

// 11) Final summary calls to touch varied code paths
foreach (array_slice($rootUuids, 0, 10) as $uuid) {
    $storage->expired($uuid);
    $storage->getLifetime($uuid);
}

// Finish and save profile
$data = $profiler->disable();
$profiler->save($data);