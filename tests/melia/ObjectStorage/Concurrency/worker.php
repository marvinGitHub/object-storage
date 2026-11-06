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
use melia\ObjectStorage\Strategy\Standard;
use Tests\melia\ObjectStorage\Concurrency\Counter;
use Tests\melia\ObjectStorage\Concurrency\OwnParent;
use Tests\melia\ObjectStorage\Concurrency\SharedChild;

require __DIR__ . '/../../../../vendor/autoload.php';

$argv = $_SERVER['argv'];
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
        $strategy = $storage->getStrategy();
        assert($strategy instanceof Standard);
        $strategy->setPolicyChildPersistence(ChildPersistence::ALWAYS);
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

    // creates a search index of the same name as all other workers: only one may succeed
    case 'create-index':
        [$name] = $args;
        try {
            $definition = new \melia\ObjectStorage\Search\Index\Definition($name, 'Product', [
                new \melia\ObjectStorage\Search\Field('created_by_' . $worker, 'name'),
            ]);
            // the engine doesn't matter: names are made unique by the index manager
            (new \melia\ObjectStorage\Search\Index\Manager($storage, [new \Tests\melia\ObjectStorage\Search\ArrayEngine()]))->create($definition);
            touch($dir . DIRECTORY_SEPARATOR . 'created-' . $worker);
        } catch (\melia\ObjectStorage\Search\Exception\IndexAlreadyExistsException) {
            // expected for all but one worker
        } catch (Throwable $e) {
            $report($e);
        }
        break;

    // stores new accounts with the same email as all other workers, with a unique index on it: only one may succeed
    case 'register-unique':
        [$email, $rounds] = $args;
        $unique = new \melia\ObjectStorage\Unique\Manager($storage);
        $unique->attach();
        for ($i = 0; $i < (int)$rounds; $i++) {
            try {
                $storage->store(new \Tests\melia\ObjectStorage\Concurrency\Account($email, $worker . '-' . $i));
                touch($dir . DIRECTORY_SEPARATOR . 'stored-' . $worker . '-' . $i);
            } catch (\melia\ObjectStorage\Unique\Exception\UniqueConstraintViolationException) {
                // expected for all but one attempt
            } catch (Throwable $e) {
                $report($e);
            }
        }
        break;

    default:
        echo "ERROR unknown scenario $scenario\n";
        exit(1);
}
