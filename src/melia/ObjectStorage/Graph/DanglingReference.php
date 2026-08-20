<?php

namespace melia\ObjectStorage\Graph;

/**
 * Placeholder for a reference whose target couldn't be resolved (see
 * GraphVisitor::buildReferenceGraph()). Deliberately minimal - just the
 * UUID it was pointing to. The reason it couldn't be resolved is still
 * available via GraphVisitor::getDanglingReferences() after the call
 * that produced this instance, keyed by the original LazyLoadReference
 * proxy rather than duplicated onto every DanglingReference.
 */
final readonly class DanglingReference
{
    public function __construct(
        public string $uuid,
    )
    {
    }
}