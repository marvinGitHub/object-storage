<?php

namespace melia\ObjectStorage\Profiling;

require_once __DIR__ . '/../vendor/autoload.php';

use melia\ObjectStorage\File\Directory;
use melia\ObjectStorage\ObjectStorage;
use stdClass;
use Xhgui\Profiler\Profiler;

$profiler = new Profiler(require __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php');

$profiler->enable();

/* operations to profile */
$directory = new Directory();
$directory->reserveRandomTemporaryDirectory();

$storage = new ObjectStorage($directory->getPath());

$someObject = new stdClass();
$nested = new stdClass();
$nested->self = $nested;
$someObject->nested = $nested;

$uuid = $storage->store($someObject);

$storage->clearCache();
$loaded = $storage->load($uuid);

$storage->clearCache();
$storage->exists($uuid);
$storage->getMemoryConsumption($uuid);
$storage->getLifetime($uuid);
$storage->expired($uuid);

$data = $profiler->disable();
$profiler->save($data);