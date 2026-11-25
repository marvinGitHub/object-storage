<?php

namespace melia\ObjectStorage\Viewer\Controller;

use Throwable;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Exception\QueryInterruptedException;
use melia\ObjectStorage\Query\Exception\ScriptException;
use melia\ObjectStorage\Query\Oql;
use melia\ObjectStorage\Query\Result;
use melia\ObjectStorage\Query\SlowQueryLog;
use melia\ObjectStorage\UUID\Validator as UuidValidator;

/**
 * The OQL console and its slow query log.
 */
final class QueryController extends Controller
{
    /**
     * query
     */
    public function query(): void
    {
        $twig = $this->app->twig;
        $logger = $this->app->logger;
        $slowQueryLogFile = $this->app->slowQueryLogFile;
        $slowQueryMilliseconds = $this->app->slowQueryMilliseconds;
        $slowQueryObjectsRead = $this->app->slowQueryObjectsRead;
        $buildStorage = $this->app->buildStorage(...);
        $searchFor = $this->app->searchFor(...);
        $uniqueFor = $this->app->uniqueFor(...);

        // OQL: SELECT … FROM Class … on the objects of the storage (docs/query.md)
        $storageDir = $_REQUEST['storage'] ?? null;
        $query = (string)($_POST['q'] ?? $_GET['q'] ?? '');
        $dryRun = isset($_POST['dryRun']);
        // Preview: the script runs and everything is rolled back at the end
        $preview = isset($_POST['preview']);
        // the maximum runtime in seconds, empty: unlimited
        $timeout = trim((string)($_POST['timeout'] ?? $_GET['timeout'] ?? ''));
        $timeout = is_numeric($timeout) && (float)$timeout > 0 ? (float)$timeout : null;
        // cancelled in the page while it ran: the query is shown again, not run
        $cancelled = isset($_GET['cancelled']);
        $storage = $buildStorage($storageDir);
        // run by the page (query.html) after the form is sent to the browser, so it can be cancelled meanwhile
        $slowQueryLog = new SlowQueryLog($slowQueryLogFile, $slowQueryMilliseconds, $slowQueryObjectsRead, context: ['storage' => (string)$storageDir]);
        $run = static function () use ($query, $dryRun, $preview, $timeout, $storage, $searchFor, $uniqueFor, $logger, $slowQueryLog): array {
            $results = null;
            $rolledBack = false;
            $error = null;
            $errorPosition = null;
            $started = microtime(true);
            // no limit of PHP: a query runs as long as it needs, or until its maximum runtime, or until the page
            // is cancelled (the browser closes the connection); then it stops cleanly, what it wrote is rolled back
            set_time_limit(0);
            ignore_user_abort(true);
            // what the page has so far goes to the browser now
            while (ob_get_level() > 0 && ob_end_flush()) {
            }
            flush();
            $timeLimit = null === $timeout ? null : Oql::timeLimit($timeout);
            $beat = $started;
            $interrupt = static function () use ($timeLimit, &$beat): void {
                if (null !== $timeLimit) {
                    $timeLimit();
                }
                // a closed connection shows only when writing to it: a space, twice a second, in the page
                $now = microtime(true);
                if ($now - $beat >= 0.5) {
                    $beat = $now;
                    echo ' ';
                    flush();
                    if (connection_aborted()) {
                        throw new QueryInterruptedException('The query was cancelled');
                    }
                }
            };
            try {
                // writes and transactions only from the form (POST): a link or the history never writes
                if (Oql::writes($query) && 'POST' !== ($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
                    throw new QueryException('INSERT, UPDATE, DELETE, ALTER and transactions run from the form only: check the statements and press Run.');
                }
                // one request is one connection: a transaction can't stay open across requests, a script left
                // with an open one is rolled back; indexes find the objects where they can; at most 1,000 rows
                // per statement are kept to show
                $script = (new Oql($storage, $searchFor($storage), $uniqueFor($storage), interrupt: $interrupt, observer: $slowQueryLog))->script($query, [], $dryRun, 1000, $preview);
                $results = $script->results;
                $rolledBack = $script->rolledBack;
            } catch (ScriptException $e) {
                $error = $e->getMessage() . ($e->rolledBack ? ' The transaction was rolled back.' : '');
                // marked in the query (a byte offset in the whole script)
                $errorPosition = $e->position;
                // what ran before the failing statement
                $results = $e->results;
            } catch (QueryException $e) {
                $error = $e->getMessage();
                $errorPosition = $e->position;
            } catch (Throwable $e) {
                $logger->error($e);
                $error = 'The query failed: ' . $e->getMessage();
            }
            return [$results, $rolledBack, $error, $errorPosition, (microtime(true) - $started) * 1000];
        };
        // lists and objects as JSON, the UUIDs in it (references) linked to their records; escaped first, so only
        // the links are HTML
        $jsonHtml = static function (string $json) use ($storageDir): string {
            $html = htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return (string)preg_replace_callback(
                '/&quot;([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})&quot;/',
                static fn(array $match): string => UuidValidator::validate($match[1])
                    ? sprintf('&quot;<a href="?action=view-record&amp;storage=%s&amp;uuid=%s" class="uuid">%s</a>&quot;', rawurlencode((string)$storageDir), $match[1], $match[1])
                    : $match[0],
                $html
            );
        };
        // values for the table: references stay UUIDs, dates are their point in time in UTC, lists and objects JSON
        $cell = static fn(mixed $value): array => match (true) {
            null === $value => ['NULL', 'null'],
            is_bool($value) => [$value ? 'true' : 'false', 'boolean'],
            is_array($value) => [$json = (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'json', $jsonHtml($json)],
            is_float($value) => [(string)json_encode($value, JSON_PRESERVE_ZERO_FRACTION), 'number'],
            is_int($value) => [(string)$value, 'number'],
            default => [(string)$value, is_string($value) && UuidValidator::validate($value) ? 'uuid' : 'string'],
        };
        $classnames = [];
        try {
            $classnames = $storage->getClassNames();
            sort($classnames, SORT_NATURAL | SORT_FLAG_CASE);
        } catch (Throwable $e) {
            $logger->error($e);
        }

        // what the page shows of a run
        $outcome = static function () use ($run, $cell): array {
            [$results, $rolledBack, $error, $errorPosition, $milliseconds] = $run();
            return [
                'error' => $error,
                'errorPosition' => $errorPosition,
                // whether the query ran (for the history), also if a later statement of a script failed
                'ran' => null !== $results,
                'results' => array_map(static fn(Result $result): array => [
                    'columns' => $result->columns,
                    // the rows; of a dry run of INSERT, the objects have no UUIDs yet
                    'rows' => array_map(
                        static fn(array $row): array => array_map($cell, $row),
                        null === $result->statement ? $result->rows : array_values(array_filter($result->rows, static fn(array $row): bool => null !== $row[0]))
                    ),
                    'statement' => $result->statement,
                    'truncated' => $result->truncated,
                    'affected' => $result->affected(),
                    'dryRun' => $result->dryRun,
                    // what it cost: shown with the rows, all objects of a class read marked
                    'objectsRead' => $result->statistics?->objectsRead,
                    'fullScans' => $result->statistics?->fullScans() ?? [],
                ], $results ?? []),
                'rolledBack' => $rolledBack,
                'milliseconds' => $milliseconds,
            ];
        };

        // displayed, not rendered: the page up to the results reaches the browser while the query runs
        $twig->display('query.html', [
            'storage' => $storageDir,
            'query' => $query,
            'classnames' => $classnames,
            'preview' => $preview,
            'dryRun' => $dryRun,
            'timeout' => $timeout,
            'cancelled' => $cancelled,
            // the template runs it (runner.run()) where the results go
            'runner' => '' === trim($query) || $cancelled ? null : new class($outcome) {
                public function __construct(private readonly \Closure $outcome)
                {
                }

                public function run(): array
                {
                    return ($this->outcome)();
                }
            },
        ]);
    }

    /**
     * slow-queries
     */
    public function slowQueries(): void
    {
        $twig = $this->app->twig;
        $slowQueryLogFile = $this->app->slowQueryLogFile;
        $slowQueryMilliseconds = $this->app->slowQueryMilliseconds;
        $slowQueryObjectsRead = $this->app->slowQueryObjectsRead;

        // the slow query log of the console (docs/query.md, "Statistics and the slow query log"): by pattern (the
        // query without its values) or the latest statements; of one storage, or of all (storage empty)
        $storageDir = (string)($_GET['storage'] ?? '');
        $view = 'latest' === ($_GET['view'] ?? '') ? 'latest' : 'patterns';
        $entries = SlowQueryLog::read($slowQueryLogFile);
        if ('' !== $storageDir) {
            $entries = array_values(array_filter($entries, static fn(array $entry): bool => ($entry['storage'] ?? null) === $storageDir));
        }
        $patterns = [];
        foreach ($entries as $entry) {
            $key = (string)($entry['storage'] ?? '') . "\0" . (string)($entry['pattern'] ?? $entry['query'] ?? '');
            $milliseconds = (float)($entry['milliseconds'] ?? 0);
            $group = $patterns[$key] ?? [
                'storage' => (string)($entry['storage'] ?? ''),
                'pattern' => (string)($entry['pattern'] ?? $entry['query'] ?? ''),
                'statement' => $entry['statement'] ?? null,
                'runs' => 0, 'totalMilliseconds' => 0.0, 'maxMilliseconds' => 0.0,
                'maxObjectsRead' => 0, 'totalRows' => 0, 'failed' => 0, 'interrupted' => 0, 'fullScans' => [],
            ];
            $group['runs']++;
            $group['totalMilliseconds'] += $milliseconds;
            $group['maxMilliseconds'] = max($group['maxMilliseconds'], $milliseconds);
            $group['maxObjectsRead'] = max($group['maxObjectsRead'], (int)($entry['objectsRead'] ?? 0));
            $group['totalRows'] += (int)($entry['rows'] ?? 0);
            $group['failed'] += null !== ($entry['error'] ?? null) && !($entry['interrupted'] ?? false) ? 1 : 0;
            $group['interrupted'] += ($entry['interrupted'] ?? false) ? 1 : 0;
            $group['fullScans'] += array_flip(array_values((array)($entry['fullScans'] ?? [])));
            // the latest run: its query is the one opened
            $group['last'] = $entry;
            $patterns[$key] = $group;
        }
        // where the time went first
        usort($patterns, static fn(array $a, array $b): int => $b['totalMilliseconds'] <=> $a['totalMilliseconds']);

        echo $twig->render('slow-queries.html', [
            'storage' => '' === $storageDir ? null : $storageDir,
            'view' => $view,
            'patterns' => $patterns,
            // the newest first
            'latest' => array_slice(array_reverse($entries), 0, 500),
            'count' => count($entries),
            'file' => $slowQueryLogFile,
            'milliseconds' => $slowQueryMilliseconds,
            'objectsRead' => $slowQueryObjectsRead,
            'cleared' => isset($_GET['cleared']),
        ]);
    }

    /**
     * slow-queries-clear
     */
    public function slowQueriesClear(): void
    {
        $slowQueryLogFile = $this->app->slowQueryLogFile;
        $requirePost = $this->app->requirePost(...);

        // the whole log, of all storages
        $requirePost();
        foreach ([$slowQueryLogFile, $slowQueryLogFile . '.1'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        header('Location: ?' . http_build_query(array_filter(['action' => 'slow-queries', 'storage' => (string)($_POST['storage'] ?? ''), 'cleared' => '1'])));
    }
}
