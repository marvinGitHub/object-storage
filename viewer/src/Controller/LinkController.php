<?php

namespace melia\ObjectStorage\Viewer\Controller;

use IteratorIterator;
use LimitIterator;
use RuntimeException;
use Throwable;
use melia\ObjectStorage\Migration\AttributeToReference;
use melia\ObjectStorage\Path\Path;
use melia\ObjectStorage\UUID\Validator as UuidValidator;

/**
 * Turning attributes holding UUIDs into references (AttributeToReference).
 */
final class LinkController extends Controller
{
    /**
     * link-attribute
     */
    public function linkAttribute(): void
    {
        $logger = $this->app->logger;
        $buildStorage = $this->app->buildStorage(...);
        $requirePost = $this->app->requirePost(...);

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
    }

    /**
     * link-class
     */
    public function linkClass(): void
    {
        $twig = $this->app->twig;
        $buildStorage = $this->app->buildStorage(...);

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
    }

    /**
     * link-class-step
     */
    public function linkClassStep(): void
    {
        $logger = $this->app->logger;
        $buildStorage = $this->app->buildStorage(...);
        $jsonResponse = $this->app->jsonResponse(...);
        $requirePost = $this->app->requirePost(...);

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
    }
}
