<?php

namespace Tests\melia\ObjectStorage\Cli;

use melia\ObjectStorage\Cli\Application;
use melia\ObjectStorage\ObjectStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\melia\ObjectStorage\Concurrency\Account;
use Tests\melia\ObjectStorage\TestCase;

/**
 * Unique indexes on the command line, and the commands writing objects check them.
 */
final class UniqueCommandsTest extends TestCase
{
    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(string $command, array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application())->find($command));
        $tester->execute($input + ['--dir' => $this->storage->getStorageDir(), '--configurations' => $this->storageDirs[] = $this->createTemporaryDirectory()]);
        return $tester;
    }

    public function testCreateListFindRebuildAndDrop(): void
    {
        $ada = $this->storage->store(new Account('ada@example.com'));

        $create = $this->runCommand('unique:create', ['name' => 'account-email', '--class' => Account::class, '--path' => ['email'], '--case-insensitive' => true]);
        $this->assertSame(Command::SUCCESS, $create->getStatusCode(), $create->getDisplay());

        $this->assertStringContainsString('account-email', $this->runCommand('unique:list')->getDisplay());

        $find = $this->runCommand('unique:find', ['name' => 'account-email', 'values' => ['ADA@example.com']]);
        $this->assertSame($ada, trim($find->getDisplay()));
        $this->assertSame(Command::FAILURE, $this->runCommand('unique:find', ['name' => 'account-email', 'values' => ['bob@example.com']])->getStatusCode());

        $this->assertStringContainsString('1 value(s)', $this->runCommand('unique:rebuild', ['name' => 'account-email'])->getDisplay());
        $this->assertSame(Command::SUCCESS, $this->runCommand('unique:drop', ['name' => 'account-email'])->getStatusCode());
        $this->assertSame(Command::FAILURE, $this->runCommand('unique:drop', ['name' => 'account-email'])->getStatusCode());
    }

    public function testCreatingOverDuplicatesListsThem(): void
    {
        $first = $this->storage->store(new Account('ada@example.com'));
        $second = $this->storage->store(new Account('ada@example.com'));

        $create = $this->runCommand('unique:create', ['name' => 'account-email', '--class' => Account::class, '--path' => ['email']]);

        $this->assertSame(Command::FAILURE, $create->getStatusCode());
        $this->assertStringContainsString($first, $create->getDisplay());
        $this->assertStringContainsString($second, $create->getDisplay());
    }

    public function testPutAndCopyAreChecked(): void
    {
        $this->runCommand('unique:create', ['name' => 'account-email', '--class' => Account::class, '--path' => ['email']]);
        (new \melia\ObjectStorage\Unique\Manager($this->storage))->attach(); // the application writing to the storage
        $this->storage->store(new Account('ada@example.com'));

        // a copy into a storage with the index and an account of the email fails
        $source = new ObjectStorage($this->storageDirs[] = $this->createTemporaryDirectory());
        $uuid = $source->store(new Account('ada@example.com'));
        $tester = new CommandTester((new Application())->find('copy'));
        $tester->execute(['uuids' => [$uuid], '--dir' => $source->getStorageDir(), '--to' => $this->storage->getStorageDir(), '--configurations' => $this->storageDirs[] = $this->createTemporaryDirectory()]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Unique index "account-email"', $tester->getDisplay());
        $this->assertSame(1, iterator_count((new ObjectStorage($this->storage->getStorageDir()))->list(Account::class)));
    }

    public function testAStorageWithoutUniqueIndexesGetsNoDirectoryForThem(): void
    {
        $this->runCommand('stats');
        $this->storage->store(new Account('ada@example.com'));
        $this->runCommand('list');

        $this->assertDirectoryDoesNotExist($this->storage->getStorageDir() . '/unique');
    }
}
