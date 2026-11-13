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
use melia\ObjectStorage\Search\Exception\NoSuitableIndexException;
use melia\ObjectStorage\Search\Exception\UnsupportedQueryException;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Index;
use melia\ObjectStorage\Search\Index\IndexInterface;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Search\Index\State;
use melia\ObjectStorage\Search\Request;
use melia\ObjectStorage\Util\UuidTrie;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * An object that can't be read (here: metadata without a checksum) is an error of the index naming the cause,
     * not only "could not be read".
     */
    public function testErrorsNameWhyAnObjectCouldNotBeRead(): void
    {
        $uuid = $this->storage->store(new Product('Chair', 'Oak', 49, []));
        $this->storage->store(new Product('Table', 'Oak', 99, []));
        $file = $this->storage->getFilePathMetadata($uuid);
        $metadata = json_decode((string)file_get_contents($file), true);
        unset($metadata['checksum']);
        file_put_contents($file, json_encode($metadata));
        $this->storage->clearCache();

        $index = $this->search->create($this->createDefinition());
        $index->build();

        $this->assertSame(1, $index->countDocuments(), 'the others are indexed');
        $errors = $index->getState()->errors;
        $this->assertCount(1, $errors);
        $this->assertStringStartsWith("$uuid: Object with uuid $uuid could not be read: The metadata of $uuid has no checksum", $errors[0]);

        try {
            $this->storage->readRaw($uuid);
            $this->fail('no checksum');
        } catch (\melia\ObjectStorage\Exception\ObjectLoadingFailureException $e) {
            $this->assertInstanceOf(\melia\ObjectStorage\Exception\MissingChecksumException::class, $e->getPrevious());
        }
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

    /**
     * Indexes of Product with different abilities, like several indexes of a table.
     *
     * @return array<string, string> name => UUID of the stored products
     */
    private function createPlannedIndexes(): array
    {
        $products = [
            'apple' => $this->storage->store(new Product('Apple', 'Fresh fruit', 1)),
            'chair' => $this->storage->store(new Product('Chair', 'Oak furniture', 2)),
        ];

        // full text over the name only
        $this->search->create(new Definition('names', Product::class, [new Field('name', 'name')]))->build();
        // full text over name and description, filters and sorting by price
        $this->search->create(new Definition('full', Product::class, [
            new Field('name', 'name'),
            new Field('description', 'description'),
            new Field('price', 'price', FieldType::NUMBER, searchable: false, filterable: true, sortable: true),
        ]))->build();
        // only the price
        $this->search->create(new Definition('prices', Product::class, [
            new Field('price', 'price', FieldType::NUMBER, searchable: false, filterable: true, sortable: true),
        ]))->build();
        // never built
        $this->search->create(new Definition('unbuilt', Product::class, [new Field('name', 'name')]));
        // another class
        $this->search->create(new Definition('categories', Category::class, [new Field('title', 'title')]))->build();

        return $products;
    }

    /**
     * @return array<string, int|string[]> name => rank, or the reasons it can't be used
     */
    private function planOf(Request $request): array
    {
        $plan = [];
        foreach ($this->search->explain(Product::class, $request)->candidates as $candidate) {
            $plan[$candidate->name] = $candidate->rank ?? $candidate->reasons;
        }
        return $plan;
    }

    public function testFullTextChoosesTheIndexSearchingTheMostFields(): void
    {
        $products = $this->createPlannedIndexes();

        $this->assertSame([
            'full' => 1,
            'names' => 2,
            'prices' => ['no searchable fields for the query'],
            'unbuilt' => ['not built yet'],
        ], $this->planOf(new Request('oak')));

        // "oak" is only in a description: the index searching the most fields finds it
        $this->assertSame([$products['chair']], $this->search->search(Product::class, new Request('oak'))->getUuids());
        $objects = iterator_to_array($this->search->searchObjects(Product::class, new Request('oak')));
        $this->assertInstanceOf(Product::class, $objects[$products['chair']]);
    }

    public function testFieldsToSearchOnMustBeSearchable(): void
    {
        $this->createPlannedIndexes();

        $plan = $this->planOf(new Request('apple', searchOn: ['description']));

        $this->assertSame(1, $plan['full']);
        $this->assertSame(['not searchable: description'], $plan['names']);
    }

    public function testFilterAndSortChooseTheSmallestIndexThatCanAnswer(): void
    {
        $products = $this->createPlannedIndexes();

        // without full text, the index with the fewest fields
        $this->assertSame([
            'prices' => 1,
            'full' => 2,
            'names' => ['not filterable: price'],
            'unbuilt' => ['not built yet'],
        ], $this->planOf(new Request(filter: "price = '1'")));
        $this->assertSame([$products['apple']], $this->search->search(Product::class, new Request(filter: "price = '1'"))->getUuids());

        $this->assertSame(1, $this->planOf(new Request(sort: ['price:desc']))['prices']);
        $this->assertSame(['not sortable: price'], $this->planOf(new Request(sort: ['price:desc']))['names']);
        $this->assertSame(['no facets for: price'], $this->planOf(new Request(facets: ['price']))['names']);

        // the UUID can always be filtered
        $this->assertSame(1, $this->planOf(new Request(filter: sprintf("uuid = '%s'", $products['apple'])))['names']);
    }

    public function testIndexesWithTheirCurrentDefinitionComeFirst(): void
    {
        $this->createPlannedIndexes();

        // outdated: searches use the definition of its last build, which still searches the description
        $this->search->update(new Definition('full', Product::class, [new Field('name', 'name')]));

        $plan = $this->search->explain(Product::class, new Request('apple'));
        $this->assertSame('names', $plan->getChosen()->name, 'ready before outdated');
        $this->assertSame([['names', 1, 'ready'], ['full', 2, 'outdated']], array_map(
            static fn($candidate) => [$candidate->name, $candidate->rank, $candidate->status],
            array_values(array_filter($plan->candidates, static fn($candidate) => $candidate->isUsable()))
        ));
    }

    public function testNoSuitableIndexExplainsWhy(): void
    {
        $this->createPlannedIndexes();

        try {
            $this->search->search(Product::class, new Request(filter: "name = 'Apple'"));
            $this->fail('NoSuitableIndexException expected');
        } catch (NoSuitableIndexException $e) {
            $this->assertNull($e->getPlan()->getChosen());
            $this->assertStringContainsString('names (array, ready): not filterable: name', $e->getMessage());
        }

        $this->expectException(NoSuitableIndexException::class);
        $this->expectExceptionMessage('No search index exists for stdClass.');
        $this->search->search(stdClass::class, new Request('apple'));
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

    public function testAttachedIndexFollowsLifetimeChanges(): void
    {
        $index = $this->search->create($this->createDefinition());
        $index->build();
        $this->search->attach();

        $uuid = $this->storage->store(new Product('Apple'));
        $this->assertSame([$uuid], $this->find($index, 'apple'));

        // expired: no longer found
        $this->storage->setLifetime($uuid, -1);
        $this->assertSame([], $this->find($index, 'apple'));

        // lives again
        $this->storage->setLifetime($uuid, null);
        $this->assertSame([$uuid], $this->find($index, 'apple'));

        $this->storage->setExpiration($uuid, new \DateTimeImmutable('-1 hour'));
        $this->assertSame([], $this->find($index, 'apple'));
        $this->assertSame(0, $index->countPendingChanges());
    }

    public function testSyncRemovesObjectsExpiredSinceIndexing(): void
    {
        $permanent = $this->storage->store(new Product('Apple'));
        $expiring = $this->storage->store(new Product('Apricot'));
        $extended = $this->storage->store(new Product('Avocado'));
        $this->storage->setLifetime($expiring, 0.4);
        $this->storage->setLifetime($extended, 0.4);

        $index = $this->search->create($this->createDefinition());
        $index->build();
        $this->assertSame(3, $index->countDocuments());
        $this->assertNotNull($index->search(new Request('apricot'))->hits[0]->document['expiresAt']);

        // the lifetime of one is extended without the index knowing (not attached)
        $this->storage->setLifetime($extended, null);
        usleep(600_000);

        // auto sync after a store doesn't look for expired objects, to stay fast
        $this->search->attach();
        $banana = $this->storage->store(new Product('Banana'));
        $this->assertSame(4, $index->countDocuments());

        $this->assertSame(1, $index->sync(), 'one expired object removed, no pending changes');
        $this->assertSame(1, $index->getState()->lastExpiredRemoved);
        $this->assertEqualsCanonicalizing([$permanent, $extended, $banana], $this->find($index));
        $this->assertNull($index->search(new Request('avocado'))->hits[0]->document['expiresAt'], 'updated instead of removed');

        $this->assertSame(0, $index->purgeExpired(), 'nothing left to remove');
    }

    public function testTheLastUpdatesAreRecorded(): void
    {
        $this->storage->store(new Product('Apple'));
        $expiring = $this->storage->store(new Product('Apricot'));
        $index = $this->search->create($this->createDefinition());
        $this->assertNull($index->getState()->getLastUpdate(), 'never updated');

        $index->build();
        $this->assertSame(['kind' => 'build', 'changed' => 2, 'expired' => 0, 'documents' => 2], array_diff_key($index->getState()->getLastUpdate(), ['at' => true]));

        // a sync without changes is no update
        $this->assertSame(0, $index->sync());
        $this->assertCount(1, $index->getState()->updates);

        $this->search->attach();
        $this->storage->store(new Product('Banana'));
        $this->assertSame(['kind' => 'sync', 'changed' => 1, 'expired' => 0, 'documents' => 3], array_diff_key($index->getState()->getLastUpdate(), ['at' => true]));

        // the index learns the expiration, the object expires later
        $this->storage->setLifetime($expiring, 0.2);
        $this->search->detach();
        usleep(400_000);
        $this->assertSame(1, $index->purgeExpired());

        $updates = $index->getState()->updates;
        $this->assertSame(['expired', 'sync', 'sync', 'build'], array_column($updates, 'kind'), 'newest first');
        $this->assertSame([1, 2], [$updates[0]['expired'], $updates[0]['documents']]);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $updates[0]['at']));
    }

    public function testOnlyTheLastUpdatesAreKept(): void
    {
        $state = new State('products');
        for ($i = 1; $i <= State::MAX_UPDATES + 5; $i++) {
            $state->addUpdate(State::UPDATE_SYNC, $i);
        }

        $this->assertCount(State::MAX_UPDATES, $state->updates);
        $this->assertSame(State::MAX_UPDATES + 5, $state->getLastUpdate()['changed']);
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
            'reserved field expiresAt' => [static fn() => new Field('expiresAt', 'expiresAt')],
            'invalid field name' => [static fn() => new Field('first-name', 'name')],
            'invalid path' => [static fn() => new Field('name', 'a..b')],
            'sortable list' => [static fn() => new Field('tags', 'tags', FieldType::STRING_ARRAY, sortable: true)],
            'invalid language' => [static fn() => new Definition('products', Product::class, [new Field('name', 'name')], ['german'])],
            'invalid engine' => [static fn() => new Definition('products', Product::class, [new Field('name', 'name')], engine: 'Not valid')],
        ];
    }

    #[DataProvider('invalidDefinitions')]
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
            'expiresAt' => null,
            'city' => 'Berlin',
            'titles' => ['A', 'B'],
            'quantities' => [1, 2],
            'owner' => $reference,
            'missing' => null,
            'referencedUuids' => [],
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
