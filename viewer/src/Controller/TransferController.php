<?php

namespace melia\ObjectStorage\Viewer\Controller;

use RuntimeException;
use Throwable;
use melia\ObjectStorage\Transfer\ObjectTransfer;

/**
 * Copying and moving objects into another storage.
 */
final class TransferController extends Controller
{
    /**
     * transfer
     */
    public function transfer(): void
    {
        $twig = $this->app->twig;
        $logger = $this->app->logger;
        $configurations = $this->app->configurations;

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
    }

    /**
     * transfer-step
     */
    public function transferStep(): void
    {
        $logger = $this->app->logger;
        $configurations = $this->app->configurations;
        $buildStorage = $this->app->buildStorage(...);
        $jsonResponse = $this->app->jsonResponse(...);
        $requirePost = $this->app->requirePost(...);

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
    }
}
