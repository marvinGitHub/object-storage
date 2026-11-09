'use strict';
/**
 * Tests of the syntax highlighting of the query console (assets/oql-highlight.js):
 *
 *     docker run --rm -v "$PWD:/app" -w /app node:22-alpine node --test 'tests/js/*.test.js'
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const H = require('../../assets/oql-highlight.js');

const kinds = query => H.tokenize(query).filter(t => t.type !== 'space').map(t => [t.type, t.text]);

test('the kinds of tokens', () => {
    assert.deepEqual(kinds("SELECT p.name AS n, COUNT(*) FROM `App\\Product` p WHERE p.UUID = :id AND x <> 'it''s' -- end"), [
        ['keyword', 'SELECT'], ['identifier', 'p'], ['punctuation', '.'], ['identifier', 'name'], ['keyword', 'AS'], ['identifier', 'n'],
        ['punctuation', ','], ['function', 'COUNT'], ['punctuation', '('], ['operator', '*'], ['punctuation', ')'], ['keyword', 'FROM'],
        ['quoted', '`App\\Product`'], ['identifier', 'p'], ['keyword', 'WHERE'], ['identifier', 'p'], ['punctuation', '.'], ['uuid', 'UUID'],
        ['operator', '='], ['parameter', ':id'], ['keyword', 'AND'], ['identifier', 'x'], ['operator', '<>'], ['string', "'it''s'"],
        ['comment', '-- end'],
    ]);
    assert.deepEqual(kinds('x IS NOT NULL OR y = TRUE AND z IN (1.5, -2, 3e2, ?)'), [
        ['identifier', 'x'], ['keyword', 'IS'], ['keyword', 'NOT'], ['literal', 'NULL'], ['keyword', 'OR'], ['identifier', 'y'],
        ['operator', '='], ['literal', 'TRUE'], ['keyword', 'AND'], ['identifier', 'z'], ['keyword', 'IN'], ['punctuation', '('],
        ['number', '1.5'], ['punctuation', ','], ['operator', '-'], ['number', '2'], ['punctuation', ','], ['number', '3e2'],
        ['punctuation', ','], ['parameter', '?'], ['punctuation', ')'],
    ]);
});

test('keywords in any case; function names only before a parenthesis', () => {
    assert.deepEqual(kinds('select uuid from Product'), [['keyword', 'select'], ['uuid', 'uuid'], ['keyword', 'from'], ['identifier', 'Product']]);
    assert.deepEqual(kinds('SELECT count, lower (name) FROM T'), [['keyword', 'SELECT'], ['identifier', 'count'], ['punctuation', ','],
        ['function', 'lower'], ['punctuation', '('], ['identifier', 'name'], ['punctuation', ')'], ['keyword', 'FROM'], ['identifier', 'T']]);
    assert.deepEqual(kinds('INSERT INTO T (a) VALUES (REF(:u))').map(t => t[0]),
        ['keyword', 'keyword', 'identifier', 'punctuation', 'identifier', 'punctuation', 'keyword', 'punctuation', 'function', 'punctuation', 'parameter', 'punctuation', 'punctuation']);
    assert.deepEqual(kinds('FROM Tests\\melia\\UUID'), [['keyword', 'FROM'], ['identifier', 'Tests\\melia\\UUID']], 'a class name');
});

test('nothing is lost, also of unfinished queries', () => {
    for (const query of [
        "SELECT 'open",
        'SELECT /* open',
        'SELECT `open',
        "SELECT a\n  FROM T\r\n\tWHERE b = '\n' # $ € 😀",
        '',
        "--\n/**/''``\"\"",
    ]) {
        assert.equal(H.tokenize(query).map(t => t.text).join(''), query, JSON.stringify(query));
    }
    assert.deepEqual(kinds("SELECT 'open"), [['keyword', 'SELECT'], ['string', "'open"]]);
    assert.deepEqual(kinds('a /* b */ c'), [['identifier', 'a'], ['comment', '/* b */'], ['identifier', 'c']]);
});

test('HTML', () => {
    assert.equal(H.highlight("SELECT '<b>' FROM T"),
        '<span class="oql-keyword">SELECT</span> <span class="oql-string">&#39;&lt;b&gt;&#39;</span> <span class="oql-keyword">FROM</span> <span class="oql-identifier">T</span>');
    assert.equal(H.highlight('a  b'), '<span class="oql-identifier">a</span>  <span class="oql-identifier">b</span>');
});

test('the position of an error', () => {
    // "WHERE x = = 1": the second = (index 10)
    assert.match(H.highlight('WHERE x = = 1', 10), /<span class="oql-operator">=<\/span> <span class="oql-operator oql-error">=<\/span>/);
    assert.match(H.highlight('SELECT name FROM', 16), /<span class="oql-error oql-error-end"> <\/span>$/, 'at the end');
    assert.match(H.highlight('ab   cd', 3), /<span class="oql-identifier oql-error">cd<\/span>/, 'in spaces: the next token');
    assert.equal((H.highlight('a b', 0).match(/oql-error/g) ?? []).length, 1, 'one mark');
});

test('byte offsets as character indexes', () => {
    assert.equal(H.indexOfByte('abc', 2), 2);
    assert.equal(H.indexOfByte("'Ä' x", 4), 3, 'Ä has two bytes');
    assert.equal(H.indexOfByte('😀x', 4), 2, 'four bytes, two UTF-16 units');
    assert.equal(H.indexOfByte('ab', 10), 2);
});

test('whether a query or script writes or controls a transaction', () => {
    assert.equal(H.writes('SELECT name FROM Product'), false);
    assert.equal(H.writes('-- a comment\n/* another */ update Product SET a = 1'), true);
    assert.equal(H.writes("SELECT 'x; DELETE FROM T' FROM T; SELECT 1 FROM T -- ; DELETE"), false, 'semicolons in strings and comments');
    assert.equal(H.writes('SELECT a FROM T; DELETE FROM T'), true, 'a later statement');
    assert.equal(H.writes('START TRANSACTION; SELECT a FROM T; ROLLBACK'), true);
    assert.equal(H.writes('begin'), true);
    assert.equal(H.writes('SELECT begin, commit FROM T'), false, 'names, not statements');
    assert.equal(H.writes(''), false);
});
