<?php

namespace Tests\melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Oql;
use stdClass;
use Tests\melia\ObjectStorage\TestCase;

/**
 * REGEXP, NOT REGEXP, REGEXP_SUBSTR and REGEXP_REPLACE.
 */
class OqlRegexpTest extends TestCase
{
    private Oql $oql;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
                     ['Homa', 'homa@example.com', ['red', 'blue'], 'DE-10115'],
                     ['Alex', 'alex@test.org', ['green'], 'AT-1010'],
                     ['Ömer', 'oemer@example.com', [], null],
                     ['kim', 'KIM@EXAMPLE.COM', ['blue'], 'DE-80331'],
                 ] as [$name, $email, $colors, $zip]) {
            $o = new stdClass();
            $o->name = $name;
            $o->email = $email;
            $o->colors = $colors;
            $o->zip = $zip;
            $o->number = strlen($name) * 111;
            $this->storage->store($o);
        }
        $this->oql = new Oql($this->storage);
    }

    private function names(string $where, array $parameters = []): array
    {
        return array_column($this->oql->query("SELECT name FROM stdClass WHERE $where ORDER BY name", $parameters)->rows, 0);
    }

    public function testMatching(): void
    {
        $this->assertSame(['Homa', 'Ömer'], $this->names("email REGEXP '@example\\.com$'"));
        $this->assertSame(['Homa', 'kim', 'Ömer'], $this->names("email REGEXP '(?i)@example\\.com$'"), '(?i) ignores case');
        $this->assertSame(['Alex', 'Homa'], $this->names("name REGEXP '^[A-H]'"), 'case matters');
        $this->assertSame(['Ömer'], $this->names("name REGEXP '^\\p{Lu}\\p{Ll}+r$'"), 'Unicode');
        $this->assertSame(['Homa', 'kim'], $this->names("zip REGEXP '^DE-\\d{5}$'"));
        $this->assertSame(['Alex', 'Homa', 'kim'], $this->names("zip NOT REGEXP 'XX'"), 'NULL is unknown');
        $this->assertSame(['Alex'], $this->names("NOT zip REGEXP '^DE' AND zip IS NOT NULL"));
        $this->assertSame(['Alex', 'Homa'], $this->names("number REGEXP '^4'"), 'numbers as text');
        $this->assertSame(['Homa'], $this->names('email REGEXP :pattern', ['pattern' => 'homa|nobody']));
        $this->assertSame(['Alex'], $this->names("email REGEXP 'a' AND email REGEXP '\\.org'"));
    }

    public function testListsWithWildcards(): void
    {
        $this->assertSame(['Homa', 'kim'], $this->names("colors.* REGEXP '^bl'"), 'any element');
        $this->assertSame(['Alex', 'Ömer'], $this->names("colors.* NOT REGEXP '^bl'"), 'no element');
    }

    public function testFunctions(): void
    {
        $result = $this->oql->query("SELECT name, REGEXP_SUBSTR(email, '@(.+)$') AS domain, REGEXP_REPLACE(zip, '^([A-Z]+)-(\\d+)$', '$2 ($1)') AS zip FROM stdClass ORDER BY name");
        $this->assertSame([
            ['Alex', '@test.org', '1010 (AT)'],
            ['Homa', '@example.com', '10115 (DE)'],
            ['kim', '@EXAMPLE.COM', '80331 (DE)'],
            ['Ömer', '@example.com', null],
        ], $result->rows);
        $this->assertSame([['example', 2], ['test', 1], ['EXAMPLE', 1]], $this->oql->query(
            "SELECT REGEXP_REPLACE(REGEXP_SUBSTR(email, '@[^.]+'), '@', '') AS d, COUNT(*) AS n FROM stdClass GROUP BY REGEXP_REPLACE(REGEXP_SUBSTR(email, '@[^.]+'), '@', '') ORDER BY n DESC, d DESC"
        )->rows);
        $this->assertSame([[null]], $this->oql->query("SELECT REGEXP_SUBSTR(name, '\\d') FROM stdClass WHERE name = 'Homa'")->rows);
    }

    public function testInvalidPatterns(): void
    {
        foreach (["SELECT name FROM stdClass WHERE name REGEXP '(unclosed'", "SELECT REGEXP_SUBSTR(name, '[') FROM stdClass"] as $query) {
            try {
                $this->oql->query($query);
                $this->fail($query);
            } catch (QueryException $e) {
                $this->assertStringContainsString('Invalid regular expression', $e->getMessage());
            }
        }
        // a parameter: when it is used
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Invalid regular expression');
        $this->oql->query('SELECT name FROM stdClass WHERE name REGEXP ?', ['*']);
    }
}
