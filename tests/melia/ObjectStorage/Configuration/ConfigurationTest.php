<?php

namespace Tests\melia\ObjectStorage\Configuration;

use melia\ObjectStorage\Cache\InMemoryCache;
use melia\ObjectStorage\Configuration\CacheSettings;
use melia\ObjectStorage\Configuration\Configuration;
use melia\ObjectStorage\Configuration\Exception\ConfigurationAlreadyExistsException;
use melia\ObjectStorage\Configuration\Exception\ConfigurationException;
use melia\ObjectStorage\Configuration\Exception\ConfigurationNotFoundException;
use melia\ObjectStorage\Configuration\Exception\InvalidConfigurationException;
use melia\ObjectStorage\Configuration\Factory;
use melia\ObjectStorage\Configuration\LockSettings;
use melia\ObjectStorage\Configuration\Registry;
use melia\ObjectStorage\Locking\Backends\SymfonyLockAdapter;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\Search\Index\Manager;
use melia\ObjectStorage\Strategy\Policy\ChildDeletion;
use melia\ObjectStorage\Strategy\Policy\ChildPersistence;
use melia\ObjectStorage\Strategy\Standard;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Cache\Psr16Cache;
use Tests\melia\ObjectStorage\Search\ArrayEngine;
use Tests\melia\ObjectStorage\Search\Product;
use Tests\melia\ObjectStorage\TestCase;

/**
 * Named configurations of storages: settings, creating what they describe, keeping and assigning them.
 */
