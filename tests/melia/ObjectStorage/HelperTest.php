<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\UUID\Exception\InvalidUUIDException;
use melia\ObjectStorage\UUID\Helper;
use stdClass;

class HelperTest extends TestCase
{
    /**
     * @throws InvalidUUIDException
     */
    public function testAssignForObjectWithoutRelatedSetter() : void
    {
        $someObject = new stdClass();
        Helper::assign($someObject, $uuid = '7a0d5e9c-164b-4c4a-9239-3fec16997afe');

        $this->assertNotNull($someObject->uuid);
        $this->assertEquals($uuid, $someObject->uuid);
    }
}
