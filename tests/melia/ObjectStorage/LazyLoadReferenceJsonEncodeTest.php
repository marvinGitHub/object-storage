<?php

namespace Tests\melia\ObjectStorage;

use JsonSerializable;
use melia\ObjectStorage\Exception\DanglingReferenceException;
use melia\ObjectStorage\LazyLoadReference;
use stdClass;

/**
 * Tests für json_encode mit LazyLoadReference
 */
class LazyLoadReferenceJsonEncodeTest extends TestCase
{
    /**
     * Testet, ob json_encode das unwrapped (geladene) Objekt encodiert und nicht die LazyLoadReference selbst
     */
    public function testJsonEncodeUnwrapsLazyLoadReference(): void
    {
        // Erstelle ein Test-Objekt und speichere es
        $originalObject = new TestObjectWithReference();
        $originalObject->child = new TestObjectWithReference();

        $uuid = $this->storage->store($originalObject);
        $this->storage->clearCache();

        $loadedObject = $this->storage->load($uuid);

        $this->assertInstanceOf(LazyLoadReference::class, $loadedObject->child);

        // Führe json_encode auf das geladene Objekt aus
        $jsonResult = json_encode($loadedObject);

        // Verifiziere, dass JSON erfolgreich erstellt wurde
        $this->assertIsString($jsonResult);
        $this->assertNotFalse($jsonResult);

        // Decode das JSON zurück zu einem Array
        $decodedData = json_decode($jsonResult, true);

        // Verifiziere, dass die LazyLoadReference unwrapped wurde
        $this->assertIsArray($decodedData);
        $this->assertArrayHasKey('child', $decodedData);

        // Das 'self' sollte nicht die LazyLoadReference-Struktur enthalten
        $this->assertArrayNotHasKey('loadedObject', $decodedData['child']);
        $this->assertArrayNotHasKey('parent', $decodedData['child']);
        $this->assertArrayNotHasKey('path', $decodedData['child']);
    }

    /**
     * Testet json_encode mit einer nicht-geladenen LazyLoadReference
     */
    public function testJsonEncodeWithUnloadedLazyLoadReference(): void
    {
        $originalObject = new stdClass();
        $child = new stdClass();
        $child->name = 'Lazy-Child';
        $originalObject->child = $child;


        $uuid = $this->storage->store($originalObject);

        $this->storage->clearCache();
        $loadedObject = $this->storage->load($uuid);

        $lazyRef = $loadedObject->child;
        $this->assertInstanceOf(LazyLoadReference::class, $lazyRef);
        $this->assertFalse($lazyRef->isLoaded());

        // json_encode sollte das Laden der Referenz auslösen
        $jsonResult = json_encode($loadedObject);

        // Das JSON sollte erfolgreich erstellt worden sein
        $this->assertIsString($jsonResult);
        $this->assertNotFalse($jsonResult);

        // Nach json_encode sollte die LazyLoadReference geladen sein
        $this->assertTrue($lazyRef->isLoaded());
    }

    /**
     * Testet json_encode mit einer DanglingReference (nicht existierendes Objekt)
     */
    public function testJsonEncodeWithDanglingReference(): void
    {
        // Erstelle ein Test-Objekt
        $testObject = new TestObjectWithReference();


        // Erstelle eine LazyLoadReference zu einem nicht-existierenden Objekt
        $danglingRef = new LazyLoadReference(
            $this->storage,
            $this->storage->getNextAvailableUuid(),
            $testObject,
            ['self']
        );

        $testObject->self = $danglingRef;

        // json_encode sollte eine Exception auslösen, wenn versucht wird, 
        // die dangling reference zu laden
        $this->expectException(DanglingReferenceException::class);
        json_encode($testObject);
    }

    /**
     * Testet json_encode mit mehrfach verschachtelten LazyLoadReferences
     */
    public function testJsonEncodeWithNestedLazyLoadReferences(): void
    {
        // Erstelle zwei verknüpfte Objekte
        $object1 = new stdClass();
        $object2 = new stdClass();
        $object2->name = 'Nested Object';

        $object1->self = $object2;


        $uuid1 = $this->storage->store($object1);
        $uuid2 = $this->storage->store($object2);

        // Lade das erste Objekt
        $loadedObject1 = $this->storage->load($uuid1);

        // json_encode sollte alle verschachtelten LazyLoadReferences auflösen
        $jsonResult = json_encode($loadedObject1);

        $this->assertIsString($jsonResult);
        $this->assertNotFalse($jsonResult);

        // Decode und verifiziere die Struktur
        $decodedData = json_decode($jsonResult, true);
        $this->assertIsArray($decodedData);
        $this->assertArrayHasKey('self', $decodedData);
        $this->assertArrayHasKey('name', $decodedData['self']);
        $this->assertEquals('Nested Object', $decodedData['self']['name']);
    }

    /**
     * Testet, ob json_encode mit JsonSerializable Interface funktioniert
     * wenn LazyLoadReference es implementieren würde
     */
    public function testJsonEncodeCallsJsonSerializableOnUnwrappedObject(): void
    {
        // Erstelle ein Test-Objekt mit Custom JSON Serialization
        $customObject = new class implements JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['custom' => 'serialized', 'data' => true];
            }
        };

        // Da TestObjectWithReference kein JsonSerializable implementiert,
        // testen wir das Verhalten mit einem mock
        $testObject = new stdClass();
        $testObject->self = $customObject;

        $uuid = $this->storage->store($testObject);
        $loadedObject = $this->storage->load($uuid);

        $jsonResult = json_encode($loadedObject);
        $decodedData = json_decode($jsonResult, true);

        // Verifiziere, dass das custom serialized Objekt korrekt behandelt wurde
        $this->assertIsArray($decodedData);
        $this->assertArrayHasKey('self', $decodedData);
        $this->assertArrayHasKey('custom', $decodedData['self']);
        $this->assertArrayHasKey('data', $decodedData['self']);
        $this->assertEquals('serialized', $decodedData['self']['custom']);
        $this->assertTrue($decodedData['self']['data']);
    }
}