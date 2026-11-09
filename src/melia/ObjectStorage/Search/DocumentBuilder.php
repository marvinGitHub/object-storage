<?php

namespace melia\ObjectStorage\Search;

use Closure;
use melia\ObjectStorage\Metadata\Metadata;
use melia\ObjectStorage\Path\PathSegment;
use melia\ObjectStorage\Path\Path;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Serialization\DateTimeValue;
use melia\ObjectStorage\UUID\Validator;

/**
 * Builds search documents from the stored data of objects. The data is read as stored, without loading the
 * objects, so indexes can also be built where the classes aren't available (e.g. in the viewer).
 *
 * Paths lead into referenced objects (a join when indexing): with a reader of referenced objects, "category.title"
 * reads the title of the referenced category. The UUIDs of the objects read are the attribute "referencedUuids" of
 * the document, so the documents depending on an object can be found when it changes.
 *
 * Dates stored inline are single values (not arrays of their parts): they are passed to the types of the fields as
 * DateTimeInterface (see FieldType).
 */
final class DocumentBuilder
{
    /** the attribute with the UUIDs of the referenced objects a document was built from */
    public const string ATTRIBUTE_REFERENCED = 'referencedUuids';

    /** how many references a path follows at most, e.g. "order.customer.address.city" follows three */
    public const int MAX_REFERENCES_PER_PATH = 3;

    /** @var array<string, array{0: array, 1: string, 2: string}|null> referenced objects read: UUID => [data, reserved name, class], null if missing */
    private array $read = [];

    /** @var array<string, true> classes of the referenced objects read */
    private array $joinedClasses = [];

    /**
     * @param Definition $definition The definition of the index.
     * @param (Closure(string): (array{0: array, 1: string, 2: string}|null))|null $reader Reads a referenced object:
     *        its data, the reserved reference name it was written with and its class; null if it doesn't exist
     *        (or expired). Without a reader, paths don't lead into referenced objects.
     */
    public function __construct(
        private readonly Definition $definition,
        private readonly ?Closure   $reader = null,
    )
    {
    }

    /**
     * The classes of the referenced objects read so far.
     *
     * @return string[]
     */
    public function getJoinedClasses(): array
    {
        return array_keys($this->joinedClasses);
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
        $reservedDateTime = $metadata->getReservedDateTimeName();

        $document = [
            'uuid' => $uuid,
            'revision' => $metadata->getRevision(),
            'expiresAt' => $metadata->getTimestampExpiresAt(),
        ];

        $referenced = [];
        $follow = null === $this->reader ? null : function (string $uuid) use (&$referenced): ?array {
            $referenced[$uuid] = true;
            return $this->readReferenced($uuid);
        };

        foreach ($this->definition->fields as $field) {
            $value = static::extract($data, $field->getPathSegments(), $reserved, $follow, 0, $reservedDateTime);
            $document[$field->name] = $field->type->convert(static::dates($value, $reservedDateTime));
        }

        $uuids = array_keys($referenced);
        sort($uuids);
        $document[self::ATTRIBUTE_REFERENCED] = $uuids;

        return $document;
    }

    /**
     * Reads a referenced object once per builder: the objects of a batch often share them, e.g. their category.
     *
     * @return array{0: array, 1: string, 2: string}|null
     */
    private function readReferenced(string $uuid): ?array
    {
        if (!array_key_exists($uuid, $this->read)) {
            $this->read[$uuid] = ($this->reader)($uuid);
            if (null !== $this->read[$uuid]) {
                $this->joinedClasses[$this->read[$uuid][2]] = true;
            }
        }

        return $this->read[$uuid];
    }

