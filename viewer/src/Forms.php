<?php

namespace melia\ObjectStorage\Viewer;

use melia\ObjectStorage\Configuration\CacheSettings;
use melia\ObjectStorage\Configuration\Configuration;
use melia\ObjectStorage\Configuration\LockSettings;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\PatternIndex;
use melia\ObjectStorage\Strategy\Policy\ChildDeletion;
use melia\ObjectStorage\Strategy\Policy\ChildPersistence;

/**
 * The forms of the viewer: what they offer to choose, and what is built from what they post.
 */
final class Forms
{
    /**
     * The definition of a search index from the posted form.
     */
    public static function definitionFromRequest(array $input): Definition
    {
        $fields = [];
        foreach ($input['fields'] ?? [] as $field) {
            if ('' === trim((string)($field['name'] ?? '')) && '' === trim((string)($field['path'] ?? ''))) {
                continue; // empty row
            }
            $fields[] = Field::fromArray([
                'name' => trim((string)($field['name'] ?? '')),
                'path' => trim((string)($field['path'] ?? '')),
                'type' => (string)($field['type'] ?? FieldType::STRING->value),
                'searchable' => isset($field['searchable']),
                'filterable' => isset($field['filterable']),
                'sortable' => isset($field['sortable']),
                'patterns' => array_values(array_filter((array)($field['patterns'] ?? []), 'is_string')),
            ]);
        }

        $languages = array_values(array_filter(array_map('trim', explode(',', (string)($input['languages'] ?? '')))));

        return new Definition(
            name: trim((string)($input['name'] ?? '')),
            className: trim((string)($input['classname'] ?? '')),
            fields: $fields,
            languages: $languages,
            engine: '' === trim((string)($input['engine'] ?? '')) ? null : trim((string)$input['engine']),
        );
    }

    /**
     * The configuration from the posted form.
     */
    public static function configurationFromRequest(array $input): Configuration
    {
        $lock = $input['lock'] ?? [];
        $cache = $input['cache'] ?? [];

        return Configuration::fromArray([
            'name' => trim((string)($input['name'] ?? '')),
            'description' => (string)($input['description'] ?? ''),
            'lock' => [
                'type' => (string)($lock['type'] ?? LockSettings::TYPE_FILE),
                'dsn' => trim((string)($lock['dsn'] ?? '')),
                'directory' => trim((string)($lock['directory'] ?? '')),
                'ttl' => trim((string)($lock['ttl'] ?? '')),
                'prefix' => (string)($lock['prefix'] ?? LockSettings::PREFIX_DEFAULT),
                'username' => trim((string)($lock['username'] ?? '')),
                'password' => (string)($lock['password'] ?? ''),
            ],
            'cache' => [
                'type' => (string)($cache['type'] ?? CacheSettings::TYPE_MEMORY),
                'dsn' => trim((string)($cache['dsn'] ?? '')),
                'directory' => trim((string)($cache['directory'] ?? '')),
                'defaultLifetime' => (int)($cache['defaultLifetime'] ?? 0),
                'namespace' => trim((string)($cache['namespace'] ?? '')),
            ],
            'shardDepth' => trim((string)($input['shardDepth'] ?? '')),
            'maxDepth' => trim((string)($input['maxDepth'] ?? '')),
            'lifetimeInheritance' => isset($input['lifetimeInheritance']),
            'checksumValidation' => isset($input['checksumValidation']),
            'optimisticLocking' => isset($input['optimisticLocking']),
            'childPersistence' => trim((string)($input['childPersistence'] ?? '')),
            'childDeletion' => trim((string)($input['childDeletion'] ?? '')),
            'fsync' => isset($input['fsync']),
        ]);
    }

    /**
     * The choices of the configuration form.
     */
    public static function configurationChoices(): array
    {
        return [
            'lockTypes' => [
                LockSettings::TYPE_FILE => 'Files (one host)',
                LockSettings::TYPE_SEMAPHORE => 'Semaphores (one host, Linux)',
                LockSettings::TYPE_REDIS => 'Redis',
                LockSettings::TYPE_MEMCACHED => 'Memcached',
                LockSettings::TYPE_PDO => 'Database (PDO)',
                LockSettings::TYPE_DSN => 'Other symfony/lock connection',
            ],
            'cacheTypes' => [
                CacheSettings::TYPE_MEMORY => 'In memory of the process',
                CacheSettings::TYPE_NONE => 'No cache',
                CacheSettings::TYPE_APCU => 'APCu (one server)',
                CacheSettings::TYPE_FILESYSTEM => 'Files (one host)',
                CacheSettings::TYPE_REDIS => 'Redis',
                CacheSettings::TYPE_MEMCACHED => 'Memcached',
            ],
            'childPersistence' => [
                ChildPersistence::IF_NOT_EXIST => 'IF_NOT_EXIST',
                ChildPersistence::ALWAYS => 'ALWAYS',
                ChildPersistence::NEVER => 'NEVER',
            ],
            'childDeletion' => [
                ChildDeletion::NEVER => 'NEVER',
                ChildDeletion::ALWAYS => 'ALWAYS',
            ],
        ];
    }

    /**
     * The pattern indexes a field of a search index can have.
     */
    public static function patternIndexes(): array
    {
        return [
            ['value' => PatternIndex::TRIE->value, 'label' => 'prefix', 'title' => "trie: the beginnings of the values, for LIKE 'Ho%'"],
            ['value' => PatternIndex::REVERSE_TRIE->value, 'label' => 'suffix', 'title' => "reverse trie: the ends of the values, for LIKE '%son'"],
            ['value' => PatternIndex::NGRAM->value, 'label' => 'n-gram', 'title' => "n-grams: the parts of 3 characters, for LIKE '%test%' and CONTAINS"],
        ];
    }
}