class ConfigurationTest extends TestCase
{
    private Registry $registry;
    private string $registryDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registryDir = $this->createTemporaryDirectory();
        $this->storageDirs[] = $this->registryDir;
        $this->registry = new Registry($this->registryDir);
    }

    private function storageDir(): string
    {
        return $this->storageDirs[] = $this->createTemporaryDirectory();
    }

    public function testSettingsSurviveStoring(): void
    {
        $configuration = new Configuration(
            'shared',
            'Redis locks, files cache',
            new LockSettings(LockSettings::TYPE_REDIS, 'redis://redis:6379', ttl: 60, prefix: 'app.'),
            new CacheSettings(CacheSettings::TYPE_FILESYSTEM, directory: '/tmp/cache', defaultLifetime: 300),
            shardDepth: 3,
            maxDepth: 64,
            lifetimeInheritance: true,
            optimisticLocking: true,
            childPersistence: ChildPersistence::ALWAYS,
            childDeletion: ChildDeletion::ALWAYS,
            fsync: false,
        );

        $this->registry->create($configuration);

        $this->assertEquals($configuration, $this->registry->get('shared'));
        $this->assertEquals($configuration, Configuration::fromArray($configuration->toArray()));
        $this->assertSame(['shared'], array_keys($this->registry->list()));
    }

    public static function invalidConfigurations(): array
    {
        return [
            'name with uppercase' => [static fn() => new Configuration('Shared')],
            'name with a slash' => [static fn() => new Configuration('a/b')],
            'unknown lock type' => [static fn() => new Configuration('a', lock: new LockSettings('zookeeper'))],
            'redis without connection' => [static fn() => new LockSettings(LockSettings::TYPE_REDIS)],
            'redis with another connection' => [static fn() => new LockSettings(LockSettings::TYPE_REDIS, 'memcached://localhost')],
            'negative ttl' => [static fn() => new LockSettings(ttl: -1)],
            'unknown cache type' => [static fn() => new CacheSettings('opcache')],
            'cache namespace with a slash' => [static fn() => new CacheSettings(namespace: 'a/b')],
            'callback policy' => [static fn() => new Configuration('a', childDeletion: ChildDeletion::CALLBACK)],
            'shard depth' => [static fn() => new Configuration('a', shardDepth: 17)],
        ];
    }

    #[DataProvider('invalidConfigurations')]
    public function testInvalidSettingsAreRejected(callable $create): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $create();
    }

    public function testNamesAreUnique(): void
    {
        $this->registry->create(new Configuration('shared'));

        $this->expectException(ConfigurationAlreadyExistsException::class);
        $this->registry->create(new Configuration('shared', 'another'));
    }

    public function testUpdateAndDelete(): void
    {
        $this->registry->create(new Configuration('shared'));
        $this->registry->update(new Configuration('shared', 'changed', fsync: false));

        $this->assertSame('changed', $this->registry->get('shared')->description);
        $this->assertTrue($this->registry->delete('shared'));
        $this->assertFalse($this->registry->has('shared'));
        $this->assertFalse($this->registry->delete('shared'), 'nothing left to delete');

        $this->expectException(ConfigurationNotFoundException::class);
        $this->registry->update(new Configuration('shared'));
    }

    public function testAStorageIsOpenedWithItsAssignedConfiguration(): void
    {
        $dir = $this->storageDir();
        $lockDir = $this->storageDir();
        $this->registry->create(new Configuration(
            'tuned',
            lock: new LockSettings(directory: $lockDir, prefix: 'tuned.'),
            cache: new CacheSettings(CacheSettings::TYPE_NONE),
            maxDepth: 32,
            lifetimeInheritance: true,
            fsync: false,
        ));

        $this->assertNull(Registry::getAssignedName($dir));
        $this->registry->assign($dir, 'tuned');
        $this->assertSame('tuned', Registry::getAssignedName($dir));

        $storage = $this->registry->open($dir);
        $this->assertSame('tuned', $storage->getConfiguration()?->name);
        $this->assertSame(32, $storage->getStrategy()->getMaxDepth());
        $this->assertNull($storage->getCache(), 'no cache');

        // the locks are kept in the configured directory
        $uuid = $storage->store(new Product('Apple'));
        $this->assertSame('Apple', $storage->load($uuid)->name);
        $this->assertNotEmpty(glob($lockDir . DIRECTORY_SEPARATOR . '*'));

        $this->registry->assign($dir, null);
        $this->assertNull($this->registry->open($dir)->getConfiguration(), 'the defaults again');
    }

    public function testAStorageIsOpenedWithANamedConfiguration(): void
    {
        $this->registry->create(new Configuration('no-cache', cache: new CacheSettings(CacheSettings::TYPE_NONE)));

        $storage = $this->registry->open($this->storageDir(), 'no-cache');

        $this->assertNull($storage->getCache());
        $this->assertNull($storage->getMetadataCache());
    }

    public function testOnlyExistingConfigurationsCanBeAssignedOrOpened(): void
    {
        $dir = $this->storageDir();

        try {
            $this->registry->assign($dir, 'missing');
            $this->fail('assigned a missing configuration');
        } catch (ConfigurationNotFoundException) {
        }

        $this->registry->create(new Configuration('gone'));
        $this->registry->assign($dir, 'gone');
        $this->registry->delete('gone');

        $this->expectException(ConfigurationNotFoundException::class);
        $this->registry->open($dir);
    }

    public function testArgumentsPassedExplicitlyTakePrecedence(): void
    {
        $cache = new InMemoryCache();
        $strategy = new Standard();
        $configuration = new Configuration('a', cache: new CacheSettings(CacheSettings::TYPE_NONE), maxDepth: 7);

        $storage = new ObjectStorage($this->storageDir(), cache: $cache, strategy: $strategy, configuration: $configuration);

        $this->assertSame($cache, $storage->getCache());
        $this->assertNull($storage->getMetadataCache(), 'not passed: from the configuration');
        $this->assertSame(Standard::DEFAULT_MAX_DEPTH, $strategy->getMaxDepth(), 'a strategy passed keeps its settings');
    }

    public function testInternalStoragesUseTheLocksOfTheStorage(): void
    {
        $lockDir = $this->storageDir();
        $storage = new ObjectStorage($this->storageDir(), configuration: new Configuration('a', lock: new LockSettings(directory: $lockDir), shardDepth: 3));

        $internal = (new Manager($storage, [new ArrayEngine()]))->getInternalStorage();

        $this->assertSame('a', $internal->getConfiguration()?->name);
        $this->assertSame(Standard::DEFAULT_SHARD_DEPTH, $internal->getStrategy()->getShardDepth(), 'the default strategy');
        $this->assertNull($internal->getCache());

        $internal->store(new Product('Apple'));
        $this->assertNotEmpty(glob($lockDir . DIRECTORY_SEPARATOR . '*'));
    }

    public function testFactoryCreatesSharedCachesPerStorage(): void
    {
        $factory = new Factory();
        $settings = new CacheSettings(CacheSettings::TYPE_FILESYSTEM, directory: $this->storageDir());
        $first = $factory->createCache($settings, $this->storageDir());
        $second = $factory->createCache($settings, $this->storageDir());
        $metadata = $factory->createCache($settings, $this->storageDir(), Factory::CACHE_METADATA);

        $this->assertInstanceOf(Psr16Cache::class, $first);
        $first->set('key', 'first');
        $this->assertNull($second->get('key'), 'another storage, another namespace');
        $this->assertNull($metadata->get('key'));
    }

    public function testCheckTakesALockAndUsesTheCache(): void
    {
        $configuration = new Configuration('a', cache: new CacheSettings(CacheSettings::TYPE_FILESYSTEM, directory: $this->storageDir()));

        $results = (new Factory())->check($configuration, $this->storageDir());

        $this->assertSame(['lock', 'cache'], array_keys($results));
        $this->assertStringEndsWith(': ok', $results['cache']);
    }

    public function testAnUnreachableBackendFailsWithAConfigurationException(): void
    {
        // an invalid connection fails when the store is created or used
        $configuration = new Configuration('a', lock: new LockSettings(LockSettings::TYPE_DSN, 'nonsense://nowhere'));

        $this->expectException(ConfigurationException::class);
        (new Factory())->check($configuration, $this->storageDir());
    }

    public function testEnvironmentVariablesAreResolved(): void
    {
        putenv('OBJECT_STORAGE_TEST_SECRET=s3cret');
        try {
            $this->assertSame('mysql:host=db;password=s3cret', Factory::resolve('mysql:host=db;password=%env(OBJECT_STORAGE_TEST_SECRET)%'));
        } finally {
            putenv('OBJECT_STORAGE_TEST_SECRET');
        }

        $this->expectException(InvalidConfigurationException::class);
        Factory::resolve('%env(OBJECT_STORAGE_TEST_MISSING)%');
    }

    public function testPasswordsAreMaskedInDescriptions(): void
    {
        $this->assertSame('redis redis://user:***@host:6379', (new LockSettings(LockSettings::TYPE_REDIS, 'redis://user:secret@host:6379'))->describe());
        $this->assertSame('pdo mysql:host=db;password=***', (new LockSettings(LockSettings::TYPE_PDO, 'mysql:host=db;password=secret'))->describe());
        $this->assertInstanceOf(SymfonyLockAdapter::class, (new Factory())->createLockAdapter(new LockSettings(), $this->storageDir()));
    }
}