    /**
     * Reads the value at a path. A referenced object yields its UUID, or, with $follow and segments left, the value
     * at the rest of the path in the referenced object. A path segment that isn't a key of a list is applied to
     * every element, so "lines.title" yields the titles of all lines, and "tags.title" those of all referenced tags.
     * Segments of Path: "*" applies the rest to all elements (also of referenced objects: "friends.*.name"), [0]
     * reads the element at a position ([-1] the last), ['key'] exactly that key.
     *
     * @param mixed $value The data to read from.
     * @param list<string|PathSegment> $segments The remaining path segments, see Path::parse().
     * @param string $reserved The reserved reference name the data was written with.
     * @param (callable(string): (array{0: array, 1: string, 2?: string}|null))|null $follow Reads a referenced
     *        object: its data and the reserved reference name it was written with; null if it doesn't exist.
     *        Without it, paths don't lead into referenced objects.
     * @param int $followed The number of references followed so far.
     * @param string|null $reservedDateTime The reserved key of dates the data was written with (dates with the
     *        default key are recognized too): a date is a single value, also in lists.
     */
    public static function extract(mixed $value, array $segments, string $reserved, ?callable $follow = null, int $followed = 0, ?string $reservedDateTime = null): mixed
    {
        $resolved = static::resolveReference($value, $reserved);

        // a reference with segments left: into the referenced object
        if ([] !== $segments && $resolved !== $value) {
            if (null === $follow || $followed >= self::MAX_REFERENCES_PER_PATH) {
                return null;
            }
            $target = $follow($resolved);
            return null === $target ? null : static::extract($target[0], $segments, $target[1], $follow, $followed + 1);
        }

        $value = $resolved;

        if ([] === $segments) {
            if (is_array($value) && !DateTimeValue::isEncodedAny($value, $reservedDateTime)) {
                return array_map(static fn(mixed $v): mixed => static::resolveReference($v, $reserved), $value);
            }
            return $value;
        }

        if (!is_array($value)) {
            return null;
        }

        $segment = array_shift($segments);

        if ($segment instanceof PathSegment) {
            // a date is a single value, it has no elements or keys
            if (DateTimeValue::isEncodedAny($value, $reservedDateTime)) {
                return null;
            }
            switch ($segment->kind) {
                case PathSegment::WILDCARD:
                    return static::collect(array_values($value), $segments, $reserved, $follow, $followed, $reservedDateTime);
                case PathSegment::INDEX:
                    $index = (int)$segment->value;
                    if ($index < 0 && array_is_list($value)) {
                        $index += count($value);
                    }
                    return array_key_exists($index, $value)
                        ? static::extract($value[$index], $segments, $reserved, $follow, $followed, $reservedDateTime)
                        : null;
                default:
                    $key = (string)$segment->value;
                    return array_key_exists($key, $value)
                        ? static::extract($value[$key], $segments, $reserved, $follow, $followed, $reservedDateTime)
                        : null;
            }
        }

        if (array_key_exists($segment, $value)) {
            return static::extract($value[$segment], $segments, $reserved, $follow, $followed, $reservedDateTime);
        }

        if (array_is_list($value)) {
            return static::collect($value, [$segment, ...$segments], $reserved, $follow, $followed, $reservedDateTime);
        }

        return null;
    }

    /**
     * The values at the rest of a path in each element, as one list (lists found are merged); null if there are none.
     *
     * @param list<mixed> $elements
     * @param list<string|PathSegment> $segments
     */
    private static function collect(array $elements, array $segments, string $reserved, ?callable $follow, int $followed, ?string $reservedDateTime): ?array
    {
        $result = [];
        foreach ($elements as $element) {
            $extracted = static::extract($element, $segments, $reserved, $follow, $followed, $reservedDateTime);
            if (is_array($extracted) && !DateTimeValue::isEncodedAny($extracted, $reservedDateTime)) {
                array_push($result, ...array_values($extracted));
            } elseif (null !== $extracted) {
                $result[] = $extracted;
            }
        }
        return [] === $result ? null : $result;
    }

    /**
     * Dates stored inline (also in lists) as DateTimeImmutable, without loading their classes.
     */
    public static function dates(mixed $value, ?string $reservedDateTime = null): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $date = DateTimeValue::pointInTime($value, $reservedDateTime);
        if (null !== $date) {
            return $date;
        }
        return array_is_list($value) ? array_map(static fn(mixed $v): mixed => static::dates($v, $reservedDateTime), $value) : $value;
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
     * sorting for numbers, dates and booleans. References (UUIDs) are only filterable.
     *
     * @param (callable(string): (array{0: array, 1: string, 2?: string}|null))|null $follow Reads a referenced object
     *        (see extract()): its values are suggested as well, e.g. "category.title" next to "category"; for a list
     *        of references, those of its first element as lists, e.g. "tags.title".
     * @return Field[]
     */
    public static function suggestFields(array $data, string $reserved, ?callable $follow = null): array
    {
        return static::suggestFieldsFrom([[$data, $reserved]], $follow);
    }

