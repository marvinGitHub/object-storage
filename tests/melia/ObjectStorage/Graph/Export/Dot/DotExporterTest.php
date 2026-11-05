<?php

namespace Tests\melia\ObjectStorage\Graph\Export\Dot;

use melia\ObjectStorage\Graph\DanglingReference;
use melia\ObjectStorage\Graph\Export\Dot\DotExporter;
use melia\ObjectStorage\Graph\GraphNode;
use Tests\melia\ObjectStorage\TestCase;

/**
 * The reference graph in the DOT language of Graphviz.
 */
final class DotExporterTest extends TestCase
{
    public function testNodesEdgesSharedAndDanglingReferences(): void
    {
        $order = new GraphNode('App\Order', '00000000-0000-4000-8000-000000000001');
        $customer = new GraphNode('App\Customer', '00000000-0000-4000-8000-000000000002');
        $line = new GraphNode('App\Line "x"', '00000000-0000-4000-8000-000000000003');
        $order->references['customer'] = $customer;
        $order->references['lines'] = [0 => $line, 'gift' => new DanglingReference($order->uuid)];
        $line->references['order'] = $order; // circular
        $line->references['customer'] = $customer; // shared

        $dot = (new DotExporter())->export($order);

        $this->assertStringStartsWith("digraph references {\n", $dot);
        $this->assertStringContainsString('"00000000-0000-4000-8000-000000000001" [label="App\\\\Order\n00000000-0000-4000-8000-000000000001", penwidth=2];', $dot, 'namespaces escaped, the root bold');
        $this->assertStringContainsString('[label="App\\\\Line \"x\"\n', $dot, 'quotes escaped');
        $this->assertStringContainsString('"00000000-0000-4000-8000-000000000001" -> "00000000-0000-4000-8000-000000000002" [label="customer"];', $dot);
        $this->assertStringContainsString('-> "00000000-0000-4000-8000-000000000003" [label="lines[0]"];', $dot);
        $this->assertStringContainsString('-> "dangling-1" [label="lines[gift]", style=dashed, color=red];', $dot);
        $this->assertStringContainsString('"00000000-0000-4000-8000-000000000003" -> "00000000-0000-4000-8000-000000000001" [label="order"];', $dot);
        $this->assertSame(1, preg_match_all('/^  "00000000-0000-4000-8000-000000000002" \[label=/m', $dot), 'a shared object is one node');
        $this->assertSame(2, preg_match_all('/-> "00000000-0000-4000-8000-000000000002"/', $dot), 'with two edges');
    }
}
