<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\SPL\WeakMapSweeper;

class WeakMapSweeperTest extends TestCase
{
    public function testClear()
    {
        $map = new \WeakMap();
        $a = new \stdClass();

        $map->offsetSet($a, 1);
        $this->assertEquals(1, $map->offsetGet($a));
        $this->assertEquals(1, count($map));

        WeakMapSweeper::clear($map);
        $this->assertEquals(0, count($map));
    }
}
