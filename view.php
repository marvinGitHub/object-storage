<?php

use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Util\Maintenance\ShardRebuilder;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use melia\ObjectStorage\File\Directory;

require_once __DIR__ . '/vendor/autoload.php';

if (!is_file($logfile = __DIR__ . '/logs/error.log')) {
    touch($logfile);
}

$logger = new Logger('ObjectStorageViewer');
$logger->pushHandler(new StreamHandler($logfile));

$loader = new FilesystemLoader(__DIR__ . '/views');
$twig = new Environment($loader);
$twig->addGlobal('baseUrl', $_SERVER['PHP_SELF']);

$action = $_GET['action'] ?? $_POST['action'] ?? 'index';

// PSR-16 CacheInterface implementation
$psr16 = new Psr16Cache(new FilesystemAdapter(
    namespace: 'object-storage-viewer',
    defaultLifetime: 60,        // seconds (can also pass per item)
    directory: __DIR__ . '/logs/cache'
));

$createCacheKeyShardDepth = function (string $storageDir): string {
    return 'shard_depth_' . sha1($storageDir);
};

$buildStorage = function (string $storageDir) use ($psr16, $createCacheKeyShardDepth): ObjectStorage {
    $storage = new ObjectStorage($storageDir);

    $depth = $psr16->get($key = $createCacheKeyShardDepth($storageDir));

    if ($depth === null) {
        $depth = (new Directory($storage->getShardDir()))->getMaxDirDepth();
        $psr16->set($key, $depth, 3600);
    }

    $storage->getStrategy()->setShardDepth($depth);

    return $storage;
};

