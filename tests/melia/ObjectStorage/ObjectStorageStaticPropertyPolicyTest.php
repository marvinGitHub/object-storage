<?php

declare(strict_types=1);

namespace melia\ObjectStorage;

use melia\ObjectStorage\Strategy\Policy\StaticProperty;
use melia\ObjectStorage\Strategy\Standard;
use PHPUnit\Framework\TestCase;

final class ObjectStorageStaticPropertyPolicyTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'object-storage-static-policy-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        StaticPropertyPolicyFixture::$persisted = 'initial';
        StaticPropertyPolicyFixture::$ignored = 'initial';

        $this->removeDirectory($this->storageDir);

        parent::tearDown();
    }

    public function testStaticPropertiesAreNotRestoredWhenPolicyIsNever(): void
    {
        $strategy = new Standard();
        $strategy->setPolicyStaticProperty(StaticProperty::ALWAYS);

        $storage = new ObjectStorage($this->storageDir, strategy: $strategy);

        /* we persist static property */
        StaticPropertyPolicyFixture::$persisted = 'stored-value';
        $object = new StaticPropertyPolicyFixture();
        $uuid = $storage->store($object);


        StaticPropertyPolicyFixture::$persisted = 'runtime-value';
        $strategy->setPolicyStaticProperty(StaticProperty::NEVER);
        $storage->setStrategy($strategy);
        $storage->clearCache();
        $storage->load($uuid);

        self::assertSame(
            'runtime-value',
            StaticPropertyPolicyFixture::$persisted,
            'Static properties must remain untouched when policy NEVER is used.'
        );
    }

    public function testStaticPropertiesAreRestoredWhenCallbackPolicyAllowsPersistence(): void
    {
        $strategy = new class extends Standard {
            public function shouldPersistStaticProperty(string $className, string $propertyName, mixed $value): bool
            {
                return $className === StaticPropertyPolicyFixture::class
                    && $propertyName === 'persisted';
            }
        };
        $strategy->setPolicyStaticProperty(StaticProperty::CALLBACK);

        $storage = new ObjectStorage($this->storageDir, strategy: $strategy);

        StaticPropertyPolicyFixture::$persisted = 'stored-value';
        StaticPropertyPolicyFixture::$ignored = 'ignored-stored-value';

        $object = new StaticPropertyPolicyFixture();
        $uuid = $storage->store($object);

        StaticPropertyPolicyFixture::$persisted = 'runtime-value';
        StaticPropertyPolicyFixture::$ignored = 'ignored-runtime-value';

        $storage->clearCache();
        $storage->load($uuid);

        self::assertSame(
            'stored-value',
            StaticPropertyPolicyFixture::$persisted,
            'Callback-approved static properties should be restored from storage.'
        );

        self::assertSame(
            'ignored-runtime-value',
            StaticPropertyPolicyFixture::$ignored,
            'Static properties rejected by the callback must not be restored.'
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}

final class StaticPropertyPolicyFixture
{
    public static string $persisted = 'initial';
    public static string $ignored = 'initial';

    public string $value = 'payload';
}