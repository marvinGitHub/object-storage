<?php

use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\ObjectStorage;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;

require_once __DIR__ . '/vendor/autoload.php';

$loader = new FilesystemLoader(__DIR__ . '/views');
$twig = new Environment($loader);
$twig->addGlobal('baseUrl', $_SERVER['PHP_SELF']);

$action = $_GET['action'] ?? $_POST['action'] ?? 'index';

try {
    switch ($action) {
        case 'index':
            echo $twig->render('index.html');
            break;
        case 'list-classnames':
            $storageDir = $_GET['storage'] ?? null;

            $exists = is_dir($storageDir);
            try {
                $classnames = $exists ? (new ObjectStorage($storageDir))->getClassnames() : [];
            } catch (IOException $e) {
                $classnames = [];
            }

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
            $storage = new ObjectStorage($storageDir);

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
                $storage = new ObjectStorage($storageDir);
                $success = $storage->delete($uuid);
            } catch (Throwable $e) {
                $success = false;
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
                $storage = new melia\ObjectStorage\ObjectStorage($storageDir);
                $exists = $storage->exists($uuid);
                $storage->getLockAdapter()->acquireExclusiveLock($uuid);

                $uuidNew = $storage->getNextAvailableUuid();

                $data = file_get_contents($storage->getFilePathData($uuid));
                $medata = file_get_contents($storage->getFilePathMetadata($uuid));

                $dataWritten = false !== file_put_contents($storage->getFilePathData($uuidNew), $data);
                $metadataWritten = false !== file_put_contents($storage->getFilePathMetadata($uuidNew), $medata);
                $storage->createStub($storage->getClassName($uuid), $uuidNew);
                $storage->getLockAdapter()->releaseLock($uuid);
                $success = $dataWritten && $metadataWritten;
            } catch (Throwable $e) {
                $success = false;
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
            $storage = new ObjectStorage($storageDir);

            $exists = $storage->exists($uuid);
            $isLocked = $storage->getLockAdapter()->isLockedByOtherProcess($uuid);
            if (false === $isLocked && $exists) {
                $metadata = $storage->loadMetadata($uuid);
                $classname = $storage->getClassName($uuid);

                $record = $storage->load($uuid);
                $json = $storage->exportGraphAndStoreReferencedChildren($record);
            }

            echo $twig->render('view-record.html', [
                'data' => json_decode($json ?? '{}', true),
                'json' => $json ?? '',
                'size' => $exists ? $storage->getMemoryConsumption($uuid) : 0,
                'storage' => $storageDir,
                'exists' => $exists,
                'uuid' => $uuid,
                'classname' => $classname ?? '',
                'checksum' => $metadata['checksum'] ?? '',
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
                    try {
                        $storage = new melia\ObjectStorage\ObjectStorage($storageDir);
                        $storage->getLockAdapter()->acquireExclusiveLock($uuid);
                        $dataWritten = false !== file_put_contents($storage->getFilePathData($uuid), $json);
                        $metadata = $storage->loadMetadata($uuid);
                        $metadata['checksum'] = md5($json);
                        $metadataWritten = false !== file_put_contents($storage->getFilePathMetadata($uuid), json_encode($metadata));
                        $storage->getLockAdapter()->releaseLock($uuid);
                        $success = $dataWritten && $metadataWritten;
                    } catch (Throwable $e) {
                        $success = false;
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
            $storage = new melia\ObjectStorage\ObjectStorage($storageDir);
            $success = true;
            try {
                $storage->rebuildStubs();
            } catch (Throwable $e) {
                $success = false;
            }

            echo $twig->render('rebuild-stubs.html', [
                'storage' => $storageDir,
                'success' => $success,
            ]);

            break;
    }
} catch (LoaderError|RuntimeError|SyntaxError $e) {
    var_dump($e);
}