    /**
     * Suggests fields for the values in the stored data of several objects, e.g. the first ones of a class: a value
     * missing in one object (null, e.g. a product without a category) is suggested from the others.
     *
     * @param iterable<array{0: array, 1: string}> $samples The data of objects and the reserved reference names they
     *        were written with.
     * @param (callable(string): (array{0: array, 1: string, 2?: string}|null))|null $follow See suggestFields().
     * @return Field[]
     */
    public static function suggestFieldsFrom(iterable $samples, ?callable $follow = null): array
    {
        $paths = [];
        foreach ($samples as [$data, $reserved]) {
            foreach (static::suggestPaths($data, $reserved, $follow) as $path => $suggestion) {
                // the first known value of a path; null only if no object has one
                if (!isset($paths[$path]) || (!$paths[$path][2] && $suggestion[2])) {
                    $paths[$path] = $suggestion;
                }
            }
        }

        $fields = [];

        foreach ($paths as $path => [$type, $isReference]) {
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
                sortable: in_array($type, [FieldType::NUMBER, FieldType::BOOLEAN, FieldType::DATE], true),
            );
        }

        return $fields;
    }

    /** how many references suggestions follow, e.g. "order.customer.name" */
    private const int SUGGESTED_REFERENCES = 2;

    /**
     * Lists the paths of the values in stored data with their guessed types.
     *
     * @param bool $listed Whether the data is an element of a list of references: its values become lists.
     * @return array<string, array{0: FieldType, 1: bool, 2: bool}> Path => [type, whether it is a reference, whether
     *         the value is known (not null or an empty list)].
     */
    private static function suggestPaths(array $data, string $reserved, ?callable $follow, string $prefix = '', int $depth = 0, int $followed = 0, bool $listed = false): array
    {
        $fields = [];

        foreach ($data as $key => $value) {
            $segment = Path::toString([(string)$key]);
            $path = '' === $prefix ? $segment : $prefix . (str_starts_with($segment, '[') ? '' : '.') . $segment;
            $resolved = static::resolveReference($value, $reserved);
            $isReference = $resolved !== $value;
            $value = static::dates($resolved);

            if (is_array($value) && !array_is_list($value) && $depth < 3) {
                $fields += static::suggestPaths($value, $reserved, $follow, $path, $depth + 1, $followed, $listed);
                continue;
            }

            if (is_array($value) && array_is_list($value) && [] !== $value && is_array(static::resolveReference($value[0], $reserved))) {
                continue; // list of nested structures
            }

            $values = is_array($value) ? array_map(static fn(mixed $v): mixed => static::resolveReference($v, $reserved), $value) : null;

            // a list of references
            $isList = null !== $values && [] !== $value && $value !== $values;
            if ($isList) {
                $isReference = true;
            }

            $type = FieldType::guess($values ?? $value);
            if ($listed) {
                if (is_array($value) && !$isReference) {
                    continue; // lists in lists
                }
                $type = match ($type) {
                    FieldType::NUMBER, FieldType::NUMBER_ARRAY => FieldType::NUMBER_ARRAY,
                    FieldType::DATE, FieldType::DATE_ARRAY => FieldType::DATE_ARRAY,
                    FieldType::BOOLEAN => null,
                    default => FieldType::STRING_ARRAY,
                };
                if (null === $type) {
                    continue;
                }
            }
            $fields[$path] = [$type, $isReference, null !== $value && [] !== $value];

            // the values of the referenced object (of the first one of a list)
            if ($isReference && null !== $follow && $followed < self::SUGGESTED_REFERENCES) {
                $uuid = $isList ? $values[0] : $value;
                $target = is_string($uuid) ? $follow($uuid) : null;
                if (null !== $target) {
                    $fields += static::suggestPaths($target[0], $target[1], $follow, $path, $depth + 1, $followed + 1, $listed || $isList);
                }
            }
        }

        return $fields;
    }
}
