<?php

namespace Tests\melia\ObjectStorage\Search;

use melia\ObjectStorage\Search\Exception\InvalidIndexDefinitionException;
use melia\ObjectStorage\Search\Field;
use melia\ObjectStorage\Search\FieldType;
use melia\ObjectStorage\Search\Index\Definition;
use melia\ObjectStorage\Search\PatternIndex;
use PHPUnit\Framework\TestCase;

/**
 * The parts pattern indexes store of values, and those patterns need.
 */
class PatternIndexTest extends TestCase
{
    public function testTermsAreThePartsInLowerCase(): void
    {
        $this->assertSame(['h', 'ho', 'hom', 'homa'], PatternIndex::TRIE->terms('Homa'));
        $this->assertSame(['a', 'ma', 'oma', 'homa'], PatternIndex::REVERSE_TRIE->terms('Homa'));
        $this->assertSame(['hom', 'oma'], PatternIndex::NGRAM->terms('Homa'));
        $this->assertSame(['äpf', 'pfe', 'fel'], PatternIndex::NGRAM->terms('ÄPFEL'), 'letters beyond ASCII');
        $this->assertSame([], PatternIndex::NGRAM->terms('ab'), 'shorter than a part');
        $this->assertSame(['abc', 'bcd', 'xyz'], PatternIndex::NGRAM->terms(['abcd', 'xyz', 'ABC', null]), 'of each text of a list, once');
        $this->assertSame([], PatternIndex::TRIE->terms(null));
        $this->assertSame(['1', '12', '123'], PatternIndex::TRIE->terms('123'), 'texts, also of digits');
        $this->assertCount(PatternIndex::TRIE_MAX_LENGTH, PatternIndex::TRIE->terms(str_repeat('x', 40) . 'y'));
    }

    public function testPatternsAreTokenized(): void
    {
        $any = PatternIndex::ANY;
        $one = PatternIndex::ONE;
        $this->assertSame(['Ho', $any], PatternIndex::tokenize('Ho%'));
        $this->assertSame([$any, 'test', $any], PatternIndex::tokenize('%%test%'), 'several % are one');
        $this->assertSame(['a', $one, 'c', $any, 'd'], PatternIndex::tokenize('a_c%d'));
        $this->assertSame(['50%', $any], PatternIndex::tokenize('50\%%'), 'escaped');
        $this->assertSame([$any, '50%', $any], PatternIndex::tokenizeContains('50%'));
        $this->assertSame([$any], PatternIndex::tokenizeContains(''));
    }

    public function testCandidatesOfATrie(): void
    {
        $this->assertSame(['terms' => ['ho'], 'exact' => true], PatternIndex::TRIE->candidates(PatternIndex::tokenize('Ho%')));
        $this->assertSame(['terms' => ['ho'], 'exact' => false], PatternIndex::TRIE->candidates(PatternIndex::tokenize('Ho%a')));
        $this->assertSame(['terms' => ['homa'], 'exact' => false], PatternIndex::TRIE->candidates(PatternIndex::tokenize('Homa')), 'equal: also longer values have the prefix');
        $this->assertNull(PatternIndex::TRIE->candidates(PatternIndex::tokenize('%son')));
        $this->assertNull(PatternIndex::TRIE->candidates(PatternIndex::tokenize('_o%')));
        $long = PatternIndex::TRIE->candidates(PatternIndex::tokenize(str_repeat('x', 40) . '%'));
        $this->assertSame([str_repeat('x', PatternIndex::TRIE_MAX_LENGTH)], $long['terms']);
        $this->assertFalse($long['exact'], 'longer than stored');
    }

    public function testCandidatesOfAReverseTrie(): void
    {
        $this->assertSame(['terms' => ['son'], 'exact' => true], PatternIndex::REVERSE_TRIE->candidates(PatternIndex::tokenize('%SON')));
        $this->assertSame(['terms' => ['son'], 'exact' => false], PatternIndex::REVERSE_TRIE->candidates(PatternIndex::tokenize('J%son')));
        $this->assertNull(PatternIndex::REVERSE_TRIE->candidates(PatternIndex::tokenize('Ho%')));
        $this->assertNull(PatternIndex::REVERSE_TRIE->candidates(PatternIndex::tokenize('%so_')));
    }

    public function testCandidatesOfNgrams(): void
    {
        $this->assertSame(['terms' => ['tes', 'est'], 'exact' => false], PatternIndex::NGRAM->candidates(PatternIndex::tokenize('%Test%')));
        $this->assertSame(['terms' => ['tes'], 'exact' => true], PatternIndex::NGRAM->candidates(PatternIndex::tokenize('%tes%')));
        $this->assertSame(['terms' => ['tes'], 'exact' => false], PatternIndex::NGRAM->candidates(PatternIndex::tokenize('tes%')), 'not anywhere');
        $this->assertSame(['terms' => ['abc', 'xyz'], 'exact' => false], PatternIndex::NGRAM->candidates(PatternIndex::tokenize('%abc_xyz%')));
        $this->assertSame(['terms' => ['abc'], 'exact' => false], PatternIndex::NGRAM->candidates(PatternIndex::tokenize('%ab_abc%')), 'parts shorter than an n-gram tell nothing');
        $this->assertNull(PatternIndex::NGRAM->candidates(PatternIndex::tokenize('%te%')));
        $this->assertNull(PatternIndex::NGRAM->candidates(PatternIndex::tokenize('%')));
    }

    public function testFieldsDeclareTheirPatternIndexes(): void
    {
        $field = new Field('name', 'name', filterable: true, patterns: [PatternIndex::TRIE, PatternIndex::NGRAM]);
        $this->assertSame(['name__trie' => PatternIndex::TRIE, 'name__ngram' => PatternIndex::NGRAM], $field->patternAttributes());
        $this->assertSame(['trie', 'ngram'], $field->toArray()['patterns']);
        $this->assertEquals($field, Field::fromArray($field->toArray()));
        $this->assertArrayNotHasKey('patterns', (new Field('name', 'name'))->toArray(), 'definitions stored before compare equal');
        $this->assertSame([], Field::fromArray(['name' => 'name', 'path' => 'name'])->patterns);
    }

    public static function invalidFields(): array
    {
        return [
            'not filterable' => [static fn() => new Field('name', 'name', patterns: [PatternIndex::NGRAM]), 'filterable'],
            'not strings' => [static fn() => new Field('n', 'n', FieldType::NUMBER, filterable: true, patterns: [PatternIndex::TRIE]), 'field of strings'],
            'twice' => [static fn() => new Field('name', 'name', filterable: true, patterns: [PatternIndex::TRIE, PatternIndex::TRIE]), 'twice'],
            'no pattern index' => [static fn() => new Field('name', 'name', filterable: true, patterns: ['trie']), 'must be a list of'],
            'unknown format' => [static fn() => Field::fromArray(['name' => 'name', 'filterable' => true, 'patterns' => ['btree']]), 'Invalid pattern index "btree"'],
            'name of a pattern index' => [static fn() => new Definition('d', 'C', [
                new Field('name', 'name', filterable: true, patterns: [PatternIndex::NGRAM]),
                new Field('name__ngram', 'other'),
            ]), 'pattern index'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFields')]
    public function testInvalidPatternIndexesAreRejected(callable $create, string $message): void
    {
        $this->expectException(InvalidIndexDefinitionException::class);
        $this->expectExceptionMessage($message);
        $create();
    }
}
