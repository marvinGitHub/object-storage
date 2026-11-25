<?php

namespace melia\ObjectStorage\Search\Index;

use melia\ObjectStorage\File\Directory;
use melia\ObjectStorage\Logger\LoggerAwareInterface;
use melia\ObjectStorage\Search\Engine\EngineIndexInterface;
use melia\ObjectStorage\Search\Engine\EngineInterface;
use melia\ObjectStorage\Search\Exception\SearchException;
use Throwable;

/**
 * The generations of a search index: the data of the engine, one directory per generation (data-<n>/) in the
 * directory of the index, with the definition it was built with. Building writes a new generation, so searches keep
 * using the previous one until the build is complete.
 *
 * Opened generations are kept open until closed.
 *
 * @internal part of Index
 */
final class Generations
{
    private const string DIR_PREFIX = 'data-';
    private const string FILE_DEFINITION = 'definition.json';

    /** @var array<int, EngineIndexInterface> open generations */
    private array $handles = [];

    /**
     * @param string $directory The directory of the index.
     * @param string $name The name of the index, for messages.
     * @param EngineInterface $engine The engine of the index.
     * @param LoggerAwareInterface $logs Where failures to remove data are logged, as it is then.
     */
    public function __construct(
        private readonly string               $directory,
        private readonly string               $name,
        private readonly EngineInterface      $engine,
        private readonly LoggerAwareInterface $logs,
    )
    {
    }

    /**
     * @return int[] The generations present on disk, in ascending order.
     */
    public function list(): array
    {
        $generations = [];
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . self::DIR_PREFIX . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $suffix = substr(basename($dir), strlen(self::DIR_PREFIX));
            if (ctype_digit($suffix)) {
                $generations[] = (int)$suffix;
            }
        }
        sort($generations);
        return $generations;
    }

    /**
     * Creates the directory of a new generation, after the active one and all on disk, with the definition it is
     * built with.
     *
     * @param int|null $active The active generation.
     * @return int The new generation.
     * @throws SearchException
     */
    public function create(?int $active, Definition $definition): int
    {
        $generation = max([$active ?? 0, ...$this->list()]) + 1;
        $path = $this->path($generation);

        Directory::remove($path);
        if (!@mkdir($path, 0777, true) && !is_dir($path)) {
            throw new SearchException(sprintf('Unable to create directory %s.', $path));
        }

        $definitionPath = $this->definitionPath($generation);
        $json = json_encode($definition->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false === $json || false === @file_put_contents($definitionPath, $json)) {
            throw new SearchException(sprintf('Unable to write %s.', $definitionPath));
        }

        return $generation;
    }

    /**
     * The definition a generation was built with; kept with its data, as the current one may differ.
     *
     * @throws SearchException
     */
    public function definition(int $generation): Definition
    {
        $path = $this->definitionPath($generation);
        $content = is_file($path) ? @file_get_contents($path) : false;

        try {
            $data = false === $content ? null : json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new SearchException(sprintf('The data of search index "%s" is missing (generation %u).', $this->name, $generation));
            }
            return Definition::fromArray($data);
        } catch (SearchException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SearchException(message: sprintf('Invalid definition of search index "%s" (generation %u).', $this->name, $generation), previous: $e);
        }
    }

    /**
     * The data of a generation, opened once.
     *
     * @throws SearchException
     */
    public function open(int $generation): EngineIndexInterface
    {
        if (!isset($this->handles[$generation])) {
            try {
                $this->handles[$generation] = $this->engine->open($this->path($generation), $this->definition($generation));
            } catch (SearchException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new SearchException(message: sprintf('Unable to open search index "%s": %s', $this->name, $e->getMessage()), previous: $e);
            }
        }

        return $this->handles[$generation];
    }

    public function close(int $generation): void
    {
        if (isset($this->handles[$generation])) {
            $this->handles[$generation]->close();
            unset($this->handles[$generation]);
        }
    }

    public function closeAll(): void
    {
        foreach (array_keys($this->handles) as $generation) {
            $this->close($generation);
        }
    }

    /**
     * Removes a generation: closes it, lets the engine remove its data and removes its directory.
     */
    public function remove(int $generation): void
    {
        $this->close($generation);
        $this->removeEngineData($generation);
        Directory::remove($this->path($generation));
    }

    /**
     * Removes all generations except one.
     */
    public function removeAllBut(int $keep): void
    {
        // data still open by another process is removed next time
        foreach ($this->list() as $generation) {
            if ($generation !== $keep) {
                $this->remove($generation);
            }
        }
    }

    /**
     * Lets the engine remove the data of all generations, before the directory of the index is removed.
     */
    public function removeAllEngineData(): void
    {
        $this->closeAll();
        foreach ($this->list() as $generation) {
            $this->removeEngineData($generation);
        }
    }

    /**
     * Lets the engine remove the data of a generation; a failure is only logged.
     */
    private function removeEngineData(int $generation): void
    {
        try {
            $this->engine->remove($this->path($generation), $this->definition($generation));
        } catch (Throwable $e) {
            $this->logs->getLogger()?->log($e);
        }
    }

    private function path(int $generation): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . self::DIR_PREFIX . $generation;
    }

    private function definitionPath(int $generation): string
    {
        return $this->path($generation) . DIRECTORY_SEPARATOR . self::FILE_DEFINITION;
    }
}
