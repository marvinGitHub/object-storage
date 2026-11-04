<?php

namespace Tests\melia\ObjectStorage\Search;

use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\Search\DocumentBuilder;
use melia\ObjectStorage\Search\Exception\EngineNotFoundException;
use melia\ObjectStorage\Search\Exception\IndexAlreadyExistsException;
use melia\ObjectStorage\Search\Exception\IndexBuildInProgressException;
use melia\ObjectStorage\Search\Exception\IndexNotFoundException;
use melia\ObjectStorage\Search\Exception\IndexNotReadyException;
use melia\ObjectStorage\Search\Exception\InvalidIndexDefinitionException;
use melia\ObjectStorage\Search\Exception\UnsupportedQueryException;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Index;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\IndexInterface;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Search\Index\State;
use melia\ObjectStorage\Search\Request;
use melia\ObjectStorage\Util\UuidTrie;
use stdClass;
use Tests\melia\ObjectStorage\TestCase;

/**
 * The index manager, independent of the engine: tested with a minimal engine of the tests.
 */
class IndexManagerTest extends TestCase
{
    private Manager $search;
    private ArrayEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new ArrayEngine();
        $this->search = new Manager($this->storage, [$this->engine]);
    }

    public function tearDown(): void
    {
        $this->search->detach();
        parent::tearDown();
    }

    private function createDefinition(string $name = 'products', ?string $engine = null): Definition
    {
        return new Definition($name, Product::class, [
            new Field('name', 'name'),
            new Field('description', 'description'),
            new Field('price', 'price', FieldType::NUMBER, searchable: false, filterable: true, sortable: true),
            new Field('tags', 'tags', FieldType::STRING_ARRAY, filterable: true),
        ], engine: $engine);
    }

    /**
     * @return string[] UUIDs of the hits
     */
    private function find(IndexInterface $index, string $query = '', ?string $filter = null, array $sort = []): array
    {
        return $index->search(new Request(query: $query, filter: $filter, sort: $sort, hitsPerPage: 100))->getUuids();
    }

    public function testDirectoriesAndInternalStorage(): void
    {
        $this->assertSame([], $this->search->list());
        $this->assertDirectoryDoesNotExist($this->search->getDirectory(), 'listing creates nothing');

        $index = $this->search->create($this->createDefinition());

        $indexes = $this->storage->getStorageDir() . DIRECTORY_SEPARATOR . 'indexes';
        $this->assertSame($indexes . DIRECTORY_SEPARATOR . 'array' . DIRECTORY_SEPARATOR . 'products', $index->getDirectory());
        $this->assertDirectoryExists($index->getDirectory());

        $meta = $this->search->getInternalStorage();
        $this->assertSame($indexes . DIRECTORY_SEPARATOR . 'internal', $meta->getStorageDir());

        $definition = $meta->load(Index::definitionUuid('products'));
        $this->assertInstanceOf(Definition::class, $definition);
        $this->assertSame('array', $definition->engine, 'the default engine is stored');
        $this->assertInstanceOf(State::class, $meta->load(Index::stateUuid('products')));
        $this->assertCount(1, iterator_to_array($meta->list(Definition::class), false), 'one record per definition');

        $this->assertSame([], $this->storage->getClassNames(), 'the indexed storage doesn\'t see them');
        $this->assertSame(IndexInterface::STATUS_NEW, $index->getStatus());
        $this->assertSame('array', $index->getEngineName());
    }

    public function testSetsOfUuidsAreObjectsOfTheInternalStorage(): void
    {
        $this->storage->store(new Product('Apple'));
        $index = $this->search->create($this->createDefinition());
        $internal = $this->search->getInternalStorage();
        $buildUuid = Index::trieUuid('products', Index::TRIE_BUILD);
        $pendingUuid = Index::trieUuid('products', Index::TRIE_PENDING);

        // while a build runs, its UUIDs are one record
        $index->startBuild();
        $queue = $internal->load($buildUuid);
        $this->assertInstanceOf(UuidTrie::class, $queue);
        $this->assertSame(1, $queue->count());

        $index->buildStep();
        $this->assertFalse($internal->exists($buildUuid), 'removed after the build');

        // pending changes are one record as well
        $this->search->attach(autoSync: false);
        $this->storage->store(new Product('Pear'));
        $this->storage->store(new Product('Plum'));
        $pending = $internal->load($pendingUuid);
        $this->assertInstanceOf(UuidTrie::class, $pending);
        $this->assertSame(2, $pending->count());
        $this->assertCount(1, iterator_to_array($internal->list(UuidTrie::class), false));

        // the directory of the index only holds the data of the engine
        $this->assertSame(['data-1'], array_values(array_diff(scandir($index->getDirectory()), ['.', '..'])));

        $this->search->drop('products');
        $this->assertFalse($internal->exists($pendingUuid), 'dropped with the index');
    }

    public function testNotifyChangedUpdatesOnlyTheChosenIndexesOfTheClass(): void
    {
        $a = $this->search->create($this->createDefinition('a'));
        $b = $this->search->create($this->createDefinition('b'));
        $categories = $this->search->create(new Definition('categories', Category::class, [new Field('title', 'title')]));
        $this->search->attach(autoSync: false);

        $uuid = $this->storage->store(new Product('Apple'));
        $this->search->sync(); // nothing built yet: drops the changes recorded by store()

        $this->assertSame(['a'], $this->search->notifyChanged($uuid, Product::class, ['a', 'categories', 'unknown']));
        $this->assertTrue($a->hasPendingChange($uuid));
        $this->assertFalse($b->hasPendingChange($uuid), 'not chosen');
        $this->assertFalse($categories->hasPendingChange($uuid), 'of another class');

        $this->assertSame([], $this->search->notifyChanged($uuid, Product::class, []), 'none chosen');

        // without a choice, all indexes of the class; the class is read from the metadata
        $this->assertSame(['a', 'b'], $this->search->notifyChanged($uuid));
        $this->assertTrue($b->hasPendingChange($uuid));
        $this->assertFalse($categories->hasPendingChange($uuid));
    }

    public function testIndexIsLoadedByNameInAnotherProcess(): void
    {
        $this->search->create($this->createDefinition());

        $other = new Manager($this->storage, [new ArrayEngine()]);
        $this->assertTrue($other->has('products'));
        $this->assertSame(['products'], array_keys($other->list()));
        $this->assertSame('products', $other->get('products')->getName());
    }

    public function testNamesAreUniqueAcrossEngines(): void
    {
        $search = new Manager($this->storage, [new ArrayEngine('first'), new ArrayEngine('second')]);
        $search->create($this->createDefinition(engine: 'first'));

        $this->expectException(IndexAlreadyExistsException::class);
        $search->create($this->createDefinition(engine: 'second'));
    }

    public function testEngineIsChosenPerIndex(): void
    {
        $search = new Manager($this->storage, [new ArrayEngine('first'), new ArrayEngine('second')]);
        $search->create($this->createDefinition('a'));
        $search->create($this->createDefinition('b', 'second'));

        $this->assertSame('first', $search->get('a')->getEngineName());
        $this->assertSame('second', $search->get('b')->getEngineName());
        $this->assertStringContainsString(DIRECTORY_SEPARATOR . 'second' . DIRECTORY_SEPARATOR . 'b', $search->get('b')->getDirectory());
    }

    public function testEngineNameOfTheInternalStorageIsReserved(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Manager($this->storage, [new ArrayEngine(Manager::INTERNAL_DIRECTORY)]);
    }

    public function testUnknownEngineIsRejected(): void
    {
        $this->expectException(EngineNotFoundException::class);
        $this->search->create($this->createDefinition(engine: 'missing'));
    }

    public function testEngineCantBeChangedByAnUpdate(): void
    {
        $this->search->create($this->createDefinition());

        $this->expectException(InvalidIndexDefinitionException::class);
        $this->search->update($this->createDefinition(engine: 'other'));
    }

    public function testEngineValidatesDefinitions(): void
    {
        $this->expectException(InvalidIndexDefinitionException::class);
        $this->search->create(new Definition('products', Product::class, [new Field('unsupported', 'name')]));
    }

    public function testBuildAndSearch(): void
    {
        $apple = $this->storage->store(new Product('Apple', 'A red fruit', 1.5, ['red']));
        $banana = $this->storage->store(new Product('Banana', 'A yellow fruit', '0.99', ['yellow']));
        $this->storage->store(new Product('Chair', 'Furniture', 49));

        $index = $this->search->create($this->createDefinition());
        $progress = $index->build();

        $this->assertTrue($progress['done']);
        $this->assertSame(3, $progress['documents']);
        $this->assertSame(IndexInterface::STATUS_READY, $index->getStatus());

        $this->assertSame([$banana, $apple], $this->find($index, 'fruit', sort: ['price:asc']));
        $this->assertSame([$apple], $this->find($index, '', "name = 'Apple'"));

        $result = $index->search(new Request(query: 'banana'));
        $this->assertSame(0.99, $result->hits[0]->document['price'], 'values are converted to the field type');
        $this->assertSame(1, $result->hits[0]->document['revision']);

        $objects = iterator_to_array($index->searchObjects(new Request(query: 'apple')));
        $this->assertSame([$apple], array_keys($objects));
        $this->assertInstanceOf(Product::class, $objects[$apple]);
    }

    public function testUnsupportedQueryObjectIsRejected(): void
    {
        $index = $this->search->create($this->createDefinition());
        $index->build();

        $this->expectException(UnsupportedQueryException::class);
        $index->search(new stdClass());
    }

    public function testSearchBeforeBuildFails(): void
    {
        $index = $this->search->create($this->createDefinition());

        $this->expectException(IndexNotReadyException::class);
        $index->search(new Request());
    }

    public function testRejectedDocumentsAreReportedAndNotSearchable(): void
    {
        $engine = new ArrayEngine(reject: static fn(array $document): ?string => 'Broken' === $document['name'] ? 'rejected by the engine' : null);
        $search = new Manager($this->storage, [$engine]);
        $this->storage->store(new Product('Apple'));
        $broken = $this->storage->store(new Product('Broken'));

        $index = $search->create($this->createDefinition());
        $progress = $index->build();

        $this->assertSame(1, $progress['documents']);
        $this->assertSame([$broken . ': rejected by the engine'], $progress['errors']);
    }

    public function testBuildInSteps(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->storage->store(new Product('Product ' . $i, price: $i));
        }

        $index = $this->search->create($this->createDefinition());
        $progress = $index->startBuild();
        $this->assertSame(['total' => 5, 'processed' => 0, 'percent' => 0, 'done' => false], array_intersect_key($progress, array_flip(['total', 'processed', 'percent', 'done'])));
        $this->assertSame(IndexInterface::STATUS_BUILDING, $index->getStatus());

        $percents = [];
        while (!$progress['done']) {
            $progress = $index->buildStep(2);
            $percents[] = $progress['percent'];
        }

        $this->assertSame([40, 80, 100], $percents);
        $this->assertSame(5, $index->countDocuments());
    }

    public function testBuildCanOnlyRunOnce(): void
    {
        $this->storage->store(new Product('Product'));
        $index = $this->search->create($this->createDefinition());
        $index->startBuild();

        $this->expectException(IndexBuildInProgressException::class);
        $index->startBuild();
    }

    public function testCancelBuildKeepsPreviousGeneration(): void
    {
        $uuid = $this->storage->store(new Product('Apple'));
        $index = $this->search->create($this->createDefinition());
        $index->build();

        $index->startBuild();
        $index->cancelBuild();

        $this->assertSame(IndexInterface::STATUS_READY, $index->getStatus());
        $this->assertSame([$uuid], $this->find($index, 'apple'));
        $this->assertCount(1, $this->engine->removed, 'the engine removes the data of the cancelled generation');
    }

    public function testAttachedIndexFollowsStoresAndDeletes(): void
    {
        $index = $this->search->create($this->createDefinition());
        $index->build();
        $this->search->attach();

        $product = new Product('Apple', price: 1);
        $uuid = $this->storage->store($product);
        $this->assertSame([$uuid], $this->find($index, 'apple'));

        $product->name = 'Pear';
        $this->storage->store($product);
        $this->assertSame([], $this->find($index, 'apple'));
        $this->assertSame([$uuid], $this->find($index, 'pear'));
        $this->assertSame(2, $index->search(new Request('pear'))->hits[0]->document['revision']);

        $this->storage->delete($uuid);
        $this->assertSame([], $this->find($index, 'pear'));
        $this->assertSame(0, $index->countPendingChanges());
    }

    public function testObjectsOfOtherClassesAreNotIndexed(): void
    {
        $index = $this->search->create($this->createDefinition());
        $index->build();
        $this->search->attach();

        $this->storage->store(new Category('Apple'));

        $this->assertSame(0, $index->countDocuments());
    }

    public function testChangesAreRecordedOnceAndAppliedBySync(): void
    {
        $index = $this->search->create($this->createDefinition());
        $index->build();
        $this->search->attach(autoSync: false);

        $product = new Product('Apple');
        $uuid = $this->storage->store($product);
        $product->name = 'Plum';
        $this->storage->store($product);

        $this->assertSame([], $this->find($index, 'plum'));
        $this->assertTrue($index->hasPendingChange($uuid));
        $this->assertSame(1, $index->countPendingChanges());

        $this->assertSame(['products' => 1], $this->search->sync());
        $this->assertSame([$uuid], $this->find($index, 'plum'));
        $this->assertSame(0, $index->countPendingChanges());
    }

    public function testSyncWithoutWaitingSkipsAnIndexLockedByAnotherProcess(): void
    {
        $index = $this->search->create($this->createDefinition());
        $index->build();
        $this->search->attach();

        // another process works on the index, e.g. a build step
        $other = new Manager($this->storage, [new ArrayEngine()]);
        $lockAdapter = $other->getInternalStorage()->getLockAdapter();
        $lockAdapter->acquireExclusiveLock(Index::lockUuid('products'));

        $startedAt = microtime(true);
        $uuid = $this->storage->store(new Product('Apple'));

        $this->assertSame([], $this->find($index, 'apple'), 'auto sync doesn\'t wait');
        $this->assertLessThan(2, microtime(true) - $startedAt, 'neither store() nor the search wait for the lock');
        $this->assertTrue($index->hasPendingChange($uuid));

        $lockAdapter->releaseLock(Index::lockUuid('products'));

        $this->assertSame(1, $index->sync());
        $this->assertSame([$uuid], $this->find($index, 'apple'));
    }

    public function testChangesDuringBuildAreIncluded(): void
    {
        $existing = $this->storage->store(new Product('Apple'));
        $index = $this->search->create($this->createDefinition());
        $this->search->attach();

        $index->startBuild();
        $added = $this->storage->store(new Product('Apricot'));
        $this->storage->delete($existing);

        $index->build();

        $this->assertSame([$added], $this->find($index));
    }

    public function testUpdatedDefinitionIsUsedAfterRebuild(): void
    {
        $uuid = $this->storage->store(new Product('Apple', 'Fresh from the farm'));
        $index = $this->search->create(new Definition('products', Product::class, [new Field('name', 'name')]));
        $index->build();
        $this->assertSame([], $this->find($index, 'farm'));

        $this->search->update(new Definition('products', Product::class, [new Field('name', 'name'), new Field('description', 'description')]));
        $this->assertSame(IndexInterface::STATUS_OUTDATED, $index->getStatus());
        $this->assertSame('array', $index->getDefinition()->engine, 'the engine is kept');
        $this->assertSame([], $this->find($index, 'farm'), 'the previous generation is used until rebuilt');

        $index->build();
        $this->assertSame(IndexInterface::STATUS_READY, $index->getStatus());
        $this->assertSame([$uuid], $this->find($index, 'farm'));
        $this->assertCount(1, glob($index->getDirectory() . DIRECTORY_SEPARATOR . 'data-*'));
        $this->assertCount(1, $this->engine->removed, 'the engine removes the data of the previous generation');
    }

    public function testDefinitionCantBeUpdatedDuringBuild(): void
    {
        $this->storage->store(new Product('Apple'));
        $index = $this->search->create($this->createDefinition());
        $index->startBuild();

        $this->expectException(IndexBuildInProgressException::class);
        $this->search->update($this->createDefinition());
    }

    public function testExpiredObjectsAreNotIndexed(): void
    {
        $this->storage->store(new Product('Apple'), ttl: 3600);
        $expired = $this->storage->store(new Product('Apricot'));
        $this->storage->setLifetime($expired, -1);

        $index = $this->search->create($this->createDefinition());
        $index->build();

        $this->assertCount(1, $this->find($index));
    }

    public function testDrop(): void
    {
        $index = $this->search->create($this->createDefinition());
        $index->build();
        $directory = $index->getDirectory();

        $this->search->drop('products');

        $this->assertDirectoryDoesNotExist($directory);
        $this->assertFalse($this->search->has('products'));
        $this->assertNotEmpty($this->engine->removed);

        $this->expectException(IndexNotFoundException::class);
        $this->search->get('products');
    }

    public function testCreateExistingIndexFails(): void
    {
        $this->search->create($this->createDefinition());

        $this->expectException(IndexAlreadyExistsException::class);
        $this->search->create($this->createDefinition());
    }

    public function testDefinitionRoundTrip(): void
    {
        $definition = $this->createDefinition(engine: 'array');
        $this->assertTrue($definition->equals(Definition::fromArray($definition->toArray())));
    }

    public static function invalidDefinitions(): array
    {
        return [
            'index name with uppercase' => [static fn() => new Definition('Products', Product::class, [new Field('name', 'name')])],
            'no fields' => [static fn() => new Definition('products', Product::class, [])],
            'duplicate field' => [static fn() => new Definition('products', Product::class, [new Field('name', 'name'), new Field('name', 'title')])],
            'reserved field' => [static fn() => new Field('uuid', 'uuid')],
            'invalid field name' => [static fn() => new Field('first-name', 'name')],
            'invalid path' => [static fn() => new Field('name', 'a..b')],
            'sortable list' => [static fn() => new Field('tags', 'tags', FieldType::STRING_ARRAY, sortable: true)],
            'invalid language' => [static fn() => new Definition('products', Product::class, [new Field('name', 'name')], ['german'])],
            'invalid engine' => [static fn() => new Definition('products', Product::class, [new Field('name', 'name')], engine: 'Not valid')],
        ];
    }

    /**
     * @dataProvider invalidDefinitions
     */
    public function testInvalidDefinitionsAreRejected(callable $create): void
    {
        $this->expectException(InvalidIndexDefinitionException::class);
        $create();
    }

    public function testInvalidSortCriterionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Request(sort: ['price']);
    }

    public function testDocumentBuilderReadsNestedPathsAndLists(): void
    {
        $metadata = new Metadata();
        $metadata->setRevision(3);
        $reference = '7d3f4b5e-2a1c-4e8f-9b6d-0c1a2b3c4d5e';
        $data = [
            'address' => ['city' => 'Berlin'],
            'lines' => [['title' => 'A', 'qty' => 1], ['title' => 'B', 'qty' => '2']],
            'owner' => ['__reference' => $reference],
        ];

        $definition = new Definition('orders', 'Order', [
            new Field('city', 'address.city'),
            new Field('titles', 'lines.title', FieldType::STRING_ARRAY),
            new Field('quantities', 'lines.qty', FieldType::NUMBER_ARRAY),
            new Field('owner', 'owner'),
            new Field('missing', 'address.zip'),
        ]);

        $document = (new DocumentBuilder($definition))->build('uuid-1', $data, $metadata);

        $this->assertSame([
            'uuid' => 'uuid-1',
            'revision' => 3,
            'city' => 'Berlin',
            'titles' => ['A', 'B'],
            'quantities' => [1, 2],
            'owner' => $reference,
            'missing' => null,
        ], $document);

        $suggested = [];
        foreach (DocumentBuilder::suggestFields($data, '__reference') as $field) {
            $suggested[$field->path] = $field;
        }
        $this->assertSame(['address.city', 'owner'], array_keys($suggested));
        $this->assertSame('address_city', $suggested['address.city']->name);
        $this->assertTrue($suggested['address.city']->searchable);
        $this->assertSame(FieldType::STRING, $suggested['owner']->type);
        $this->assertFalse($suggested['owner']->searchable, 'a reference is not full text');
        $this->assertTrue($suggested['owner']->filterable);
    }
}
