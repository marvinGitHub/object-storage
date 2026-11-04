<?php

use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Graph\GraphVisitor;
use melia\ObjectStorage\Util\Maintenance\ShardRebuilder;
use melia\ObjectStorage\Util\Maintenance\StubRebuilder;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use melia\ObjectStorage\File\Directory;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\Search\DocumentBuilder;
use melia\ObjectStorage\Search\Engine\EngineInterface;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Index;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\IndexInterface;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Search\Loupe\LoupeEngine;
use melia\ObjectStorage\Search\Request;
use Loupe\Loupe\LoupeFactory;
use function SebastianBergmann\ObjectGraph\object_graph_dump;

require_once __DIR__ . '/vendor/autoload.php';

$appDataDir = __DIR__ . DIRECTORY_SEPARATOR . 'appData';
$logDir = $appDataDir . DIRECTORY_SEPARATOR . 'logs';
$cacheDir = $appDataDir . DIRECTORY_SEPARATOR . 'cache';
$exportDir = $appDataDir . DIRECTORY_SEPARATOR . 'export';

foreach ([
             $appDataDir,
             $logDir,
             $cacheDir,
             $exportDir
         ] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
        chmod($dir, 0777);
    }
}

if (!is_file($logfile = $logDir . DIRECTORY_SEPARATOR . 'error.log')) {
    touch($logfile);
}

$logger = new Logger('ObjectStorageViewer');
$logger->pushHandler(new StreamHandler($logfile));

$loader = new FilesystemLoader(__DIR__ . '/views');
$twig = new Environment($loader);
$twig->addGlobal('baseUrl', $_SERVER['PHP_SELF']);
// sizes in bytes, readable: 512 B, 3.4 KB, 12.1 MB (1 KB = 1024 bytes)
$twig->addFilter(new \Twig\TwigFilter('bytes', $formatBytes = static function (?int $bytes): string {
    if (null === $bytes) {
        return '–';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $unit = 0;
    $value = (float)$bytes;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    return 0 === $unit ? sprintf('%d B', $bytes) : sprintf('%.1f %s', $value, $units[$unit]);
}));

$action = $_GET['action'] ?? $_POST['action'] ?? 'index';

// PSR-16 CacheInterface implementation
$psr16 = new Psr16Cache(new FilesystemAdapter(
    namespace: 'object-storage-viewer',
    defaultLifetime: 60,        // seconds (can also pass per item)
    directory: $cacheDir
));

$createCacheKeyShardDepth = static function (string $storageDir): string {
    return 'shard_depth_' . sha1($storageDir);
};

$buildStorage = static function (string $storageDir) use ($psr16, $createCacheKeyShardDepth): ObjectStorage {
    $storage = new ObjectStorage($storageDir);

    $depth = $psr16->get($key = $createCacheKeyShardDepth($storageDir));

    if ($depth === null) {
        $depth = (new Directory($storage->getShardDir()))->getMaxDirDepth();
        $psr16->set($key, $depth, 3600);
    }

    $storage->getStrategy()->setShardDepth($depth);

    return $storage;
};

// the available search engines; Loupe needs loupe/loupe, ext-intl and ext-pdo_sqlite
$searchEngines = [];
if (class_exists(LoupeFactory::class) && extension_loaded('intl') && extension_loaded('pdo_sqlite')) {
    $searchEngines[] = new LoupeEngine();
}
$searchAvailable = [] !== $searchEngines;

/* the search indexes of a storage, attached so changes made in the viewer are applied to them */
$searchFor = static function (ObjectStorage $storage) use ($searchEngines): ?Manager {
    static $instances = [];

    if ([] === $searchEngines) {
        return null;
    }

    $id = spl_object_id($storage);
    if (!isset($instances[$id])) {
        $instances[$id] = new Manager($storage, $searchEngines);
        $instances[$id]->attach();
    }

    return $instances[$id];
};

$engineNames = array_map(static fn(EngineInterface $engine): string => $engine->getName(), $searchEngines);

$buildStorage = static function (?string $storageDir) use ($buildStorage, $searchFor): ObjectStorage {
    $storage = $buildStorage((string)$storageDir);
    $searchFor($storage);
    return $storage;
};

$jsonResponse = static function (array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};

$requirePost = static function (): void {
    if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
        throw new RuntimeException('This action requires a POST request.');
    }
};

