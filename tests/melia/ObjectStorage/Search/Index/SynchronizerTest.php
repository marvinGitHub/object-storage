<?php

namespace Tests\melia\ObjectStorage\Search\Index;

use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\DocumentIndexer;
use melia\ObjectStorage\Search\Index\Generations;
use melia\ObjectStorage\Search\Index\State;
use melia\ObjectStorage\Search\Index\Synchronizer;
use melia\ObjectStorage\Search\Index\UuidSets;
use Tests\melia\ObjectStorage\Search\ArrayEngine;
use Tests\melia\ObjectStorage\Search\Product;
use Tests\melia\ObjectStorage\TestCase;

class SynchronizerTest extends TestCase
{
    private string $directory;
    private Generations $generations;
    private UuidSets $sets;
    private Synchronizer $synchronizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = $this->createTemporaryDirectory() . DIRECTORY_SEPARATOR . 'products';
        $this->generations = new Generations($this->directory, 'products', new ArrayEngine(), $this->storage);
        $this->sets = new UuidSets($this->storage, 'products');
        $this->synchronizer = new Synchronizer($this->sets, $this->generations, new DocumentIndexer($this->storage, $this->generations), $this->storage);
    }

    public function tearDown(): void
    {
        $this->generations->closeAll();
        $this->tearDownDirectory(dirname($this->directory));
        parent::tearDown();
    }

    public function testPendingChangesAreAppliedUpToTheLimit(): void
    {
        $generation = $this->generations->create(null, new Definition('products', Product::class, [new Field('name', 'name')]));
        $uuids = [];
        foreach (['Apple', 'Banana', 'Cherry'] as $name) {
            $uuids[] = $this->storage->store(new Product($name));
        }
        $this->sets->add(UuidSets::PENDING, $uuids);
        $state = new State('products', $generation);

        $this->assertSame(2, $this->synchronizer->applyPending($state, 1, maxChanges: 2));
        $this->assertSame(1, $this->synchronizer->countPending(), 'the others stay pending');
        $this->assertSame(2, $this->generations->open($generation)->count());
        $this->assertSame(0, $this->sets->count(UuidSets::REPLAY), 'no build runs');

        $state->build = ['generation' => $generation + 1, 'total' => 0, 'processed' => 0, 'failed' => 0, 'cursor' => null, 'startedAt' => ''];
        $this->assertSame(1, $this->synchronizer->applyPending($state, 10));
        $this->assertSame(1, $this->sets->count(UuidSets::REPLAY), 'replayed at the end of the build');
    }

    public function testChangesThatFailToApplyStayPending(): void
    {
        $uuid = $this->storage->store(new Product('Apple'));
        $this->sets->add(UuidSets::PENDING, [$uuid]);

        try {
            // a generation without data
            $this->synchronizer->applyPending(new State('products', 7), 10);
            $this->fail('expected a SearchException');
        } catch (SearchException) {
        }

        $this->assertSame([$uuid], $this->sets->read(UuidSets::PENDING)->first(10));
    }

    public function testErrorsAreKeptInTheStateTheLastOnes(): void
    {
        $state = new State('products');
        for ($i = 1; $i <= State::MAX_ERRORS + 5; $i++) {
            $this->synchronizer->addError($state, 'Context', new \RuntimeException((string)$i));
        }

        $this->assertCount(State::MAX_ERRORS, $state->errors);
        $this->assertSame('Context: 6', $state->errors[0]);
        $this->assertSame('Context: ' . (State::MAX_ERRORS + 5), end($state->errors));
    }
}
