<?php

/**
 * Loads the classes of the library and of the viewer (which isn't part of the library, so not in composer.json).
 */
$autoloader = require dirname(__DIR__) . '/vendor/autoload.php';
$autoloader->addPsr4('melia\\ObjectStorage\\Viewer\\', __DIR__ . '/src');

return $autoloader;
