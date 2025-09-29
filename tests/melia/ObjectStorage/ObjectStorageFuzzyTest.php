<?php

use Faker\Factory as FakerFactory;
use Tests\melia\ObjectStorage\TestCase;

final class ObjectStorageFuzzyTest extends TestCase
{
    public function testFuzzyStoreLoadRoundtrip(): void
    {
        $iterations = 100;

        $uuids = [];

        for ($i = 0; $i < $iterations; $i++) {
            $obj = $this->randomObjectGraph($depth = 0);

            // Store
            $uuid = $this->storage->store($obj);
            $this->assertNotEmpty($uuid, 'UUID must not be empty');
            $this->assertTrue($this->storage->exists($uuid), 'Object must exist after store');

            // Load
            $loaded = $this->storage->load($uuid);
            $this->assertIsObject($loaded);
            $this->assertSame(get_class($obj), get_class($loaded), 'Class must round-trip');

            // Structural checks (not deep equality because references turn into lazy refs)
            $this->assertThatPropertiesExistAndTypesMatch($obj, $loaded);

            $uuids[] = $uuid;
        }

        // Listing sanity
        $all = iterator_to_array($this->storage->list());
        $this->assertGreaterThanOrEqual($iterations, count($all));
        foreach ($uuids as $uuid) {
            $this->assertContains($uuid, $all);
        }
    }

    public function testFuzzyReferencesAndSelfCycles(): void
    {
        $a = new stdClass();
        $b = new stdClass();
        $a->name = 'A';
        $b->name = 'B';

        // Cross-reference
        $a->peer = $b;
        $b->peer = $a;

        // Arrays with mixed values
        $a->mixed = [$b, 1, 2.5, true, 'x', ['nested' => $a]];

        $uuidA = $this->storage->store($a);
        $uuidB = $this->storage->store($b);

        $this->assertTrue($this->storage->exists($uuidA));
        $this->assertTrue($this->storage->exists($uuidB));

        // Force reload (no cache)
        if (method_exists($this->storage, 'clearCache')) {
            $this->storage->clearCache();
        }

        $loadedA = $this->storage->load($uuidA);
        $this->assertIsObject($loadedA);
        $this->assertSame(stdClass::class, get_class($loadedA));

        // Accessing through lazy references should not crash and should preserve identity where applicable
        $this->assertTrue(is_object($loadedA->peer));
        $this->assertTrue(is_array($loadedA->mixed));
        $this->assertArrayHasKey('nested', $loadedA->mixed[5]);
    }

    private function randomObjectGraph(int $depth, int $maxDepth = 3): object
    {
        $faker = FakerFactory::create();
        // Alternate between stdClass and anonymous classes (class variance)
        $obj = (rand(0, 1) === 0)
            ? new stdClass()
            : new class extends stdClass {
            };

        $propCount = rand(1, 8);
        for ($i = 0; $i < $propCount; $i++) {
            $name = $this->randomPropName($faker);
            $obj->{$name} = $this->randomValue($faker, $depth, $maxDepth, $obj);
        }

        // Occasionally add self-reference
        if (rand(0, 6) === 0) {
            $obj->self = $obj;
        }

        return $obj;
    }

    private function randomPropName(\Faker\Generator $faker): string
    {
        return lcfirst(preg_replace('/\W+/', '', $faker->unique()->word()));
    }

    private function randomValue(\Faker\Generator $faker, int $depth, int $maxDepth, object $parent)
    {
        $choice = rand(0, 9);

        if ($depth < $maxDepth && $choice >= 7) {
            // Nested array/object
            return (rand(0, 1) === 0)
                ? $this->randomArray($faker, $depth + 1, $maxDepth, $parent)
                : $this->randomObjectGraph($depth + 1, $maxDepth);
        }

        return match ($choice) {
            0 => $faker->randomNumber(),
            1 => $faker->randomFloat(3, -1000, 1000),
            2 => $faker->boolean(),
            3 => $faker->sentence(3),
            4 => null,
            5 => $this->randomArray($faker, $depth, $maxDepth, $parent),
            6 => $parent, // create a back-reference to parent
            default => $faker->word(),
        };
    }

    private function randomArray(\Faker\Generator $faker, int $depth, int $maxDepth, object $parent): array
    {
        $len = rand(0, 6);
        $arr = [];
        for ($i = 0; $i < $len; $i++) {
            $key = (rand(0, 1) === 0) ? $i : $faker->word();
            $arr[$key] = $this->randomValue($faker, $depth, $maxDepth, $parent);
        }
        return $arr;
    }

    private function assertThatPropertiesExistAndTypesMatch(object $original, object $loaded): void
    {
        foreach (get_object_vars($original) as $k => $v) {
            $this->assertTrue(property_exists($loaded, $k), "Missing property '$k'");
            $lv = $loaded->{$k};

            // For scalars, types should match exactly
            if (is_scalar($v) || $v === null) {
                $this->assertSame(gettype($v), gettype($lv), "Type mismatch for '$k'");
            } else if (is_array($v)) {
                $this->assertIsArray($lv, "Expected array for '$k'");
            } else if (is_object($v)) {
                // Object may be a LazyLoadReference proxy or the real object; at least it must be object-like
                $this->assertIsObject($lv, "Expected object for '$k'");
            }
        }
    }
}