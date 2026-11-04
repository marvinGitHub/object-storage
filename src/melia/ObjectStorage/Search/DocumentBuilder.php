<?php

namespace melia\ObjectStorage\Search;

use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\UUID\Validator;

/**
 * Builds search documents from the stored data of objects. The data is read as stored, without loading the
 * objects, so indexes can also be built where the classes aren't available (e.g. in the viewer).
 */
final class DocumentBuilder
{
    public function __construct(private readonly Definition $definition)
    {
    }

    /**
     * @param string $uuid The UUID of the object.
     * @param array $data The decoded stored data of the object.
     * @param Metadata $metadata The metadata of the object.
     * @return array The search document.
     */
    public function build(string $uuid, array $data, Metadata $metadata): array
    {
        $reserved = $metadata->getReservedReferenceName();

        $document = [
            'uuid' => $uuid,
            'revision' => $metadata->getRevision(),
        ];

        foreach ($this->definition->fields as $field) {
            $value = static::extract($data, $field->getPathSegments(), $reserved);
            $document[$field->name] = $field->type->convert($value);
        }

        return $document;
    }

    /**
     * Reads the value at a path. A referenced object yields its UUID; a path doesn't lead into referenced objects.
     * A path segment that isn't a key of a list is applied to every element, so "lines.title" yields the titles
     * of all lines.
     *
     * @param mixed $value The data to read from.
     * @param string[] $segments The remaining path segments.
     * @param string $reserved The reserved reference name the data was written with.
     */
    public static function extract(mixed $value, array $segments, string $reserved): mixed
    {
        $value = static::resolveReference($value, $reserved);

        if ([] === $segments) {
            if (is_array($value)) {
                return array_map(static fn(mixed $v): mixed => static::resolveReference($v, $reserved), $value);
            }
            return $value;
        }

        if (!is_array($value)) {
            return null;
        }

        $segment = array_shift($segments);

        if (array_key_exists($segment, $value)) {
            return static::extract($value[$segment], $segments, $reserved);
        }

        if (array_is_list($value)) {
            $result = [];
            foreach ($value as $element) {
                $extracted = static::extract($element, [$segment, ...$segments], $reserved);
                if (is_array($extracted)) {
                    array_push($result, ...array_values($extracted));
                } elseif (null !== $extracted) {
                    $result[] = $extracted;
                }
            }
            return [] === $result ? null : $result;
        }

        return null;
    }

    /**
     * Replaces a reference by the UUID of the referenced object.
     */
    private static function resolveReference(mixed $value, string $reserved): mixed
    {
        if (is_array($value) && 1 === count($value) && isset($value[$reserved]) && is_string($value[$reserved]) && Validator::validate($value[$reserved])) {
            return $value[$reserved];
        }

        return $value;
    }

    /**
     * Suggests fields for the values in stored data: full text for texts, filters for everything else,
     * sorting for numbers and booleans. References (UUIDs) are only filterable.
     *
     * @return Field[]
     */
    public static function suggestFields(array $data, string $reserved): array
    {
        $fields = [];

        foreach (static::suggestPaths($data, $reserved) as $path => [$type, $isReference]) {
            $name = preg_replace('/[^a-zA-Z0-9_]+/', '_', (string)$path);
            if (!preg_match('/^[a-zA-Z]/', $name) || in_array($name, Definition::RESERVED_ATTRIBUTES, true)) {
                $name = 'f_' . $name;
            }

            $isText = !$isReference && in_array($type, [FieldType::STRING, FieldType::STRING_ARRAY], true);

            $fields[] = new Field(
                name: substr($name, 0, 64),
                path: (string)$path,
                type: $type,
                searchable: $isText,
                filterable: !$isText || FieldType::STRING_ARRAY === $type,
                sortable: in_array($type, [FieldType::NUMBER, FieldType::BOOLEAN], true),
            );
        }

        return $fields;
    }

    /**
     * Lists the paths of the values in stored data with their guessed types.
     *
     * @return array<string, array{0: FieldType, 1: bool}> Path => [type, whether it is a reference].
     */
    private static function suggestPaths(array $data, string $reserved, string $prefix = '', int $depth = 0): array
    {
        $fields = [];

        foreach ($data as $key => $value) {
            $path = '' === $prefix ? (string)$key : $prefix . '.' . $key;
            $resolved = static::resolveReference($value, $reserved);
            $isReference = $resolved !== $value;
            $value = $resolved;

            if (is_array($value) && !array_is_list($value) && $depth < 3) {
                $fields += static::suggestPaths($value, $reserved, $path, $depth + 1);
                continue;
            }

            if (is_array($value) && array_is_list($value) && [] !== $value && is_array(static::resolveReference($value[0], $reserved))) {
                continue; // list of nested structures
            }

            // a list of references
            if (is_array($value) && [] !== $value && $value !== array_map(static fn(mixed $v): mixed => static::resolveReference($v, $reserved), $value)) {
                $isReference = true;
            }

            $fields[$path] = [FieldType::guess(is_array($value) ? array_map(static fn(mixed $v): mixed => static::resolveReference($v, $reserved), $value) : $value), $isReference];
        }

        return $fields;
    }
}
