<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Exception\TypeConversionFailureException;
use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\Strategy\Standard;
use PHPUnit\Framework\TestCase;

final class StandardHydrationTest extends TestCase
{
    /**
     * @dataProvider provideLosslessConversions
     */
    public function testScalarValuesAreConvertedToDeclaredType(string $property, mixed $value, mixed $expected): void
    {
        $target = new HydrationTarget();

        (new Standard())->hydrateProperty(new Reflection($target), $property, $value);

        $this->assertSame($expected, $target->$property);
    }

    public static function provideLosslessConversions(): array
    {
        return [
            'numeric string to int' => ['int', '5', 5],
            'whole float to int' => ['int', 2.0, 2],
            'bool to int' => ['int', true, 1],
            'int to float' => ['float', 3, 3.0],
            'numeric string to float' => ['float', '1.5', 1.5],
            'int to string' => ['string', 5, '5'],
            'string "1" to bool' => ['bool', '1', true],
            'int to bool' => ['bool', 0, false],
        ];
    }

    /**
     * @dataProvider provideLossyConversions
     */
    public function testLossyConversionIsRejected(string $property, mixed $value): void
    {
        $this->expectException(TypeConversionFailureException::class);

        (new Standard())->hydrateProperty(new Reflection(new HydrationTarget()), $property, $value);
    }

    public static function provideLossyConversions(): array
    {
        return [
            'non-numeric string to int' => ['int', 'abc'],
            'fractional float to int' => ['int', 2.5],
            'non-numeric string to float' => ['float', 'abc'],
            'arbitrary string to bool' => ['bool', 'false'],
        ];
    }
}

class HydrationTarget
{
    public int $int = 0;
    public float $float = 0.0;
    public bool $bool = false;
    public string $string = '';
}
