<?php

namespace melia\ObjectStorage\Graph;

/**
 * Slim stand-in for a stored object in the graph produced by
 * GraphVisitor::buildReferenceGraph(): just enough to tell what it is
 * and what it connects to, none of its actual data. Using a dedicated
 * class instead of a real domain object avoids the property-type
 * conflicts you'd get trying to shove this into e.g. a
 * `LazyLoadReference|ChildObject $child` slot - every node here is
 * either a GraphNode or a DanglingReference, uniformly.
 *
 * $references only ever contains entries for attributes that actually
 * were references - non-reference attributes are dropped entirely, and
 * an array attribute that contained a mix of references and other
 * values keeps only the reference entries (original keys preserved).
 *
 * A given UUID is only ever represented by one GraphNode instance
 * (GraphVisitor reuses it wherever that UUID is referenced again), so
 * shared/circular references show up as the same object appearing in
 * multiple places in the tree - which is exactly what
 * object_graph_dump() is built to detect and represent correctly, no
 * separate edge bookkeeping needed on our side.
 */
final class GraphNode
{
    /** @var array<string, GraphNode|DanglingReference|array<int|string, GraphNode|DanglingReference>> */
    public array $references = [];

    public function __construct(
        public readonly string $className,
        public readonly string $uuid,
        /**
         * Whether $className is a generated PHP anonymous-class name
         * (e.g. "class@anonymous/path/to/file.php:12$0") rather than a
         * real, meaningful class name. Happens when the metadata lookup
         * for this UUID wasn't available/didn't have a class name and
         * the object itself turned out to be an instance of an
         * anonymous class - true names for those don't exist to fall
         * back to.
         */
        public readonly bool $isAnonymous = false,
    ) {
    }
}