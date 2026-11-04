<?php

namespace Tests\melia\ObjectStorage\Cli;

use melia\ObjectStorage\Cli\Application;
use melia\ObjectStorage\Configuration\CacheSettings;
use melia\ObjectStorage\Configuration\Configuration;
use melia\ObjectStorage\Configuration\LockSettings;
use melia\ObjectStorage\Configuration\Registry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\melia\ObjectStorage\Search\Product;
use Tests\melia\ObjectStorage\TestCase;

/**
 * The CLI opens storages with their configuration and manages configurations.
 */
class ConfigurationCommandsTest extends TestCase
{
    private Application $application;
    private string $registryDir;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->application = new Application();
        $this->registryDir = $this->storageDirs[] = $this->createTemporaryDirectory();
        $this->dir = $this->storageDirs[] = $this->createTemporaryDirectory();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(string $command, array $input = []): CommandTester
    {
        $tester = new CommandTester($this->application->find($command));
        $tester->execute($input + ['--dir' => $this->dir, '--configurations' => $this->registryDir]);
        return $tester;
    }

    public function testImportAssignListAndExport(): void
    {
        $file = $this->createTemporaryFile();
        file_put_contents($file, json_encode(['name' => 'tuned', 'description' => 'no cache', 'cache' => ['type' => 'none'], 'maxDepth' => 64]));

        $import = $this->runCommand('configuration:import', ['file' => $file]);
        $this->assertSame(Command::SUCCESS, $import->getStatusCode(), $import->getDisplay());
        $this->assertStringContainsString('Configuration tuned created', $import->getDisplay());

        $this->assertSame(Command::FAILURE, $this->runCommand('configuration:import', ['file' => $file])->getStatusCode(), 'exists already');
        $this->assertSame(Command::SUCCESS, $this->runCommand('configuration:import', ['file' => $file, '--replace' => true])->getStatusCode());

        $this->assertSame(Command::SUCCESS, $this->runCommand('configuration:assign', ['name' => 'tuned'])->getStatusCode());
        $this->assertSame('tuned', Registry::getAssignedName($this->dir));

        $list = $this->runCommand('configuration:list');
        $this->assertStringContainsString('tuned *', $list->getDisplay());

        $export = json_decode($this->runCommand('configuration:export', ['name' => 'tuned'])->getDisplay(), true);
        $this->assertSame(64, $export['maxDepth']);
        $this->assertSame('none', $export['cache']['type']);

        unlink($file);
    }

    public function testCommandsOpenTheStorageWithItsConfiguration(): void
    {
        $lockDir = $this->storageDirs[] = $this->createTemporaryDirectory();
        $registry = new Registry($this->registryDir);
        $registry->create(new Configuration('own-locks', lock: new LockSettings(directory: $lockDir), cache: new CacheSettings(CacheSettings::TYPE_NONE)));
        $registry->assign($this->dir, 'own-locks');

        $uuid = (new ObjectStorageOpener($registry))->store($this->dir, new Product('Apple'));
        $get = $this->runCommand('get', ['uuid' => $uuid]);

        $this->assertSame(Command::SUCCESS, $get->getStatusCode(), $get->getDisplay());
        $this->assertStringContainsString('Apple', $get->getDisplay());
        $this->assertNotEmpty(glob($lockDir . DIRECTORY_SEPARATOR . '*'), 'the locks of the configuration are used');
    }

    public function testAMissingAssignedConfigurationFailsInsteadOfUsingOtherLocks(): void
    {
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . Registry::ASSIGNMENT_FILE, json_encode(['configuration' => 'elsewhere']));

        $tester = new CommandTester($this->application->find('stats'));
        $this->application->setCatchExceptions(false);

        $this->expectExceptionMessage('The configuration "elsewhere" is not in');
        $tester->execute(['--dir' => $this->dir, '--configurations' => $this->registryDir]);
    }

    public function testCheckAndDelete(): void
    {
        (new Registry($this->registryDir))->create(new Configuration('files'));

        $check = $this->runCommand('configuration:check', ['name' => 'files']);
        $this->assertSame(Command::SUCCESS, $check->getStatusCode(), $check->getDisplay());
        $this->assertStringContainsString('lock   file locks: ok', $check->getDisplay());

        $this->assertSame(Command::SUCCESS, $this->runCommand('configuration:delete', ['name' => 'files'])->getStatusCode());
        $this->assertSame(Command::FAILURE, $this->runCommand('configuration:delete', ['name' => 'files'])->getStatusCode());
    }
}

/**
 * Stores an object the way an application does: opened with the configuration assigned to the storage.
 */
final class ObjectStorageOpener
{
    public function __construct(private readonly Registry $registry)
    {
    }

    public function store(string $dir, object $object): string
    {
        return $this->registry->open($dir)->store($object);
    }
}
