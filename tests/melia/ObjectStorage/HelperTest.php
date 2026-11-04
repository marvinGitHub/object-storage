<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\Exception\InvalidUUIDException;
use melia\ObjectStorage\UUID\Helper;
use melia\ObjectStorage\UUID\Validator;

class HelperTest extends TestCase
{
    public function testCreateFixedUuidV4(): void
    {
        $uuid = Helper::createFixedUuidV4('search-index:products');

        $this->assertTrue(Validator::validate($uuid), 'in the format of version 4');
        $this->assertSame($uuid, Helper::createFixedUuidV4('search-index:products'), 'always the same for the same input');
        $this->assertNotSame($uuid, Helper::createFixedUuidV4('search-index:orders'));
        $this->assertNotSame($uuid, Helper::createFixedUuidV4('search-index:products', 'sha256'), 'depends on the algorithm');
        $this->assertTrue(Validator::validate(Helper::createFixedUuidV4('', 'sha512')));

        // the first 32 hex digits of the hash, with version and variant set: the UUIDs of existing indexes stay the same
        $hash = md5('search-index:products');
        $hash[12] = '4';
        $hash[16] = '89ab'[hexdec($hash[16]) & 3];
        $this->assertSame(vsprintf('%s-%s-%s-%s-%s', [substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12)]), $uuid);
    }

    public function testCreateFixedUuidV4SetsVersionAndVariantForAnyHash(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $uuid = Helper::createFixedUuidV4('input ' . $i, 'sha1');
            $this->assertSame('4', $uuid[14]);
            $this->assertContains($uuid[19], ['8', '9', 'a', 'b']);
            $this->assertTrue(Validator::validate($uuid));
        }
    }

    public function testCreateFixedUuidV4RejectsUnknownAlgorithms(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Helper::createFixedUuidV4('input', 'no-such-algorithm');
    }

    public function testCreateFixedUuidV4RejectsShortHashes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Helper::createFixedUuidV4('input', 'crc32b'); // 32 bits
    }

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
