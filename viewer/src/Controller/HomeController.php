<?php

namespace melia\ObjectStorage\Viewer\Controller;

use melia\ObjectStorage\Viewer\LogFile;

/**
 * The start page and the log of the viewer.
 */
final class HomeController extends Controller
{
    /**
     * index
     */
    public function index(): void
    {
        $twig = $this->app->twig;

        echo $twig->render('index.html');
    }

    /**
     * log
     */
    public function log(): void
    {
        $twig = $this->app->twig;
        $logfile = $this->app->logfile;
        $readLog = LogFile::read(...);

        // the error log of the viewer and of the storages it opened; of one storage, or of all (storage empty)
        $storageDir = (string)($_GET['storage'] ?? '');
        $entries = $readLog($logfile);
        $count = count($entries);
        if ('' !== $storageDir) {
            $entries = array_values(array_filter($entries, static fn(array $entry): bool => $entry['storage'] === $storageDir));
        }
        echo $twig->render('log.html', [
            'storage' => '' === $storageDir ? null : $storageDir,
            'entries' => array_slice($entries, 0, 500),
            'shown' => count($entries),
            'count' => $count,
            'file' => $logfile,
            'size' => is_file($logfile) ? filesize($logfile) : 0,
            'cleared' => isset($_GET['cleared']),
        ]);
    }

    /**
     * log-clear
     */
    public function logClear(): void
    {
        $logfile = $this->app->logfile;
        $requirePost = $this->app->requirePost(...);

        // the whole log, of all storages
        $requirePost();
        file_put_contents($logfile, '');
        header('Location: ?' . http_build_query(array_filter(['action' => 'log', 'storage' => (string)($_POST['storage'] ?? ''), 'cleared' => '1'])));
    }
}
