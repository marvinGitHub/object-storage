<?php

namespace melia\ObjectStorage\Viewer;

use melia\ObjectStorage\Viewer\Controller\ConfigurationController;
use melia\ObjectStorage\Viewer\Controller\HomeController;
use melia\ObjectStorage\Viewer\Controller\LinkController;
use melia\ObjectStorage\Viewer\Controller\MaintenanceController;
use melia\ObjectStorage\Viewer\Controller\QueryController;
use melia\ObjectStorage\Viewer\Controller\RecordController;
use melia\ObjectStorage\Viewer\Controller\SearchIndexController;
use melia\ObjectStorage\Viewer\Controller\TransferController;
use melia\ObjectStorage\Viewer\Controller\UniqueIndexController;

/**
 * The actions of the viewer (the parameter "action" of a request) and the controllers handling them.
 */
final class Routes
{
    /** @var array<string, array{0: class-string, 1: string}> action => [controller, method] */
    public const array ACTIONS = [
        'index' => [HomeController::class, 'index'],
        'log' => [HomeController::class, 'log'],
        'log-clear' => [HomeController::class, 'logClear'],
        'transfer' => [TransferController::class, 'transfer'],
        'transfer-step' => [TransferController::class, 'transferStep'],
        'unique-indexes' => [UniqueIndexController::class, 'uniqueIndexes'],
        'unique-create' => [UniqueIndexController::class, 'uniqueCreate'],
        'unique-rebuild' => [UniqueIndexController::class, 'uniqueCreate'],
        'unique-drop' => [UniqueIndexController::class, 'uniqueCreate'],
        'storage-create' => [ConfigurationController::class, 'storageCreate'],
        'configurations' => [ConfigurationController::class, 'configurations'],
        'configuration-edit' => [ConfigurationController::class, 'configurationEdit'],
        'configuration-save' => [ConfigurationController::class, 'configurationEdit'],
        'configuration-delete' => [ConfigurationController::class, 'configurationDelete'],
        'configuration-check' => [ConfigurationController::class, 'configurationDelete'],
        'configuration-assign' => [ConfigurationController::class, 'configurationDelete'],
        'query' => [QueryController::class, 'query'],
        'slow-queries' => [QueryController::class, 'slowQueries'],
        'slow-queries-clear' => [QueryController::class, 'slowQueriesClear'],
        'list-classnames' => [RecordController::class, 'listClassnames'],
        'list-uuids' => [RecordController::class, 'listUuids'],
        'delete-records' => [RecordController::class, 'deleteRecords'],
        'delete-record' => [RecordController::class, 'deleteRecord'],
        'duplicate-record' => [RecordController::class, 'duplicateRecord'],
        'view-record' => [RecordController::class, 'viewRecord'],
        'touch-record' => [RecordController::class, 'touchRecord'],
        'set-lifetime' => [RecordController::class, 'setLifetime'],
        'save-record' => [RecordController::class, 'saveRecord'],
        'export-record' => [RecordController::class, 'exportRecord'],
        'link-attribute' => [LinkController::class, 'linkAttribute'],
        'link-class' => [LinkController::class, 'linkClass'],
        'link-class-step' => [LinkController::class, 'linkClassStep'],
        'rebuild-stubs' => [MaintenanceController::class, 'rebuildStubs'],
        'rebuild-stubs-start' => [MaintenanceController::class, 'rebuildStubsStart'],
        'rebuild-stubs-step' => [MaintenanceController::class, 'rebuildStubsStart'],
        'rebuild-shards' => [MaintenanceController::class, 'rebuildShards'],
        'rebuild-shards-start' => [MaintenanceController::class, 'rebuildShardsStart'],
        'rebuild-shards-step' => [MaintenanceController::class, 'rebuildShardsStart'],
        'indexes' => [SearchIndexController::class, 'indexes'],
        'index-errors' => [SearchIndexController::class, 'indexErrors'],
        'index-edit' => [SearchIndexController::class, 'indexEdit'],
        'index-save' => [SearchIndexController::class, 'indexSave'],
        'index-build' => [SearchIndexController::class, 'indexBuild'],
        'index-build-start' => [SearchIndexController::class, 'indexBuildStart'],
        'index-build-step' => [SearchIndexController::class, 'indexBuildStart'],
        'index-build-cancel' => [SearchIndexController::class, 'indexBuildStart'],
        'index-sync' => [SearchIndexController::class, 'indexBuildStart'],
        'index-drop' => [SearchIndexController::class, 'indexBuildStart'],
        'index-search' => [SearchIndexController::class, 'indexSearch'],
        'class-search' => [SearchIndexController::class, 'indexSearch'],
    ];
}
