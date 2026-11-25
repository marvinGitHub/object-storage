<?php

namespace melia\ObjectStorage\Viewer\Controller;

use RuntimeException;
use Throwable;
use melia\ObjectStorage\Configuration\Configuration;
use melia\ObjectStorage\Configuration\Factory as ConfigurationFactory;
use melia\ObjectStorage\Configuration\Registry as ConfigurationRegistry;
use melia\ObjectStorage\Viewer\Forms;
use melia\ObjectStorage\Viewer\Summaries;

/**
 * Creating storages, and the named configurations assigned to them.
 */
final class ConfigurationController extends Controller
{
    /**
     * storage-create
     */
    public function storageCreate(): void
    {
        $configurations = $this->app->configurations;
        $requirePost = $this->app->requirePost(...);

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
    }

    /**
     * configurations
     */
    public function configurations(): void
    {
        $twig = $this->app->twig;
        $logger = $this->app->logger;
        $configurations = $this->app->configurations;
        $describeConfiguration = Summaries::describeConfiguration(...);

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
    }

    /**
     * configuration-edit, configuration-save
     */
    public function configurationEdit(string $action): void
    {
        $twig = $this->app->twig;
        $logger = $this->app->logger;
        $configurations = $this->app->configurations;
        $requirePost = $this->app->requirePost(...);
        $configurationFromRequest = Forms::configurationFromRequest(...);
        $configurationChoices = Forms::configurationChoices();

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
                return;
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
    }

    /**
     * configuration-delete, configuration-check, configuration-assign
     */
    public function configurationDelete(string $action): void
    {
        $logger = $this->app->logger;
        $psr16 = $this->app->psr16;
        $configurations = $this->app->configurations;
        $jsonResponse = $this->app->jsonResponse(...);
        $requirePost = $this->app->requirePost(...);
        $createCacheKeyShardDepth = $this->app->createCacheKeyShardDepth(...);

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
    }
}
