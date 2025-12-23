<?php

namespace Tests\melia\ObjectStorage;

class StaticFunctionTest extends TestCase
{
    /**
     * Provides test data for a static function.
     *
     * @return array Returns an array of test cases, each represented as a sub-array.
     */
    public function providerTestStaticFunction()
    {
        return [
            ['foo'],
            ['bar'],
        ];
    }

    /**
     * @dataProvider providerTestStaticFunction
     */
    public function testStaticFunction(string $arg)
    {
        $staticFunction = static fn() => $arg;
        $this->assertEquals($arg, $staticFunction());
    }
}