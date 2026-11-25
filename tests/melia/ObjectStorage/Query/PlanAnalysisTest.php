<?php

namespace Tests\melia\ObjectStorage\Query;

use melia\ObjectStorage\Query\Ast\Select;
use melia\ObjectStorage\Query\Parser;
use melia\ObjectStorage\Query\Plan;
use melia\ObjectStorage\Query\PlanAnalysis;
use melia\ObjectStorage\Query\Planner;
use PHPUnit\Framework\TestCase;

class PlanAnalysisTest extends TestCase
{
    private function plan(string $query): Plan
    {
        $select = Parser::parse($query);
        $this->assertInstanceOf(Select::class, $select);
        return (new Planner())->plan($select);
    }

    public function testThePropertiesReadOfEachAlias(): void
    {
        $plan = $this->plan('SELECT p.name, c.title FROM Person p JOIN Company c ON c = p.company WHERE p.age > 3 ORDER BY c.city.name');

        $keys = PlanAnalysis::keysUsed($plan);
        $this->assertSame(['name' => true, 'company' => true, 'age' => true], $keys['p']);
        $this->assertSame(['title' => true, 'city' => true], $keys['c'], 'the first names of the paths');

        $this->assertSame(['name' => 'A'], PlanAnalysis::stripped(['uuid' => 'x', 'data' => ['name' => 'A', 'other' => 1]], ['name' => true])['data']);
        $this->assertSame([], PlanAnalysis::keepOf(['p' => null], 'q'), 'an alias the query doesn\'t read');
        $this->assertNull(PlanAnalysis::keepOf(['p' => null], 'p'), 'all');
    }

    public function testStarReadsAllProperties(): void
    {
        $keys = PlanAnalysis::keysUsed($this->plan('SELECT c.*, p.name FROM Person p JOIN Company c ON c = p.company'));

        $this->assertNull($keys['c']);
        $this->assertSame(['name' => true, 'company' => true], $keys['p']);
        $this->assertNull(PlanAnalysis::keysUsed($this->plan('SELECT * FROM Person p'))['p']);
        $this->assertSame(['tags' => true], PlanAnalysis::keysUsed($this->plan('SELECT p.tags[0] FROM Person p'))['p'], 'the first name of a path with an index');
    }

    public function testTheAggregatesOfAPlan(): void
    {
        $plan = $this->plan('SELECT p.city, COUNT(*), MAX(p.age) FROM Person p GROUP BY p.city HAVING SUM(p.age) > 3 ORDER BY COUNT(*)');

        $names = array_map(static fn($call): string => $call->name, array_values(PlanAnalysis::aggregatesOf($plan)));
        $this->assertSame(['COUNT', 'MAX', 'SUM'], array_values(array_unique($names)));
    }
}
