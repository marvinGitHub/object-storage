<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\Exception\InvalidUUIDException;
use melia\ObjectStorage\UUID\Helper;

class HelperTest extends TestCase
{
    /**
     * @throws InvalidUUIDException
     */
    public function testAssign(): void
    {
        $someObject = new class () implements AwareInterface {
            public string $uuid;

            public function setUuid(string|null $uuid): void
            {
                $this->uuid = $uuid;
            }

            public function getUUID(): ?string
            {
                return $this->uuid;
            }
        };
        Helper::assign($someObject, $uuid = '7a0d5e9c-164b-4c4a-9239-3fec16997afe');

        $this->assertNotNull($someObject->uuid);
        $this->assertEquals($uuid, $someObject->uuid);
    }

    public function testAssignWithoutImplementingAwareInterface(): void
    {
        $someObject = new class () {
            public string $uuid;

            public function setUuid(string|null $uuid): void
            {
                $this->uuid = $uuid;
            }

            public function getUUID(): ?string
            {
                return $this->uuid;
            }
        };
        $someUUID = '00000000-0000-0000-0000-3fec16997afe';
        $someObject->uuid = $someUUID;

        Helper::assign($someObject, '7a0d5e9c-164b-4c4a-9239-3fec16997afe');
        // this should not override
        $this->assertEquals($someUUID, $someObject->uuid);
    }

    public function testRemoveHyphens(): void
    {
        $this->assertEquals('7a0d5e9c164b4c4a92393fec16997afe', Helper::removeHyphens('7a0d5e9c-164b-4c4a-9239-3fec16997afe'));
    }
}
