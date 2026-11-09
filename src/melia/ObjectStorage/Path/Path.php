<?php

namespace melia\ObjectStorage\Path;

use melia\ObjectStorage\Path\Exception\InvalidPathException;

/**
 * Paths into stored data in dot notation, as used by the fields of search indexes, unique indexes and OQL:
 *
 * - `address.city`: names separated by dots; a name that isn't a key of a list applies to all its elements
 *   (`lines.title`), a referenced object is followed (`category.title`);
 * - `friends.*.name` or `friends[*].name`: all elements of a list (or values of a map), also of referenced objects;
 * - `friends[0].name`, `friends[-1].name`: the element of a list at a position (from 0, negative from the end);
 * - `friends['alex'].name` (or `["alex"]`): the value of exactly this key, also for keys with dots, spaces or
 *   brackets; a quote is written twice.
 *
 * Segments are plain names (strings) or PathSegment for the others.
 */
final class Path
{
    /**
     * @return list<string|PathSegment>
     * @throws InvalidPathException
     */
    public static function parse(string $path): array
    {
        $segments = [];
        $length = strlen($path);
        $i = 0;
        $expectSegment = true; // at the start or after a dot

        while ($i < $length) {
            $c = $path[$i];

            if ('[' === $c) {
                if ($expectSegment && [] !== $segments) {
                    throw new InvalidPathException(sprintf('Invalid path "%s": a name expected after the dot at %d, not "[".', $path, $i));
                }
                [$segment, $i] = self::bracket($path, $i);
                $segments[] = $segment;
                $expectSegment = false;
                if ($i < $length && '.' !== $path[$i] && '[' !== $path[$i]) {
                    throw new InvalidPathException(sprintf('Invalid path "%s": "." or "[" expected after "]" at %d.', $path, $i));
                }
                if ($i < $length && '.' === $path[$i]) {
                    $i++;
                    $expectSegment = true;
                    if ($i >= $length) {
                        throw new InvalidPathException(sprintf('Invalid path "%s": it ends with a dot.', $path));
                    }
                }
                continue;
            }

            if (!$expectSegment) {
                throw new InvalidPathException(sprintf('Invalid path "%s" at %d.', $path, $i));
            }

            $end = strcspn($path, '.[]', $i);
            $name = substr($path, $i, $end);
            if ('' === $name) {
                throw new InvalidPathException(sprintf('Invalid path "%s": empty name at %d.', $path, $i));
            }
            $segments[] = '*' === $name ? PathSegment::wildcard() : $name;
            $i += $end;
            $expectSegment = false;

            if ($i < $length) {
                if (']' === $path[$i]) {
                    throw new InvalidPathException(sprintf('Invalid path "%s": unexpected "]" at %d.', $path, $i));
                }
                if ('.' === $path[$i]) {
                    $i++;
                    $expectSegment = true;
                    if ($i >= $length) {
                        throw new InvalidPathException(sprintf('Invalid path "%s": it ends with a dot.', $path));
                    }
                }
            }
        }

        if ([] === $segments) {
            throw new InvalidPathException('A path can\'t be empty.');
        }

        return $segments;
    }

    /**
     * The path of segments as written, the same for the same segments: `friends.*.name`, `friends[0].name`,
     * `friends['a.b'].name`. Names that can't be written plainly (dots, brackets, quotes, "*", empty) are keys.
     *
     * @param list<string|PathSegment> $segments
     */
    public static function toString(array $segments): string
    {
        $path = '';
        foreach ($segments as $segment) {
            if (is_string($segment) && !self::isPlainName($segment)) {
                $segment = PathSegment::key($segment);
            }
            if ($segment instanceof PathSegment && !$segment->isWildcard()) {
                $path .= (string)$segment;
                continue;
            }
            $path .= ('' === $path ? '' : '.') . $segment;
        }
        return $path;
    }

    /**
     * Whether the segments may name more than one value: a wildcard. (A plain name applied to the elements of a list
     * does too, but that depends on the data.)
     *
     * @param list<string|PathSegment> $segments
     */
    public static function hasWildcard(array $segments): bool
    {
        foreach ($segments as $segment) {
            if ($segment instanceof PathSegment && $segment->isWildcard()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether all segments are plain names (paths as before brackets and wildcards).
     *
     * @param list<string|PathSegment> $segments
     */
    public static function isPlain(array $segments): bool
    {
        foreach ($segments as $segment) {
            if (!is_string($segment)) {
                return false;
            }
        }
        return true;
    }

    /** a name written without brackets */
    public static function isPlainName(string $name): bool
    {
        return '' !== $name && '*' !== $name && strcspn($name, ".[]'\"") === strlen($name);
    }

    /**
     * @return array{0: PathSegment, 1: int} The segment and the offset after "]".
     * @throws InvalidPathException
     */
    private static function bracket(string $path, int $start): array
    {
        $i = $start + 1;
        $c = $path[$i] ?? '';

        if ("'" === $c || '"' === $c) {
            $quote = $c;
            $key = '';
            $i++;
            while (true) {
                if ($i >= strlen($path)) {
                    throw new InvalidPathException(sprintf('Invalid path "%s": unterminated key at %d.', $path, $start));
                }
                if ($quote === $path[$i]) {
                    if ($quote === ($path[$i + 1] ?? '')) {
                        $key .= $quote;
                        $i += 2;
                        continue;
                    }
                    $i++;
                    break;
                }
                $key .= $path[$i++];
            }
            $segment = PathSegment::key($key);
        } elseif ('*' === $c) {
            $i++;
            $segment = PathSegment::wildcard();
        } elseif (preg_match('/\G-?\d+/', $path, $match, 0, $i)) {
            $i += strlen($match[0]);
            $segment = PathSegment::index((int)$match[0]);
        } else {
            throw new InvalidPathException(sprintf('Invalid path "%s": a position, a quoted key or * expected in brackets at %d.', $path, $start));
        }

        if (']' !== ($path[$i] ?? '')) {
            throw new InvalidPathException(sprintf('Invalid path "%s": "]" expected at %d.', $path, $i));
        }

        return [$segment, $i + 1];
    }
}
