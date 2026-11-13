<?php

use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Configuration\CacheSettings;
use melia\ObjectStorage\Configuration\Configuration;
use melia\ObjectStorage\Configuration\Exception\ConfigurationException;
use melia\ObjectStorage\Configuration\Factory as ConfigurationFactory;
use melia\ObjectStorage\Configuration\LockSettings;
use melia\ObjectStorage\Configuration\Registry as ConfigurationRegistry;
use melia\ObjectStorage\Strategy\Policy\ChildDeletion;
use melia\ObjectStorage\Transfer\ObjectTransfer;
use melia\ObjectStorage\Transfer\RawObject;
use melia\ObjectStorage\Unique\Definition as UniqueDefinition;
use melia\ObjectStorage\Unique\Manager as UniqueManager;
use melia\ObjectStorage\Unique\PathIndexes as UniquePathIndexes;
use melia\ObjectStorage\Strategy\Policy\ChildPersistence;
use melia\ObjectStorage\Graph\GraphVisitor;
use melia\ObjectStorage\Migration\AttributeToReference;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Exception\QueryInterruptedException;
use melia\ObjectStorage\Query\Exception\ScriptException;
use melia\ObjectStorage\Query\Oql;
use melia\ObjectStorage\Query\Result;
use melia\ObjectStorage\Query\SlowQueryLog;
use melia\ObjectStorage\Path\Path;
use melia\ObjectStorage\UUID\Validator as UuidValidator;
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
use melia\ObjectStorage\Serialization\DateTimeValue;
use melia\ObjectStorage\Search\DocumentBuilder;
use melia\ObjectStorage\Search\Engine\EngineInterface;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Index;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\IndexInterface;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Search\Loupe\LoupeEngine;
use melia\ObjectStorage\Search\PatternIndex;
use melia\ObjectStorage\Search\Request;
use Loupe\Loupe\LoupeFactory;
use melia\ObjectStorage\Graph\Export\Dot\DotExporter;

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

// the slow statements of the query console (docs/query.md, "Slow query log"): those running at least this long, or
// reading at least this many objects; set with the environment variables of the server
$slowQueryLogFile = $logDir . DIRECTORY_SEPARATOR . 'slow-queries.log';
// (0 too: every statement)
$slowQueryMilliseconds = is_numeric($value = getenv('OBJECT_STORAGE_SLOW_QUERY_MS')) ? (float)$value : SlowQueryLog::DEFAULT_MILLISECONDS;
$slowQueryObjectsRead = is_numeric($value = getenv('OBJECT_STORAGE_SLOW_QUERY_OBJECTS')) ? (int)$value : 10000;

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

// named configurations of storages (lock backend, caches, strategy), assigned to storages in the viewer
$configurations = new ConfigurationRegistry($appDataDir . DIRECTORY_SEPARATOR . 'configurations');

