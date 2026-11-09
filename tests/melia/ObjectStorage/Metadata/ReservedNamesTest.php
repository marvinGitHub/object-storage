<?php

namespace Tests\melia\ObjectStorage\Metadata;

use InvalidArgumentException;
use melia\ObjectStorage\Metadata\Metadata;
use PHPUnit\Framework\TestCase;

class ReservedNamesTest extends TestCase
{
    public function testDefaults(): void
    {
        $metadata = new Metadata();

        $this->assertSame('__reference', $metadata->getReservedReferenceName());
        $this->assertSame('__datetime', $metadata->getReservedDateTimeName());
        $this->assertSame([Metadata::RESERVED_REFERENCE => '__reference', Metadata::RESERVED_DATETIME => '__datetime'], $metadata->getReservedNames());
    }

    public function testNamesAreStoredByKindAndReadBack(): void
    {
        $metadata = new Metadata();
        $metadata->setReservedName(Metadata::RESERVED_DATETIME, '__datetime_1');

        $stored = $metadata->jsonSerialize();
        $this->assertSame('__reference', $stored['reservedReferenceName']);
        $this->assertSame('__datetime_1', $stored['reservedDateTimeName']);

        unset($stored['uuid']); // none set
        $this->assertSame('__datetime_1', Metadata::createFromArray($stored)->getReservedDateTimeName());
    }

    public function testOlderMetadataKeepsTheDefaults(): void
    {
        $metadata = Metadata::createFromArray(['checksum' => str_repeat('0', 64), 'reservedReferenceName' => '__reference_x']);

        $this->assertSame('__reference_x', $metadata->getReservedReferenceName());
        $this->assertSame('__datetime', $metadata->getReservedDateTimeName());
    }

    public function testCollidingNamesAreReplacedAndAllDiffer(): void
    {
        $metadata = new Metadata();
        $names = $metadata->reserveNamesAvoiding(['__reference', '__datetime', 'title', 0]);

        $this->assertStringStartsWith('__reference', $names[Metadata::RESERVED_REFERENCE]);
        $this->assertStringStartsWith('__datetime', $names[Metadata::RESERVED_DATETIME]);
        $this->assertNotContains($names[Metadata::RESERVED_REFERENCE], ['__reference', '__datetime']);
        $this->assertNotContains($names[Metadata::RESERVED_DATETIME], ['__reference', '__datetime']);
        $this->assertSame($names, $metadata->getReservedNames());
    }

    public function testNamesThatDontCollideAreKept(): void
    {
        $chosen = Metadata::chooseReservedNames([Metadata::RESERVED_REFERENCE => '__reference_a'], ['title']);

        $this->assertSame([Metadata::RESERVED_REFERENCE => '__reference_a', Metadata::RESERVED_DATETIME => '__datetime'], $chosen);
    }

    public function testKindsMustDiffer(): void
    {
        // the name of one kind taken by another: the later kind gets another one
        $chosen = Metadata::chooseReservedNames([Metadata::RESERVED_REFERENCE => 'x', Metadata::RESERVED_DATETIME => 'x'], []);

        $this->assertSame('x', $chosen[Metadata::RESERVED_REFERENCE]);
        $this->assertNotSame('x', $chosen[Metadata::RESERVED_DATETIME]);
    }

    public function testUnknownKindIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Metadata())->setReservedName('blob', '__blob');
    }
}
