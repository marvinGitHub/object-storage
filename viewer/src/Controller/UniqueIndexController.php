<?php

namespace melia\ObjectStorage\Viewer\Controller;

use Throwable;
use melia\ObjectStorage\Unique\Definition as UniqueDefinition;

/**
 * The unique indexes of a storage.
 */
final class UniqueIndexController extends Controller
{
    /**
     * unique-indexes
     */
    public function uniqueIndexes(): void
    {
        $twig = $this->app->twig;
        $buildStorage = $this->app->buildStorage(...);
        $uniqueFor = $this->app->uniqueFor(...);

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
    }

    /**
     * unique-create, unique-rebuild, unique-drop
     */
    public function uniqueCreate(string $action): void
    {
        $logger = $this->app->logger;
        $buildStorage = $this->app->buildStorage(...);
        $uniqueFor = $this->app->uniqueFor(...);
        $requirePost = $this->app->requirePost(...);

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
    }
}
