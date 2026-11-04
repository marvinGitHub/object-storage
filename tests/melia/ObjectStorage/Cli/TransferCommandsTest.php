<?php

namespace Tests\melia\ObjectStorage\Cli;

use melia\ObjectStorage\Cli\Application;
use melia\ObjectStorage\ObjectStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\melia\ObjectStorage\Search\Category;
use Tests\melia\ObjectStorage\Search\Product;
use Tests\melia\ObjectStorage\TestCase;

/**
 * copy and move on the command line.
 */
class TransferCommandsTest extends TestCase
{
    private string $targetDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->targetDir = $this->createTemporaryDirectory();
        $this->storageDirs[] = $this->targetDir;
        rmdir($this->targetDir); // created by --create
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(string $command, array $input): CommandTester
    {
        $tester = new CommandTester((new Application())->find($command));
        $tester->execute($input + [
            '--dir' => $this->storage->getStorageDir(),
            '--to' => $this->targetDir,
            '--configurations' => $this->storageDirs[] = $this->createTemporaryDirectory(),
        ]);
        return $tester;
    }

    public function testCopyByUuidCreatesTheTarget(): void
    {
        $uuid = $this->storage->store(new Product('Chair', category: new Category('Furniture')));

        $this->assertSame(Command::FAILURE, $this->runCommand('copy', ['uuids' => [$uuid]])->getStatusCode(), 'the target doesn\'t exist');

        $tester = $this->runCommand('copy', ['uuids' => [$uuid], '--create' => true, '--with-references' => true]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Copied 1 object(s)', $tester->getDisplay());
        $this->assertStringContainsString('with 1 referenced object(s)', $tester->getDisplay());
        $this->assertSame('Furniture', (new ObjectStorage($this->targetDir))->load($uuid)->category->title);
    }

    public function testMoveAClassWithJsonOutput(): void
    {
        $products = [$this->storage->store(new Product('Apple')), $this->storage->store(new Product('Pear'))];
        $category = $this->storage->store(new Category('Fruit'));

        $tester = $this->runCommand('move', ['--class' => Product::class, '--create' => true, '--json' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertEqualsCanonicalizing($products, json_decode($tester->getDisplay(), true)['transferred']);
        $this->assertSame([$category], iterator_to_array($this->storage->list(), false), 'only the class was moved');
    }

    public function testConflictsAndInvalidInput(): void
    {
        $uuid = $this->storage->store(new Product('Apple'));
        $this->runCommand('copy', ['uuids' => [$uuid], '--create' => true]);

        $failed = $this->runCommand('copy', ['uuids' => [$uuid]]);
        $this->assertSame(Command::FAILURE, $failed->getStatusCode());
        $this->assertStringContainsString('exists in the target', $failed->getDisplay());

        $skipped = $this->runCommand('copy', ['uuids' => [$uuid], '--on-conflict' => 'skip']);
        $this->assertSame(Command::SUCCESS, $skipped->getStatusCode());
        $this->assertStringContainsString('1 skipped', $skipped->getDisplay());

        $this->assertSame(Command::INVALID, $this->runCommand('copy', ['uuids' => [$uuid], '--all' => true])->getStatusCode(), 'one selection');
        $this->assertSame(Command::INVALID, $this->runCommand('copy', ['--all' => true, '--on-conflict' => 'merge'])->getStatusCode());
    }
}
