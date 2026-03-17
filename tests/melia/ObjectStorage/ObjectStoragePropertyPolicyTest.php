<?php

namespace Tests\melia\ObjectStorage;

use melia\ObjectStorage\Reflection\Reflection;
use melia\ObjectStorage\Strategy\Policy\PropertyHydration;
use melia\ObjectStorage\Strategy\Policy\PropertyPersistence;
use melia\ObjectStorage\Strategy\Standard;

class SimplePropertyPolicyObject
{
    public string $included = 'keep-me';
    public string $excluded = 'drop-me';
}

class ObjectStoragePropertyPolicyTest extends TestCase
{
    public function test_property_persistence_and_hydration_callbacks_are_applied(): void
    {
        $strategy = new class () extends Standard {
            /** @var array<string, true> */
            public array $persistedProperties = [];

            /** @var array<string, true> */
            public array $hydratedProperties = [];

            public function __construct()
            {
                $this->setPolicyPropertyPersistence(PropertyPersistence::CALLBACK);
                $this->setPolicyPropertyHydration(PropertyHydration::CALLBACK);
            }

            public function shouldPersistProperty(Reflection $reflection, string $propertyName, mixed $value): bool
            {
                $this->persistedProperties[$propertyName] = true;

                return $propertyName === 'included';
            }

            public function shouldHydrateProperty(Reflection $reflection, string $propertyName, mixed $value): bool
            {
                $this->hydratedProperties[$propertyName] = true;

                return $propertyName === 'included';
            }
        };

        $this->storage->setStrategy($strategy);

        $object = new SimplePropertyPolicyObject();

        $uuid = $this->storage->store($object);

        $this->assertArrayHasKey('included', $strategy->persistedProperties);
        $this->assertArrayHasKey('excluded', $strategy->persistedProperties);

        $this->storage->clearCache();

        $loaded = $this->storage->load($uuid);

        $this->assertNotNull($loaded);
        $this->assertSame('keep-me', $loaded->included);

        $this->assertArrayHasKey('included', $strategy->hydratedProperties);
        $this->assertArrayNotHasKey('excluded', $strategy->hydratedProperties);
    }
}