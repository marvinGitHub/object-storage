<?php

/**
 * Worker process for the concurrency tests, see ConcurrencyTest.
 *
 * Usage: php worker.php <scenario> <storage dir> <start file> <worker name> [args...]
 *
 * The worker waits until the start file exists, so all workers begin at the same time, then runs the scenario
 * and prints every failure as one line "ERROR <class>: <message>". It never stops on a failure, so the test
 * sees all of them.
 */

use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Strategy\Policy\ChildPersistence;
use Tests\melia\ObjectStorage\Concurrency\Counter;
use Tests\melia\ObjectStorage\Concurrency\OwnParent;
use Tests\melia\ObjectStorage\Concurrency\SharedChild;

require __DIR__ . '/../../../../vendor/autoload.php';

[, $scenario, $dir, $startFile, $worker] = $argv;
$args = array_slice($argv, 5);

$storage = new ObjectStorage($dir);
// durability is not under test here, flushing to disk would only slow the workers down
$storage->getWriter()->setSyncEnabled(false);

$report = static function (Throwable $e): void {
    $root = $e;
    while (null !== $root->getPrevious()) {
        $root = $root->getPrevious();
    }
    echo sprintf("ERROR %s: %s (cause: %s: %s)\n", get_class($e), $e->getMessage(), get_class($root), $root->getMessage());
};

$deadline = microtime(true) + 30;
while (!file_exists($startFile)) {
    if (microtime(true) > $deadline) {
        echo "ERROR start file did not appear\n";
        exit(1);
    }
    usleep(1000);
}

switch ($scenario) {
    // stores its own parent, which references a child shared by all workers and changed every round;
    // with the policy ALWAYS the child is written along with every parent
    case 'shared-child':
        [$childUuid, $rounds] = $args;
        $storage->getStrategy()->setPolicyChildPersistence(ChildPersistence::ALWAYS);
        $child = new SharedChild($worker);
        $child->setUUID($childUuid);
        $parent = new OwnParent("parent of $worker", $child);
        for ($round = 1; $round <= (int)$rounds; $round++) {
            $child->writer = $worker;
            $child->round = $round;
            // a large, worker-specific payload makes interleaved writes of the same file likely
            $child->payload = str_repeat($worker, 100_000 + $round);
            try {
                $storage->store($parent);
            } catch (Throwable $e) {
                $report($e);
            }
        }
        break;

    // read-modify-write with an exclusive load: no increment may get lost
    case 'increment':
        [$uuid, $rounds] = $args;
        for ($i = 0; $i < (int)$rounds; $i++) {
            try {
                $counter = $storage->load($uuid, exclusive: true);
                $counter->value++;
                $counter->lastWriter = $worker;
                $storage->store($counter);
            } catch (Throwable $e) {
                $report($e);
                $storage->getLockAdapter()?->releaseActiveLocks();
            }
        }
        break;

    // loads an object over and over while other workers store it
    case 'read':
        [$uuid, $rounds] = $args;
        for ($i = 0; $i < (int)$rounds; $i++) {
            try {
                $storage->clearCache();
                if (null === $storage->load($uuid)) {
                    echo "ERROR object $uuid not found\n";
                }
            } catch (Throwable $e) {
                $report($e);
            }
        }
        break;

    // stores objects of classes no other worker uses, so every worker registers its own class names
    case 'register-classes':
        [$count] = $args;
        for ($i = 0; $i < (int)$count; $i++) {
            $class = sprintf('Generated_%s_%d', $worker, $i);
            eval("final class $class { public int \$value = $i; }");
            try {
                $storage->store(new $class());
            } catch (Throwable $e) {
                $report($e);
            }
        }
        break;

    default:
        echo "ERROR unknown scenario $scenario\n";
        exit(1);
}
