<?php

namespace melia\ObjectStorage\Viewer\Controller;

use Throwable;
use melia\ObjectStorage\Util\Maintenance\ShardRebuilder;
use melia\ObjectStorage\Util\Maintenance\StubRebuilder;

/**
 * Rebuilding the stubs and the shards of a storage, step by step.
 */
final class MaintenanceController extends Controller
{
    /**
     * rebuild-stubs
     */
    public function rebuildStubs(): void
    {
        $twig = $this->app->twig;
        $buildStorage = $this->app->buildStorage(...);

        // the page runs the rebuild step by step via the JSON actions below, showing the progress
        $storageDir = $_GET['storage'] ?? null;
        $stubRebuilder = new StubRebuilder();
        $stubRebuilder->setStorage($buildStorage($storageDir));

        echo $twig->render('rebuild-stubs.html', [
            'storage' => $storageDir,
            'running' => $stubRebuilder->isRunning() ? $stubRebuilder->getProgress() : null,
        ]);
    }

    /**
     * rebuild-stubs-start, rebuild-stubs-step
     */
    public function rebuildStubsStart(string $action): void
    {
        $logger = $this->app->logger;
        $buildStorage = $this->app->buildStorage(...);
        $jsonResponse = $this->app->jsonResponse(...);
        $requirePost = $this->app->requirePost(...);

        try {
            $requirePost();
            $stubRebuilder = new StubRebuilder();
            $stubRebuilder->setStorage($buildStorage($_POST['storage'] ?? null));

            $progress = 'rebuild-stubs-start' === $action
                ? $stubRebuilder->start()
                : $stubRebuilder->step(max(1, min(20000, (int)($_POST['batchSize'] ?? StubRebuilder::DEFAULT_BATCH_SIZE))));

            $jsonResponse(['ok' => true, 'result' => $progress]);
        } catch (Throwable $e) {
            $logger->error($e);
            $jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * rebuild-shards
     */
    public function rebuildShards(): void
    {
        $twig = $this->app->twig;
        $buildStorage = $this->app->buildStorage(...);

        // the page runs the rebuild step by step via the JSON actions below, showing the progress
        $storageDir = $_GET['storage'] ?? null;
        $storage = $buildStorage($storageDir);
        $shardRebuilder = new ShardRebuilder();
        $shardRebuilder->setStorage($storage);

        echo $twig->render('rebuild-shards.html', [
            'storage' => $storageDir,
            'currentDepth' => $storage->getStrategy()?->getShardDepth(),
            'running' => $shardRebuilder->isRunning() ? $shardRebuilder->getProgress() : null,
        ]);
    }

    /**
     * rebuild-shards-start, rebuild-shards-step
     */
    public function rebuildShardsStart(string $action): void
    {
        $logger = $this->app->logger;
        $psr16 = $this->app->psr16;
        $buildStorage = $this->app->buildStorage(...);
        $jsonResponse = $this->app->jsonResponse(...);
        $requirePost = $this->app->requirePost(...);
        $createCacheKeyShardDepth = $this->app->createCacheKeyShardDepth(...);

        try {
            $requirePost();
            $storageDir = $_POST['storage'] ?? null;
            $storage = $buildStorage($storageDir);
            $shardRebuilder = new ShardRebuilder();
            $shardRebuilder->setStorage($storage);

            if ('rebuild-shards-start' === $action) {
                $depth = (int)($_POST['depth'] ?? 0);
                $storage->getStrategy()->setShardDepth($depth); // validates the depth
                $progress = $shardRebuilder->start();
            } else {
                $progress = $shardRebuilder->step(max(1, min(20000, (int)($_POST['batchSize'] ?? ShardRebuilder::DEFAULT_BATCH_SIZE))));
            }

            // the viewer detects the depth of a storage once and caches it: use the new one from now on
            if ($progress['done'] && null !== $progress['depth']) {
                $psr16->set($createCacheKeyShardDepth((string)$storageDir), $progress['depth'], 3600);
            }

            $jsonResponse(['ok' => true, 'result' => $progress]);
        } catch (Throwable $e) {
            $logger->error($e);
            $jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
