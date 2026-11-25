<?php

namespace melia\ObjectStorage\Search\Index;

use melia\ObjectStorage\Search\Engine\EngineInterface;
use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Search\Exception\UnknownPathException;
use melia\ObjectStorage\Search\Request;

/**
 * Fields named by their paths: a search may name a field by its path instead of its name, in the filter, the sort,
 * the facets and the fields searched, e.g. "friend.name = 'Homa'" for a field "friendName" with the path
 * "friend.name". A path also leads into referenced objects (see "Joins" in docs/search.md), but only to values
 * indexed: a path that isn't the path of a field fails.
 *
 * @internal part of Index
 */
final class PathResolver
{
    /**
     * @param EngineInterface $engine The engine, which reads and renames the attributes of its filters.
     * @param string $name The name of the index, for messages.
     */
    public function __construct(
        private readonly EngineInterface $engine,
        private readonly string          $name,
    )
    {
    }

    /**
     * @return Request The request with field names; the same request if it names no field by its path.
     * @throws UnknownPathException If a path isn't the path of a field.
     * @throws SearchException If the filter isn't valid.
     */
    public function resolve(Request $request, Definition $definition): Request
    {
        // names come first: a path that is also the name of a field names that field
        $fieldNames = array_fill_keys(array_map(static fn($field): string => $field->name, $definition->fields), true);
        $names = [];
        foreach ($definition->fields as $field) {
            if ($field->path !== $field->name && !isset($fieldNames[$field->path])) {
                $names[$field->path] ??= $field->name;
            }
        }

        $unknown = [];
        $rename = static function (string $attribute) use ($names, &$unknown): string {
            if (str_contains($attribute, '.') && !isset($names[$attribute])) {
                $unknown[$attribute] = true;
            }
            return $names[$attribute] ?? $attribute;
        };

        $filter = $request->filter;
        if (null !== $filter && '' !== trim($filter)) {
            array_map($rename, $this->engine->filterAttributes($filter));
            if ([] !== $names) {
                $filter = $this->engine->renameFilterAttributes($filter, $names);
            }
        }
        $sort = array_map(static function (string $criterion) use ($rename): string {
            $at = strrpos($criterion, ':');
            return false === $at ? $rename($criterion) : $rename(substr($criterion, 0, $at)) . substr($criterion, $at);
        }, $request->sort);
        $facets = array_map($rename, $request->facets);
        $searchOn = array_map($rename, $request->searchOn);

        if ([] !== $unknown) {
            throw new UnknownPathException(sprintf(
                'Search index "%s" has no field with the path %s: add a field with the path and rebuild the index.',
                $this->name,
                implode(', ', array_map(static fn(string $path): string => '"' . $path . '"', array_keys($unknown)))
            ));
        }

        if ($filter === $request->filter && $sort === $request->sort && $facets === $request->facets && $searchOn === $request->searchOn) {
            return $request;
        }

        return new Request(
            query: $request->query,
            filter: $filter,
            sort: $sort,
            facets: $facets,
            page: $request->page,
            hitsPerPage: $request->hitsPerPage,
            highlight: $request->highlight,
            highlightStartTag: $request->highlightStartTag,
            highlightEndTag: $request->highlightEndTag,
            searchOn: $searchOn,
        );
    }
}