$buildStorage = static function (string $storageDir) use ($psr16, $createCacheKeyShardDepth, $configurations): ObjectStorage {
    // with the configuration assigned to the storage, the defaults without one
    $storage = $configurations->open($storageDir);

    $depth = $psr16->get($key = $createCacheKeyShardDepth($storageDir));

    if ($depth === null) {
        $depth = (new Directory($storage->getShardDir()))->getMaxDirDepth();
        $psr16->set($key, $depth, 3600);
    }

    // the depth of the files found wins; an empty storage keeps the configured (or default) depth
    if (null !== $depth && $depth > 0) {
        $storage->getStrategy()->setShardDepth($depth);
    }

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

/* the unique indexes of a storage, attached so every write in the viewer is checked against them */
$uniqueFor = static function (ObjectStorage $storage): UniqueManager {
    static $instances = [];

    $id = spl_object_id($storage);
    if (!isset($instances[$id])) {
        $instances[$id] = new UniqueManager($storage);
        $instances[$id]->attach();
    }

    return $instances[$id];
};

/* the fields of a search index form with the unique indexes on their paths: flag and case handling */
$withUniqueFlags = static function (array $fields, string $className, ObjectStorage $storage) use ($uniqueFor): array {
    $unique = '' === $className ? [] : (new UniquePathIndexes($uniqueFor($storage)))->forClass($className);
    return array_map(static function (array $field) use ($unique): array {
        $index = $unique[$field['path'] ?? ''] ?? null;
        return $field + [
            'unique' => null !== $index,
            'uniqueIgnoreCase' => $index?->caseInsensitive ?? false,
            'uniqueName' => $index?->name,
        ];
    }, $fields);
};

$buildStorage = static function (?string $storageDir) use ($buildStorage, $searchFor, $uniqueFor): ObjectStorage {
    $storage = $buildStorage((string)$storageDir);
    $searchFor($storage);
    $uniqueFor($storage);
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

/* the pattern indexes a field can have, for the form */
$patternIndexes = [
    ['value' => PatternIndex::TRIE->value, 'label' => 'prefix', 'title' => "trie: the beginnings of the values, for LIKE 'Ho%'"],
    ['value' => PatternIndex::REVERSE_TRIE->value, 'label' => 'suffix', 'title' => "reverse trie: the ends of the values, for LIKE '%son'"],
    ['value' => PatternIndex::NGRAM->value, 'label' => 'n-gram', 'title' => "n-grams: the parts of 3 characters, for LIKE '%test%' and CONTAINS"],
];

/* field suggestions from a stored object of the class: path => type */
$suggestFields = static function (ObjectStorage $storage, string $className): array {
    foreach ($storage->list($className) as $uuid) {
        $metadata = $storage->loadMetadata($uuid);
        $data = json_decode((string)@file_get_contents($storage->getFilePathData($uuid)), true);
        if (null === $metadata || !is_array($data)) {
            continue;
        }

        // also the values of referenced objects, e.g. "category.title"
        $follow = static function (string $uuid) use ($storage): ?array {
            try {
                $object = $storage->readRaw($uuid);
                $data = json_decode($object->data, true);
                return is_array($data) ? [$data, $object->metadata->getReservedReferenceName(), $object->metadata->getClassName()] : null;
            } catch (Throwable) {
                return null;
            }
        };

        // a few objects, as a value may be missing in one of them, e.g. a product without a category
        $samples = [[$data, $metadata->getReservedReferenceName()]];
        foreach ($storage->list($className) as $other) {
            if (count($samples) >= 20) {
                break;
            }
            if ($other !== $uuid && is_array($sample = $follow($other))) {
                $samples[] = $sample;
            }
        }

        return array_map(
            static fn(Field $field): array => $field->toArray(),
            DocumentBuilder::suggestFieldsFrom($samples, $follow)
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
            'patterns' => array_values(array_filter((array)($field['patterns'] ?? []), 'is_string')),
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

/* the configuration from the posted form */
$configurationFromRequest = static function (array $input): Configuration {
    $lock = $input['lock'] ?? [];
    $cache = $input['cache'] ?? [];

    return Configuration::fromArray([
        'name' => trim((string)($input['name'] ?? '')),
        'description' => (string)($input['description'] ?? ''),
        'lock' => [
            'type' => (string)($lock['type'] ?? LockSettings::TYPE_FILE),
            'dsn' => trim((string)($lock['dsn'] ?? '')),
            'directory' => trim((string)($lock['directory'] ?? '')),
            'ttl' => trim((string)($lock['ttl'] ?? '')),
            'prefix' => (string)($lock['prefix'] ?? LockSettings::PREFIX_DEFAULT),
            'username' => trim((string)($lock['username'] ?? '')),
            'password' => (string)($lock['password'] ?? ''),
        ],
        'cache' => [
            'type' => (string)($cache['type'] ?? CacheSettings::TYPE_MEMORY),
            'dsn' => trim((string)($cache['dsn'] ?? '')),
            'directory' => trim((string)($cache['directory'] ?? '')),
            'defaultLifetime' => (int)($cache['defaultLifetime'] ?? 0),
            'namespace' => trim((string)($cache['namespace'] ?? '')),
        ],
        'shardDepth' => trim((string)($input['shardDepth'] ?? '')),
        'maxDepth' => trim((string)($input['maxDepth'] ?? '')),
        'lifetimeInheritance' => isset($input['lifetimeInheritance']),
        'checksumValidation' => isset($input['checksumValidation']),
        'optimisticLocking' => isset($input['optimisticLocking']),
        'childPersistence' => trim((string)($input['childPersistence'] ?? '')),
        'childDeletion' => trim((string)($input['childDeletion'] ?? '')),
        'fsync' => isset($input['fsync']),
    ]);
};

/* the choices of the configuration form */
$configurationChoices = [
    'lockTypes' => [
        LockSettings::TYPE_FILE => 'Files (one host)',
        LockSettings::TYPE_SEMAPHORE => 'Semaphores (one host, Linux)',
        LockSettings::TYPE_REDIS => 'Redis',
        LockSettings::TYPE_MEMCACHED => 'Memcached',
        LockSettings::TYPE_PDO => 'Database (PDO)',
        LockSettings::TYPE_DSN => 'Other symfony/lock connection',
    ],
    'cacheTypes' => [
        CacheSettings::TYPE_MEMORY => 'In memory of the process',
        CacheSettings::TYPE_NONE => 'No cache',
        CacheSettings::TYPE_APCU => 'APCu (one server)',
        CacheSettings::TYPE_FILESYSTEM => 'Files (one host)',
        CacheSettings::TYPE_REDIS => 'Redis',
        CacheSettings::TYPE_MEMCACHED => 'Memcached',
    ],
    'childPersistence' => [
        ChildPersistence::IF_NOT_EXIST => 'IF_NOT_EXIST',
        ChildPersistence::ALWAYS => 'ALWAYS',
        ChildPersistence::NEVER => 'NEVER',
    ],
    'childDeletion' => [
        ChildDeletion::NEVER => 'NEVER',
        ChildDeletion::ALWAYS => 'ALWAYS',
    ],
];

/* a configuration for lists: name, description and short summaries */
$describeConfiguration = static function (Configuration $configuration): array {
    $strategy = array_filter([
        null === $configuration->shardDepth ? null : 'shard depth ' . $configuration->shardDepth,
        null === $configuration->maxDepth ? null : 'max depth ' . $configuration->maxDepth,
        $configuration->lifetimeInheritance ? 'lifetime inheritance' : null,
        $configuration->checksumValidation ? null : 'no checksum validation',
        $configuration->optimisticLocking ? 'optimistic locking' : null,
        null === $configuration->childPersistence ? null : 'children stored ' . ([ChildPersistence::ALWAYS => 'ALWAYS', ChildPersistence::NEVER => 'NEVER', ChildPersistence::IF_NOT_EXIST => 'IF_NOT_EXIST'][$configuration->childPersistence] ?? '?'),
        null === $configuration->childDeletion ? null : 'children deleted ' . ([ChildDeletion::ALWAYS => 'ALWAYS', ChildDeletion::NEVER => 'NEVER'][$configuration->childDeletion] ?? '?'),
        $configuration->fsync ? null : 'no fsync',
    ]);

    return [
        'name' => $configuration->name,
        'description' => $configuration->description,
        'lock' => $configuration->lock->describe(),
        'lockType' => $configuration->lock->type,
        'cache' => $configuration->cache->describe(),
        'cacheType' => $configuration->cache->type,
        'strategy' => [] === $strategy ? 'defaults' : implode(', ', $strategy),
    ];
};

try {
    switch ($action) {
        case 'index':
            echo $twig->render('index.html');
            break;
        case 'transfer':
            // copy or move the chosen objects into another storage; the page runs the transfer in batches via
            // transfer-step, showing the progress, and retries failed or skipped objects
            $storageDir = (string)($_GET['storage'] ?? '');
            $uuids = array_values(array_unique(array_filter(array_map('strval', (array)($_GET['uuids'] ?? [])), static fn(string $uuid): bool => '' !== $uuid)));

            $configurationNames = [];
            try {
                $configurationNames = array_keys($configurations->list());
            } catch (Throwable $e) {
                $logger->error($e);
            }

            echo $twig->render('transfer.html', [
                'storage' => $storageDir,
                'uuids' => $uuids,
                'mode' => 'move' === ($_GET['mode'] ?? '') ? 'move' : 'copy',
                'configurationNames' => $configurationNames,
            ]);
            break;
        case 'transfer-step':
            // one batch of a transfer: the posted UUIDs
            try {
                $requirePost();
                $storageDir = (string)($_POST['storage'] ?? '');
                $targetDir = trim((string)($_POST['target'] ?? ''));
                $move = 'move' === ($_POST['mode'] ?? '');
                $batch = array_slice(array_values(array_filter(array_map('strval', (array)($_POST['uuids'] ?? [])))), 0, 500);

                if ('' === $targetDir) {
                    throw new RuntimeException('Choose the target storage.');
                }
                if (!is_dir($targetDir)) {
                    if (!isset($_POST['create'])) {
                        throw new RuntimeException(sprintf('The target storage %s doesn\'t exist; choose to create it.', $targetDir));
                    }
                    $targetConfiguration = trim((string)($_POST['configuration'] ?? ''));
                    if ('' !== $targetConfiguration) {
                        $configurations->assign($targetDir, $targetConfiguration);
                    }
                }

                $source = $buildStorage($storageDir);
                $target = $buildStorage($targetDir); // with its configuration and its search indexes attached
                $transfer = new ObjectTransfer($source, $target);

                $result = $move
                    ? $transfer->move($batch, isset($_POST['withReferences']), (string)($_POST['onConflict'] ?? ObjectTransfer::CONFLICT_FAIL))
                    : $transfer->copy($batch, isset($_POST['withReferences']), (string)($_POST['onConflict'] ?? ObjectTransfer::CONFLICT_FAIL));

                $jsonResponse(['ok' => true, 'result' => $result->toArray()]);
            } catch (Throwable $e) {
                $logger->error($e);
                $jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
            }
            break;
        case 'unique-indexes':
            // the unique indexes of a storage: values no two objects of a class may share
            $storageDir = (string)($_GET['storage'] ?? '');
            $storage = $buildStorage($storageDir);
            $unique = $uniqueFor($storage);
            $classnames = $storage->getClassNames();
            sort($classnames, SORT_NATURAL | SORT_FLAG_CASE);

            echo $twig->render('unique-indexes.html', [
                'storage' => $storageDir,
                'indexes' => array_map(static fn(UniqueDefinition $definition): array => $definition->toArray(), array_values($unique->list())),
                'classnames' => $classnames,
                'message' => $_GET['message'] ?? null,
                'error' => $_GET['error'] ?? null,
                'form' => [
                    'name' => (string)($_GET['name'] ?? ''),
                    'classname' => (string)($_GET['classname'] ?? ''),
                    'paths' => (string)($_GET['paths'] ?? ''),
                    'caseInsensitive' => isset($_GET['caseInsensitive']),
                ],
            ]);
            break;
        case 'unique-create':
        case 'unique-rebuild':
        case 'unique-drop':
            // form posts of the page of unique indexes; the page shows the outcome
            $requirePost();
            $storageDir = (string)($_POST['storage'] ?? '');
            $name = trim((string)($_POST['name'] ?? ''));
            $back = '?action=unique-indexes&storage=' . rawurlencode($storageDir);
            try {
                $unique = $uniqueFor($buildStorage($storageDir));
                $message = match ($action) {
                    'unique-create' => (static function () use ($unique, $name): string {
                        $paths = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['paths'] ?? '')))));
                        $unique->create(new UniqueDefinition($name, trim((string)($_POST['classname'] ?? '')), $paths, isset($_POST['caseInsensitive'])));
                        return sprintf('Unique index %s created.', $name);
                    })(),
                    'unique-rebuild' => sprintf('Unique index %s rebuilt: %d values.', $name, $unique->rebuild($name)),
                    'unique-drop' => $unique->drop($name) ? sprintf('Unique index %s dropped.', $name) : sprintf('There is no unique index %s.', $name),
                };
                header('Location: ' . $back . '&message=' . rawurlencode($message));
            } catch (Throwable $e) {
                $logger->error($e);
                // the form as it was, so nothing typed is lost
                $form = 'unique-create' === $action ? '&' . http_build_query(array_filter([
                    'name' => $name,
                    'classname' => $_POST['classname'] ?? null,
                    'paths' => $_POST['paths'] ?? null,
                    'caseInsensitive' => isset($_POST['caseInsensitive']) ? '1' : null,
                ])) : '';
                header('Location: ' . $back . '&error=' . rawurlencode($e->getMessage()) . $form);
            }
            break;
        case 'storage-create':
            // creates the directory of a storage, assigned the chosen configuration
            $requirePost();
            $storageDir = trim((string)($_POST['storage'] ?? ''));
            $configurationName = trim((string)($_POST['configuration'] ?? ''));
            if ('' === $storageDir) {
                throw new RuntimeException('No storage directory given.');
            }
            if (is_dir($storageDir)) {
                throw new RuntimeException(sprintf('The storage %s exists already.', $storageDir));
            }

            // the configuration first: the storage is created with its settings, e.g. the shard depth
            if ('' !== $configurationName) {
                $configurations->assign($storageDir, $configurationName);
            }
            $configurations->open($storageDir);

            header('Location: ?action=list-classnames&storage=' . rawurlencode($storageDir));
            break;
        case 'configurations':
            // the named configurations; with a storage, also which one it is assigned
            $storageDir = $_GET['storage'] ?? null;
            $error = null;
            $list = [];
            try {
                foreach ($configurations->list() as $configuration) {
                    $list[] = $describeConfiguration($configuration);
                }
            } catch (Throwable $e) {
                $logger->error($e);
                $error = $e->getMessage();
            }

            $assigned = null;
            if (null !== $storageDir && '' !== $storageDir) {
                try {
                    $assigned = ConfigurationRegistry::getAssignedName($storageDir);
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }

            echo $twig->render('configurations.html', [
                'storage' => $storageDir,
                'exists' => false,
                'configurations' => $list,
                'assigned' => $assigned,
                'directory' => $configurations->getDirectory(),
                'error' => $error,
                'message' => $_GET['message'] ?? null,
            ]);
            break;
        case 'configuration-edit':
        case 'configuration-save':
            // create (no name) or edit a configuration; saving validates it and returns to the list
            $isSave = 'configuration-save' === $action;
            $input = $isSave ? $_POST : $_GET;
            $storageDir = $input['storage'] ?? null;
            $name = $isSave ? null : ($_GET['name'] ?? null);
            $isNew = $isSave ? '1' === ($_POST['isNew'] ?? '1') : null === $name;
            $error = null;
            $values = null;

            if ($isSave) {
                $requirePost();
                try {
                    $configuration = $configurationFromRequest($_POST);
                    $isNew ? $configurations->create($configuration) : $configurations->update($configuration);

                    $message = sprintf('Configuration %s saved.', $configuration->name);
                    if (isset($_POST['assign']) && null !== $storageDir && '' !== $storageDir) {
                        $configurations->assign($storageDir, $configuration->name);
                        $message .= sprintf(' Assigned to %s.', $storageDir);
                    }
                    header('Location: ?action=configurations&message=' . rawurlencode($message) . (null === $storageDir || '' === $storageDir ? '' : '&storage=' . rawurlencode($storageDir)));
                    break;
                } catch (Throwable $e) {
                    $logger->error($e);
                    $error = $e->getMessage();
                    // the form as posted, so nothing typed is lost
                    $values = $_POST + ['lock' => [], 'cache' => []];
                    $values['lifetimeInheritance'] = isset($_POST['lifetimeInheritance']);
                    $values['checksumValidation'] = isset($_POST['checksumValidation']);
                    $values['optimisticLocking'] = isset($_POST['optimisticLocking']);
                    $values['fsync'] = isset($_POST['fsync']);
                }
            } elseif (null !== $name) {
                $values = $configurations->get($name)->toArray();
            }

            $values ??= (new Configuration('new'))->toArray();
            if ($isNew && !$isSave) {
                $values['name'] = (string)($_GET['newName'] ?? '');
            }

            echo $twig->render('configuration-edit.html', [
                'storage' => $storageDir,
                'exists' => false,
                'isNew' => $isNew,
                'values' => $values,
                'choices' => $configurationChoices,
                'error' => $error,
            ]);
            break;
        case 'configuration-delete':
        case 'configuration-check':
        case 'configuration-assign':
            // JSON actions, called by the pages
            try {
                $requirePost();
                $name = (string)($_POST['name'] ?? '');
                $storageDir = (string)($_POST['storage'] ?? '');

                $result = match ($action) {
                    'configuration-delete' => ['deleted' => $configurations->delete($name)],
                    // takes a lock and uses the cache, in the directory of the chosen storage or a temporary one
                    'configuration-check' => (new ConfigurationFactory())->check(
                        $configurations->get($name),
                        '' !== $storageDir && is_dir($storageDir) ? $storageDir : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'object-storage-viewer-check'
                    ),
                    'configuration-assign' => (static function () use ($configurations, $storageDir, $name, $psr16, $createCacheKeyShardDepth): array {
                        if ('' === $storageDir || !is_dir($storageDir)) {
                            throw new RuntimeException('Storage not found.');
                        }
                        $configurations->assign($storageDir, '' === $name ? null : $name);
                        // the shard depth may come from the configuration now: detect it again
                        $psr16->delete($createCacheKeyShardDepth($storageDir));
                        return ['assigned' => '' === $name ? null : $name];
                    })(),
                };

                $jsonResponse(['ok' => true, 'result' => $result]);
            } catch (Throwable $e) {
                $logger->error($e);
                $jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
            }
            break;
        case 'query':
            // OQL: SELECT … FROM Class … on the objects of the storage (docs/query.md)
            $storageDir = $_REQUEST['storage'] ?? null;
            $query = (string)($_POST['q'] ?? $_GET['q'] ?? '');
            $dryRun = isset($_POST['dryRun']);
            // Preview: the script runs and everything is rolled back at the end
            $preview = isset($_POST['preview']);
            // the maximum runtime in seconds, empty: unlimited
            $timeout = trim((string)($_POST['timeout'] ?? $_GET['timeout'] ?? ''));
            $timeout = is_numeric($timeout) && (float)$timeout > 0 ? (float)$timeout : null;
            // cancelled in the page while it ran: the query is shown again, not run
            $cancelled = isset($_GET['cancelled']);
            $storage = $buildStorage($storageDir);
            // run by the page (query.html) after the form is sent to the browser, so it can be cancelled meanwhile
            $slowQueryLog = new SlowQueryLog($slowQueryLogFile, $slowQueryMilliseconds, $slowQueryObjectsRead, context: ['storage' => (string)$storageDir]);
            $run = static function () use ($query, $dryRun, $preview, $timeout, $storage, $searchFor, $uniqueFor, $logger, $slowQueryLog): array {
                $results = null;
                $rolledBack = false;
                $error = null;
                $errorPosition = null;
                $started = microtime(true);
                // no limit of PHP: a query runs as long as it needs, or until its maximum runtime, or until the page
                // is cancelled (the browser closes the connection); then it stops cleanly, what it wrote is rolled back
                set_time_limit(0);
                ignore_user_abort(true);
                // what the page has so far goes to the browser now
                while (ob_get_level() > 0 && ob_end_flush()) {
                }
                flush();
                $timeLimit = null === $timeout ? null : Oql::timeLimit($timeout);
                $beat = $started;
                $interrupt = static function () use ($timeLimit, &$beat): void {
                    if (null !== $timeLimit) {
                        $timeLimit();
                    }
                    // a closed connection shows only when writing to it: a space, twice a second, in the page
                    $now = microtime(true);
                    if ($now - $beat >= 0.5) {
                        $beat = $now;
                        echo ' ';
                        flush();
                        if (connection_aborted()) {
                            throw new QueryInterruptedException('The query was cancelled');
                        }
                    }
                };
                try {
                    // writes and transactions only from the form (POST): a link or the history never writes
                    if (Oql::writes($query) && 'POST' !== ($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
                        throw new QueryException('INSERT, UPDATE, DELETE, ALTER and transactions run from the form only: check the statements and press Run.');
                    }
                    // one request is one connection: a transaction can't stay open across requests, a script left
                    // with an open one is rolled back; indexes find the objects where they can; at most 1,000 rows
                    // per statement are kept to show
                    $script = (new Oql($storage, $searchFor($storage), $uniqueFor($storage), interrupt: $interrupt, observer: $slowQueryLog))->script($query, [], $dryRun, 1000, $preview);
                    $results = $script->results;
                    $rolledBack = $script->rolledBack;
                } catch (ScriptException $e) {
                    $error = $e->getMessage() . ($e->rolledBack ? ' The transaction was rolled back.' : '');
                    // marked in the query (a byte offset in the whole script)
                    $errorPosition = $e->position;
                    // what ran before the failing statement
                    $results = $e->results;
                } catch (QueryException $e) {
                    $error = $e->getMessage();
                    $errorPosition = $e->position;
                } catch (Throwable $e) {
                    $logger->error($e);
                    $error = 'The query failed: ' . $e->getMessage();
                }
                return [$results, $rolledBack, $error, $errorPosition, (microtime(true) - $started) * 1000];
            };
            // lists and objects as JSON, the UUIDs in it (references) linked to their records; escaped first, so only
            // the links are HTML
            $jsonHtml = static function (string $json) use ($storageDir): string {
                $html = htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return (string)preg_replace_callback(
                    '/&quot;([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})&quot;/',
                    static fn(array $match): string => UuidValidator::validate($match[1])
                        ? sprintf('&quot;<a href="?action=view-record&amp;storage=%s&amp;uuid=%s" class="uuid">%s</a>&quot;', rawurlencode((string)$storageDir), $match[1], $match[1])
                        : $match[0],
                    $html
                );
            };
            // values for the table: references stay UUIDs, dates are their point in time in UTC, lists and objects JSON
            $cell = static fn(mixed $value): array => match (true) {
                null === $value => ['NULL', 'null'],
                is_bool($value) => [$value ? 'true' : 'false', 'boolean'],
                is_array($value) => [$json = (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'json', $jsonHtml($json)],
                is_float($value) => [(string)json_encode($value, JSON_PRESERVE_ZERO_FRACTION), 'number'],
                is_int($value) => [(string)$value, 'number'],
                default => [(string)$value, is_string($value) && UuidValidator::validate($value) ? 'uuid' : 'string'],
            };
            $classnames = [];
            try {
                $classnames = $storage->getClassNames();
                sort($classnames, SORT_NATURAL | SORT_FLAG_CASE);
            } catch (Throwable $e) {
                $logger->error($e);
            }

            // what the page shows of a run
            $outcome = static function () use ($run, $cell): array {
                [$results, $rolledBack, $error, $errorPosition, $milliseconds] = $run();
                return [
                    'error' => $error,
                    'errorPosition' => $errorPosition,
                    // whether the query ran (for the history), also if a later statement of a script failed
                    'ran' => null !== $results,
                    'results' => array_map(static fn(Result $result): array => [
                        'columns' => $result->columns,
                        // the rows; of a dry run of INSERT, the objects have no UUIDs yet
                        'rows' => array_map(
                            static fn(array $row): array => array_map($cell, $row),
                            null === $result->statement ? $result->rows : array_values(array_filter($result->rows, static fn(array $row): bool => null !== $row[0]))
                        ),
                        'statement' => $result->statement,
                        'truncated' => $result->truncated,
                        'affected' => $result->affected(),
                        'dryRun' => $result->dryRun,
                        // what it cost: shown with the rows, all objects of a class read marked
                        'objectsRead' => $result->statistics?->objectsRead,
                        'fullScans' => $result->statistics?->fullScans() ?? [],
                    ], $results ?? []),
                    'rolledBack' => $rolledBack,
                    'milliseconds' => $milliseconds,
                ];
            };

            // displayed, not rendered: the page up to the results reaches the browser while the query runs
            $twig->display('query.html', [
                'storage' => $storageDir,
                'query' => $query,
                'classnames' => $classnames,
                'preview' => $preview,
                'dryRun' => $dryRun,
                'timeout' => $timeout,
                'cancelled' => $cancelled,
                // the template runs it (runner.run()) where the results go
                'runner' => '' === trim($query) || $cancelled ? null : new class($outcome) {
                    public function __construct(private readonly \Closure $outcome)
                    {
                    }

                    public function run(): array
                    {
                        return ($this->outcome)();
                    }
                },
            ]);
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

            // the configuration of the storage, to show and change it
            $configurationNames = [];
            try {
                $configurationNames = array_keys($configurations->list());
            } catch (Throwable $e) {
                $logger->error($e);
            }

            echo $twig->render('list-classnames.html', [
                'storage' => $storageDir,
                'configuration' => $exists ? ConfigurationRegistry::getAssignedName($storageDir) : null,
                'configurationNames' => $configurationNames,
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
            // e.g. after deleting the objects of the last page
            $page = max(1, min($page, $pages));
            $offset = ($page - 1) * $pageSize;

            $uuids = [];
            foreach (new LimitIterator(new IteratorIterator($it), $offset, $pageSize) as $uuid) {
                $uuids[] = $uuid;
            }

            echo $twig->render('list-uuids.html', [
                'message' => $_GET['message'] ?? null,
                'errors' => array_map('strval', (array)($_GET['errors'] ?? [])),
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
        case 'delete-records':
            // deletes the objects selected in the list, then shows the list again with the result
            $storageDir = (string)($_POST['storage'] ?? '');
            $className = (string)($_POST['classname'] ?? '');
            $uuids = array_values(array_unique(array_filter(array_map('strval', (array)($_POST['uuids'] ?? [])), static fn(string $uuid): bool => '' !== $uuid)));
            $deleted = 0;
            $errors = [];

            try {
                $storage = $buildStorage($storageDir);
                foreach ($uuids as $uuid) {
                    try {
                        $storage->delete($uuid); // the attached search and unique indexes follow the delete
                        $deleted++;
                    } catch (Throwable $e) {
                        $logger->error($e);
                        $errors[$uuid] = $e->getMessage();
                    }
                }
            } catch (Throwable $e) {
                $logger->error($e);
                $errors[''] = $e->getMessage();
            }

            // a few errors, shortened: they travel in the URL of the redirect
            $shown = [];
            foreach (array_slice($errors, 0, 5, true) as $uuid => $error) {
                $shown[$uuid] = mb_strimwidth($error, 0, 200, '…');
            }
            if (count($errors) > 5) {
                $shown['…'] = sprintf('and %d more, see the log', count($errors) - 5);
            }

            header('Location: ?' . http_build_query([
                    'action' => 'list-uuids',
                    'storage' => $storageDir,
                    'classname' => $className,
                    'page' => max(1, (int)($_POST['page'] ?? 1)),
                    'message' => sprintf('%d object(s) deleted.', $deleted),
                    'errors' => $shown,
                ]));
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
        case 'link-attribute':
            // linking afterwards: an attribute of a record becomes a reference, to a chosen object or to the objects
            // whose UUIDs it holds as text
            $storageDir = $_POST['storage'] ?? null;
            $uuid = (string)($_POST['uuid'] ?? '');
            $attribute = trim((string)($_POST['attribute'] ?? ''));
            $target = trim((string)($_POST['target'] ?? ''));
            $result = [];

            try {
                $requirePost();
                $storage = $buildStorage($storageDir);
                if ('' === $attribute) {
                    throw new RuntimeException('Name the attribute.');
                }
                $object = $storage->load($uuid) ?? throw new RuntimeException('Record not found.');
                // the attached search and unique indexes follow the change; the attribute is a name or a path into
                // nested values (address.owner, contacts['alex'], friends[0])
                $migration = new AttributeToReference($attribute);
                $migration->setStorage($storage);

                if ('' !== $target) {
                    if (!UuidValidator::validate($target)) {
                        throw new RuntimeException(sprintf('"%s" is not a UUID.', $target));
                    }
                    if ($target === $uuid) {
                        throw new RuntimeException('A record can\'t refer to itself.');
                    }
                    if (!$storage->exists($target)) {
                        throw new RuntimeException(sprintf('There is no object %s.', $target));
                    }
                    $migration->linkTo($object, $target, $uuid);
                    $result['message'] = sprintf('"%s" refers to %s now.', $attribute, $target);
                } else {
                    $linked = $migration->apply($object, $uuid);
                    $missing = $migration->getMissing();
                    if ($linked) {
                        $result['message'] = sprintf('The UUIDs in "%s" are references now.', $attribute)
                            . ([] === $missing ? '' : sprintf(' No object found for: %s.', implode(', ', $missing)));
                    } else {
                        $result['error'] = [] === $missing
                            ? sprintf('"%s" holds no UUID as text.', $attribute)
                            : sprintf('Nothing linked, no object found for: %s.', implode(', ', $missing));
                    }
                }
            } catch (Throwable $e) {
                $logger->error($e);
                $result['error'] = 'The objects couldn\'t be linked: ' . $e->getMessage();
            }

            header('Location: ?' . http_build_query(['action' => 'view-record', 'storage' => $storageDir, 'uuid' => $uuid] + $result));
            break;
        case 'link-class':
            // linking afterwards for all objects of a class, step by step via link-class-step
            $storageDir = $_GET['storage'] ?? null;
            $classname = (string)($_GET['classname'] ?? '');
            $storage = $buildStorage($storageDir);

            // suggestions: attributes holding UUIDs as text in the first objects, with how many of them do
            $suggested = [];
            $sampled = 0;
            foreach (new LimitIterator(new IteratorIterator($storage->list($classname)), 0, 100) as $sampleUuid) {
                $sampled++;
                try {
                    $sample = json_decode((string)file_get_contents($storage->getFilePathData($sampleUuid)), true);
                } catch (Throwable) {
                    continue;
                }
                foreach (is_array($sample) ? $sample : [] as $attribute => $value) {
                    foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $element) {
                        if (is_string($element) && UuidValidator::validate($element)) {
                            $name = Path::toString([(string)$attribute]);
                            $suggested[$name] = ($suggested[$name] ?? 0) + 1;
                            break;
                        }
                    }
                }
            }
            arsort($suggested);

            echo $twig->render('link-class.html', [
                'storage' => $storageDir,
                'classname' => $classname,
                'suggested' => $suggested,
                'sampled' => $sampled,
                'attribute' => (string)($_GET['attribute'] ?? array_key_first($suggested) ?? ''),
            ]);
            break;
        case 'link-class-step':
            try {
                $requirePost();
                $storage = $buildStorage($_POST['storage'] ?? null);
                $classname = (string)($_POST['classname'] ?? '');
                $attribute = trim((string)($_POST['attribute'] ?? ''));
                if ('' === $attribute) {
                    throw new RuntimeException('Name the attribute.');
                }
                $offset = max(0, (int)($_POST['offset'] ?? 0));
                $batchSize = max(1, min(2000, (int)($_POST['batchSize'] ?? 100)));
                // the counts so far, sent back by the page
                $linked = max(0, (int)($_POST['linked'] ?? 0));
                $missing = max(0, (int)($_POST['missing'] ?? 0));
                $failed = max(0, (int)($_POST['failed'] ?? 0));

                $migration = new AttributeToReference($attribute);
                $migration->setStorage($storage);
                $total = iterator_count(new IteratorIterator($storage->list($classname)));

                // linking doesn't change the class, so the objects keep their positions in the list
                $processed = 0;
                $errors = [];
                foreach (new LimitIterator(new IteratorIterator($storage->list($classname)), $offset, $batchSize) as $objectUuid) {
                    $processed++;
                    try {
                        $object = $storage->load($objectUuid);
                        if (null !== $object && $migration->apply($object, $objectUuid)) {
                            $linked++;
                        }
                        $missing += count($migration->getMissing());
                    } catch (Throwable $e) {
                        $failed++;
                        $logger->error($e);
                        if (count($errors) < 5) {
                            $errors[$objectUuid] = mb_strimwidth($e->getMessage(), 0, 200, '…');
                        }
                    }
                }
                $storage->clearCache();

                $position = $offset + $processed;
                $done = 0 === $processed || $position >= $total;
                $jsonResponse(['ok' => true, 'result' => [
                    'offset' => $position,
                    'processed' => min($position, $total),
                    'total' => $total,
                    'percent' => $total > 0 ? (int)floor(100 * min($position, $total) / $total) : 100,
                    'done' => $done,
                    'linked' => $linked,
                    'missing' => $missing,
                    'failed' => $failed,
                    'errors' => $errors,
                ]]);
            } catch (Throwable $e) {
                $logger->error($e);
                $jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
            }
            break;
        case 'duplicate-record':
            $uuid = $_GET['uuid'] ?? null;
            $storageDir = $_GET['storage'] ?? null;
            try {
                $storage = $buildStorage($storageDir);
                $exists = $storage->exists($uuid);
                $storage->getLockAdapter()->acquireExclusiveLock($uuid);

                $uuidNew = $storage->getNextAvailableUuid();

                // read as stored, verified by its checksum: a damaged record isn't copied
                $raw = $storage->readRaw($uuid);
                $data = $raw->data;

                // the copied metadata must name the duplicate, otherwise later metadata updates of the
                // duplicate (e.g. its lifetime) would be written to the original; the checksum of the data written
                $metadata = clone $raw->metadata;
                $metadata->setUuid($uuidNew);
                $metadata->setChecksum(hash($metadata->getChecksumAlgorithm(), $data));

                // written without store(): the constraints (e.g. unique values) are checked explicitly; a
                // duplicate of an object with unique values is refused
                $finalizeConstraints = $storage->checkConstraints($uuidNew, $metadata->getClassName(), $data, $metadata->getReservedReferenceName());
                $written = false;
                try {
                    // written as a group (the writer creates the shard directory), so a crash leaves no half copy
                    $storage->getWriter()->atomicWriteAll([
                        $storage->getFilePathData($uuidNew) => $data,
                        $storage->getFilePathMetadata($uuidNew) => json_encode($metadata, JSON_THROW_ON_ERROR),
                    ]);
                    $written = true;
                } finally {
                    $finalizeConstraints($written);
                }
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
                $duplicateError = $e->getMessage();
                if (isset($storage) && $storage->getLockAdapter()->isLockedByThisProcess((string)$uuid)) {
                    $storage->getLockAdapter()->releaseLock((string)$uuid);
                }
            }

            echo $twig->render('duplicate-record.html', [
                'storage' => $storageDir,
                'exists' => $exists ?? false,
                'success' => $success,
                'uuidNew' => $uuidNew ?? '',
                'error' => $duplicateError ?? null,
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

            // the metadata as stored, pretty-printed
            $metadataPath = $exists ? $storage->getFilePathMetadata($uuid) : null;
            $metadataJson = null;
            if (null !== $metadataPath && is_file($metadataPath)) {
                $rawMetadata = (string)file_get_contents($metadataPath);
                $decodedMetadata = json_decode($rawMetadata, true);
                $metadataJson = null === $decodedMetadata ? $rawMetadata : json_encode($decodedMetadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            // attributes holding UUIDs as text: they can become references (linking afterwards)
            $linkCandidates = [];
            foreach (json_decode($json ?? '{}', true) ?: [] as $attribute => $value) {
                $uuids = array_values(array_filter(is_array($value) && array_is_list($value) ? $value : [$value],
                    static fn(mixed $v): bool => is_string($v) && UuidValidator::validate($v)));
                if ([] !== $uuids) {
                    $linkCandidates[] = [
                        'attribute' => Path::toString([(string)$attribute]),
                        'uuids' => array_map(static fn(string $v): array => ['uuid' => $v, 'exists' => $storage->exists($v)], $uuids),
                    ];
                }
            }

            // the time zones of the dates that browsers don't know (abbreviations like CEST): their fixed offsets
            $reservedDateTime = $metadata?->getReservedDateTimeName() ?? Metadata::RESERVED_DATETIME_NAME_DEFAULT;
            $fixedOffsets = [];
            $collectZones = static function (mixed $value) use (&$collectZones, &$fixedOffsets, $reservedDateTime): void {
                if (DateTimeValue::isEncoded($value, $reservedDateTime)) {
                    try {
                        $zone = new DateTimeZone($value[DateTimeValue::KEY_TIMEZONE]);
                        if (2 === ($zone->__serialize()['timezone_type'] ?? null)) {
                            $fixedOffsets[$value[DateTimeValue::KEY_TIMEZONE]] = $zone->getOffset(new DateTimeImmutable('@0'));
                        }
                    } catch (Throwable) {
                        // unknown: the widget shows it can't be edited
                    }
                } elseif (is_array($value)) {
                    array_walk($value, $collectZones);
                }
            };
            $collectZones(json_decode($json ?? '{}', true) ?: []);

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
                    // also those with documents built from objects of the class (paths into referenced objects)
                    $joining = $search->getIndexesJoiningClass($classname);
                    foreach ($search->getIndexesForClass($classname) + $joining as $name => $index) {
                        $recordIndexes[] = [
                            'name' => $name,
                            'engine' => $index->getEngineName(),
                            'status' => $index->getStatus(),
                            'pending' => $index->hasPendingChange($uuid),
                            'joined' => isset($joining[$name]),
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
                'metadataJson' => $metadataJson,
                'metadataPath' => $metadataPath,
                'linkCandidates' => $linkCandidates,
                'reservedDateTime' => $reservedDateTime,
                'fixedOffsets' => (object)$fixedOffsets,
                // metadata without a checksum (MissingChecksumException) is shown all the same
                'checksum' => (static function () use ($metadata): string {
                    try {
                        return $metadata?->getChecksum() ?? '';
                    } catch (Throwable) {
                        return '';
                    }
                })(),
                'algorithm' => $metadata?->getChecksumAlgorithm() ?? '',
                'isLocked' => $isLocked,
                'lifetime' => $storage->getLifetime($uuid) ?? 'unlimited',
                // seconds since the epoch, shown in the time zone of the browser; null without expiration
                'expiresAt' => $metadata?->getTimestampExpiresAt(),
                'createdAt' => null === $metadata ? '' : $storage->getCreatedAt($uuid)?->format('Y-m-d\\TH:i:s.v\\Z'),
                'modifiedAt' => null === $metadata ? '' : $storage->getModifiedAt($uuid)?->format('Y-m-d\\TH:i:s.v\\Z'),
                'revision' => $metadata?->getRevision() ?? 0,
                'shard' => $storage->buildShardedDirectory($uuid),
                'message' => $_GET['message'] ?? null,
                'error' => $_GET['error'] ?? null,
            ]);
            break;
        case 'touch-record':
            $storageDir = $_POST['storage'] ?? null;
            $uuid = (string)($_POST['uuid'] ?? '');
            $result = [];
            try {
                $requirePost();
                $storage = $buildStorage($storageDir);
                if (!$storage->exists($uuid)) {
                    throw new RuntimeException('Record not found.');
                }
                $storage->touch($uuid);
                $result['message'] = 'The record has been touched: modified now, its data unchanged.';
            } catch (Throwable $e) {
                $logger->error($e);
                $result['error'] = 'The record couldn\'t be touched: ' . $e->getMessage();
            }
            header('Location: ?' . http_build_query(['action' => 'view-record', 'storage' => $storageDir, 'uuid' => $uuid] + $result));
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
                        $storage->getLockAdapter()->acquireExclusiveLock($uuid);
                        $lockAcquired = true;

                        // as on disk now, under the lock (also metadata the object can't be read with, e.g. without
                        // a checksum: saving repairs it)
                        $storage->clearCache();
                        $metadata = $storage->loadMetadata($uuid);
                        if (null === $metadata) {
                            throw new RuntimeException('No metadata found for UUID: ' . $uuid);
                        }

                        // changed by someone else since the page was loaded: not overwritten
                        $shownRevision = isset($_POST['revision']) && is_numeric($_POST['revision']) ? (int)$_POST['revision'] : null;
                        if (null !== $shownRevision && $shownRevision !== $metadata->getRevision()) {
                            throw new RuntimeException(sprintf('The object was changed in the meantime (revision %d, the page showed %d): reload it and apply the changes again.', $metadata->getRevision(), $shownRevision));
                        }

                        // written as storing does: both files as a group (crash-safe), the constraints (e.g. unique
                        // values) checked, a new revision, modified now; the search indexes are detached meanwhile,
                        // so only those chosen in the form are updated (below)
                        $metadata = clone $metadata;
                        $metadata->setChecksum(hash($metadata->getChecksumAlgorithm(), $json));
                        $metadata->setRevision($metadata->getRevision() + 1);
                        $metadata->setTimestampModification(microtime(true));
                        $search = $searchFor($storage);
                        $search?->detach();
                        try {
                            $storage->writeRaw(new RawObject($uuid, $json, $metadata), overwrite: true);
                        } finally {
                            $search?->attach();
                        }

                        $success = true;

                        // the chosen search indexes are notified explicitly; without a choice in the form (e.g. no
                        // indexes when the page was loaded), all of the class
                        try {
                            $storage->getLockAdapter()->releaseLock($uuid);
                            $lockAcquired = false;
                            if (null !== $search) {
                                $chosen = isset($_POST['indexSelection']) ? array_map('strval', (array)($_POST['indexes'] ?? [])) : null;
                                $updatedIndexes = $search->notifyChanged($uuid, $metadata->getClassName(), $chosen);
                                $skippedIndexes = array_values(array_diff(array_keys($search->getIndexesForClass($metadata->getClassName()) + $search->getIndexesJoiningClass($metadata->getClassName())), $updatedIndexes));
                            }
                        } catch (Throwable $e) {
                            $logger->error($e);
                            $indexError = $e->getMessage();
                        }
                    } catch (Throwable $e) {
                        $success = false;
                        $logger->error($e);
                        $saveError = $e->getMessage();
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
                'error' => $saveError ?? null,
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

                if (false === file_put_contents($destination, (new DotExporter())->export($referenceGraph))) {
                    throw new RuntimeException('Unable to write ' . $destination);
                }
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
        case 'slow-queries':
            // the slow query log of the console (docs/query.md, "Statistics and the slow query log"): by pattern (the
            // query without its values) or the latest statements; of one storage, or of all (storage empty)
            $storageDir = (string)($_GET['storage'] ?? '');
            $view = 'latest' === ($_GET['view'] ?? '') ? 'latest' : 'patterns';
            $entries = SlowQueryLog::read($slowQueryLogFile);
            if ('' !== $storageDir) {
                $entries = array_values(array_filter($entries, static fn(array $entry): bool => ($entry['storage'] ?? null) === $storageDir));
            }
            $patterns = [];
            foreach ($entries as $entry) {
                $key = (string)($entry['storage'] ?? '') . "\0" . (string)($entry['pattern'] ?? $entry['query'] ?? '');
                $milliseconds = (float)($entry['milliseconds'] ?? 0);
                $group = $patterns[$key] ?? [
                    'storage' => (string)($entry['storage'] ?? ''),
                    'pattern' => (string)($entry['pattern'] ?? $entry['query'] ?? ''),
                    'statement' => $entry['statement'] ?? null,
                    'runs' => 0, 'totalMilliseconds' => 0.0, 'maxMilliseconds' => 0.0,
                    'maxObjectsRead' => 0, 'totalRows' => 0, 'failed' => 0, 'interrupted' => 0, 'fullScans' => [],
                ];
                $group['runs']++;
                $group['totalMilliseconds'] += $milliseconds;
                $group['maxMilliseconds'] = max($group['maxMilliseconds'], $milliseconds);
                $group['maxObjectsRead'] = max($group['maxObjectsRead'], (int)($entry['objectsRead'] ?? 0));
                $group['totalRows'] += (int)($entry['rows'] ?? 0);
                $group['failed'] += null !== ($entry['error'] ?? null) && !($entry['interrupted'] ?? false) ? 1 : 0;
                $group['interrupted'] += ($entry['interrupted'] ?? false) ? 1 : 0;
                $group['fullScans'] += array_flip(array_values((array)($entry['fullScans'] ?? [])));
                // the latest run: its query is the one opened
                $group['last'] = $entry;
                $patterns[$key] = $group;
            }
            // where the time went first
            usort($patterns, static fn(array $a, array $b): int => $b['totalMilliseconds'] <=> $a['totalMilliseconds']);

            echo $twig->render('slow-queries.html', [
                'storage' => '' === $storageDir ? null : $storageDir,
                'view' => $view,
                'patterns' => $patterns,
                // the newest first
                'latest' => array_slice(array_reverse($entries), 0, 500),
                'count' => count($entries),
                'file' => $slowQueryLogFile,
                'milliseconds' => $slowQueryMilliseconds,
                'objectsRead' => $slowQueryObjectsRead,
                'cleared' => isset($_GET['cleared']),
            ]);
            break;
        case 'slow-queries-clear':
            // the whole log, of all storages
            $requirePost();
            foreach ([$slowQueryLogFile, $slowQueryLogFile . '.1'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            header('Location: ?' . http_build_query(array_filter(['action' => 'slow-queries', 'storage' => (string)($_POST['storage'] ?? ''), 'cleared' => '1'])));
            break;
        case 'index-errors':
            // the objects an index couldn't index ("<uuid>: <reason>"), with their classes as stored now
            $storageDir = $_GET['storage'] ?? null;
            $name = (string)($_GET['name'] ?? '');
            $storage = $buildStorage($storageDir);
            $errors = [];
            $error = null;
            try {
                $search = $searchFor($storage) ?? throw new RuntimeException('Search is not available.');
                foreach ($search->get($name)->getState()->errors as $text) {
                    $uuid = preg_match('/^([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}): (.*)$/s', (string)$text, $match) ? $match[1] : null;
                    $className = null;
                    if (null !== $uuid) {
                        try {
                            $className = $storage->exists($uuid) ? $storage->loadMetadata($uuid)?->getClassName() : null;
                        } catch (Throwable $e) {
                            $logger->error($e);
                        }
                    }
                    $errors[] = ['uuid' => $uuid, 'className' => $className, 'reason' => null === $uuid ? (string)$text : $match[2]];
                }
            } catch (Throwable $e) {
                $logger->error($e);
                $error = $e->getMessage();
            }

            echo $twig->render('index-errors.html', [
                'storage' => $storageDir,
                'searchAvailable' => $searchAvailable,
                'name' => $name,
                'errors' => $errors,
                'maxErrors' => Index::MAX_ERRORS,
                'error' => $error,
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

            // a path to add, e.g. from the query builder: a filterable field named after it ("friend.name" → friendName)
            $addPath = trim((string)($_GET['addPath'] ?? ''));
            $addedPath = null;
            if ('' !== $addPath && !in_array($addPath, array_column($fields, 'path'), true)) {
                $addedPath = $addPath;
                $fieldName = lcfirst(str_replace(' ', '', ucwords(preg_replace('/[^a-zA-Z0-9]+/', ' ', $addPath))));
                $fieldName = preg_match(Field::NAME_PATTERN, $fieldName) ? $fieldName : 'field' . count($fields);
                $suggestedField = null;
                if ('' !== $classname) {
                    try {
                        foreach ($suggestFields($storage, $classname) as $candidate) {
                            if (($candidate['path'] ?? null) === $addPath) {
                                $suggestedField = $candidate;
                            }
                        }
                    } catch (Throwable $e) {
                        $logger->error($e);
                    }
                }
                $fields[] = [
                    'name' => $fieldName,
                    'path' => $addPath,
                    'type' => $suggestedField['type'] ?? FieldType::STRING->value,
                    'searchable' => $suggestedField['searchable'] ?? true,
                    'filterable' => true,
                    'sortable' => $suggestedField['sortable'] ?? false,
                ];
            }
            $fields = $withUniqueFlags($fields, (string)$classname, $storage);

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
                'patternIndexes' => $patternIndexes,
                'engines' => $engineNames,
                'engine' => $definition['engine'] ?? null,
                'index' => $described,
                'error' => null,
                'addedPath' => $addedPath,
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
                if ($isNew && $search->has($definition->name)) {
                    throw new RuntimeException(sprintf('A search index "%s" exists already.', $definition->name));
                }

                // the unique flags of the fields first: if a value isn't unique, nothing is saved
                $uniquePaths = [];
                foreach ($_POST['fields'] ?? [] as $field) {
                    $path = trim((string)($field['path'] ?? ''));
                    if ('' !== $path) {
                        $uniquePaths[$path] = [
                            'unique' => isset($field['unique']),
                            'caseInsensitive' => isset($field['uniqueIgnoreCase']),
                            'name' => (string)($field['name'] ?? $path),
                        ];
                    }
                }
                (new UniquePathIndexes($uniqueFor($storage)))->apply($definition->className, $uniquePaths, $definition->name);

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
                        'patterns' => array_values(array_filter((array)($field['patterns'] ?? []), 'is_string')),
                        'unique' => isset($field['unique']),
                        'uniqueIgnoreCase' => isset($field['uniqueIgnoreCase']),
                    ], $_POST['fields'] ?? [])),
                    'types' => array_map(static fn(FieldType $type): string => $type->value, FieldType::cases()),
                    'patternIndexes' => $patternIndexes,
                    'engines' => $engineNames,
                    // an edited index doesn't post its engine (it can't be changed)
                    'engine' => $_POST['engine'] ?? (!$isNew && $search->has((string)($_POST['name'] ?? '')) ? $search->get((string)$_POST['name'])->getEngineName() : null),
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
    // e.g. the configuration of a storage can't be applied: say so instead of an empty page
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo $twig->render('error.html', [
        'storage' => $_GET['storage'] ?? $_POST['storage'] ?? null,
        'error' => $e->getMessage(),
        'isConfiguration' => $e instanceof ConfigurationException,
        'exists' => false, // not remembered as a recent storage
    ]);
}