/* the index summary shown in the list */
$describeIndex = static function (IndexInterface $index): array {
    $definition = $index->getDefinition();
    $state = $index->getState();

    return [
        'name' => $index->getName(),
        'engine' => $index->getEngineName(),
        'className' => $definition->className,
        'status' => $index->getStatus(),
        'documents' => $index->countDocuments(),
        'size' => $index->getSize(),
        'pending' => $index->countPendingChanges(),
        'fields' => array_map(static fn(Field $field): array => $field->toArray(), $definition->fields),
        'languages' => $definition->languages,
        'lastBuildAt' => $state->lastBuildAt,
        'lastSyncAt' => $state->lastSyncAt,
        'updates' => $state->updates,
        'lastUpdate' => $state->getLastUpdate(),
        // states stored before the updates were recorded only know the last build and sync
        'lastUpdatedAt' => $state->getLastUpdate()['at'] ?? (array_filter([$state->lastBuildAt, $state->lastSyncAt])
            ? max(array_map('strtotime', array_filter([$state->lastBuildAt, $state->lastSyncAt])))
            : null),
        'errors' => $state->errors,
        'progress' => $index->getProgress($state),
    ];
};

/* field suggestions from a stored object of the class: path => type */
$suggestFields = static function (ObjectStorage $storage, string $className): array {
    foreach ($storage->list($className) as $uuid) {
        $metadata = $storage->loadMetadata($uuid);
        $data = json_decode((string)@file_get_contents($storage->getFilePathData($uuid)), true);
        if (null === $metadata || !is_array($data)) {
            continue;
        }

        return array_map(
            static fn(Field $field): array => $field->toArray(),
            DocumentBuilder::suggestFields($data, $metadata->getReservedReferenceName())
        );
    }

    return [];
};

