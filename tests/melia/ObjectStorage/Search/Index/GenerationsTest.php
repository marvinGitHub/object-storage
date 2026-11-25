<?php

namespace Tests\melia\ObjectStorage\Search\Index;

use melia\ObjectStorage\Logger\LoggerInterface;
use melia\ObjectStorage\Search\Engine\EngineIndexInterface;
use melia\ObjectStorage\Search\Engine\EngineInterface;
use melia\ObjectStorage\Search\Exception\SearchException;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\Index\Generations;
use RuntimeException;
use Tests\melia\ObjectStorage\Search\ArrayEngine;
use Tests\melia\ObjectStorage\Search\Product;
use Tests\melia\ObjectStorage\TestCase;
use Throwable;

class GenerationsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = $this->createTemporaryDirectory() . DIRECTORY_SEPARATOR . 'products';
    }

    public function tearDown(): void
    {
        $this->tearDownDirectory(dirname($this->directory));
        parent::tearDown();
    }

    private function definition(string ...$fields): Definition
    {
        return new Definition('products', Product::class, array_map(static fn(string $name): Field => new Field($name, $name), $fields ?: ['name']));
    }

    public function testEachGenerationKeepsTheDefinitionItWasBuiltWith(): void
    {
        $generations = new Generations($this->directory, 'products', new ArrayEngine(), $this->storage);

        $this->assertSame([], $generations->list());
        $this->assertSame(1, $generations->create(null, $this->definition('name')));
        $this->assertSame(2, $generations->create(1, $this->definition('name', 'description')));
        $this->assertSame(5, $generations->create(4, $this->definition()), 'after the active generation');
        $this->assertSame(6, $generations->create(null, $this->definition()), 'after the generations on disk');

        $this->assertSame([1, 2, 5, 6], $generations->list());
        $this->assertCount(1, $generations->definition(1)->fields);
        $this->assertCount(2, $generations->definition(2)->fields);
    }

    public function testAGenerationIsOpenedOnceUntilClosed(): void
    {
        $generations = new Generations($this->directory, 'products', new ArrayEngine(), $this->storage);
        $generation = $generations->create(null, $this->definition());

        $handle = $generations->open($generation);
        $this->assertSame($handle, $generations->open($generation));

        $generations->close($generation);
        $this->assertNotSame($handle, $generations->open($generation));
    }

    public function testRemovingGenerationsLetsTheEngineRemoveItsData(): void
    {
        $engine = new ArrayEngine();
        $generations = new Generations($this->directory, 'products', $engine, $this->storage);
        foreach ([null, 1, 2] as $active) {
            $generations->create($active, $this->definition());
        }

        $generations->removeAllBut(2);

        $this->assertSame([2], $generations->list());
        $this->assertSame([$this->directory . DIRECTORY_SEPARATOR . 'data-1', $this->directory . DIRECTORY_SEPARATOR . 'data-3'], $engine->removed);

        $generations->removeAllEngineData();
        $this->assertSame($this->directory . DIRECTORY_SEPARATOR . 'data-2', end($engine->removed));
        $this->assertSame([2], $generations->list(), 'the directories stay for the index to remove');
    }

    public function testAFailureToRemoveTheDataOfTheEngineIsOnlyLogged(): void
    {
        $logger = new class implements LoggerInterface {
            /** @var Throwable[] */
            public array $logged = [];

            public function log(Throwable $error): void
            {
                $this->logged[] = $error;
            }
        };
        $this->storage->setLogger($logger);
        $engine = new class implements EngineInterface {
            private ArrayEngine $engine;

            public function __construct()
            {
                $this->engine = new ArrayEngine();
            }

            public function getName(): string
            {
                return $this->engine->getName();
            }

            public function validate(Definition $definition): void
            {
                $this->engine->validate($definition);
            }

            public function filterAttributes(string $filter): array
            {
                return $this->engine->filterAttributes($filter);
            }

            public function renameFilterAttributes(string $filter, array $names): string
            {
                return $this->engine->renameFilterAttributes($filter, $names);
            }

            public function open(string $directory, Definition $definition): EngineIndexInterface
            {
                return $this->engine->open($directory, $definition);
            }

            public function remove(string $directory, Definition $definition): void
            {
                throw new RuntimeException('busy');
            }
        };
        $generations = new Generations($this->directory, 'products', $engine, $this->storage);
        $generation = $generations->create(null, $this->definition());

        $generations->remove($generation);

        $this->assertSame([], $generations->list(), 'the directory is removed anyway');
        $this->assertCount(1, $logger->logged);
        $this->assertSame('busy', $logger->logged[0]->getMessage());
    }

    public function testAGenerationWithoutDefinitionFails(): void
    {
        $generations = new Generations($this->directory, 'products', new ArrayEngine(), $this->storage);

        $this->expectException(SearchException::class);
        $this->expectExceptionMessage('The data of search index "products" is missing (generation 7).');
        $generations->definition(7);
    }
}
