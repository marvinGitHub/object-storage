<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Strategy\Standard;

class ObjectStorageStrategyTest extends TestCase {
    public function testStrategy() {
        $strategy = new Standard();
        $strategy->setChecksumAlgorithm('sha256');

        $this->storage->setStrategy($strategy);
        $uuid = $this->storage->store(new TestObject());
        $this->assertEquals('sha256', $this->storage->loadMetadata($uuid)->getChecksumAlgorithm());
    }

    /**
     * The depth was once ORed into the flags of json_encode(): 512 is JSON_PARTIAL_OUTPUT_ON_ERROR, which stored
     * invalid UTF-8 as null and NAN as 0, without an error.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideValuesJsonCannotHold')]
    public function testValuesJsonCannotHoldFailInsteadOfBeingLost(mixed $value): void
    {
        $this->expectException(\JsonException::class);
        (new Standard())->serialize(['value' => $value], Standard::DEFAULT_MAX_DEPTH);
    }

    public static function provideValuesJsonCannotHold(): array
    {
        return [
            'invalid UTF-8' => ["M\xFCller"],
            'NAN' => [NAN],
            'INF' => [INF],
        ];
    }

    /**
     * A depth sharing bits with flags (here 32 = JSON_NUMERIC_CHECK) must not change the data.
     */
    public function testDepthDoesNotChangeTheEncoding(): void
    {
        $this->assertSame('{"zip":"01234","code":"1e3"}', (new Standard())->serialize(['zip' => '01234', 'code' => '1e3'], 100));
    }

    public function testStoringInvalidUtf8Fails(): void
    {
        $object = new TestObject();
        $object->somePublicAttributeWhichDefaultsToNull = "M\xFCller";

        $this->expectException(\melia\ObjectStorage\Exception\ObjectSavingFailureException::class);
        $this->storage->store($object);
    }
}
