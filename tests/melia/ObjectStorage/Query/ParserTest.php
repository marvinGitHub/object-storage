<?php

namespace Tests\melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\Ast\Join;
use melia\ObjectStorage\Query\Ast\TransactionControl;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Lexer;
use melia\ObjectStorage\Query\Parser;
use melia\ObjectStorage\Query\Printer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    private static function where(string $condition): string
    {
        return Printer::print(Parser::parse("SELECT x FROM T WHERE $condition")->where);
    }

    public static function precedence(): array
    {
        return [
            'AND before OR' => ['a = 1 OR b = 2 AND c = 3', '((a = 1) OR ((b = 2) AND (c = 3)))'],
            'NOT before AND' => ['NOT a = 1 AND b', '(NOT ((a = 1)) AND b)'],
            '* before +' => ['a + b * c = 7', '((a + (b * c)) = 7)'],
            '|| before *' => ["a * b || c = 1", '((a * (b || c)) = 1)'],
            'left to right' => ['a - b - c = 0', '(((a - b) - c) = 0)'],
            'comparison before equality' => ['a < b = c', '((a < b) = c)'],
            'BETWEEN takes its AND' => ['a BETWEEN 1 AND 2 AND b', '((a BETWEEN 1 AND 2) AND b)'],
            'NOT IN, NOT LIKE, NOT BETWEEN' => ["a NOT IN (1, 'x') AND b NOT LIKE 'y%' AND c NOT BETWEEN 1 AND 2", "(((a NOT IN (1, 'x')) AND (b NOT LIKE 'y%')) AND (c NOT BETWEEN 1 AND 2))"],
            'IS NOT NULL' => ['a IS NOT NULL OR b IS NULL', '((a IS NOT NULL) OR (b IS NULL))'],
            'negative numbers' => ['a = -1.5 AND b = - c', '((a = -1.5) AND (b = -(c)))'],
            'parentheses' => ['(a = 1 OR b = 2) AND c', '(((a = 1) OR (b = 2)) AND c)'],
            'paths and functions' => ['LOWER(p.category.title) != COALESCE(x, NULL, TRUE)', '(LOWER(p.category.title) != COALESCE(x, NULL, TRUE))'],
            '<> is !=' => ['a <> 1', '(a != 1)'],
            'numbers' => ['a = 10 AND b = 1e3 AND c = .5 AND d = 007', '((((a = 10) AND (b = 1000.0)) AND (c = 0.5)) AND (d = 7))'],
            'strings' => ["a = 'it''s'", "(a = 'it''s')"],
            'keywords in any case' => ['a is not null and b in (1)', '((a IS NOT NULL) AND (b IN (1)))'],
        ];
    }

    #[DataProvider('precedence')]
    public function testPrecedence(string $condition, string $expected): void
    {
        $this->assertSame($expected, self::where($condition));
    }

    public function testStatement(): void
    {
        $select = Parser::parse(
            'SELECT DISTINCT p.name AS n, COUNT(DISTINCT c.title) total, c.* FROM `App\Product` p
             LEFT OUTER JOIN Category AS c ON c = p.category INNER JOIN Tag t ON t = p.tag JOIN Tag ON 1 = 1
             WHERE p.price > :min GROUP BY p.name, 2 HAVING COUNT(*) > ? ORDER BY n DESC, 2 ASC, p.name LIMIT 10 OFFSET 5;'
        );
        $this->assertTrue($select->distinct);
        $this->assertSame('n', $select->items[0]->alias);
        $this->assertSame('p.name', $select->items[0]->text);
        $this->assertSame('total', $select->items[1]->alias);
        $this->assertSame('COUNT(DISTINCT c.title)', $select->items[1]->text);
        $this->assertSame('c', $select->items[2]->starOf);
        $this->assertSame('App\Product', $select->from->class);
        $this->assertSame('p', $select->from->alias);
        $this->assertSame([Join::LEFT, Join::INNER, Join::INNER], array_map(static fn($join) => $join->type, $select->joins));
        $this->assertSame('Tag', $select->joins[2]->source->alias, 'the short name of the class');
        $this->assertCount(2, $select->groupBy);
        $this->assertSame([true, false, false], array_map(static fn($order) => $order->descending, $select->orderBy));
        $this->assertSame('10', Printer::print($select->limit));
        $this->assertSame('5', Printer::print($select->offset));
        $this->assertSame('(p.price > :min)', Printer::print($select->where));
        $this->assertSame('(COUNT(*) > ?0)', Printer::print($select->having));
    }

    public function testShortNameOfAQualifiedClass(): void
    {
        $this->assertSame('Product', Parser::parse('SELECT name FROM \App\Entity\Product')->from->alias);
        $this->assertSame('App\Entity\Product', Parser::parse('SELECT name FROM \App\Entity\Product')->from->class);
    }

    public function testTokens(): void
    {
        $tokens = Lexer::tokenize("SELECT a.b, 'x''y', 1.5, ?, :name -- comment\n/* block */ <> `quoted id`");
        $this->assertSame(
            [['keyword', 'SELECT'], ['identifier', 'a'], ['symbol', '.'], ['identifier', 'b'], ['symbol', ','], ['string', "x'y"], ['symbol', ','],
                ['number', '1.5'], ['symbol', ','], ['parameter', '0'], ['symbol', ','], ['parameter', 'name'], ['symbol', '!='], ['identifier', 'quoted id'], ['end', '']],
            array_map(static fn($token) => [$token->type, $token->value], $tokens)
        );
    }

    public static function invalid(): array
    {
        return [
            ['SELECT', 'Unexpected end of the query'],
            ['SELECT a FROM', 'Expected a name, found the end of the query'],
            ['SELECT a FROM T WHERE a = 1abc', 'Invalid number'],
            ['SELECT a FROM T WHERE a = #', 'Unexpected character "#"'],
            ['SELECT a FROM T /* open', 'Unterminated comment'],
            ['SELECT `open FROM T', 'Unterminated quoted identifier'],
            ['SELECT a FROM T WHERE a IN ()', 'Unexpected ")"'],
            ['SELECT a FROM T WHERE a BETWEEN 1', 'Expected AND'],
            ['SELECT a FROM T WHERE a IS 1', 'Expected NULL'],
            ['SELECT a FROM T JOIN U', 'Expected ON'],
            ['SELECT a FROM T GROUP a', 'Expected BY'],
            ['INSERT INTO T VALUES (1)', 'Expected ('],
            ['INSERT INTO T (a, b) VALUES (1)', '1 values for 2 properties'],
            ['INSERT INTO T (a.b) VALUES (1)', 'Only properties of the object itself'],
            ['UPDATE T SET a.b. = 1', 'Expected a name'],
            ['UPDATE T REMOVE a', 'REMOVE takes keys and elements'],
            ['INSERT INTO T (a.b) VALUES (1)', 'Only properties of the object itself'],
            ['UPDATE T SET UUID = 1', 'Expected a name, found "UUID"'],
            ['UPDATE T a = 1', 'SET or REMOVE expected'],
            ['DELETE T', 'Expected FROM'],
            ['SELECT p.UUID.name FROM T p', 'Expected FROM, found "."'],
            ['START', 'START TRANSACTION expected'],
            ['START WORK', 'START TRANSACTION expected'],
            ['COMMIT now', 'Unexpected "now" after the end of the query'],
            ['SELECT a FROM T; SELECT b FROM T', 'Unexpected "SELECT" after the end of the query'],
        ];
    }

    #[DataProvider('invalid')]
    public function testInvalidQueries(string $query, string $message): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage($message);
        Parser::parse($query);
    }

    public function testTransactionStatements(): void
    {
        foreach ([
            'START TRANSACTION' => TransactionControl::BEGIN,
            'start transaction;' => TransactionControl::BEGIN,
            'BEGIN' => TransactionControl::BEGIN,
            'begin work' => TransactionControl::BEGIN,
            'COMMIT' => TransactionControl::COMMIT,
            'COMMIT WORK;' => TransactionControl::COMMIT,
            'ROLLBACK' => TransactionControl::ROLLBACK,
            'Rollback Work' => TransactionControl::ROLLBACK,
        ] as $query => $action) {
            $statement = Parser::parse($query);
            $this->assertInstanceOf(TransactionControl::class, $statement, $query);
            $this->assertSame($action, $statement->action, $query);
        }
    }

    public function testTheWordsOfTransactionsStayNames(): void
    {
        $select = Parser::parse('SELECT start, begin, commit, rollback, transaction, work FROM T WHERE begin = 1');
        $this->assertSame(['start', 'begin', 'commit', 'rollback', 'transaction', 'work'], array_map(static fn($item) => $item->text, $select->items));
    }

    public function testScriptsAreSplitAtSemicolonsOutsideStringsNamesAndComments(): void
    {
        $script = "SELECT 'a;b' FROM T; -- c;d\nSELECT `x;y` FROM T /* ; */ WHERE a = ?;;\n  DELETE FROM T WHERE b = ? ;";
        $statements = Parser::splitScript($script);

        $this->assertCount(3, $statements);
        $texts = array_map(static fn(array $s): string => trim(substr($script, $s['start'], $s['end'] - $s['start'])), $statements);
        $this->assertSame(["SELECT 'a;b' FROM T", "SELECT `x;y` FROM T /* ; */ WHERE a = ?", 'DELETE FROM T WHERE b = ?'], $texts);
        $this->assertSame([0, 0, 1], array_column($statements, 'positional'), 'the ? before each statement');
    }

    public function testAScriptWithoutStatements(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('No statement');
        Parser::splitScript("-- only a comment\n;");
    }
}
