<?php

namespace Tests\melia\ObjectStorage\Search\Index;

use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\IndexRecords;
use melia\ObjectStorage\Search\Index\State;
use Tests\melia\ObjectStorage\Search\Product;
use Tests\melia\ObjectStorage\TestCase;

class IndexRecordsTest extends TestCase
{
    public function testTheStateIsChangedUnderTheLockOfTheIndex(): void
    {
        $records = new IndexRecords($this->storage, 'products');
        $this->storage->store(new State('products'), IndexRecords::stateUuid('products'));
        $lockAdapter = $this->storage->getLockAdapter();

        $result = $records->withState(function (State $state) use ($lockAdapter): string {
            $this->assertTrue($lockAdapter->isLockedByThisProcess(IndexRecords::lockUuid('products')));
            $state->generation = 3;
            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(3, $records->state()->generation, 'the state is stored');
        $this->assertFalse($lockAdapter->isLockedByThisProcess(IndexRecords::lockUuid('products')));
    }

    public function testAFailingChangeIsNotStored(): void
    {
        $records = new IndexRecords($this->storage, 'products');
        $this->storage->store(new State('products'), IndexRecords::stateUuid('products'));

        try {
            $records->withState(static function (State $state): void {
                $state->generation = 3;
                throw new \RuntimeException('failed');
            });
            $this->fail('expected a SearchException');
        } catch (SearchException $e) {
            $this->assertSame('Search index "products": failed', $e->getMessage());
        }

        $this->assertNull($records->state()->generation, 'the state is read as stored, not as the failed change left it');

        $records->withState(static function (State $state): void {
            $state->lastSyncAt = 'now';
        });
        $this->storage->clearCache();
        $this->assertNull($records->state()->generation, 'the next change doesn\'t store the failed one along');
        $this->assertSame('now', $records->state()->lastSyncAt);
        $this->assertFalse($this->storage->getLockAdapter()->isLockedByThisProcess(IndexRecords::lockUuid('products')));
    }

    public function testWithoutAStateTheIndexDoesNotExist(): void
    {
        $records = new IndexRecords($this->storage, 'products');

        $this->assertNull($records->state()->generation, 'read as a new state');
        $this->expectException(SearchException::class);
        $this->expectExceptionMessage('Search index "products" doesn\'t exist.');
        $records->withState(static fn(State $state): null => null);
    }

    public function testDefinitionAndStateAreRemoved(): void
    {
        $records = new IndexRecords($this->storage, 'products');
        $this->storage->store(new State('products'), IndexRecords::stateUuid('products'));
        $records->storeDefinition(new Definition('products', Product::class, [new Field('name', 'name')]));

        $this->assertSame(Product::class, $records->definition()->className);

        $records->remove();
        $this->assertFalse($this->storage->exists(IndexRecords::stateUuid('products')));
        $this->expectException(SearchException::class);
        $this->expectExceptionMessage('The definition of search index "products" is missing.');
        $records->definition();
    }
}
