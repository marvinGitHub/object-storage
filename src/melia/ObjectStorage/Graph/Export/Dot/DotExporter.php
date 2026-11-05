<?php

namespace melia\ObjectStorage\Graph\Export\Dot;

use melia\ObjectStorage\Graph\DanglingReference;
use melia\ObjectStorage\Graph\GraphNode;

/**
 * Renders the reference graph of GraphVisitor::buildReferenceGraph() in the DOT language of Graphviz: one node per
 * stored object (class and UUID), one edge per reference, labelled with the property (and the key within an
 * array). Shared and circular references point to the same node; dangling references become dashed red nodes.
 *
 *   $graph = (new GraphVisitor($storage))->buildReferenceGraph($object, $uuid);
 *   file_put_contents('graph.dot', (new DotExporter())->export($graph)); // dot -Tsvg graph.dot -o graph.svg
 */
final class DotExporter
{
    /**
     * @param GraphNode $root The root of the reference graph.
     * @return string The graph in DOT.
     */
    public function export(GraphNode $root): string
    {
        $lines = [];
        $visited = [];
        $dangling = 0;
        $pending = [$root];

        while (null !== ($node = array_shift($pending))) {
            if (isset($visited[$node->uuid])) {
                continue;
            }
            $visited[$node->uuid] = true;

            $lines[] = sprintf(
                '  %s [label=%s%s];',
                self::quote($node->uuid),
                self::quote(($node->isAnonymous ? 'class@anonymous' : $node->className) . "\n" . $node->uuid),
                $node === $root ? ', penwidth=2' : ''
            );

            foreach ($node->references as $property => $references) {
                // an array property keeps the keys of its references
                $targets = is_array($references) ? $references : ['' => $references];
                foreach ($targets as $key => $target) {
                    $label = '' === $key ? (string)$property : sprintf('%s[%s]', $property, $key);

                    if ($target instanceof DanglingReference) {
                        $id = 'dangling-' . ++$dangling;
                        $lines[] = sprintf('  %s [label="dangling reference", style=dashed, color=red, fontcolor=red];', self::quote($id));
                        $lines[] = sprintf('  %s -> %s [label=%s, style=dashed, color=red];', self::quote($node->uuid), self::quote($id), self::quote($label));
                        continue;
                    }

                    $lines[] = sprintf('  %s -> %s [label=%s];', self::quote($node->uuid), self::quote($target->uuid), self::quote($label));
                    $pending[] = $target;
                }
            }
        }

        return "digraph references {\n"
            . "  rankdir=LR;\n"
            . "  node [shape=box, style=rounded, fontname=\"Helvetica\", fontsize=10];\n"
            . "  edge [fontname=\"Helvetica\", fontsize=9];\n"
            . implode("\n", $lines) . "\n"
            . "}\n";
    }

    /**
     * A DOT string: quotes and backslashes (e.g. of namespaces) escaped, line breaks as \n.
     */
    private static function quote(string $text): string
    {
        return '"' . str_replace(["\\", '"', "\n"], ["\\\\", '\\"', '\\n'], $text) . '"';
    }
}
