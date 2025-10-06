<?php

namespace Tests\melia\ObjectStorage;


use melia\ObjectStorage\LazyLoadReference;

class TrieNode
{
    public array $children = [];
    public bool $isEnd = false;
}

class Trie
{
    private TrieNode $root;

    public function __construct()
    {
        $this->root = new TrieNode();
    }

    // Insert a word into the trie
    public function insert(string $word): void
    {
        $node = $this->root;
        $len = strlen($word);

        for ($i = 0; $i < $len; $i++) {
            $ch = $word[$i];
            if (!isset($node->children[$ch])) {
                $node->children[$ch] = new TrieNode();
            }
            $node = $node->children[$ch];
        }
        $node->isEnd = true;
    }

    // Returns true if the word is in the trie
    public function search(string $word): bool
    {
        $node = $this->traverse($word);
        return $node !== null && $node->isEnd;
    }

    // Returns true if there is any word in the trie that starts with the given prefix
    public function startsWith(string $prefix): bool
    {
        return $this->traverse($prefix) !== null;
    }

    // Remove a word from the trie, returns true if removed
    public function remove(string $word): bool
    {
        return $this->removeRecursive($this->root, $word, 0);
    }

    // Get all words with a given prefix (optional helper)
    public function wordsWithPrefix(string $prefix): array
    {
        $node = $this->traverse($prefix);
        if ($node === null) {
            return [];
        }
        $results = [];
        $this->collect($node, $prefix, $results);
        return $results;
    }

    // Internal: traverse to node matching given string
    private function traverse(string $s): null|LazyLoadReference|TrieNode
    {
        $node = $this->root;
        $len = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if (!isset($node->children[$ch])) {
                return null;
            }
            $node = $node->children[$ch];
        }
        return $node;
    }

    // Internal: remove using DFS, prune empty nodes
    private function removeRecursive(TrieNode $node, string $word, int $index): bool
    {
        if ($index === strlen($word)) {
            if (!$node->isEnd) {
                return false; // word not present
            }
            $node->isEnd = false;
            return empty($node->children); // signal to prune if no children
        }

        $ch = $word[$index];
        if (!isset($node->children[$ch])) {
            return false; // word not present
        }

        $shouldPrune = $this->removeRecursive($node->children[$ch], $word, $index + 1);

        if ($shouldPrune) {
            unset($node->children[$ch]);
        }

        // prune current node if it has no children and is not end of another word
        return !$node->isEnd && empty($node->children);
    }

    // Internal: collect words from a node
    private function collect(TrieNode $node, string $prefix, array &$results): void
    {
        if ($node->isEnd) {
            $results[] = $prefix;
        }
        foreach ($node->children as $ch => $child) {
            $this->collect($child, $prefix . $ch, $results);
        }
    }
}

class ObjectStorageTrieTest extends TestCase
{

    public function testTriePersistence()
    {
        $trie = new Trie();
        $trie->insert('apple');
        $trie->insert('apples');
        $trie->insert('banana');

        $this->assertTrue($trie->search('apple'));
        $this->assertTrue($trie->search('apples'));
        $this->assertTrue($trie->search('banana'));
        $this->assertFalse($trie->search('NonExistent'));

        $uuid = $this->storage->store($trie);
        $this->storage->clearCache();
        $loaded = $this->storage->load($uuid);

        $this->assertTrue($loaded->search('apple'));
        $this->assertTrue($loaded->search('apples'));
        $this->assertTrue($loaded->search('banana'));
        $this->assertFalse($loaded->search('NonExistent'));

        $loaded->remove('apple');
        $this->assertFalse($loaded->search('apple'));
        $this->assertTrue($loaded->search('apples'));;

        $this->storage->store($loaded, $uuid);
        $this->storage->clearCache();
        $loaded = $this->storage->load($uuid);

        $this->assertFalse($loaded->search('apple'));
        $this->assertTrue($loaded->search('apples'));
        $this->assertTrue($loaded->search('banana'));
        $this->assertFalse($loaded->search('NonExistent'));
    }
}