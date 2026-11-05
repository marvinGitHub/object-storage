<?php

namespace Tests\melia\ObjectStorage\Search\Loupe;

use Loupe\Loupe\LoupeFactory;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Index;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Search\Loupe\LoupeEngine;
use melia\ObjectStorage\Search\Request;
use stdClass;
use Tests\melia\ObjectStorage\TestCase;

/**
 * The filters the query builder of the viewer writes find exactly the documents they describe: the filters of
 * tests/fixtures/query-builder.json (random trees of conditions and groups, written by assets/query-builder.js, see
 * tests/js) are searched with Loupe and compared with an evaluation of their trees here, independent of the engine.
 * This covers NOT, LIKE and CONTAINS of the Loupe engine, paths into referenced objects and the quoting of values.
 *
 * The semantics of Loupe this relies on: = and IN are exact (case-sensitive) and false for missing values, != and
 * NOT IN true for them; comparisons and BETWEEN are false for missing values; IS EMPTY is true for '' and empty
 * lists; LIKE and CONTAINS ignore case. NOT is the complement of what it negates.
 */
class QueryBuilderFiltersTest extends TestCase
{
    private static array $fixture;

    /** @var array<string, string> UUID => id of the document */
    private array $ids = [];

    /** @var array<string, array> id => document */
    private array $documents = [];

    /** @var array<string, array> name and path => field */
    private array $fields = [];

    private Manager $search;

