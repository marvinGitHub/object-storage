<?php

namespace Tests\melia\ObjectStorage\Search\Loupe;

use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\SearchResult as LoupeSearchResult;
use melia\ObjectStorage\File\Directory;
use melia\ObjectStorage\Search\Exception\InvalidIndexDefinitionException;
use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Search\Exception\UnknownPathException;
use melia\ObjectStorage\Search\Exception\UnsupportedQueryException;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Index;
use melia\ObjectStorage\Search\Index\IndexInterface;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Search\Loupe\LoupeEngine;
use melia\ObjectStorage\Search\PatternIndex;
use melia\ObjectStorage\Search\Request;
use stdClass;
use Tests\melia\ObjectStorage\Search\Category;
use Tests\melia\ObjectStorage\Search\Product;
use Tests\melia\ObjectStorage\TestCase;

/**
 * The Loupe engine: translating requests and results, its filter language, types and features.
 */
class LoupeEngineTest extends TestCase
{
    private Manager $search;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('intl') || !class_exists(\Loupe\Loupe\Loupe::class)) {
            $this->markTestSkipped('Loupe needs loupe/loupe and ext-intl');
        }
        $this->search = new Manager($this->storage, [new LoupeEngine()]);
    }

    public function tearDown(): void
    {
        if (isset($this->search)) {
            $this->search->detach();
            foreach ($this->search->list() as $index) {
                $index->close();
            }
            unset($this->search);
            gc_collect_cycles();
        }
        parent::tearDown();
    }

    private function createIndex(): Index
    {
        return $this->search->create(new Definition('products', Product::class, [
            new Field('name', 'name'),
            new Field('description', 'description'),
            new Field('price', 'price', FieldType::NUMBER, searchable: false, filterable: true, sortable: true),
            new Field('tags', 'tags', FieldType::STRING_ARRAY, filterable: true),
            new Field('category', 'category', FieldType::STRING, searchable: false, filterable: true),
            new Field('active', 'active', FieldType::BOOLEAN, searchable: false, filterable: true),
        ], languages: ['en']));
    }

    /**
     * @return string[]
     */
    private function find(IndexInterface $index, string $query = '', ?string $filter = null, array $sort = []): array
    {
        return $index->search(new Request(query: $query, filter: $filter, sort: $sort, hitsPerPage: 100))->getUuids();
    }

    public function testDataIsKeptInTheLoupeDirectory(): void
    {
        $index = $this->createIndex();
        $index->build();

        $this->assertSame('loupe', $index->getEngineName());
        $this->assertSame('loupe', $index->getDefinition()->engine);
        $this->assertFileExists($index->getDirectory() . DIRECTORY_SEPARATOR . 'data-1' . DIRECTORY_SEPARATOR . 'loupe.db');
        $this->assertStringEndsWith('indexes' . DIRECTORY_SEPARATOR . 'loupe' . DIRECTORY_SEPARATOR . 'products', $index->getDirectory());
    }

    public function testSizeIsTheSpaceOfTheIndexOnDisk(): void
    {
        $index = $this->createIndex();
        $this->assertSame(0, $index->getSize(), 'nothing on disk before the first build');

        $this->storage->store(new Product('Apple', str_repeat('A crunchy red fruit. ', 50)));
        $index->build();
        $size = $index->getSize();

        $this->assertGreaterThan(0, $size);
        $this->assertSame(Directory::size($index->getDirectory()), $size);
    }

    public function testFiltersSortingAndFacetsInLoupesLanguage(): void
    {
        $fruit = new Category('Fruit');
        $apple = $this->storage->store(new Product('Apple', 'A crunchy red fruit', 1.5, ['red', 'sweet'], $fruit));
        $banana = $this->storage->store(new Product('Banana', 'A yellow fruit', 0.99, ['yellow', 'sweet'], $fruit));
        $chair = $this->storage->store(new Product('Chair', 'Wooden furniture', 49, ['wood'], new Category('Furniture'), false));

        $index = $this->createIndex();
        $index->build();

        $this->assertSame([$banana], $this->find($index, 'yellow'));
        $this->assertSame([$apple, $banana], $this->find($index, 'fruit', sort: ['price:desc']));
        $this->assertSame([$banana, $apple], $this->find($index, '', 'price < 10', ['price:asc']));
        $this->assertSame([$banana, $apple], $this->find($index, '', 'price BETWEEN 0.5 AND 2', ['price:asc']));
        $this->assertEqualsCanonicalizing([$apple, $banana], $this->find($index, '', "tags IN ('sweet')"));
        $this->assertSame([$chair], $this->find($index, '', 'active = false'));
        $this->assertEqualsCanonicalizing([$apple, $chair], $this->find($index, '', sprintf("uuid IN ('%s', '%s')", $apple, $chair)));

        $fruitUuid = json_decode(file_get_contents($this->storage->getFilePathData($apple)), true)['category']['__reference'];
        $this->assertEqualsCanonicalizing([$apple, $banana], $this->find($index, '', sprintf("category = '%s'", $fruitUuid)));

        $result = $index->search(new Request(facets: ['tags', 'active'], hitsPerPage: 0));
        $this->assertSame(['red' => 1, 'sweet' => 2, 'wood' => 1, 'yellow' => 1], $result->facets['tags']);
        $this->assertSame(['false' => 1, 'true' => 2], $result->facets['active']);
    }

    public function testValuesAreConvertedToTheDeclaredType(): void
    {
        $string = $this->storage->store(new Product('String price', price: '12.5'));
        $int = $this->storage->store(new Product('Int price', price: 10));
        $invalid = $this->storage->store(new Product('Invalid price', price: 'expensive'));

        $index = $this->createIndex();
        $progress = $index->build();

        $this->assertSame(0, $progress['failed']);
        $this->assertSame([$int, $string], $this->find($index, '', 'price > 5', ['price:asc']));
        $this->assertSame([$invalid], $this->find($index, '', 'price IS NULL'));
    }

    public function testTypoToleranceHighlightingAndScore(): void
    {
        $uuid = $this->storage->store(new Product('Apple', 'A crunchy red apple from the region'));
        $this->storage->store(new Product('Chair', 'Oak wood'));

        $index = $this->createIndex();
        $index->build();

        $result = $index->search(new Request(query: 'crunchy aple', highlight: true, highlightStartTag: '[', highlightEndTag: ']'));

        $this->assertSame(1, $result->totalHits);
        $hit = $result->hits[0];
        $this->assertSame($uuid, $hit->uuid);
        $this->assertSame('Apple', $hit->document['name']);
        $this->assertArrayNotHasKey('uuid', $hit->document);
        $this->assertSame('A [crunchy] red apple from the region', $hit->highlighted['description']);
        $this->assertGreaterThan(0, $hit->score);

        // sorted by a field, Loupe computes no relevance: no score rather than 0
        $sorted = $index->search(new Request(query: 'crunchy', sort: ['price:asc']));
        $this->assertNull($sorted->hits[0]->score);
        $this->assertInstanceOf(LoupeSearchResult::class, $result->raw, 'the result of Loupe is available');
    }

    public function testSyncRemovesObjectsExpiredSinceIndexing(): void
    {
        $permanent = $this->storage->store(new Product('Apple'));
        $expiring = $this->storage->store(new Product('Apricot'));
        $this->storage->setLifetime($expiring, 0.4);

        $index = $this->createIndex();
        $index->build();
        $this->assertSame(2, $index->countDocuments());
        usleep(600_000);

        // Loupe finds the expired documents by a filter on "expiresAt"
        $this->assertSame(1, $index->purgeExpired());
        $this->assertSame([$permanent], $this->find($index));
    }

    public function testFilterAttributesAreReadWithLoupesLexer(): void
    {
        $engine = new LoupeEngine();

        $this->assertSame(
            ['price', 'tags', 'name', 'active'],
            $engine->filterAttributes("price < 10 AND (tags IN ('price', 'name') OR name = 'O''Neill') AND active = true")
        );
        $this->assertSame(['price'], $engine->filterAttributes('price BETWEEN 1 AND 2'));
        $this->assertSame(['friend.friend.name', 'price'], $engine->filterAttributes("friend.friend.name = 'a.b' AND price > 1.5"), 'a path is one attribute');
    }

    public function testLikeAndContains(): void
    {
        $chair = $this->storage->store(new Product('Chair', 'Oak furniture', 49, ['wood', 'Living room']));
        $apple = $this->storage->store(new Product('Apple', 'Fresh fruit', 1, ['fruit', 'red']));
        $pine = $this->storage->store(new Product("O'Pine 50%", 'Pine', 20, ['wood']));
        $index = $this->search->create(new Definition('products', Product::class, [
            new Field('name', 'name', filterable: true),
            new Field('price', 'price', FieldType::NUMBER, searchable: false, filterable: true),
            new Field('tags', 'tags', FieldType::STRING_ARRAY, filterable: true),
            new Field('description', 'description'),
        ]));
        $index->build();

        $this->assertSame([$chair], $this->find($index, '', "name LIKE 'ch%'"), 'ignoring case');
        $this->assertSame([$apple], $this->find($index, '', "name LIKE '_pple'"));
        $this->assertEqualsCanonicalizing([$chair, $pine], $this->find($index, '', "name NOT LIKE 'A%'"));
        $this->assertSame([$pine], $this->find($index, '', "name LIKE 'o''pine 50\\%'"), 'a quote and an escaped %');
        $this->assertSame([$pine], $this->find($index, '', "name CONTAINS '50%'"), 'CONTAINS takes the text as it is');
        $this->assertSame([$chair], $this->find($index, '', "tags CONTAINS 'room' AND price > 10"), 'any value of a list');
        $this->assertEqualsCanonicalizing([$apple, $pine], $this->find($index, '', "tags NOT CONTAINS 'room'"));
        $this->assertSame([], $this->find($index, '', "name CONTAINS 'xyz'"), 'nothing matches');
        $this->assertCount(3, $this->find($index, '', "name NOT CONTAINS 'xyz'"));
        $this->assertSame(['name', 'price'], (new LoupeEngine())->filterAttributes("name LIKE 'a%' AND price > 1"), 'operators aren\'t attributes');

        // NOT, which Loupe lacks: rewritten into the negated conditions
        $this->assertEqualsCanonicalizing([$apple, $pine], $this->find($index, '', "NOT (name LIKE 'ch%')"));
        $this->assertSame([$apple], $this->find($index, '', "price < 30 AND NOT (tags IN ('wood') OR price > 10)"));
        $this->assertEqualsCanonicalizing([$chair, $apple], $this->find($index, '', "NOT (name CONTAINS 'pine' AND price BETWEEN 10 AND 30)"));

        $this->expectException(SearchException::class);
        $this->expectExceptionMessage('field of strings');
        $index->search(new Request(filter: "price LIKE '1%'"));
    }

    /**
     * Pattern indexes find the same as matching all distinct values, and their parts aren't part of the hits.
     */
    public function testPatternIndexesFindTheSame(): void
    {
        $values = ['Homa', 'homeland', 'Johnson', 'Jackson', 'Testing', 'contest', 'A test', 'tes', 'ÄPFEL', "O'Pine 50%", '', 'x, y', 'Ho'];
        foreach ($values as $i => $value) {
            $object = new stdClass();
            $object->s = $value;
            $object->tags = [$value, 'tag' . $i];
            $this->storage->store($object);
        }
        $this->storage->store(new stdClass()); // without values
        $fields = static fn(array $patterns): array => [
            new Field('s', 's', filterable: true, patterns: $patterns),
            new Field('tags', 'tags', FieldType::STRING_ARRAY, filterable: true, patterns: $patterns),
        ];
        $plain = $this->search->create(new Definition('plain', stdClass::class, $fields([])));
        $plain->build();
        $indexed = $this->search->create(new Definition('indexed', stdClass::class, $fields([PatternIndex::TRIE, PatternIndex::REVERSE_TRIE, PatternIndex::NGRAM])));
        $indexed->build();
        $ngrams = $this->search->create(new Definition('ngrams', stdClass::class, $fields([PatternIndex::NGRAM])));
        $ngrams->build();

        $filters = [
            "s LIKE 'ho%'", "s LIKE 'Ho%a'", "s LIKE '%son'", "s LIKE 'J%son'", "s LIKE '%test%'", "s LIKE '%tes%'",
            "s CONTAINS 'TEST'", "s LIKE '%te_t%'", "s LIKE '%äpf%'", "s LIKE 'o''pine 50\\%'", "s CONTAINS '50%'",
            "s LIKE 'ho'", "s LIKE '%'", "s LIKE '%x%'", "s CONTAINS ', '", "s NOT LIKE 'ho%'", "s NOT CONTAINS 'test'",
            "s NOT LIKE '%son'", "tags LIKE '%son'", "tags CONTAINS 'test'", "tags NOT LIKE 'ho%'", "tags LIKE 'tag1%'",
            "s LIKE '%nothing%'", "NOT (s LIKE '%test%' OR s LIKE 'ho%')",
        ];
        foreach ($filters as $filter) {
            $expected = $this->find($plain, '', $filter);
            sort($expected);
            foreach ([$indexed, $ngrams] as $index) {
                $found = $this->find($index, '', $filter);
                sort($found);
                $this->assertSame($expected, $found, "{$index->getName()}: $filter");
            }
        }

        $hit = $indexed->search(new Request(filter: "s LIKE 'homa'", hitsPerPage: 10))->hits[0];
        $this->assertSame(['revision', 'expiresAt', 's', 'tags', 'referencedUuids'], array_keys($hit->document), 'the parts aren\'t shown');
    }

    /**
     * What Loupe's filters don't do as expected (loupe/loupe 1.1), done by the engine: negative numbers, BETWEEN,
     * NOT, patterns over values with commas and the empty string, and filters after a failed one.
     */
    public function testFiltersBeyondWhatLoupeTakes(): void
    {
        $objects = [
            'neg' => ['n' => -7.5, 's' => 'x, y'],
            'one' => ['n' => 1, 's' => ''],
            'big' => ['n' => 100, 's' => 'Homa'],
            'none' => [],
        ];
        $uuids = [];
        foreach ($objects as $id => $values) {
            $object = (object)$values;
            $uuids[$id] = $this->storage->store($object);
        }
        $index = $this->search->create(new Definition('values', stdClass::class, [
            new Field('n', 'n', FieldType::NUMBER, searchable: false, filterable: true),
            new Field('s', 's', filterable: true),
        ]));
        $index->build();
        $find = function (string $filter) use ($index, $uuids): array {
            $found = array_map(static fn(string $uuid): string => array_search($uuid, $uuids, true), $this->find($index, '', $filter));
            sort($found);
            return $found;
        };

        // negative numbers
        $this->assertSame(['neg'], $find('n = -7.5'));
        $this->assertSame(['neg'], $find('n < -1'));
        $this->assertSame(['neg', 'one'], $find('n IN (-7.5, 1)'));
        $this->assertSame(['big', 'none', 'one'], $find('n NOT IN (-7.5)'));
        // BETWEEN as in SQL: in order, nothing for bounds in the wrong order; NOT BETWEEN includes missing values
        $this->assertSame(['neg', 'one'], $find('n BETWEEN -8 AND 1'));
        $this->assertSame([], $find('n BETWEEN 100 AND 1'));
        $this->assertSame(['big', 'none'], $find('n NOT BETWEEN -8 AND 1'));
        // NOT is the complement, also of comparisons, which are false for missing values
        $this->assertSame(['neg', 'none', 'one'], $find('NOT (n > 1)'));
        $this->assertSame(['big', 'none'], $find('NOT (n BETWEEN -8 AND 1)'));
        $this->assertSame(['none'], $find('NOT (n IS NOT NULL)'));
        // patterns: a value with a comma (which Loupe's facets split), the empty string
        $this->assertSame(['neg'], $find("s CONTAINS ', '"));
        $this->assertSame(['neg'], $find("s LIKE 'x%'"));
        $this->assertSame(['big', 'neg', 'one'], $find("s LIKE '%'"), "'' is LIKE '%', as in SQL");
        $this->assertSame(['none'], $find("s NOT LIKE '%'"));
        $this->assertSame(['big', 'none', 'one'], $find("s NOT CONTAINS ','"));

        // a filter Loupe fails on inside parentheses doesn't break the next ones
        try {
            $this->find($index, '', "(s = 'x' OR n = )");
            $this->fail('an invalid filter');
        } catch (SearchException) {
        }
        $this->assertSame(['big'], $find("(s = 'Homa' OR n = 1000)"));
        $this->assertSame(['big'], $find("s = 'Homa'"));
    }

    public function testFilterAttributesAreRenamed(): void
    {
        $engine = new LoupeEngine();

        $this->assertSame(
            "friendName = 'friend.name' AND (tags IN ('a') OR friend.friend.name IS NULL) AND friendName != 'x'",
            $engine->renameFilterAttributes(
                "friend.name = 'friend.name' AND (tags IN ('a') OR friend.friend.name IS NULL) AND friend.name != 'x'",
                ['friend.name' => 'friendName']
            ),
            'quoted values and other paths stay'
        );
    }

    public function testFieldsAreNamedByTheirPaths(): void
    {
        $friend = new stdClass();
        $friend->name = 'Homa';
        $other = new stdClass();
        $other->name = 'Bob';
        $friend->friend = $other;
        $anna = new stdClass();
        $anna->name = 'Anna';
        $anna->friend = $friend;
        $annaUuid = $this->storage->store($anna);
        $this->storage->store($other);

        $index = $this->search->create(new Definition('people', stdClass::class, [
            new Field('name', 'name', filterable: true),
            new Field('friendName', 'friend.name', filterable: true, sortable: true),
            new Field('friendOfFriend', 'friend.friend.name', filterable: true),
        ]));
        $index->build();

        $this->assertSame([$annaUuid], $this->find($index, '', "friend.name = 'Homa'"));
        $this->assertSame([$annaUuid], $this->find($index, '', "friend.friend.name = 'Bob' AND name = 'Anna'"), 'nested further');
        $result = $index->search(new Request(filter: "friend.name IS NOT NULL", sort: ['friend.name:asc'], facets: ['friend.name']));
        $this->assertEqualsCanonicalizing(['Homa' => 1, 'Bob' => 1], $result->facets['friendName'], 'counted under the name of the field; the friend of Homa is Bob');

        try {
            $index->search(new Request(filter: "friend.friend.friend.name = 'x'"));
            $this->fail('a path without a field');
        } catch (UnknownPathException $e) {
            $this->assertStringContainsString('"friend.friend.friend.name"', $e->getMessage());
        }

        $plan = $this->search->explain(stdClass::class, new Request(filter: "friend.name = 'Homa'"));
        $this->assertSame('people', $plan->getChosen()?->name);
        $plan = $this->search->explain(stdClass::class, new Request(sort: ['friend.age:asc']));
        $this->assertNull($plan->getChosen());
        $this->assertStringContainsString('no field with the path "friend.age"', $plan->candidates[0]->reasons[0]);
    }

    public function testClassSearchChoosesAnIndexWithLoupe(): void
    {
        $chair = $this->storage->store(new Product('Chair', 'Oak furniture', 49, ['wood']));
        $this->storage->store(new Product('Apple', 'Fresh fruit', 1, ['fruit']));
        $this->createIndex()->build(); // name, description, price (sortable), tags, category, active
        $this->search->create(new Definition('names', Product::class, [new Field('name', 'name')]))->build();

        $plan = $this->search->explain(Product::class, new Request('oak', filter: "tags IN ('wood')", sort: ['_relevance:desc', 'price:asc']));
        $this->assertSame('products', $plan->getChosen()->name);
        $this->assertSame(['not filterable: tags', 'not sortable: price'], $plan->candidates[1]->reasons);

        $this->assertSame([$chair], $this->search->search(Product::class, new Request('oak', filter: "tags IN ('wood')"))->getUuids());
    }

    public function testPaging(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->storage->store(new Product('Product ' . $i, price: $i));
        }
        $index = $this->createIndex();
        $index->build();

        $result = $index->search(new Request(sort: ['price:asc'], page: 2, hitsPerPage: 2));

        $this->assertSame(5, $result->totalHits);
        $this->assertSame(3, $result->totalPages);
        $this->assertSame(2, $result->page);
        $this->assertSame([3.0, 4.0], array_map(static fn($hit) => (float)$hit->document['price'], $result->hits));
    }

    public function testNativeSearchParametersAreAccepted(): void
    {
        $uuid = $this->storage->store(new Product('Apple', price: 2));
        $index = $this->createIndex();
        $index->build();

        $result = $index->search(SearchParameters::create()->withQuery('apple')->withAttributesToRetrieve(['uuid', 'price']));

        $this->assertSame([$uuid], $result->getUuids());
        $this->assertSame(['price'], array_keys($result->hits[0]->document));
    }

    public function testOtherQueryObjectsAreRejected(): void
    {
        $index = $this->createIndex();
        $index->build();

        $this->expectException(UnsupportedQueryException::class);
        $index->search(new stdClass());
    }

    public function testInvalidFilterFails(): void
    {
        // Loupe only parses the filter if there are documents
        $this->storage->store(new Product('Apple', tags: ['sweet']));
        $index = $this->createIndex();
        $index->build();

        $this->expectException(SearchException::class);
        $index->search(new Request(filter: "tags IN ['sweet']"));
    }

    public function testQuotesInFilterValues(): void
    {
        $uuid = $this->storage->store(new Product("O'Neill's Tea"));
        $index = $this->search->create(new Definition('teas', Product::class, [new Field('name', 'name', filterable: true)]));
        $index->build();

        $filter = sprintf("name = '%s'", str_replace("'", "''", "O'Neill's Tea"));
        $this->assertSame([$uuid], $this->find($index, '', $filter));
    }

    public function testConfigurationCanBeAdjusted(): void
    {
        $search = new Manager($this->storage, [new LoupeEngine(
            configure: static fn(\Loupe\Loupe\Configuration $configuration) => $configuration->withTypoTolerance(\Loupe\Loupe\Config\TypoTolerance::disabled())
        )]);
        $this->storage->store(new Product('Apple'));
        $index = $search->create(new Definition('strict', Product::class, [new Field('name', 'name')]));
        $index->build();

        $this->assertSame([], $index->search(new Request('aple'))->getUuids(), 'no typo tolerance');
        $this->assertCount(1, $index->search(new Request('apple'))->getUuids());
        $index->close();
    }

    public function testLoupeAcceptsGenericFieldNames(): void
    {
        // the generic field names are a subset of the attribute names Loupe accepts
        $definition = new Definition('names', Product::class, [
            new Field('a', 'name'),
            new Field('Name_2', 'name'),
            new Field(str_repeat('x', Field::MAX_NAME_LENGTH), 'name'),
        ]);

        (new LoupeEngine())->validate($definition);
        $this->assertSame('loupe', $this->search->create($definition)->getEngineName());
    }

    public function testPathsIntoReferencedObjectsAreUpdatedWhenTheyChange(): void
    {
        $fruit = new Category('Fruit');
        $apple = $this->storage->store(new Product('Apple', category: $fruit));
        $banana = $this->storage->store(new Product('Banana', category: $fruit));
        $chair = $this->storage->store(new Product('Chair', category: new Category('Furniture')));

        $index = $this->search->create(new Definition('products', Product::class, [
            new Field('name', 'name'),
            new Field('categoryTitle', 'category.title', filterable: true),
        ], languages: ['en']));
        $index->build();
        $this->search->attach();

        $this->assertEqualsCanonicalizing([$apple, $banana], $this->find($index, 'fruit'));
        $this->assertSame([$chair], $this->find($index, '', "categoryTitle = 'Furniture'"));

        $fruitUuid = json_decode(file_get_contents($this->storage->getFilePathData($apple)), true)['category']['__reference'];
        $handle = (fn() => $this->openGeneration($this->getState()->generation))->call($index);
        $this->assertEqualsCanonicalizing([$apple, $banana], $handle->referencing([$fruitUuid, 'unknown'], 10));
        $this->assertSame([], $handle->referencing([$fruitUuid], 10, 'x'), 'by the prefix of the UUID');
        $this->assertSame([$apple], $handle->referencing([$fruitUuid], 10, $apple));

        $category = $this->storage->load($fruitUuid);
        $category->title = "Produce's";
        $this->storage->store($category, $fruitUuid);

        $this->assertSame([], $this->find($index, 'fruit'));
        $this->assertEqualsCanonicalizing([$apple, $banana], $this->find($index, '', "categoryTitle = 'Produce''s'"));
    }
}
