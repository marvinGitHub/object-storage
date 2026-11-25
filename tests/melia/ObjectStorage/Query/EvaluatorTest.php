<?php

namespace Tests\melia\ObjectStorage\Query;

use melia\ObjectStorage\Path\PathSegment;
use melia\ObjectStorage\Query\Aggregates;
use melia\ObjectStorage\Query\Ast\Binary;
use melia\ObjectStorage\Query\Ast\BoundColumn;
use melia\ObjectStorage\Query\Ast\FunctionCall;
use melia\ObjectStorage\Query\Ast\IsNull;
use melia\ObjectStorage\Query\Ast\Literal;
use melia\ObjectStorage\Query\DateValue;
use melia\ObjectStorage\Query\Evaluator;
use melia\ObjectStorage\Query\Exception\QueryException;
use melia\ObjectStorage\Query\Functions;
use PHPUnit\Framework\TestCase;

class EvaluatorTest extends TestCase
{
    private const string FRIEND = '00000000-0000-4000-8000-00000000000f';
    private const string MISSING = '00000000-0000-4000-8000-00000000000e';

    /** @var list<string> the referenced objects asked for */
    private array $followed = [];

    private function evaluator(): Evaluator
    {
        $referenced = [self::FRIEND => [['name' => 'Alex'], '__reference']];
        return new Evaluator(function (string $uuid) use ($referenced): ?array {
            $this->followed[] = $uuid;
            return $referenced[$uuid] ?? null;
        }, new Functions());
    }

    /** a row of one object of the alias p */
    private function row(array $data): array
    {
        return ['p' => ['uuid' => 'p-uuid', 'class' => 'Person', 'data' => $data, 'reserved' => '__reference', 'dateTime' => '__dateTime', 'created' => 0.0, 'modified' => 1.5]];
    }

    public function testColumnsFollowReferences(): void
    {
        $evaluator = $this->evaluator();
        $row = $this->row(['name' => 'Homa', 'friend' => ['__reference' => self::FRIEND], 'other' => ['__reference' => self::MISSING]]);

        $this->assertSame('p-uuid', $evaluator->evaluate(new BoundColumn('p', []), $row), 'the alias alone is the UUID');
        $this->assertSame('Homa', $evaluator->evaluate(new BoundColumn('p', ['name']), $row));
        $this->assertSame('Alex', $evaluator->evaluate(new BoundColumn('p', ['friend', 'name']), $row));
        $this->assertSame(self::FRIEND, $evaluator->evaluate(new BoundColumn('p', ['friend'], uuid: true), $row));
        $this->assertNull($evaluator->evaluate(new BoundColumn('p', ['other', 'name']), $row), 'a missing referenced object');
        $this->assertSame([self::FRIEND, self::MISSING], $this->followed);
        $this->assertNull($evaluator->evaluate(new BoundColumn('q', ['name']), ['q' => null]), 'the missing object of a LEFT JOIN');
        $this->assertInstanceOf(DateValue::class, $evaluator->evaluate(new FunctionCall('MODIFIED', [new BoundColumn('p', [])]), $row));
    }

    public function testConditionsOnPathsWithWildcardHoldForAnyElement(): void
    {
        $evaluator = $this->evaluator();
        $row = $this->row(['tags' => ['red', 'blue'], 'none' => []]);
        $tags = new BoundColumn('p', ['tags', PathSegment::wildcard()]);
        $none = new BoundColumn('p', ['none', PathSegment::wildcard()]);

        $this->assertTrue($evaluator->evaluate(new Binary('=', $tags, new Literal('blue')), $row));
        $this->assertFalse($evaluator->evaluate(new Binary('!=', $tags, new Literal('blue')), $row), 'negated: true for none');
        $this->assertTrue($evaluator->evaluate(new Binary('!=', $tags, new Literal('green')), $row));
        $this->assertTrue($evaluator->evaluate(new Binary('!=', $none, new Literal('green')), $row), 'also for no elements');
        $this->assertFalse($evaluator->evaluate(new Binary('=', $none, new Literal('green')), $row));
    }

    public function testThreeValuedLogic(): void
    {
        $evaluator = $this->evaluator();
        $row = $this->row(['age' => null]);
        $age = new BoundColumn('p', ['age']);

        $this->assertNull($evaluator->evaluate(new Binary('>', $age, new Literal(3)), $row));
        $this->assertFalse($evaluator->holds(new Binary('>', $age, new Literal(3)), $row), 'unknown doesn\'t hold');
        $this->assertTrue($evaluator->holds(null, $row), 'no condition always holds');
        $this->assertTrue($evaluator->evaluate(new IsNull($age, false), $row));
        $this->assertFalse($evaluator->evaluate(new Binary('AND', new Literal(false), new Binary('>', $age, new Literal(3))), $row));
        $this->assertNull($evaluator->evaluate(new Binary('/', new Literal(1), new Literal(0)), $row), 'division by 0');
    }

    public function testAggregatesAreReadFromTheGroup(): void
    {
        $evaluator = $this->evaluator();
        $count = new FunctionCall('COUNT', [], star: true);
        $state = Aggregates::newState();
        Aggregates::accumulate($count, $state, null);
        Aggregates::accumulate($count, $state, null);

        $this->assertSame(2, $evaluator->evaluate($count, $this->row([]), [spl_object_id($count) => $state]));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('COUNT needs a group');
        $evaluator->evaluate($count, $this->row([]));
    }
}
