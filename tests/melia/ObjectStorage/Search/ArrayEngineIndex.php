<?php

namespace Tests\melia\ObjectStorage\Search;

use melia\ObjectStorage\Search\Engine\EngineIndexInterface;
use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Search\Exception\UnsupportedQueryException;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Hit;
use melia\ObjectStorage\Search\Request;
use melia\ObjectStorage\Search\Result;

final class ArrayEngineIndex implements EngineIndexInterface
{
    public function __construct(
        private readonly string          $file,
        private readonly Definition $definition,
        private readonly mixed           $reject,
    )
    {
    }

    /**
     * @return array<string, array>
     */
    private function read(): array
    {
        return is_file($this->file) ? json_decode((string)file_get_contents($this->file), true) : [];
    }

    private function write(array $documents): void
    {
        file_put_contents($this->file, json_encode($documents));
    }

    public function upsert(array $documents): array
    {
        $stored = $this->read();
        $failures = [];

        foreach ($documents as $uuid => $document) {
            $error = null === $this->reject ? null : ($this->reject)($document);
            if (null !== $error) {
                $failures[$uuid] = $error;
                continue;
            }
            $stored[$uuid] = $document;
        }

        $this->write($stored);
        return $failures;
    }

    public function delete(array $uuids): void
    {
        $this->write(array_diff_key($this->read(), array_flip($uuids)));
    }

    public function count(): int
    {
        return count($this->read());
    }

    public function search(object $request): Result
    {
        if (!$request instanceof Request) {
            throw new UnsupportedQueryException('The array engine only accepts ' . Request::class);
        }

        $searchable = $this->definition->fieldNames(static fn(Field $field): bool => $field->searchable);
        $hits = [];

        foreach ($this->read() as $uuid => $document) {
            // full text: every word is contained in a searchable field
            foreach (preg_split('/\s+/', strtolower(trim($request->query)), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $text = strtolower(implode(' ', array_map(static fn(string $name): string => is_array($document[$name] ?? null) ? implode(' ', $document[$name]) : (string)($document[$name] ?? ''), $searchable)));
                if (!str_contains($text, $word)) {
                    continue 2;
                }
            }

            // filter: "field = value"
            if (null !== $request->filter) {
                if (!preg_match('/^(\w+)\s*=\s*\'(.*)\'$/', trim($request->filter), $match)) {
                    throw new SearchException('Invalid filter: ' . $request->filter);
                }
                if ((string)($document[$match[1]] ?? '') !== $match[2]) {
                    continue;
                }
            }

            $hits[$uuid] = $document;
        }

        foreach (array_reverse($request->sort) as $criterion) {
            [$field, $direction] = explode(':', $criterion);
            uasort($hits, static fn(array $a, array $b): int => ('desc' === $direction ? -1 : 1) * (($a[$field] ?? null) <=> ($b[$field] ?? null)));
        }

        $facets = [];
        foreach ($request->facets as $facet) {
            foreach ($hits as $document) {
                foreach ((array)($document[$facet] ?? []) as $value) {
                    $facets[$facet][(string)$value] = ($facets[$facet][(string)$value] ?? 0) + 1;
                }
            }
        }

        $total = count($hits);
        $page = array_slice($hits, ($request->page - 1) * $request->hitsPerPage, $request->hitsPerPage, true);

        return new Result(
            hits: array_map(static function (string $uuid, array $document): Hit {
                unset($document['uuid']);
                return new Hit($uuid, $document);
            }, array_keys($page), array_values($page)),
            totalHits: $total,
            page: $request->page,
            hitsPerPage: $request->hitsPerPage,
            totalPages: max(1, (int)ceil($total / max(1, $request->hitsPerPage))),
            facets: $facets,
        );
    }

    public function close(): void
    {
    }
}