    public static function setUpBeforeClass(): void
    {
        // another fixture, e.g. a larger one: QUERY_BUILDER_FIXTURE=/path/to.json (see tests/js/generate-fixtures.js)
        $file = getenv('QUERY_BUILDER_FIXTURE') ?: __DIR__ . '/../../../../fixtures/query-builder.json';
        self::$fixture = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(LoupeFactory::class) || !extension_loaded('intl')) {
            $this->markTestSkipped('Loupe needs loupe/loupe and ext-intl');
        }
        $this->search = new Manager($this->storage, [new LoupeEngine()]);
    }

    public function tearDown(): void
    {
        $this->search->detach();
        parent::tearDown();
    }

    private function createIndex(): Index
    {
        $objects = [];
        foreach (self::$fixture['documents'] as $document) {
            $this->documents[$document['id']] = $document;
            $object = new stdClass();
            foreach ($document as $key => $value) {
                if ('friend' !== $key) {
                    $object->$key = $value;
                }
            }
            $objects[$document['id']] = $object;
        }
        // friends: references to the objects
        foreach (self::$fixture['documents'] as $document) {
            if (isset($document['friend'])) {
                $objects[$document['id']]->friend = $objects[$document['friend']];
            }
        }
        foreach ($objects as $id => $object) {
            $this->ids[$this->storage->store($object)] = $id;
        }

        $fields = [];
        foreach (self::$fixture['fields'] as $field) {
            $this->fields[$field['name']] = $field;
            $this->fields[$field['path']] ??= $field;
            $fields[] = new Field($field['name'], $field['path'], FieldType::from($field['type']), $field['searchable'], $field['filterable'], $field['sortable']);
        }
        $index = $this->search->create(new Definition('people', stdClass::class, $fields));
        $index->build();
        return $index;
    }

    public function testTheFixtureIsComplete(): void
    {
        $this->assertGreaterThanOrEqual(500, count(self::$fixture['filters']));
        // every operator of every type, by name and by path, negated groups and nesting
        $seen = [];
        $walk = function (array $node, int $depth) use (&$walk, &$seen): void {
            if ('condition' === $node['kind']) {
                $field = null;
                foreach (self::$fixture['fields'] as $candidate) {
                    if ($candidate['name'] === $node['field'] || $candidate['path'] === $node['field']) {
                        $field = $candidate;
                    }
                }
                $seen[$field['type'] . ' ' . $node['op']] = true;
                $seen[str_contains($node['field'], '.') ? 'by path' : 'by name'] = true;
                return;
            }
            $seen[$node['combinator']] = true;
            if ($node['negated']) {
                $seen['NOT'] = true;
            }
            $seen['depth ' . $depth] = true;
            foreach ($node['children'] as $child) {
                $walk($child, $depth + 1);
            }
        };
        foreach (self::$fixture['filters'] as $filter) {
            $walk($filter['tree'], 0);
        }
        $operators = [
            'string' => ['=', '!=', 'CONTAINS', 'NOT CONTAINS', 'STARTS WITH', 'ENDS WITH', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL', 'IS EMPTY', 'IS NOT EMPTY'],
            'number' => ['=', '!=', '>', '>=', '<', '<=', 'BETWEEN', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL'],
            'boolean' => ['=', '!=', 'IS NULL', 'IS NOT NULL'],
            'array<string>' => ['IN', 'NOT IN', 'CONTAINS', 'NOT CONTAINS', 'LIKE', 'NOT LIKE', 'IS EMPTY', 'IS NOT EMPTY'],
        ];
        foreach ($operators as $type => $ops) {
            foreach ($ops as $op) {
                $this->assertArrayHasKey("$type $op", $seen);
            }
        }
        foreach (['by path', 'by name', 'AND', 'OR', 'NOT', 'depth 3'] as $key) {
            $this->assertArrayHasKey($key, $seen);
        }
    }

    public function testTheFiltersOfTheBuilderFindWhatTheyDescribe(): void
    {
        $index = $this->createIndex();
        $all = array_keys($this->documents);
        sort($all);

        $failures = [];
        $empty = $everything = 0;
        foreach (self::$fixture['filters'] as $number => $filter) {
            $expected = array_values(array_filter($all, fn(string $id): bool => $this->evaluate($filter['tree'], $this->documents[$id])));
            try {
                $uuids = $index->search(new Request(filter: $filter['filter'], hitsPerPage: 1000))->getUuids();
                $found = array_map(fn(string $uuid): string => $this->ids[$uuid], $uuids);
                sort($found);
            } catch (\Throwable $e) {
                $failures[] = sprintf("#%d %s\n  error: %s", $number, $filter['filter'], $e->getMessage());
                continue;
            }
            if ($found !== $expected) {
                $failures[] = sprintf("#%d %s\n  missing: %s\n  too many: %s", $number, $filter['filter'],
                    implode(', ', array_diff($expected, $found)) ?: '-', implode(', ', array_diff($found, $expected)) ?: '-');
            }
            $empty += [] === $expected ? 1 : 0;
            $everything += $expected === $all ? 1 : 0;
        }

        $this->assertSame([], array_slice($failures, 0, 15), sprintf('%d of %d filters found other documents', count($failures), count(self::$fixture['filters'])));
        // the filters tell documents apart: not (nearly) all of them match nothing or everything
        $this->assertLessThan(count(self::$fixture['filters']) / 2, $empty + $everything, 'the fixture distinguishes documents');
    }

    // ---- the reference: what a tree means ----

    private function evaluate(array $node, array $document): bool
    {
        if ('condition' === $node['kind']) {
            return $this->condition($node, $document);
        }
        $results = array_map(fn(array $child): bool => $this->evaluate($child, $document), $node['children']);
        $result = 'AND' === $node['combinator'] ? !in_array(false, $results, true) : in_array(true, $results, true);
        return $node['negated'] ? !$result : $result;
    }

    /** the value of a field of a document: null if missing, also a path through a missing friend */
    private function valueOf(array $field, array $document): mixed
    {
        $value = $document;
        foreach (explode('.', $field['path']) as $segment) {
            if ('friend' === $segment) {
                $value = isset($value['friend']) ? $this->documents[$value['friend']] : null;
            } else {
                $value = is_array($value) ? ($value[$segment] ?? null) : null;
            }
        }
        return $value;
    }

    private function condition(array $condition, array $document): bool
    {
        $field = $this->fields[trim($condition['field'])] ?? $this->fail('unknown field ' . $condition['field']);
        $type = $field['type'];
        $value = $this->valueOf($field, $document);
        $op = $condition['op'];
        $text = $condition['value'];
        $list = static fn(string $text): array => array_values(array_filter(array_map('trim', explode(',', $text)), static fn(string $v): bool => '' !== $v));
        $isArray = str_starts_with($type, 'array');
        // a value of the condition as the type of the field
        $typed = static fn(string $text): mixed => match ($type) {
            'number', 'array<number>' => (float)trim($text),
            'boolean' => 'true' === $text,
            default => $text,
        };
        $equals = static fn(mixed $a, mixed $b): bool => is_float($b) ? (is_int($a) || is_float($a)) && (float)$a === $b : $a === $b;
        $lower = static fn(string $text): string => mb_strtolower($text, 'UTF-8');
        // any value of a list, the value itself otherwise; none if missing
        $values = null === $value ? [] : ($isArray ? $value : [$value]);
        $any = static fn(callable $test): bool => [] !== array_filter($values, $test);

        return match ($op) {
            '=' => $any(fn($v) => $equals($v, $typed($text))),
            '!=' => !$any(fn($v) => $equals($v, $typed($text))),
            '>' => $any(fn($v) => $v > $typed($text)),
            '>=' => $any(fn($v) => $v >= $typed($text)),
            '<' => $any(fn($v) => $v < $typed($text)),
            '<=' => $any(fn($v) => $v <= $typed($text)),
            'BETWEEN' => (function () use ($any, $list, $text, $typed): bool {
                [$from, $to] = array_map($typed, $list($text));
                return $any(fn($v) => $v >= $from && $v <= $to);
            })(),
            'IN', 'NOT IN' => ('IN' === $op) === $any(function ($v) use ($list, $text, $typed, $equals): bool {
                foreach ($list($text) as $item) {
                    if ($equals($v, $typed($item))) {
                        return true;
                    }
                }
                return false;
            }),
            'IS NULL' => null === $value,
            'IS NOT NULL' => null !== $value,
            'IS EMPTY', 'IS NOT EMPTY' => ('IS EMPTY' === $op) === ($isArray ? null !== $value && [] === $value : '' === $value),
            'CONTAINS', 'NOT CONTAINS' => ('CONTAINS' === $op) === $any(fn($v) => str_contains($lower($v), $lower($text))),
            'STARTS WITH' => $any(fn($v) => str_starts_with($lower($v), $lower($text))),
            'ENDS WITH' => $any(fn($v) => str_ends_with($lower($v), $lower($text))),
            'LIKE', 'NOT LIKE' => ('LIKE' === $op) === $any(fn($v) => static::like($lower($v), $lower($text))),
            default => $this->fail('unknown operator ' . $op),
        };
    }

    /**
     * LIKE, independent of the engine: % any characters, _ one, \ takes the next character as it is.
     */
    private static function like(string $value, string $pattern): bool
    {
        $characters = mb_str_split($pattern);
        $text = mb_str_split($value);
        // dynamic programming over the positions in the pattern and the value
        $matching = [0 => true];
        $n = count($text);
        $tokens = [];
        for ($i = 0; $i < count($characters); $i++) {
            if ('\\' === $characters[$i] && $i + 1 < count($characters)) {
                $tokens[] = ['literal', $characters[++$i]];
            } else {
                $tokens[] = match ($characters[$i]) {
                    '%' => ['any'],
                    '_' => ['one'],
                    default => ['literal', $characters[$i]],
                };
            }
        }
        $current = array_fill(0, $n + 1, false);
        $current[0] = true;
        foreach ($tokens as $token) {
            $next = array_fill(0, $n + 1, false);
            for ($j = 0; $j <= $n; $j++) {
                if (!$current[$j]) {
                    continue;
                }
                if ('any' === $token[0]) {
                    for ($k = $j; $k <= $n; $k++) {
                        $next[$k] = true;
                    }
                } elseif ($j < $n && ('one' === $token[0] || $text[$j] === $token[1])) {
                    $next[$j + 1] = true;
                }
            }
            $current = $next;
        }
        return $current[$n];
    }
}
