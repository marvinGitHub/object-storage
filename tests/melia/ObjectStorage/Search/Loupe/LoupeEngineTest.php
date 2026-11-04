<?php

namespace Tests\melia\ObjectStorage\Search\Loupe;

use Loupe\Loupe\SearchParameters;
use Loupe\Loupe\SearchResult as LoupeSearchResult;
use melia\ObjectStorage\Search\Exception\InvalidIndexDefinitionException;
use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Search\Exception\UnsupportedQueryException;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Index;
use melia\ObjectStorage\Search\Index\IndexInterface;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Search\Loupe\LoupeEngine;
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
}
