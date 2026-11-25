<?php

namespace melia\ObjectStorage\Viewer;

use melia\ObjectStorage\Configuration\Registry as ConfigurationRegistry;
use melia\ObjectStorage\File\Directory;
use melia\ObjectStorage\Logger\LoggerInterface as StorageLoggerInterface;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Query\SlowQueryLog;
use melia\ObjectStorage\Search\Engine\EngineInterface;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Search\Loupe\LoupeEngine;
use melia\ObjectStorage\Unique\Manager as UniqueManager;
use melia\ObjectStorage\Unique\PathIndexes as UniquePathIndexes;
use Loupe\Loupe\LoupeFactory;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use RuntimeException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Throwable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * What the pages of the viewer share: its directories (appData/), its log, the templates, the configurations of
 * storages, and the storages themselves, opened with their configuration and with their search and unique indexes
 * attached, so changes made in the viewer are applied to them.
 */
final class App
{
    public readonly string $appDataDir;
    public readonly string $logDir;
    public readonly string $cacheDir;
    public readonly string $exportDir;

    /** the log of the viewer and of the storages it opened: one JSON object per line, see LogFile */
    public readonly string $logfile;
    public readonly Logger $logger;

    public readonly Environment $twig;

    /** what the viewer remembers across requests, e.g. the detected shard depth of a storage */
    public readonly Psr16Cache $psr16;

    /** named configurations of storages (lock backend, caches, strategy), assigned to storages in the viewer */
    public readonly ConfigurationRegistry $configurations;

    /** @var list<EngineInterface> the available search engines; Loupe needs loupe/loupe, ext-intl and ext-pdo_sqlite */
    public readonly array $searchEngines;
    public readonly bool $searchAvailable;

    /** @var list<string> */
    public readonly array $engineNames;

    /**
     * The slow statements of the query console (docs/query.md, "Slow query log"): those running at least this long,
     * or reading at least this many objects; set with the environment variables of the server.
     */
    public readonly string $slowQueryLogFile;
    public readonly float $slowQueryMilliseconds;
    public readonly int $slowQueryObjectsRead;

    /** @var array<int, Manager> the search indexes of the storages opened, by the id of the storage */
    private array $searches = [];

    /** @var array<int, UniqueManager> the unique indexes of the storages opened, by the id of the storage */
    private array $uniques = [];

    /**
     * @param string $baseDir The directory of view.php: appData/ and views/ are there.
     * @param string $baseUrl The URL of the viewer, for the links of the pages.
     */
    public function __construct(string $baseDir, string $baseUrl)
    {
        $this->appDataDir = $baseDir . DIRECTORY_SEPARATOR . 'appData';
        $this->logDir = $this->appDataDir . DIRECTORY_SEPARATOR . 'logs';
        $this->cacheDir = $this->appDataDir . DIRECTORY_SEPARATOR . 'cache';
        $this->exportDir = $this->appDataDir . DIRECTORY_SEPARATOR . 'export';

        foreach ([$this->appDataDir, $this->logDir, $this->cacheDir, $this->exportDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
                chmod($dir, 0777);
            }
        }

        if (!is_file($this->logfile = $this->logDir . DIRECTORY_SEPARATOR . 'error.log')) {
            touch($this->logfile);
        }

        // one JSON object per line (an error with its stack trace too), read by the log page (action "log")
        $this->logger = new Logger('ObjectStorageViewer');
        $this->logger->pushHandler((new StreamHandler($this->logfile))->setFormatter(new JsonFormatter()));

        $this->slowQueryLogFile = $this->logDir . DIRECTORY_SEPARATOR . 'slow-queries.log';
        // (0 too: every statement)
        $this->slowQueryMilliseconds = is_numeric($value = getenv('OBJECT_STORAGE_SLOW_QUERY_MS')) ? (float)$value : SlowQueryLog::DEFAULT_MILLISECONDS;
        $this->slowQueryObjectsRead = is_numeric($value = getenv('OBJECT_STORAGE_SLOW_QUERY_OBJECTS')) ? (int)$value : 10000;

        $this->twig = new Environment(new FilesystemLoader($baseDir . DIRECTORY_SEPARATOR . 'views'));
        $this->twig->addGlobal('baseUrl', $baseUrl);
        $this->twig->addFilter(new TwigFilter('bytes', self::formatBytes(...)));

        $this->psr16 = new Psr16Cache(new FilesystemAdapter(
            namespace: 'object-storage-viewer',
            defaultLifetime: 60,
            directory: $this->cacheDir
        ));

        $this->configurations = new ConfigurationRegistry($this->appDataDir . DIRECTORY_SEPARATOR . 'configurations');

        $engines = [];
        if (class_exists(LoupeFactory::class) && extension_loaded('intl') && extension_loaded('pdo_sqlite')) {
            $engines[] = new LoupeEngine();
        }
        $this->searchEngines = $engines;
        $this->searchAvailable = [] !== $engines;
        $this->engineNames = array_map(static fn(EngineInterface $engine): string => $engine->getName(), $engines);
    }

