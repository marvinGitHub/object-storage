<?php

namespace Tests\melia\ObjectStorage\Cli;

use melia\ObjectStorage\Cli\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\melia\ObjectStorage\Search\Category;
use Tests\melia\ObjectStorage\Search\Product;
use Tests\melia\ObjectStorage\TestCase;

final class QueryCommandTest extends TestCase
{
    private function runQuery(array $input): CommandTester
    {
        $tester = new CommandTester((new Application())->find('query'));
        $tester->execute($input + ['--dir' => $this->storage->getStorageDir(), '--configurations' => $this->storageDirs[] = $this->createTemporaryDirectory()]);
        return $tester;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $fruit = new Category('Fruit');
        $this->storage->store(new Product('Apple', price: 1.5, tags: ['red'], category: $fruit));
        $this->storage->store(new Product('Banana', price: 2, category: $fruit));
        $this->storage->store(new Product('Chair', price: 49));
    }

    public function testATable(): void
    {
        $tester = $this->runQuery(['query' => 'SELECT name, price, category.title AS category, tags FROM Product ORDER BY price']);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $display = $tester->getDisplay();
        $this->assertMatchesRegularExpression('/name\s+price\s+category\s+tags/', $display);
        $this->assertMatchesRegularExpression('/Apple\s+1\.5\s+Fruit\s+\["red"\]/', $display);
        $this->assertMatchesRegularExpression('/Chair\s+49\s+NULL/', $display);
        $this->assertStringContainsString('3 row(s)', $display);
    }

    public function testJsonAndParameters(): void
    {
        $tester = $this->runQuery([
            'query' => 'SELECT name, price FROM Product WHERE price < :max AND name != ? ORDER BY name',
            '--param' => ['max=10', '"Banana"'],
            '--json' => true,
        ]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame([['name' => 'Apple', 'price' => 1.5]], json_decode($tester->getDisplay(), true));
    }

    public function testAQueryFromAFile(): void
    {
        $file = $this->createTemporaryDirectory() . '/query.oql';
        file_put_contents($file, "-- products per category\nSELECT category.title, COUNT(*) FROM Product GROUP BY 1 ORDER BY 2 DESC");
        $tester = $this->runQuery(['--file' => $file, '--json' => true]);
        $this->assertSame([['category.title' => 'Fruit', 'COUNT(*)' => 2], ['category.title' => null, 'COUNT(*)' => 1]], json_decode($tester->getDisplay(), true));
    }

    public function testWriting(): void
    {
        $dry = $this->runQuery(['query' => 'UPDATE Product SET price = price * 2 WHERE price < 10', '--dry-run' => true]);
        $this->assertSame(Command::SUCCESS, $dry->getStatusCode(), $dry->getDisplay());
        $this->assertStringContainsString('Dry run: 2 object(s) would be updated, nothing was written.', $dry->getDisplay());

        $update = $this->runQuery(['query' => 'UPDATE Product SET price = price * 2 WHERE price < 10', '--json' => true]);
        $json = json_decode($update->getDisplay(), true);
        $this->assertSame(['statement' => 'UPDATE', 'dryRun' => false, 'affected' => 2], array_slice($json, 0, 3));
        $this->assertCount(2, $json['uuids']);

        $insert = $this->runQuery(['query' => "INSERT INTO Category (title) VALUES ('Tools')"]);
        $this->assertStringContainsString('1 object(s) inserted.', $insert->getDisplay());
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/m', $insert->getDisplay());

        $delete = $this->runQuery(['query' => 'DELETE FROM Product WHERE UUID = :u', '--param' => ['u="' . $json['uuids'][0] . '"']]);
        $this->assertStringContainsString('1 object(s) deleted.', $delete->getDisplay());
        $this->assertSame([[2]], (new \melia\ObjectStorage\Query\Oql($this->storage))->query('SELECT COUNT(*) FROM Product')->rows);
    }

    public function testErrors(): void
    {
        $tester = $this->runQuery(['query' => 'SELECT name FROM Product WHERE']);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Unexpected end of the query', $tester->getDisplay());

        $tester = $this->runQuery(['--file' => '/does/not/exist']);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Unable to read', $tester->getDisplay());
    }
}
