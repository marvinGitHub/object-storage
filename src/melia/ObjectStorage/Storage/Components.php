<?php

namespace melia\ObjectStorage\Storage;

use Closure;

/**
 * The components of a storage (see docs/architecture.md), each created when first needed and created again when one
 * of its inputs has changed, e.g. by a setter of the storage: components don't follow changes themselves.
 *
 * The inputs are kept and compared with ===: objects by identity, other values by value. Kept, an object can't be
 * freed while its component exists, so its identity can't be taken by another object.
 */
final class Components
{
    /** @var array<string, array{inputs: list<mixed>, component: object}> */
    private array $components = [];

    /**
     * The component of a name, created from its inputs if there is none yet or its inputs have changed.
     *
     * @template T of object
     * @param string $name The name of the component, e.g. its class.
     * @param list<mixed> $inputs What the component is created from.
     * @param Closure(): T $create
     * @return T
     */
    public function get(string $name, array $inputs, Closure $create): object
    {
        $entry = $this->components[$name] ?? null;
        if (null === $entry || $entry['inputs'] !== $inputs) {
            $entry = ['inputs' => $inputs, 'component' => $create()];
            $this->components[$name] = $entry;
        }

        return $entry['component'];
    }
}
