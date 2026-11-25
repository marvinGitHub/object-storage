<?php

namespace Tests\melia\ObjectStorage\Storage;

use melia\ObjectStorage\Storage\Components;
use PHPUnit\Framework\TestCase;
use stdClass;

class ComponentsTest extends TestCase
{
    public function testAComponentIsCreatedOnceForTheSameInputs(): void
    {
        $components = new Components();
        $input = new stdClass();
        $created = 0;
        $create = static function () use (&$created): stdClass {
            $created++;
            return new stdClass();
        };

        $first = $components->get('a', [$input, 2, null], $create);
        $second = $components->get('a', [$input, 2, null], $create);

        $this->assertSame($first, $second);
        $this->assertSame(1, $created);
    }

    public function testObjectsAreComparedByIdentityOtherValuesByValue(): void
    {
        $components = new Components();
        $first = $components->get('a', [new stdClass(), 2], static fn(): stdClass => new stdClass());

        $this->assertNotSame($first, $components->get('a', [new stdClass(), 2], static fn(): stdClass => new stdClass()), 'an equal object is another input');

        $input = new stdClass();
        $byObject = $components->get('a', [$input, 2], static fn(): stdClass => new stdClass());
        $this->assertNotSame($byObject, $components->get('a', [$input, 3], static fn(): stdClass => new stdClass()), 'another value');
        $this->assertNotSame($byObject, $components->get('a', [$input, '3'], static fn(): stdClass => new stdClass()), 'no type juggling');
    }

    public function testComponentsAreKeptByName(): void
    {
        $components = new Components();
        $a = $components->get('a', [], static fn(): stdClass => new stdClass());
        $b = $components->get('b', [], static fn(): stdClass => new stdClass());

        $this->assertNotSame($a, $b);
        $this->assertSame($a, $components->get('a', [], static fn(): stdClass => new stdClass()));
    }
}
