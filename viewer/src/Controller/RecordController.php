<?php

namespace melia\ObjectStorage\Viewer\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use IteratorIterator;
use JsonException;
use LimitIterator;
use RuntimeException;
use Throwable;
use melia\ObjectStorage\Configuration\Registry as ConfigurationRegistry;
use melia\ObjectStorage\Exception\IOException;
use melia\ObjectStorage\Graph\Export\Dot\DotExporter;
use melia\ObjectStorage\Graph\GraphVisitor;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\Path\Path;
use melia\ObjectStorage\Serialization\DateTimeValue;
use melia\ObjectStorage\Transfer\RawObject;
use melia\ObjectStorage\UUID\Validator as UuidValidator;

/**
 * The classes and objects of a storage: listing, viewing, editing, duplicating, deleting, exporting.
 */
final class RecordController extends Controller
{
    /**
     * list-classnames
     */
    public function listClassnames(): void
    {
        $twig = $this->app->twig;
        $logger = $this->app->logger;
        $configurations = $this->app->configurations;
        $buildStorage = $this->app->buildStorage(...);
        $searchFor = $this->app->searchFor(...);

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
    }

    /**
     * list-uuids
     */
    public function listUuids(): void
    {
        $twig = $this->app->twig;
        $buildStorage = $this->app->buildStorage(...);

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
    }

    /**
     * delete-records
     */
    public function deleteRecords(): void
    {
        $logger = $this->app->logger;
        $buildStorage = $this->app->buildStorage(...);

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
    }

    /**
     * delete-record
     */
    public function deleteRecord(): void
    {
        $twig = $this->app->twig;
        $logger = $this->app->logger;
        $buildStorage = $this->app->buildStorage(...);

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
    }

    /**
     * duplicate-record
     */
    public function duplicateRecord(): void
    {
        $twig = $this->app->twig;
        $logger = $this->app->logger;
        $buildStorage = $this->app->buildStorage(...);
        $searchFor = $this->app->searchFor(...);

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
                // written as a group (the files create the shard directory), so a crash leaves no half copy
                $storage->getFiles()->writeGroup([
                    $storage->getFilePathData($uuidNew) => $data,
                    $storage->getFilePathMetadata($uuidNew) => json_encode($metadata, JSON_THROW_ON_ERROR),
                ]);
                $written = true;
            } finally {
                $finalizeConstraints($written);
            }
            $storage->stubIndex()->add($storage->getClassName($uuid), $uuidNew);
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

    }

    /**
     * view-record
     */
    public function viewRecord(): void
    {
        $twig = $this->app->twig;
        $logger = $this->app->logger;
        $buildStorage = $this->app->buildStorage(...);
        $searchFor = $this->app->searchFor(...);

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
            'size' => $size,
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
            'shard' => $storage->objectPaths()->shardedDirectory($uuid),
            'message' => $_GET['message'] ?? null,
            'error' => $_GET['error'] ?? null,
        ]);
    }

    /**
     * touch-record
     */
    public function touchRecord(): void
    {
        $logger = $this->app->logger;
        $buildStorage = $this->app->buildStorage(...);
        $requirePost = $this->app->requirePost(...);

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
    }

    /**
     * set-lifetime
     */
    public function setLifetime(): void
    {
        $logger = $this->app->logger;
        $buildStorage = $this->app->buildStorage(...);
        $requirePost = $this->app->requirePost(...);

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
    }

    /**
     * save-record
     */
    public function saveRecord(): void
    {
        $twig = $this->app->twig;
        $logger = $this->app->logger;
        $buildStorage = $this->app->buildStorage(...);
        $searchFor = $this->app->searchFor(...);

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
    }

    /**
     * export-record
     */
    public function exportRecord(): void
    {
        $twig = $this->app->twig;
        $logger = $this->app->logger;
        $exportDir = $this->app->exportDir;
        $buildStorage = $this->app->buildStorage(...);

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

    }
}
