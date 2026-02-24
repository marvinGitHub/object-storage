<?php

namespace melia\ObjectStorage\Profiling;

require_once __DIR__ . '/../vendor/autoload.php';

use melia\ObjectStorage\Event\Dispatcher;
use melia\ObjectStorage\File\Directory;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Locking\Backends\FileSystem;
use melia\ObjectStorage\Locking\Backends\Memcache as Cache;
use melia\ObjectStorage\State\StateHandler;
use melia\ObjectStorage\Cache\InMemoryCache;
use melia\ObjectStorage\Logger\LoggerInterface;
use Memcache;
use stdClass;
use Throwable;
use Xhgui\Profiler\Profiler;

// Define configurations to profile
$configurations = [
    'filesystem-lock' => function (string $dir) {
        $logger = new class implements LoggerInterface {
            public function log(Throwable|string $error): void
            {
                // No-op logger
            }
        };

        $eventDispatcher = new Dispatcher();
        $state = new StateHandler($dir);
        $state->setEventDispatcher($eventDispatcher);

        $lock = new FileSystem($dir);
        $lock->setStateHandler($state);
        $lock->setLogger($logger);
        $lock->setEventDispatcher($eventDispatcher);

        $cache = new InMemoryCache();

        return new ObjectStorage(
            $dir,
            $logger,
            $lock,
            $state,
            $eventDispatcher,
            $cache
        );
    },

    'memcache-lock' => function (string $dir) {
        $logger = new class implements LoggerInterface {
            public function log(Throwable|string $error): void
            {
                // No-op logger
            }
        };

        $eventDispatcher = new Dispatcher();
        $state = new StateHandler($dir);
        $state->setEventDispatcher($eventDispatcher);

        $memcache = new Memcache();
        $memcache->connect('localhost', 11211);

        $lock = new Cache($memcache);
        $lock->setStateHandler($state);
        $lock->setEventDispatcher($eventDispatcher);

        $cache = new InMemoryCache();

        return new ObjectStorage(
            $dir,
            $logger,
            $lock,
            $state,
            $eventDispatcher,
            $cache
        );
    },
];

// Select configuration to profile (can be changed or passed as CLI argument)
$configName = $argv[1] ?? 'all';

if ($configName !== 'all' && !isset($configurations[$configName])) {
    echo "Unknown configuration: $configName\n";
    echo "Available configurations: " . implode(', ', array_keys($configurations)) . ", all\n";
    exit(1);
}

$configurationsToRun = $configName === 'all'
    ? array_keys($configurations)
    : [$configName];

// Workload function to avoid duplication
function runWorkload(ObjectStorage $storage): array
{
    $rootUuids = [];

    // 1) Generate heterogeneous dataset
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
    foreach (array_slice($rootUuids, 0, 30) as $uuid) {
        $storage->load($uuid);
    }

    // 3) Complex queries
    $foundUsers = [];
    foreach ($storage->match(function (stdClass $o) {
        return isset($o->profile->username, $o->flags)
            && strpos($o->profile->username, 'dev_') === 0
            && in_array('featured', $o->flags);
    }, stdClass::class) as $uuid => $obj) {
        $foundUsers[$uuid] = $obj;
    }

    // 4) Subset refinement
    $admins = [];
    foreach ($storage->match(matcher: function (stdClass $o) {
        return isset($o->roles) && is_array($o->roles) && in_array('admin', $o->roles);
    }, subSet: array_keys($foundUsers)) as $uuid => $obj) {
        $admins[$uuid] = $obj;
    }

    // 5) Price-based filtering
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

    // 6) Cache-churn cycles
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

    // 7) Update operations
    foreach (array_slice($highValue, 0, 10, true) as $uuid => $obj) {
        $obj->profile->email = 'updated+' . $obj->profile->email;
        $obj->flags[] = 'touched';
        $storage->store($obj);
    }

    // 8) Deletions
    $toDelete = array_slice($rootUuids, 10, 50);
    foreach ($toDelete as $uuid) {
        $storage->delete($uuid);
    }
    $rootUuids = array_diff($rootUuids, $toDelete);
    $storage->clearCache();

    // 9) Warm-cache operations
    $warmSet = array_slice($rootUuids, 0, 40);
    foreach ($warmSet as $uuid) {
        $storage->load($uuid);
    }

    $filtered = [];
    foreach ($storage->match(function (stdClass $o) {
        if (!isset($o->type) || $o->type !== 'user') return false;
        if (!isset($o->meta->tags) || !is_array($o->meta->tags)) return false;

        $hasBeta = false;
        foreach ($o->meta->tags as $tag) {
            if (is_array($tag) && isset($tag['name']) && $tag['name'] === 'beta') {
                $hasBeta = true;
                break;
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

    // 10) Multiple cold/warm cycles
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

    // 11) Final summary calls
    foreach (array_slice($rootUuids, 0, 10) as $uuid) {
        $storage->expired($uuid);
        $storage->getLifetime($uuid);
    }

    return $rootUuids;
}

foreach ($configurationsToRun as $currentConfig) {

    echo "Profiling configuration: $currentConfig\n";

    $profiler = new Profiler(require __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php');
    $profiler->enable();

    // Setup storage with selected configuration
    $directory = new Directory();
    $directory->reserveRandomTemporaryDirectory();
    $storage = $configurations[$currentConfig]($directory->getPath());

    // Run the workload
    runWorkload($storage);

    // Finish and save profile
    $data = $profiler->disable();
    $profiler->save($data);

    echo "Profiling complete for $currentConfig. Results saved.\n";

    // Cleanup
    unset($storage, $directory);

    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "All profiling sessions complete.\n";
echo str_repeat('=', 60) . "\n";