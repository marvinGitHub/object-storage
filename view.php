<?php

/**
 * The viewer: a small web interface to browse, view, edit and delete stored objects, and to manage indexes,
 * configurations and maintenance (docs/maintenance.md). Run it with the built-in server: php -S localhost:8080 view.php
 *
 * Each request is an action (the parameter "action", the start page by default), handled by a controller of
 * viewer/src/Controller (see Routes); the pages are the templates in views/.
 */

use melia\ObjectStorage\Configuration\Exception\ConfigurationException;
use melia\ObjectStorage\Viewer\App;
use melia\ObjectStorage\Viewer\Routes;

require __DIR__ . '/viewer/bootstrap.php';

$app = new App(__DIR__, $_SERVER['PHP_SELF']);
$action = $_GET['action'] ?? $_POST['action'] ?? 'index';

try {
    if (isset(Routes::ACTIONS[$action])) {
        [$controller, $method] = Routes::ACTIONS[$action];
        (new $controller($app))->$method($action);
    }
} catch (Throwable $e) {
    $app->logger->error($e);
    // e.g. the configuration of a storage can't be applied: say so instead of an empty page
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo $app->twig->render('error.html', [
        'storage' => $_GET['storage'] ?? $_POST['storage'] ?? null,
        'error' => $e->getMessage(),
        'isConfiguration' => $e instanceof ConfigurationException,
        'exists' => false, // not remembered as a recent storage
    ]);
}