try {
    switch ($action) {
        case 'index':
            echo $twig->render('index.html');
            break;
        case 'list-classnames':
            $storageDir = $_GET['storage'] ?? null;

            $exists = is_dir($storageDir);
            try {
                $classnames = $exists ? ($buildStorage($storageDir))->getClassNames() : [];
            } catch (IOException $e) {
                $classnames = [];
            }
            sort($classnames, SORT_NATURAL | SORT_FLAG_CASE);

            echo $twig->render('list-classnames.html', [
                'storage' => $storageDir,
                'classnames' => $classnames,
                'exists' => $exists,
                'hasEntries' => count($classnames) > 0,
            ]);
            break;
        case 'list-uuids':
            $page = (int)($_GET['page'] ?? 1);
            if ($page < 1) {
                $page = 1;
            }

            $pageSize = 100;

            $storageDir = $_GET['storage'] ?? null;
            $className = $_GET['classname'] ?? null;
            $storage = $buildStorage($storageDir);

            $it = $storage->list($className);
            $total = iterator_count(new IteratorIterator($it));

            $pages = $pageSize > 0 ? (int)ceil($total / $pageSize) : 0;
            $offset = ($page - 1) * $pageSize;

            $uuids = [];
            foreach (new LimitIterator(new IteratorIterator($it), $offset, $pageSize) as $uuid) {
                $uuids[] = $uuid;
            }

            echo $twig->render('list-uuids.html', [
                'storage' => $storageDir,
                'uuids' => $uuids,
                'classname' => $className ?? '',
                'hasEntries' => count($uuids) > 0,
                'pages' => $pages,
                'page' => $page,
                'total' => $total,
                'offset' => $offset,
                'end' => $offset + count($uuids),
            ]);
            break;
        case 'delete-record':
            $storageDir = $_GET['storage'] ?? null;
            $uuid = $_GET['uuid'] ?? null;

            try {
                $storage = $buildStorage($storageDir);
                $storage->delete($uuid);
                $success = false === $storage->exists($uuid);
            } catch (Throwable $e) {
                $success = false;
                $logger->error($e);
            }

            echo $twig->render('delete-record.html', [
                'storage' => $storageDir,
                'success' => $success,
                'uuid' => $uuid,
            ]);
            break;
        case 'duplicate-record':
            $uuid = $_GET['uuid'] ?? null;
            $storageDir = $_GET['storage'] ?? null;
            try {
                $storage = $buildStorage($storageDir);
                $exists = $storage->exists($uuid);
                $storage->getLockAdapter()->acquireExclusiveLock($uuid);

                $uuidNew = $storage->getNextAvailableUuid();

                $data = file_get_contents($storage->getFilePathData($uuid));
                $medata = file_get_contents($storage->getFilePathMetadata($uuid));

                $storage->buildShardedDirectory($uuidNew);

                $dataWritten = false !== file_put_contents($storage->getFilePathData($uuidNew), $data);
                $metadataWritten = false !== file_put_contents($storage->getFilePathMetadata($uuidNew), $medata);
                $storage->createStub($storage->getClassName($uuid), $uuidNew);
                $storage->getLockAdapter()->releaseLock($uuid);
                $success = $dataWritten && $metadataWritten;
            } catch (Throwable $e) {
                $success = false;
                $logger->error($e);
            }

            echo $twig->render('duplicate-record.html', [
                'storage' => $storageDir,
                'exists' => $exists ?? false,
                'success' => $success,
                'uuidNew' => $uuidNew ?? '',
            ]);

            break;
        case 'view-record':
            $storageDir = $_GET['storage'] ?? null;
            $uuid = $_GET['uuid'] ?? null;
            $storage = $buildStorage($storageDir);

            $exists = $storage->exists($uuid);
            $isLocked = $storage->getLockAdapter()->isLockedByOtherProcess($uuid);
            $metadata = null;
            if (false === $isLocked && $exists) {
                $metadata = $storage->loadMetadata($uuid);
                $classname = $storage->getClassName($uuid);
                $json = file_get_contents($storage->getFilePathData($uuid));
            }

            echo $twig->render('view-record.html', [
                'data' => json_decode($json ?? '{}', true),
                'json' => $json ?? '',
                'size' => $exists ? $storage->getMemoryConsumption($uuid) : 0,
                'storage' => $storageDir,
                'exists' => $exists,
                'uuid' => $uuid,
                'classname' => $classname ?? '',
                'checksum' => $metadata?->getChecksum() ?? '',
                'algorithm' => $metadata?->getChecksumAlgorithm() ?? '',
                'isLocked' => $isLocked,
                'lifetime' => $storage->getLifetime($uuid) ?? 'unlimited'
            ]);
            break;
        case 'save-record':
            $storageDir = $_POST['storage'] ?? null;
            $uuid = $_POST['uuid'] ?? null;
            $json = $_POST['json'] ?? null;

            if ($json) {
                $json = trim($json);
                $object = json_decode($json, false);
                if (false === is_object($object)) {
                    $success = false;
                } else {
                    $storage = null;
                    try {
                        $storage = $buildStorage($storageDir);
                        $storage->getLockAdapter()->acquireExclusiveLock($uuid);
                        $dataWritten = false !== file_put_contents($storage->getFilePathData($uuid), $json);
                        $metadata = $storage->loadMetadata($uuid);
                        $metadata->setChecksum($md5 = md5($json));
                        $metadata->setChecksumAlgorithm('md5');
                        $metadataWritten = false !== file_put_contents($storage->getFilePathMetadata($uuid), json_encode($metadata));
                        $storage->getLockAdapter()->releaseLock($uuid);
                        $success = $dataWritten && $metadataWritten;
                    } catch (Throwable $e) {
                        $success = false;
                        if ($storage?->getLockAdapter()->hasActiveExclusiveLock($uuid)) {
                            $storage?->getLockAdapter()->releaseLock($uuid);
                        }
                        $logger->error($e);
                    }
                }
            }

            echo $twig->render('save-record.html', [
                'storage' => $storageDir,
                'success' => $success ?? false,
                'uuid' => $uuid,
            ]);
            break;
        case 'rebuild-stubs':
            $storageDir = $_GET['storage'] ?? null;
            $storage = $buildStorage($storageDir);
            $success = true;
            try {
                $storage->rebuildStubs();
            } catch (Throwable $e) {
                $success = false;
                $logger->error($e);
            }

            echo $twig->render('rebuild-stubs.html', [
                'storage' => $storageDir,
                'success' => $success,
            ]);

            break;
        case 'rebuild-shards':
            $success = null;
            try {
                $storageDir = $_GET['storage'] ?? null;
                $depth = $_GET['depth'] ?? null;

                $storage = $buildStorage($storageDir);

                if ($depth) {
                    $storage->getStrategy()->setShardDepth((int)$depth);

                    $shardRebuilder = new ShardRebuilder();
                    $shardRebuilder->setStorage($storage);
                    $shardRebuilder->rebuildShards();

                    $psr16->delete($createCacheKeyShardDepth($storageDir));

                    $success = true;
                }
            } catch (Throwable $e) {
                $logger->error($e);
                $success = false;
            }

            echo $twig->render('rebuild-shards.html', [
                'storage' => $storageDir,
                'success' => $success,
            ]);

            break;
    }
} catch (Throwable $e) {
    $logger->error($e);
}