/* the definition from the posted form */
$definitionFromRequest = static function (array $input): Definition {
    $fields = [];
    foreach ($input['fields'] ?? [] as $field) {
        if ('' === trim((string)($field['name'] ?? '')) && '' === trim((string)($field['path'] ?? ''))) {
            continue; // empty row
        }
        $fields[] = Field::fromArray([
            'name' => trim((string)($field['name'] ?? '')),
            'path' => trim((string)($field['path'] ?? '')),
            'type' => (string)($field['type'] ?? FieldType::STRING->value),
            'searchable' => isset($field['searchable']),
            'filterable' => isset($field['filterable']),
            'sortable' => isset($field['sortable']),
        ]);
    }

    $languages = array_values(array_filter(array_map('trim', explode(',', (string)($input['languages'] ?? '')))));

    return new Definition(
        name: trim((string)($input['name'] ?? '')),
        className: trim((string)($input['classname'] ?? '')),
        fields: $fields,
        languages: $languages,
        engine: '' === trim((string)($input['engine'] ?? '')) ? null : trim((string)$input['engine']),
    );
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

            // the number of search indexes per class, to search a class
            $indexCounts = [];
            if ($exists && null !== ($search = $searchFor($buildStorage($storageDir)))) {
                try {
                    foreach ($search->list() as $index) {
                        $indexClass = $index->getDefinition()->className;
                        $indexCounts[$indexClass] = ($indexCounts[$indexClass] ?? 0) + 1;
                    }
                } catch (Throwable $e) {
                    $logger->error($e);
                }
            }

            echo $twig->render('list-classnames.html', [
                'storage' => $storageDir,
                'indexCounts' => $indexCounts,
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
                $storage->delete($uuid); // the attached search indexes follow the delete
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

                // the copied metadata must name the duplicate, otherwise later metadata updates of the
                // duplicate (e.g. its lifetime) would be written to the original
                $metadata = Metadata::createFromArray(json_decode(file_get_contents($storage->getFilePathMetadata($uuid)), true, 512, JSON_THROW_ON_ERROR));
                $metadata->setUuid($uuidNew);

                // written as a group (the writer creates the shard directory), so a crash leaves no half copy
                $storage->getWriter()->atomicWriteAll([
                    $storage->getFilePathData($uuidNew) => $data,
                    $storage->getFilePathMetadata($uuidNew) => json_encode($metadata, JSON_THROW_ON_ERROR),
                ]);
                $storage->createStub($storage->getClassName($uuid), $uuidNew);
                $storage->getLockAdapter()->releaseLock($uuid);
                $success = true;

                // written without the storage: the search indexes are notified explicitly
                try {
                    $searchFor($storage)?->notifyChanged($uuidNew, $metadata->getClassName());
                } catch (Throwable $e) {
                    $logger->error($e);
                }
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
            $metadata = $storage->loadMetadata($uuid);
            $classname = $storage->getClassName($uuid);

            if (false === $isLocked && $exists) {
                $json = file_get_contents($storage->getFilePathData($uuid));
            }

            $size = 0;
            if ($exists) {
                try {
                    $size = $storage->getMemoryConsumption($uuid);
                } catch (Exception $e) {
                    $logger->error($e);
                }
            }

            // the search indexes of the class of the record, to choose which to update when saving it
            $recordIndexes = [];
            if (null !== $classname && null !== ($search = $searchFor($storage))) {
                try {
                    foreach ($search->getIndexesForClass($classname) as $name => $index) {
                        $recordIndexes[] = [
                            'name' => $name,
                            'engine' => $index->getEngineName(),
                            'status' => $index->getStatus(),
                            'pending' => $index->hasPendingChange($uuid),
                        ];
                    }
                } catch (Throwable $e) {
                    $logger->error($e);
                }
            }

            echo $twig->render('view-record.html', [
                'indexes' => $recordIndexes,
                'data' => json_decode($json ?? '{}', true, 512, JSON_THROW_ON_ERROR),
                'json' => $json ?? '',
                'size' => $size ?? 0,
                'storage' => $storageDir,
                'exists' => $exists,
                'uuid' => $uuid,
                'classname' => $classname ?? '',
                'checksum' => $metadata?->getChecksum() ?? '',
                'algorithm' => $metadata?->getChecksumAlgorithm() ?? '',
                'isLocked' => $isLocked,
                'lifetime' => $storage->getLifetime($uuid) ?? 'unlimited',
                // seconds since the epoch, shown in the time zone of the browser; null without expiration
                'expiresAt' => $metadata?->getTimestampExpiresAt(),
                'shard' => $storage->buildShardedDirectory($uuid),
                'message' => $_GET['message'] ?? null,
                'error' => $_GET['error'] ?? null,
            ]);
            break;
        case 'set-lifetime':
            $storageDir = $_POST['storage'] ?? null;
            $uuid = (string)($_POST['uuid'] ?? '');
            $result = [];

            try {
                $requirePost();
                $storage = $buildStorage($storageDir);
                if (!$storage->exists($uuid)) {
                    throw new RuntimeException('Record not found.');
                }

                // the attached search indexes follow the change (LIFETIME_CHANGED)
                switch ($_POST['mode'] ?? '') {
                    case 'unlimited':
                        $storage->setLifetime($uuid, null);
                        $result['message'] = 'The record never expires now.';
                        break;
                    case 'ttl':
                        $units = ['seconds' => 1, 'minutes' => 60, 'hours' => 3600, 'days' => 86400];
                        $amount = (float)($_POST['amount'] ?? 0);
                        $unit = $units[$_POST['unit'] ?? ''] ?? throw new RuntimeException('Invalid unit.');
                        if ($amount <= 0) {
                            throw new RuntimeException('The lifetime must be greater than 0.');
                        }
                        $storage->setLifetime($uuid, $amount * $unit);
                        $result['message'] = sprintf('The record expires in %s %s.', $_POST['amount'], $_POST['unit']);
                        break;
                    case 'expires':
                        // sent by the browser in UTC, see view-record.html
                        $expiresAt = new DateTimeImmutable((string)($_POST['expiresAt'] ?? ''));
                        $storage->setExpiration($uuid, $expiresAt);
                        $result['message'] = $expiresAt->getTimestamp() <= time()
                            ? 'The record has expired: loading it returns null now.'
                            : 'The expiration has been set.';
                        break;
                    default:
                        throw new RuntimeException('Choose how long the record lives.');
                }
            } catch (Throwable $e) {
                $logger->error($e);
                $result['error'] = 'The lifetime couldn\'t be set: ' . $e->getMessage();
            }

            header('Location: ?' . http_build_query(['action' => 'view-record', 'storage' => $storageDir, 'uuid' => $uuid] + $result));
            break;
        case 'save-record':
            $storageDir = $_POST['storage'] ?? null;
            $uuid = $_POST['uuid'] ?? null;
            $json = $_POST['json'] ?? null;

            if ($json) {
                $json = trim($json);

                try {
                    $object = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    $success = false;
                    $object = null;
                }

                if (false === is_object($object)) {
                    $success = false;
                } else {
                    $storage = null;
                    $lockAcquired = false;
                    try {
                        $storage = $buildStorage($storageDir);
                        $metadata = $storage->loadMetadata($uuid);
                        if (null === $metadata) {
                            throw new RuntimeException('No metadata found for UUID: ' . $uuid);
                        }

                        $storage->getLockAdapter()->acquireExclusiveLock($uuid);
                        $lockAcquired = true;

                        $dataWritten = false !== file_put_contents($storage->getFilePathData($uuid), $json);

                        if (!$dataWritten) {
                            throw new RuntimeException('Unable to write object data.');
                        }

                        $metadata->setChecksum(md5($json));
                        $metadata->setChecksumAlgorithm('md5');

                        $metadataJson = json_encode($metadata, JSON_THROW_ON_ERROR);
                        $metadataWritten = false !== file_put_contents($storage->getFilePathMetadata($uuid), $metadataJson);

                        if (!$metadataWritten) {
                            throw new RuntimeException('Unable to write metadata.');
                        }

                        $success = true;

                        // written without the storage: the chosen search indexes are notified explicitly; without
                        // a choice in the form (e.g. no indexes when the page was loaded), all of the class
                        try {
                            $storage->getLockAdapter()->releaseLock($uuid);
                            $lockAcquired = false;
                            $search = $searchFor($storage);
                            if (null !== $search) {
                                $chosen = isset($_POST['indexSelection']) ? array_map('strval', (array)($_POST['indexes'] ?? [])) : null;
                                $updatedIndexes = $search->notifyChanged($uuid, $metadata->getClassName(), $chosen);
                                $skippedIndexes = array_values(array_diff(array_keys($search->getIndexesForClass($metadata->getClassName())), $updatedIndexes));
                            }
                        } catch (Throwable $e) {
                            $logger->error($e);
                            $indexError = $e->getMessage();
                        }
                    } catch (Throwable $e) {
                        $success = false;
                        $logger->error($e);
                    } finally {
                        if ($lockAcquired && $storage?->getLockAdapter() && $storage->getLockAdapter()->hasActiveExclusiveLock($uuid)) {
                            $storage->getLockAdapter()->releaseLock($uuid);
                        }
                    }
                }
            }

            echo $twig->render('save-record.html', [
                'storage' => $storageDir,
                'success' => $success ?? false,
                'uuid' => $uuid,
                'updatedIndexes' => $updatedIndexes ?? [],
                'skippedIndexes' => $skippedIndexes ?? [],
                'indexError' => $indexError ?? null,
            ]);
            break;
        case 'export-record':
            $storageDir = $_GET['storage'] ?? null;
            $uuid = $_GET['uuid'] ?? null;
            $storage = $buildStorage($storageDir);

            $filename = uniqid($uuid, true) . '.dot';
            $destination = $exportDir . DIRECTORY_SEPARATOR . $filename;

            try {
                $target = $storage->load($uuid);
                $visitor = new GraphVisitor($storage);
                $referenceGraph = $visitor->buildReferenceGraph($target, $uuid, $target::class);

                object_graph_dump($destination, $referenceGraph);
                $success = true;

                header('Content-Description: File Transfer');
                header('Content-Type: text/vnd.graphviz');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . filesize($destination));
                header('Cache-Control: no-cache, must-revalidate');
                header('Pragma: public');
                readfile($destination);
                exit;
            } catch (Throwable $e) {
                $logger->error($e);

                echo $twig->render('export-failure.html', [
                    'storage' => $storageDir,
                    'success' => false,
                    'uuid' => $uuid,
                ]);
            } finally {
                if (is_file($destination)) {
                    unlink($destination);
                }
            }

            break;
        case 'rebuild-stubs':
            // the page runs the rebuild step by step via the JSON actions below, showing the progress
            $storageDir = $_GET['storage'] ?? null;
            $stubRebuilder = new StubRebuilder();
            $stubRebuilder->setStorage($buildStorage($storageDir));

            echo $twig->render('rebuild-stubs.html', [
                'storage' => $storageDir,
                'running' => $stubRebuilder->isRunning() ? $stubRebuilder->getProgress() : null,
            ]);
            break;
        case 'rebuild-stubs-start':
        case 'rebuild-stubs-step':
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
            break;
        case 'indexes':
            $storageDir = $_GET['storage'] ?? null;
            $indexes = [];
            $error = null;

            if ($searchAvailable) {
                try {
                    foreach ($searchFor($buildStorage($storageDir))->list() as $index) {
                        try {
                            $indexes[] = $describeIndex($index);
                        } catch (Throwable $e) {
                            $logger->error($e);
                            $indexes[] = ['name' => $index->getName(), 'broken' => $e->getMessage()];
                        }
                    }
                } catch (Throwable $e) {
                    $logger->error($e);
                    $error = $e->getMessage();
                }
            }

            echo $twig->render('indexes.html', [
                'storage' => $storageDir,
                'searchAvailable' => $searchAvailable,
                'indexes' => $indexes,
                'error' => $error,
                'message' => $_GET['message'] ?? null,
            ]);
            break;
        case 'index-edit':
            // create (no name) or edit an index; with "suggest", the fields are suggested from a stored object
            $storageDir = $_GET['storage'] ?? null;
            $name = $_GET['name'] ?? null;
            $storage = $buildStorage($storageDir);
            $search = $searchFor($storage);

            $classnames = $storage->getClassNames();
            sort($classnames, SORT_NATURAL | SORT_FLAG_CASE);

            $definition = null;
            $described = null;
            if (null !== $name && null !== $search) {
                $index = $search->get($name);
                $definition = $index->getDefinition()->toArray();
                $described = $describeIndex($index);
            }

            $classname = $_GET['classname'] ?? $definition['className'] ?? '';
            $fields = $definition['fields'] ?? [];
            if (isset($_GET['suggest']) && '' !== $classname) {
                $fields = $suggestFields($storage, $classname);
            }

            echo $twig->render('index-edit.html', [
                'storage' => $storageDir,
                'searchAvailable' => $searchAvailable,
                'isNew' => null === $definition,
                'name' => $definition['name'] ?? ($_GET['newName'] ?? ''),
                'classname' => $classname,
                'classnames' => $classnames,
                'languages' => implode(', ', $definition['languages'] ?? []),
                'fields' => $fields,
                'types' => array_map(static fn(FieldType $type): string => $type->value, FieldType::cases()),
                'engines' => $engineNames,
                'engine' => $definition['engine'] ?? null,
                'index' => $described,
                'error' => null,
            ]);
            break;
        case 'index-save':
            $requirePost();
            $storageDir = $_POST['storage'] ?? null;
            $storage = $buildStorage($storageDir);
            $search = $searchFor($storage) ?? throw new RuntimeException('Search is not available.');
            $isNew = '1' === ($_POST['isNew'] ?? '1');

            try {
                $definition = $definitionFromRequest($_POST);
                $isNew ? $search->create($definition) : $search->update($definition);

                // build it right away, showing the progress
                header('Location: ?action=index-build&start=1&storage=' . rawurlencode((string)$storageDir) . '&name=' . rawurlencode($definition->name));
            } catch (Throwable $e) {
                $logger->error($e);
                $classnames = $storage->getClassNames();
                sort($classnames, SORT_NATURAL | SORT_FLAG_CASE);

                echo $twig->render('index-edit.html', [
                    'storage' => $storageDir,
                    'searchAvailable' => $searchAvailable,
                    'isNew' => $isNew,
                    'name' => $_POST['name'] ?? '',
                    'classname' => $_POST['classname'] ?? '',
                    'classnames' => $classnames,
                    'languages' => $_POST['languages'] ?? '',
                    'fields' => array_values(array_map(static fn(array $field): array => [
                        'name' => $field['name'] ?? '',
                        'path' => $field['path'] ?? '',
                        'type' => $field['type'] ?? FieldType::STRING->value,
                        'searchable' => isset($field['searchable']),
                        'filterable' => isset($field['filterable']),
                        'sortable' => isset($field['sortable']),
                    ], $_POST['fields'] ?? [])),
                    'types' => array_map(static fn(FieldType $type): string => $type->value, FieldType::cases()),
                    'engines' => $engineNames,
                    'engine' => $_POST['engine'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
            break;
        case 'index-build':
            // the page runs the build step by step via the JSON actions below, showing the progress
            $storageDir = $_GET['storage'] ?? null;
            $name = $_GET['name'] ?? '';
            $search = $searchFor($buildStorage($storageDir));

            echo $twig->render('index-build.html', [
                'storage' => $storageDir,
                'searchAvailable' => $searchAvailable,
                'index' => null === $search ? null : $describeIndex($search->get($name)),
                'start' => isset($_GET['start']),
            ]);
            break;
        case 'index-build-start':
        case 'index-build-step':
        case 'index-build-cancel':
        case 'index-sync':
        case 'index-drop':
            // JSON actions, called by the pages
            try {
                $requirePost();
                $storageDir = $_POST['storage'] ?? null;
                $search = $searchFor($buildStorage($storageDir)) ?? throw new RuntimeException('Search is not available.');
                $index = $search->get((string)($_POST['name'] ?? ''));

                $result = match ($action) {
                    // a running build is continued instead of started again, e.g. after reloading the page
                    'index-build-start' => IndexInterface::STATUS_BUILDING === $index->getStatus() ? $index->getProgress() : $index->startBuild(),
                    'index-build-step' => $index->buildStep(max(1, min(5000, (int)($_POST['batchSize'] ?? Index::DEFAULT_BATCH_SIZE)))),
                    'index-build-cancel' => (static function () use ($index): array {
                        $index->cancelBuild();
                        return $index->getProgress();
                    })(),
                    'index-sync' => ['applied' => $index->sync(purgeExpired: false), 'expired' => $index->purgeExpired()],
                    'index-drop' => (static function () use ($search, $index): array {
                        $search->drop($index->getName());
                        return ['dropped' => true];
                    })(),
                };

                // the build page shows the size growing
                if (str_starts_with($action, 'index-build-')) {
                    $result['size'] = $formatBytes($index->getSize());
                }

                $jsonResponse(['ok' => true, 'result' => $result]);
            } catch (Throwable $e) {
                $logger->error($e);
                $jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
            }
            break;
        case 'index-search':
        case 'class-search':
            // where the time of the page goes: shown on the page and sent as Server-Timing for the browser's dev tools
            $timings = ['bootstrap' => (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000];
            $timer = microtime(true);
            $lap = static function (string $step) use (&$timings, &$timer): void {
                $now = microtime(true);
                $timings[$step] = ($timings[$step] ?? 0) + ($now - $timer) * 1000;
                $timer = $now;
            };

            $storageDir = $_GET['storage'] ?? null;
            $name = $_GET['name'] ?? '';
            $storage = $buildStorage($storageDir);
            $search = $searchFor($storage);
            $lap('storage');

            $form = [
                'query' => (string)($_GET['q'] ?? ''),
                'filter' => (string)($_GET['filter'] ?? ''),
                'sort' => (string)($_GET['sort'] ?? ''),
                'facets' => (string)($_GET['facets'] ?? ''),
                'page' => max(1, (int)($_GET['page'] ?? 1)),
                'hitsPerPage' => max(1, min(1000, (int)($_GET['hitsPerPage'] ?? 20))),
                'searchOn' => (string)($_GET['searchOn'] ?? ''),
            ];

            $result = null;
            $error = null;
            $index = null;
            $definition = null;

            // searching a class: the index manager chooses the index, like a database the index of a table
            $classname = 'class-search' === $action ? (string)($_GET['classname'] ?? '') : null;
            $plan = null;

            try {
                if (null === $search) {
                    throw new RuntimeException('Search is not available.');
                }

                $split = static fn(string $list): array => array_values(array_filter(array_map('trim', explode(',', $list))));

                // engine-independent; only the filter is written in the language of the engine
                $request = new Request(
                    query: $form['query'],
                    filter: '' === trim($form['filter']) ? null : $form['filter'],
                    sort: $split($form['sort']),
                    facets: $split($form['facets']),
                    page: $form['page'],
                    hitsPerPage: $form['hitsPerPage'],
                    highlight: true,
                    highlightStartTag: '<mark>',
                    highlightEndTag: '</mark>',
                    searchOn: $split($form['searchOn']),
                );

                if (null !== $classname) {
                    $explained = $search->explain($classname, $request);
                    $plan = array_map(static fn($candidate): array => [
                        'name' => $candidate->name,
                        'engine' => $candidate->engine,
                        'status' => $candidate->status,
                        'rank' => $candidate->rank,
                        'reasons' => $candidate->reasons,
                    ], $explained->candidates);

                    // the chosen index, or, before searching or without one, any index of the class for the help
                    $name = $explained->getChosen()?->name ?? array_key_first($search->getIndexesForClass($classname)) ?? '';
                    if ('' === $name) {
                        throw new RuntimeException(sprintf('No search index exists for %s.', $classname));
                    }
                    $lap('plan');
                    if (isset($_GET['run']) && null === $explained->getChosen()) {
                        throw new RuntimeException('No index of the class can answer this search, see the plan.');
                    }
                }

                $index = $search->get($name);
                $definition = $index->getActiveDefinition() ?? $index->getDefinition();
                $lap('index');

                if (isset($_GET['run'])) {
                    $searchResult = $index->search($request);
                    $lap('search');
                    $timings['engine'] = (float)$searchResult->processingTimeMs;

                    $hits = [];
                    foreach ($searchResult->hits as $hit) {
                        $values = [];
                        foreach ($hit->document as $attribute => $value) {
                            $highlighted = $hit->highlighted[$attribute] ?? null;
                            $values[] = [
                                'name' => $attribute,
                                'value' => is_scalar($value) || null === $value ? var_export($value, true) : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                // the text is escaped, only the marks of the matches are kept as markup
                                'highlighted' => null === $highlighted ? null : str_replace(['&lt;mark&gt;', '&lt;/mark&gt;'], ['<mark>', '</mark>'], htmlspecialchars($highlighted)),
                            ];
                        }

                        $hits[] = ['uuid' => $hit->uuid, 'score' => $hit->score, 'values' => $values];
                    }

                    // page links: the first and last three, two around the current page, gaps in between
                    $pageLinks = [];
                    for ($i = 1, $total = $searchResult->totalPages; $i <= $total; $i++) {
                        if ($i <= 3 || $i > $total - 3 || abs($i - $searchResult->page) <= 2) {
                            $pageLinks[] = $i;
                        } elseif (null !== end($pageLinks)) {
                            $pageLinks[] = null;
                        }
                    }

                    $result = [
                        'hits' => $hits,
                        'pageLinks' => $pageLinks,
                        'totalHits' => $searchResult->totalHits,
                        'totalPages' => $searchResult->totalPages,
                        'page' => $searchResult->page,
                        'processingTimeMs' => $searchResult->processingTimeMs,
                        'facets' => $searchResult->facets,
                    ];
                }
            } catch (Throwable $e) {
                $logger->error($e);
                $error = $e->getMessage();
            }

            $variables = [
                'storage' => $storageDir,
                'name' => $name,
                'classname' => $classname,
                'plan' => $plan,
                'form' => $form,
                'result' => $result,
                'error' => $error,
                'status' => $index?->getStatus(),
                'engine' => $index?->getEngineName(),
                'fields' => null === $definition ? [] : array_map(static fn(Field $field): array => $field->toArray(), $definition->fields),
                'queryString' => http_build_query(array_diff_key($_GET, ['page' => true])),
            ];
            $lap('prepare');

            // rendered first, so its time is known and the header can be sent before the page
            $variables['timings'] = $timings;
            $html = $twig->render('index-search.html', $variables);
            $lap('render');
            $timings['total'] = (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000;

            $serverTiming = [];
            foreach ($timings as $step => $ms) {
                // the time inside the engine is part of "search", not a step of its own
                $serverTiming[] = sprintf('%s;%sdur=%.1f', $step, 'engine' === $step ? 'desc="inside the engine";' : '', $ms);
            }
            header('Server-Timing: ' . implode(', ', $serverTiming));

            echo str_replace('<!--timings-->', htmlspecialchars(implode(' · ', array_map(
                static fn(string $step, float $ms): string => sprintf('%s %.0f ms', $step, $ms),
                array_keys($timings),
                $timings
            ))), $html);
            break;
        case 'rebuild-shards':
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
            break;
        case 'rebuild-shards-start':
        case 'rebuild-shards-step':
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
            break;
    }
} catch (Throwable $e) {
    $logger->error($e);
}