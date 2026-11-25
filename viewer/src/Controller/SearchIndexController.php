<?php

namespace melia\ObjectStorage\Viewer\Controller;

use RuntimeException;
use Throwable;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Index;
use melia\ObjectStorage\Search\Index\IndexInterface;
use melia\ObjectStorage\Search\Request;
use melia\ObjectStorage\Unique\PathIndexes as UniquePathIndexes;
use melia\ObjectStorage\Viewer\App;
use melia\ObjectStorage\Viewer\Forms;
use melia\ObjectStorage\Viewer\Summaries;

/**
 * The search indexes of a storage: defining, building, syncing, dropping and searching them.
 */
final class SearchIndexController extends Controller
{
    /**
     * indexes
     */
    public function indexes(): void
    {
        $twig = $this->app->twig;
        $logger = $this->app->logger;
        $searchAvailable = $this->app->searchAvailable;
        $buildStorage = $this->app->buildStorage(...);
        $searchFor = $this->app->searchFor(...);
        $describeIndex = Summaries::describeIndex(...);

        $storageDir = $_GET['storage'] ?? null;
        $indexes = [];
        $error = null;

        if ($searchAvailable) {
            try {
                foreach ($searchFor($buildStorage($storageDir))->list() as $index) {
                    try {
                        $indexes[] = $describeIndex($index);
                    } catch (Throwable $e) {
                        $logger->error($e);
                        $indexes[] = ['name' => $index->getName(), 'broken' => $e->getMessage()];
                    }
                }
            } catch (Throwable $e) {
                $logger->error($e);
                $error = $e->getMessage();
            }
        }

        echo $twig->render('indexes.html', [
            'storage' => $storageDir,
            'searchAvailable' => $searchAvailable,
            'indexes' => $indexes,
            'error' => $error,
            'message' => $_GET['message'] ?? null,
        ]);
    }

    /**
     * index-errors
     */
    public function indexErrors(): void
    {
        $twig = $this->app->twig;
        $logger = $this->app->logger;
        $searchAvailable = $this->app->searchAvailable;
        $buildStorage = $this->app->buildStorage(...);
        $searchFor = $this->app->searchFor(...);

        // the objects an index couldn't index ("<uuid>: <reason>"), with their classes as stored now
        $storageDir = $_GET['storage'] ?? null;
        $name = (string)($_GET['name'] ?? '');
        $storage = $buildStorage($storageDir);
        $errors = [];
        $error = null;
        try {
            $search = $searchFor($storage) ?? throw new RuntimeException('Search is not available.');
            foreach ($search->get($name)->getState()->errors as $text) {
                $uuid = preg_match('/^([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}): (.*)$/s', (string)$text, $match) ? $match[1] : null;
                $className = null;
                if (null !== $uuid) {
                    try {
                        $className = $storage->exists($uuid) ? $storage->loadMetadata($uuid)?->getClassName() : null;
                    } catch (Throwable $e) {
                        $logger->error($e);
                    }
                }
                $errors[] = ['uuid' => $uuid, 'className' => $className, 'reason' => null === $uuid ? (string)$text : $match[2]];
            }
        } catch (Throwable $e) {
            $logger->error($e);
            $error = $e->getMessage();
        }

        echo $twig->render('index-errors.html', [
            'storage' => $storageDir,
            'searchAvailable' => $searchAvailable,
            'name' => $name,
            'errors' => $errors,
            'maxErrors' => Index::MAX_ERRORS,
            'error' => $error,
        ]);
    }

