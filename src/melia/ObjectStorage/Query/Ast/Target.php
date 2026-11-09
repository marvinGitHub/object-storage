<?php

namespace melia\ObjectStorage\Query\Ast;

use melia\ObjectStorage\Path\Path;
use melia\ObjectStorage\Path\PathSegment;

/**
 * What UPDATE writes or removes: a property of the object itself, or a path into the lists and maps it holds, e.g.
 * newlist['today'], tags[0], tags[-1], settings.mail.enabled, friends.*.active, tags[] (appended).
 *
 * @internal Not part of the public API; may change in any release.
 */
final readonly class Target
{
    /**
     * @param string $property The property of the object.
     * @param list<string|PathSegment|null> $segments The path in the value of the property: names and ['key'] are
     *        keys, [n] positions (negative from the end), * all elements, null ([]) a new element at the end.
     */
    public function __construct(public string $property, public array $segments, public int $position)
    {
    }

    public function isPath(): bool
    {
        return [] !== $this->segments;
    }

    /** as written, e.g. newlist['today'], tags[] */
    public function __toString(): string
    {
        $path = '';
        $plain = [$this->property];
        foreach ($this->segments as $segment) {
            if (null === $segment) {
                $path .= Path::toString($plain) . '[]';
                $plain = [];
                continue;
            }
            $plain[] = $segment;
        }
        if ([] === $plain) {
            return $path;
        }
        $rest = Path::toString($plain);
        return $path . ('' === $path || str_starts_with($rest, '[') ? '' : '.') . $rest;
    }
}
