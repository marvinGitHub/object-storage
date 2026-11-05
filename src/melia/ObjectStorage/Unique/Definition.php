<?php

namespace melia\ObjectStorage\Unique;

use melia\ObjectStorage\Search\DocumentBuilder;
use melia\ObjectStorage\Unique\Exception\InvalidUniqueIndexException;

/**
 * A unique index: no two objects of the class may have the same values at the paths, e.g. the email of a customer,
 * or the combination of a customer and a number.
 *
 * Paths lead into the stored data of an object, separated by dots (`address.email`); a reference is its UUID. As
 * in SQL, an object with a null value at a path has no key: any number of objects may lack the value.
 */
final readonly class Definition
{
    /** Lowercase letters, digits, "_" and "-", so names are unique on case-insensitive file systems too. */
    public const string NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /** @var string[] */
    public array $paths;

    /**
     * @param string $name The unique name of the index.
     * @param string $className The class of the objects (not its subclasses).
     * @param string[]|string $paths The paths of the values, one for a single value, several for a combination.
     * @param bool $caseInsensitive Whether strings differing in case (and surrounding spaces) are the same, e.g.
     *        for emails.
     * @throws InvalidUniqueIndexException
     */
    public function __construct(
        public string $name,
        public string $className,
        array|string  $paths,
        public bool   $caseInsensitive = false,
    )
    {
        $paths = array_values((array)$paths);
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new InvalidUniqueIndexException(sprintf('Invalid unique index name "%s": lowercase letters, digits, "_" and "-", starting with a letter or digit, at most 64.', $name));
        }
        if ('' === trim($className)) {
            throw new InvalidUniqueIndexException('A unique index needs a class.');
        }
        if ([] === $paths) {
            throw new InvalidUniqueIndexException('A unique index needs at least one path.');
        }
        foreach ($paths as $path) {
            if (!is_string($path) || !preg_match('/^[^.\s]+(\.[^.\s]+)*$/', $path)) {
                throw new InvalidUniqueIndexException(sprintf('Invalid path "%s": keys separated by dots, e.g. "address.email".', (string)$path));
            }
        }
        if (count($paths) !== count(array_unique($paths))) {
            throw new InvalidUniqueIndexException('A path is listed twice.');
        }
        $this->paths = $paths;
    }

    /**
     * The key of an object: its values at the paths, normalized.
     *
     * @param array $data The stored data of the object.
     * @param string $reservedReferenceName The key marking references in the data.
     * @return array{key: string, values: array<string, mixed>}|null The key (JSON of the normalized values) and
     *         the values as stored, path => value; null if a value is missing (null) or isn't a single value.
     */
    public function keyOf(array $data, string $reservedReferenceName): ?array
    {
        $values = [];
        $normalized = [];
        foreach ($this->paths as $path) {
            $value = DocumentBuilder::extract($data, explode('.', $path), $reservedReferenceName);
            if (null === $value || is_array($value) || is_object($value)) {
                return null;
            }
            $values[$path] = $value;
            $normalized[] = $this->normalize($value);
        }

        return ['key' => json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'values' => $values];
    }

    /**
     * The key of values given in the order of the paths, e.g. to look an object up.
     */
    public function keyOfValues(array $values): ?string
    {
        if (count($values) !== count($this->paths)) {
            throw new InvalidUniqueIndexException(sprintf('Unique index "%s" has %d path(s), %d value(s) given.', $this->name, count($this->paths), count($values)));
        }
        $normalized = [];
        foreach (array_values($values) as $value) {
            if (null === $value || is_array($value) || is_object($value)) {
                return null;
            }
            $normalized[] = $this->normalize($value);
        }
        return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Values as compared: numbers and numeric strings alike (12, 12.0 and "12" are the same), strings optionally
     * without case and surrounding spaces.
     */
    private function normalize(string|int|float|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value) || (is_numeric($value) && trim($value) === $value)) {
            return 'n:' . (0 + $value);
        }
        return 's:' . ($this->caseInsensitive ? mb_strtolower(trim($value)) : $value);
    }

    /**
     * @return array{name: string, className: string, paths: string[], caseInsensitive: bool}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'className' => $this->className, 'paths' => $this->paths, 'caseInsensitive' => $this->caseInsensitive];
    }

    /**
     * @throws InvalidUniqueIndexException
     */
    public static function fromArray(array $data): self
    {
        return new self((string)($data['name'] ?? ''), (string)($data['className'] ?? ''), (array)($data['paths'] ?? []), (bool)($data['caseInsensitive'] ?? false));
    }

    public function __serialize(): array
    {
        return $this->toArray();
    }

    /**
     * @throws InvalidUniqueIndexException
     */
    public function __unserialize(array $data): void
    {
        $definition = self::fromArray($data);
        $this->name = $definition->name;
        $this->className = $definition->className;
        $this->paths = $definition->paths;
        $this->caseInsensitive = $definition->caseInsensitive;
    }
}