    /**
     * index-edit
     */
    public function indexEdit(): void
    {
        $twig = $this->app->twig;
        $logger = $this->app->logger;
        $searchAvailable = $this->app->searchAvailable;
        $engineNames = $this->app->engineNames;
        $buildStorage = $this->app->buildStorage(...);
        $searchFor = $this->app->searchFor(...);
        $withUniqueFlags = $this->app->withUniqueFlags(...);
        $patternIndexes = Forms::patternIndexes();
        $describeIndex = Summaries::describeIndex(...);
        $suggestFields = Summaries::suggestFields(...);

        // create (no name) or edit an index; with "suggest", the fields are suggested from a stored object
        $storageDir = $_GET['storage'] ?? null;
        $name = $_GET['name'] ?? null;
        $storage = $buildStorage($storageDir);
        $search = $searchFor($storage);

        $classnames = $storage->getClassNames();
        sort($classnames, SORT_NATURAL | SORT_FLAG_CASE);

        $definition = null;
        $described = null;
        if (null !== $name && null !== $search) {
            $index = $search->get($name);
            $definition = $index->getDefinition()->toArray();
            $described = $describeIndex($index);
        }

        $classname = $_GET['classname'] ?? $definition['className'] ?? '';
        $fields = $definition['fields'] ?? [];
        if (isset($_GET['suggest']) && '' !== $classname) {
            $fields = $suggestFields($storage, $classname);
        }

        // a path to add, e.g. from the query builder: a filterable field named after it ("friend.name" → friendName)
        $addPath = trim((string)($_GET['addPath'] ?? ''));
        $addedPath = null;
        if ('' !== $addPath && !in_array($addPath, array_column($fields, 'path'), true)) {
            $addedPath = $addPath;
            $fieldName = lcfirst(str_replace(' ', '', ucwords(preg_replace('/[^a-zA-Z0-9]+/', ' ', $addPath))));
            $fieldName = preg_match(Field::NAME_PATTERN, $fieldName) ? $fieldName : 'field' . count($fields);
            $suggestedField = null;
            if ('' !== $classname) {
                try {
                    foreach ($suggestFields($storage, $classname) as $candidate) {
                        if (($candidate['path'] ?? null) === $addPath) {
                            $suggestedField = $candidate;
                        }
                    }
                } catch (Throwable $e) {
                    $logger->error($e);
                }
            }
            $fields[] = [
                'name' => $fieldName,
                'path' => $addPath,
                'type' => $suggestedField['type'] ?? FieldType::STRING->value,
                'searchable' => $suggestedField['searchable'] ?? true,
                'filterable' => true,
                'sortable' => $suggestedField['sortable'] ?? false,
            ];
        }
        $fields = $withUniqueFlags($fields, (string)$classname, $storage);

        echo $twig->render('index-edit.html', [
            'storage' => $storageDir,
            'searchAvailable' => $searchAvailable,
            'isNew' => null === $definition,
            'name' => $definition['name'] ?? ($_GET['newName'] ?? ''),
            'classname' => $classname,
            'classnames' => $classnames,
            'languages' => implode(', ', $definition['languages'] ?? []),
            'fields' => $fields,
            'types' => array_map(static fn(FieldType $type): string => $type->value, FieldType::cases()),
            'patternIndexes' => $patternIndexes,
            'engines' => $engineNames,
            'engine' => $definition['engine'] ?? null,
            'index' => $described,
            'error' => null,
            'addedPath' => $addedPath,
        ]);
    }

