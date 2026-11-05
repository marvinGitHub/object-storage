<?php

namespace melia\ObjectStorage\Unique;

use melia\ObjectStorage\Unique\Exception\UniqueIndexException;
use Throwable;

/**
 * Unique indexes on single paths, managed per path, e.g. from a "unique" flag on the fields of a form: which paths
 * of a class are unique, and making exactly the chosen ones unique.
 *
 *   $paths = new PathIndexes($unique);
 *   $paths->forClass(Customer::class);  // ['email' => Definition, ...]
 *   $paths->apply(Customer::class, ['email' => ['unique' => true, 'caseInsensitive' => true, 'name' => 'email']], 'customers');
 */
final readonly class PathIndexes
{
    public function __construct(private Manager $manager)
    {
    }

    /**
     * The unique indexes of a class on a single path, whatever their names.
     *
     * @return array<string, Definition> path => index
     */
    public function forClass(string $className): array
    {
        $indexes = [];
        foreach ($this->manager->getIndexesForClass($className) as $definition) {
            if (1 === count($definition->paths)) {
                $indexes[$definition->paths[0]] = $definition;
            }
        }
        return $indexes;
    }

    /**
     * Makes the given paths unique or not: creates the missing indexes (named "<prefix>-<name>"), drops those of
     * paths no longer unique, and recreates those whose case handling changed. Paths not given are left alone.
     * If an index can't be created, e.g. because objects share values, the indexes created by this call are
     * dropped again, the ones it dropped are created again, and the exception is passed on.
     *
     * @param string $className The class of the objects.
     * @param array<string, array{unique: bool, caseInsensitive?: bool, name?: string}> $paths path => wanted
     *        state; name is used for the name of a new index (default: the path).
     * @param string $prefix The beginning of the names of new indexes, e.g. the name of a search index.
     * @return array{created: string[], dropped: string[]} The names of the indexes created and dropped.
     * @throws UniqueIndexException
     */
    public function apply(string $className, array $paths, string $prefix): array
    {
        $existing = $this->forClass($className);
        $create = [];
        $drop = [];

        foreach ($paths as $path => $wanted) {
            $path = (string)$path;
            $current = $existing[$path] ?? null;
            $caseInsensitive = (bool)($wanted['caseInsensitive'] ?? false);

            if (!($wanted['unique'] ?? false)) {
                if (null !== $current) {
                    $drop[] = $current;
                }
                continue;
            }
            if (null !== $current && $current->caseInsensitive === $caseInsensitive) {
                continue; // as wanted
            }
            if (null !== $current) {
                $drop[] = $current; // the case handling changed: replaced below
            }
            $create[] = new Definition($this->nameFor($prefix, (string)($wanted['name'] ?? $path), $current?->name), $className, $path, $caseInsensitive);
        }

        // dropped first, so a replaced index can keep its name; undone if a creation fails
        $dropped = [];
        $created = [];
        try {
            foreach ($drop as $definition) {
                $this->manager->drop($definition->name);
                $dropped[] = $definition;
            }
            foreach ($create as $definition) {
                $this->manager->create($definition);
                $created[] = $definition;
            }
        } catch (Throwable $e) {
            foreach ($created as $definition) {
                $this->manager->drop($definition->name);
            }
            foreach ($dropped as $definition) {
                try {
                    $this->manager->create($definition);
                } catch (Throwable) {
                    // its values may be duplicated meanwhile; the failure of the call is what matters
                }
            }
            throw $e instanceof UniqueIndexException ? $e : new UniqueIndexException(message: $e->getMessage(), previous: $e);
        }

        return [
            'created' => array_map(static fn(Definition $d): string => $d->name, $created),
            'dropped' => array_map(static fn(Definition $d): string => $d->name, $dropped),
        ];
    }

    /**
     * A free name "<prefix>-<name>" as unique index names allow it; a replaced index keeps its name.
     */
    private function nameFor(string $prefix, string $name, ?string $replaced): string
    {
        if (null !== $replaced) {
            return $replaced;
        }
        $base = trim(preg_replace('/[^a-z0-9_-]+/', '-', strtolower($prefix . '-' . $name)) ?? '', '-_');
        $base = substr('' === $base ? 'unique' : $base, 0, 60);
        $candidate = $base;
        for ($i = 2; $this->manager->has($candidate); $i++) {
            $candidate = $base . '-' . $i;
        }
        return $candidate;
    }
}