    /**
     * Sizes in bytes, readable: 512 B, 3.4 KB, 12.1 MB (1 KB = 1024 bytes).
     */
    public static function formatBytes(?int $bytes): string
    {
        if (null === $bytes) {
            return '–';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;
        $value = (float)$bytes;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        return 0 === $unit ? sprintf('%d B', $bytes) : sprintf('%.1f %s', $value, $units[$unit]);
    }

    /**
     * A storage opened by the viewer: with the configuration assigned to it (the defaults without one), its errors
     * logged to the log of the viewer, the shard depth of its files, and its search and unique indexes attached.
     */
    public function buildStorage(?string $storageDir): ObjectStorage
    {
        $storageDir = (string)$storageDir;
        $storage = $this->configurations->open($storageDir);
        $storage->setLogger($this->storageLoggerFor($storageDir));

        $depth = $this->psr16->get($key = $this->createCacheKeyShardDepth($storageDir));
        if ($depth === null) {
            $depth = (new Directory($storage->getShardDir()))->getMaxDirDepth();
            $this->psr16->set($key, $depth, 3600);
        }

        // the depth of the files found wins; an empty storage keeps the configured (or default) depth
        if (null !== $depth && $depth > 0) {
            $storage->getStrategy()->setShardDepth($depth);
        }

        $this->searchFor($storage);
        $this->uniqueFor($storage);

        return $storage;
    }

    /**
     * The key under which the detected shard depth of a storage is cached.
     */
    public function createCacheKeyShardDepth(string $storageDir): string
    {
        return 'shard_depth_' . sha1($storageDir);
    }

    /**
     * Errors a storage reports without stopping (e.g. an index failing while a query chooses one) go to the log of
     * the viewer, with the directory of the storage.
     */
    public function storageLoggerFor(string $storageDir): StorageLoggerInterface
    {
        return new class ($this->logger, $storageDir) implements StorageLoggerInterface {
            public function __construct(private readonly Logger $logger, private readonly string $storageDir)
            {
            }

            public function log(Throwable $error): void
            {
                $this->logger->error($error, ['storage' => $this->storageDir]);
            }
        };
    }

    /**
     * The search indexes of a storage, attached so changes made in the viewer are applied to them; null without a
     * search engine.
     */
    public function searchFor(ObjectStorage $storage): ?Manager
    {
        if ([] === $this->searchEngines) {
            return null;
        }

        $id = spl_object_id($storage);
        if (!isset($this->searches[$id])) {
            $this->searches[$id] = new Manager($storage, $this->searchEngines);
            $this->searches[$id]->attach();
        }

        return $this->searches[$id];
    }

    /**
     * The unique indexes of a storage, attached so every write in the viewer is checked against them.
     */
    public function uniqueFor(ObjectStorage $storage): UniqueManager
    {
        $id = spl_object_id($storage);
        if (!isset($this->uniques[$id])) {
            $this->uniques[$id] = new UniqueManager($storage);
            $this->uniques[$id]->attach();
        }

        return $this->uniques[$id];
    }

    /**
     * The fields of a search index form with the unique indexes on their paths: flag and case handling.
     */
    public function withUniqueFlags(array $fields, string $className, ObjectStorage $storage): array
    {
        $unique = '' === $className ? [] : (new UniquePathIndexes($this->uniqueFor($storage)))->forClass($className);
        return array_map(static function (array $field) use ($unique): array {
            $index = $unique[$field['path'] ?? ''] ?? null;
            return $field + [
                    'unique' => null !== $index,
                    'uniqueIgnoreCase' => $index?->caseInsensitive ?? false,
                    'uniqueName' => $index?->name,
                ];
        }, $fields);
    }

    public function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @throws RuntimeException If the request isn't a POST request.
     */
    public function requirePost(): void
    {
        if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
            throw new RuntimeException('This action requires a POST request.');
        }
    }
}