    /**
     * index-save
     */
    public function indexSave(): void
    {
        $twig = $this->app->twig;
        $logger = $this->app->logger;
        $searchAvailable = $this->app->searchAvailable;
        $engineNames = $this->app->engineNames;
        $buildStorage = $this->app->buildStorage(...);
        $searchFor = $this->app->searchFor(...);
        $uniqueFor = $this->app->uniqueFor(...);
        $requirePost = $this->app->requirePost(...);
        $definitionFromRequest = Forms::definitionFromRequest(...);
        $patternIndexes = Forms::patternIndexes();

        $requirePost();
        $storageDir = $_POST['storage'] ?? null;
        $storage = $buildStorage($storageDir);
        $search = $searchFor($storage) ?? throw new RuntimeException('Search is not available.');
        $isNew = '1' === ($_POST['isNew'] ?? '1');

        try {
            $definition = $definitionFromRequest($_POST);
            if ($isNew && $search->has($definition->name)) {
                throw new RuntimeException(sprintf('A search index "%s" exists already.', $definition->name));
            }

            // the unique flags of the fields first: if a value isn't unique, nothing is saved
            $uniquePaths = [];
            foreach ($_POST['fields'] ?? [] as $field) {
                $path = trim((string)($field['path'] ?? ''));
                if ('' !== $path) {
                    $uniquePaths[$path] = [
                        'unique' => isset($field['unique']),
                        'caseInsensitive' => isset($field['uniqueIgnoreCase']),
                        'name' => (string)($field['name'] ?? $path),
                    ];
                }
            }
            (new UniquePathIndexes($uniqueFor($storage)))->apply($definition->className, $uniquePaths, $definition->name);

            $isNew ? $search->create($definition) : $search->update($definition);

            // build it right away, showing the progress
            header('Location: ?action=index-build&start=1&storage=' . rawurlencode((string)$storageDir) . '&name=' . rawurlencode($definition->name));
        } catch (Throwable $e) {
            $logger->error($e);
            $classnames = $storage->getClassNames();
            sort($classnames, SORT_NATURAL | SORT_FLAG_CASE);

            echo $twig->render('index-edit.html', [
                'storage' => $storageDir,
                'searchAvailable' => $searchAvailable,
                'isNew' => $isNew,
                'name' => $_POST['name'] ?? '',
                'classname' => $_POST['classname'] ?? '',
                'classnames' => $classnames,
                'languages' => $_POST['languages'] ?? '',
                'fields' => array_values(array_map(static fn(array $field): array => [
                    'name' => $field['name'] ?? '',
                    'path' => $field['path'] ?? '',
                    'type' => $field['type'] ?? FieldType::STRING->value,
                    'searchable' => isset($field['searchable']),
                    'filterable' => isset($field['filterable']),
                    'sortable' => isset($field['sortable']),
                    'patterns' => array_values(array_filter((array)($field['patterns'] ?? []), 'is_string')),
                    'unique' => isset($field['unique']),
                    'uniqueIgnoreCase' => isset($field['uniqueIgnoreCase']),
                ], $_POST['fields'] ?? [])),
                'types' => array_map(static fn(FieldType $type): string => $type->value, FieldType::cases()),
                'patternIndexes' => $patternIndexes,
                'engines' => $engineNames,
                // an edited index doesn't post its engine (it can't be changed)
                'engine' => $_POST['engine'] ?? (!$isNew && $search->has((string)($_POST['name'] ?? '')) ? $search->get((string)$_POST['name'])->getEngineName() : null),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * index-build
     */
    public function indexBuild(): void
    {
        $twig = $this->app->twig;
        $searchAvailable = $this->app->searchAvailable;
        $buildStorage = $this->app->buildStorage(...);
        $searchFor = $this->app->searchFor(...);
        $describeIndex = Summaries::describeIndex(...);

        // the page runs the build step by step via the JSON actions below, showing the progress
        $storageDir = $_GET['storage'] ?? null;
        $name = $_GET['name'] ?? '';
        $search = $searchFor($buildStorage($storageDir));

        echo $twig->render('index-build.html', [
            'storage' => $storageDir,
            'searchAvailable' => $searchAvailable,
            'index' => null === $search ? null : $describeIndex($search->get($name)),
            'start' => isset($_GET['start']),
        ]);
    }

    /**
     * index-build-start, index-build-step, index-build-cancel, index-sync, index-drop
     */
    public function indexBuildStart(string $action): void
    {
        $logger = $this->app->logger;
        $buildStorage = $this->app->buildStorage(...);
        $searchFor = $this->app->searchFor(...);
        $jsonResponse = $this->app->jsonResponse(...);
        $requirePost = $this->app->requirePost(...);
        $formatBytes = App::formatBytes(...);

        // JSON actions, called by the pages
        try {
            $requirePost();
            $storageDir = $_POST['storage'] ?? null;
            $search = $searchFor($buildStorage($storageDir)) ?? throw new RuntimeException('Search is not available.');
            $index = $search->get((string)($_POST['name'] ?? ''));

            $result = match ($action) {
                // a running build is continued instead of started again, e.g. after reloading the page
                'index-build-start' => IndexInterface::STATUS_BUILDING === $index->getStatus() ? $index->getProgress() : $index->startBuild(),
                'index-build-step' => $index->buildStep(max(1, min(5000, (int)($_POST['batchSize'] ?? Index::DEFAULT_BATCH_SIZE)))),
                'index-build-cancel' => (static function () use ($index): array {
                    $index->cancelBuild();
                    return $index->getProgress();
                })(),
                'index-sync' => ['applied' => $index->sync(purgeExpired: false), 'expired' => $index->purgeExpired()],
                'index-drop' => (static function () use ($search, $index): array {
                    $search->drop($index->getName());
                    return ['dropped' => true];
                })(),
            };

            // the build page shows the size growing
            if (str_starts_with($action, 'index-build-')) {
                $result['size'] = $formatBytes($index->getSize());
            }

            $jsonResponse(['ok' => true, 'result' => $result]);
        } catch (Throwable $e) {
            $logger->error($e);
            $jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * index-search, class-search
     */
    public function indexSearch(string $action): void
    {
        $twig = $this->app->twig;
        $logger = $this->app->logger;
        $buildStorage = $this->app->buildStorage(...);
        $searchFor = $this->app->searchFor(...);

        // where the time of the page goes: shown on the page and sent as Server-Timing for the browser's dev tools
        $timings = ['bootstrap' => (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000];
        $timer = microtime(true);
        $lap = static function (string $step) use (&$timings, &$timer): void {
            $now = microtime(true);
            $timings[$step] = ($timings[$step] ?? 0) + ($now - $timer) * 1000;
            $timer = $now;
        };

        $storageDir = $_GET['storage'] ?? null;
        $name = $_GET['name'] ?? '';
        $storage = $buildStorage($storageDir);
        $search = $searchFor($storage);
        $lap('storage');

        $form = [
            'query' => (string)($_GET['q'] ?? ''),
            'filter' => (string)($_GET['filter'] ?? ''),
            'sort' => (string)($_GET['sort'] ?? ''),
            'facets' => (string)($_GET['facets'] ?? ''),
            'page' => max(1, (int)($_GET['page'] ?? 1)),
            'hitsPerPage' => max(1, min(1000, (int)($_GET['hitsPerPage'] ?? 20))),
            'searchOn' => (string)($_GET['searchOn'] ?? ''),
        ];

        $result = null;
        $error = null;
        $index = null;
        $definition = null;

        // searching a class: the index manager chooses the index, like a database the index of a table
        $classname = 'class-search' === $action ? (string)($_GET['classname'] ?? '') : null;
        $plan = null;

        try {
            if (null === $search) {
                throw new RuntimeException('Search is not available.');
            }

            $split = static fn(string $list): array => array_values(array_filter(array_map('trim', explode(',', $list))));

            // engine-independent; only the filter is written in the language of the engine
            $request = new Request(
                query: $form['query'],
                filter: '' === trim($form['filter']) ? null : $form['filter'],
                sort: $split($form['sort']),
                facets: $split($form['facets']),
                page: $form['page'],
                hitsPerPage: $form['hitsPerPage'],
                highlight: true,
                highlightStartTag: '<mark>',
                highlightEndTag: '</mark>',
                searchOn: $split($form['searchOn']),
            );

            if (null !== $classname) {
                $explained = $search->explain($classname, $request);
                $plan = array_map(static fn($candidate): array => [
                    'name' => $candidate->name,
                    'engine' => $candidate->engine,
                    'status' => $candidate->status,
                    'rank' => $candidate->rank,
                    'reasons' => $candidate->reasons,
                ], $explained->candidates);

                // the chosen index, or, before searching or without one, any index of the class for the help
                $name = $explained->getChosen()?->name ?? array_key_first($search->getIndexesForClass($classname)) ?? '';
                if ('' === $name) {
                    throw new RuntimeException(sprintf('No search index exists for %s.', $classname));
                }
                $lap('plan');
                if (isset($_GET['run']) && null === $explained->getChosen()) {
                    throw new RuntimeException('No index of the class can answer this search, see the plan.');
                }
            }

            $index = $search->get($name);
            $definition = $index->getActiveDefinition() ?? $index->getDefinition();
            $lap('index');

            if (isset($_GET['run'])) {
                $searchResult = $index->search($request);
                $lap('search');
                $timings['engine'] = (float)$searchResult->processingTimeMs;

                $hits = [];
                foreach ($searchResult->hits as $hit) {
                    $values = [];
                    foreach ($hit->document as $attribute => $value) {
                        $highlighted = $hit->highlighted[$attribute] ?? null;
                        $values[] = [
                            'name' => $attribute,
                            'value' => is_scalar($value) || null === $value ? var_export($value, true) : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            // the text is escaped, only the marks of the matches are kept as markup
                            'highlighted' => null === $highlighted ? null : str_replace(['&lt;mark&gt;', '&lt;/mark&gt;'], ['<mark>', '</mark>'], htmlspecialchars($highlighted)),
                        ];
                    }

                    $hits[] = ['uuid' => $hit->uuid, 'score' => $hit->score, 'values' => $values];
                }

                // page links: the first and last three, two around the current page, gaps in between
                $pageLinks = [];
                for ($i = 1, $total = $searchResult->totalPages; $i <= $total; $i++) {
                    if ($i <= 3 || $i > $total - 3 || abs($i - $searchResult->page) <= 2) {
                        $pageLinks[] = $i;
                    } elseif (null !== end($pageLinks)) {
                        $pageLinks[] = null;
                    }
                }

                $result = [
                    'hits' => $hits,
                    'pageLinks' => $pageLinks,
                    'totalHits' => $searchResult->totalHits,
                    'totalPages' => $searchResult->totalPages,
                    'page' => $searchResult->page,
                    'processingTimeMs' => $searchResult->processingTimeMs,
                    'facets' => $searchResult->facets,
                ];
            }
        } catch (Throwable $e) {
            $logger->error($e);
            $error = $e->getMessage();
        }

        $variables = [
            'storage' => $storageDir,
            'name' => $name,
            'classname' => $classname,
            'plan' => $plan,
            'form' => $form,
            'result' => $result,
            'error' => $error,
            'status' => $index?->getStatus(),
            'engine' => $index?->getEngineName(),
            'fields' => null === $definition ? [] : array_map(static fn(Field $field): array => $field->toArray(), $definition->fields),
            'queryString' => http_build_query(array_diff_key($_GET, ['page' => true])),
        ];
        $lap('prepare');

        // rendered first, so its time is known and the header can be sent before the page
        $variables['timings'] = $timings;
        $html = $twig->render('index-search.html', $variables);
        $lap('render');
        $timings['total'] = (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000;

        $serverTiming = [];
        foreach ($timings as $step => $ms) {
            // the time inside the engine is part of "search", not a step of its own
            $serverTiming[] = sprintf('%s;%sdur=%.1f', $step, 'engine' === $step ? 'desc="inside the engine";' : '', $ms);
        }
        header('Server-Timing: ' . implode(', ', $serverTiming));

        echo str_replace('<!--timings-->', htmlspecialchars(implode(' · ', array_map(
            static fn(string $step, float $ms): string => sprintf('%s %.0f ms', $step, $ms),
            array_keys($timings),
            $timings
        ))), $html);
    }
}
