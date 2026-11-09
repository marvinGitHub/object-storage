'use strict';
/**
 * Tests of the logic of the query builder (assets/query-builder.js). Run with Node 20 or newer, no packages needed:
 *
 *     docker run --rm -v "$PWD:/app" -w /app node:22-alpine node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const QB = require('../../assets/query-builder.js');
const {FIELDS, prng, documents, tree, fixture} = require('./random.js');

const fields = QB.createFields([
    ...FIELDS,
    {name: 'uuid', path: 'uuid', type: 'string', filterable: true, reserved: true},
    {name: 'secret', path: 'secret', type: 'string', filterable: false},
    {name: 'counts', path: 'counts', type: 'array<number>', filterable: true},
    {name: 'born', path: 'born', type: 'date', filterable: true},
    {name: 'visits', path: 'visits', type: 'array<date>', filterable: true},
    // a field named like the path of another: the name wins
    {name: 'other', path: 'name', type: 'number', filterable: true},
]);

const condition = (field, op, value = '') => ({kind: 'condition', field, op, value});
const group = (combinator, children, negated = false) => ({kind: 'group', combinator, negated, children});
const write = c => QB.conditionText(c, fields);
const filter = (t, kept = '') => QB.buildFilter(t, fields, kept);

// ---- writing conditions ----

test('comparisons by type', () => {
    assert.deepEqual(write(condition('name', '=', 'Anna')), {text: "name = 'Anna'"});
    assert.deepEqual(write(condition('name', '!=', "O'Neill")), {text: "name != 'O''Neill'"});
    assert.deepEqual(write(condition('name', '=', ' spaced ')), {text: "name = ' spaced '"}, 'strings exactly as typed');
    assert.deepEqual(write(condition('age', '>=', ' -7.5 ')), {text: 'age >= -7.5'}, 'numbers trimmed');
    for (const op of ['=', '!=', '>', '>=', '<', '<=']) {
        assert.deepEqual(write(condition('age', op, '42')), {text: `age ${op} 42`});
    }
    assert.deepEqual(write(condition('active', '=', 'true')), {text: 'active = true'});
    assert.deepEqual(write(condition('active', '!=', 'false')), {text: 'active != false'});
});

test('lists, ranges and the IS operators', () => {
    assert.deepEqual(write(condition('name', 'IN', 'a,  b , c')), {text: "name IN ('a', 'b', 'c')"});
    assert.deepEqual(write(condition('tags', 'NOT IN', "it's")), {text: "tags NOT IN ('it''s')"});
    assert.deepEqual(write(condition('age', 'IN', '1, -2.5')), {text: 'age IN (1, -2.5)'});
    assert.deepEqual(write(condition('counts', 'IN', '3')), {text: 'counts IN (3)'});
    assert.deepEqual(write(condition('age', 'BETWEEN', '10, 20')), {text: 'age BETWEEN 10 AND 20'});
    for (const op of ['IS NULL', 'IS NOT NULL', 'IS EMPTY', 'IS NOT EMPTY']) {
        assert.deepEqual(write(condition('name', op, 'ignored')), {text: `name ${op}`});
    }
});

test('dates', () => {
    assert.deepEqual(write(condition('born', '>=', ' 2026-01-01 ')), {text: "born >= '2026-01-01'"}, 'trimmed and quoted');
    assert.deepEqual(write(condition('born', '<', '2026-01-01T10:00:00.5+01:00')), {text: "born < '2026-01-01T10:00:00.5+01:00'"});
    assert.deepEqual(write(condition('born', 'IN', '2026-01-01, 2026-01-02 10:00')), {text: "born IN ('2026-01-01', '2026-01-02 10:00')"});
    assert.deepEqual(write(condition('visits', 'NOT IN', '2026-03-01')), {text: "visits NOT IN ('2026-03-01')"});
    assert.deepEqual(write(condition('born', 'BETWEEN', '2026-01-01T00:30+01:00, 2026-01-01')), {text: "born BETWEEN '2026-01-01T00:30+01:00' AND '2026-01-01'"}, 'by the point in time');
    assert.match(write(condition('born', 'BETWEEN', '2026-01-02, 2026-01-01')).error, /earlier date first/);
    assert.match(write(condition('born', '=', 'tomorrow')).error, /is no date/);
    assert.match(write(condition('born', '=', '2026-02-30')).error, /is no date/, 'a day that does not exist');
    assert.match(write(condition('born', 'LIKE', '2026%')).error, /isn't possible/);

    assert.equal(QB.dateTime('2026-01-01'), Date.UTC(2026, 0, 1));
    assert.equal(QB.dateTime('2026-01-01T01:00+01:00'), Date.UTC(2026, 0, 1));
    assert.equal(QB.dateTime('2025-12-31 20:00-05:00'), Date.UTC(2026, 0, 1, 1));

    // read back into the builder
    const parsed = QB.parse("born BETWEEN '2026-01-01' AND '2026-02-01' AND visits IN ('2026-03-01')", fields);
    assert.deepEqual(parsed.children.map(c => [c.field, c.op, c.value]), [['born', 'BETWEEN', '2026-01-01, 2026-02-01'], ['visits', 'IN', '2026-03-01']]);
    assert.throws(() => QB.parse("born = 'soon'", fields), /is no date/);
    assert.throws(() => QB.parse('born = 5', fields), /doesn't fit/);
});

test('patterns', () => {
    assert.deepEqual(write(condition('name', 'CONTAINS', 'om')), {text: "name CONTAINS 'om'"});
    assert.deepEqual(write(condition('name', 'NOT CONTAINS', '50%')), {text: "name NOT CONTAINS '50%'"}, 'CONTAINS takes the text as it is');
    assert.deepEqual(write(condition('name', 'LIKE', 'Ho_a%')), {text: "name LIKE 'Ho_a%'"});
    assert.deepEqual(write(condition('tags', 'NOT LIKE', 're%')), {text: "tags NOT LIKE 're%'"});
    // STARTS WITH and ENDS WITH: wildcards and backslashes of the value escaped
    assert.deepEqual(write(condition('name', 'STARTS WITH', '50%_\\x')), {text: "name LIKE '50\\%\\_\\\\x%'"});
    assert.deepEqual(write(condition('name', 'ENDS WITH', "O'")), {text: "name LIKE '%O'''"});
});

test('fields by name or path', () => {
    assert.deepEqual(write(condition('friend.name', '=', 'Homa')), {text: "friend.name = 'Homa'"});
    assert.deepEqual(write(condition('friendName', '=', 'Homa')), {text: "friendName = 'Homa'"});
    assert.deepEqual(write(condition('  friend.age ', '>', '3')), {text: 'friend.age > 3'}, 'the field trimmed');
    // "name" is the name of a string field and the path of the number field "other": the name wins
    assert.deepEqual(write(condition('name', '=', 'x')), {text: "name = 'x'"});
});

test('a condition that can\'t be written is an error, not left out', () => {
    const errors = [
        condition('unknown', '=', 'x'),
        condition('secret', '=', 'x'),
        condition('name', '>', 'x'),
        condition('tags', '=', 'x'),
        condition('active', 'IN', 'true'),
        condition('name', '=', ''),
        condition('name', 'CONTAINS', ''),
        condition('name', 'STARTS WITH', ''),
        condition('name', '=', 'line\nbreak'),
        condition('age', '=', 'abc'),
        condition('age', '=', '1e3'),
        condition('age', '=', ''),
        condition('age', 'IN', '1, x'),
        condition('age', 'IN', ' , '),
        condition('age', 'BETWEEN', '1'),
        condition('age', 'BETWEEN', '1, 2, 3'),
        condition('age', 'BETWEEN', '1, x'),
        condition('age', 'BETWEEN', '20, 10'),
        condition('age', 'BETWEEN', '-1, -2'),
        condition('active', '=', 'yes'),
    ];
    for (const c of errors) {
        assert.ok(write(c).error, `${c.field} ${c.op} ${JSON.stringify(c.value)}`);
    }
    assert.deepEqual(write(condition('', '=', 'x')), {empty: true}, 'without a field: an empty row');
});

// ---- groups ----

test('groups, parentheses and NOT', () => {
    const a = condition('name', '=', 'a');
    const b = condition('age', '=', '1');
    const c = condition('active', '=', 'true');
    assert.equal(filter(group('AND', [a])).filter, "name = 'a'");
    assert.equal(filter(group('OR', [a, b])).filter, "name = 'a' OR age = 1");
    assert.equal(filter(group('AND', [a, group('OR', [b, c])])).filter, "name = 'a' AND (age = 1 OR active = true)");
    assert.equal(filter(group('AND', [a, group('OR', [b])])).filter, "name = 'a' AND age = 1", 'a group of one condition needs no parentheses');
    assert.equal(filter(group('AND', [a, group('OR', [b, c], true)])).filter, "name = 'a' AND NOT (age = 1 OR active = true)");
    assert.equal(filter(group('AND', [a], true)).filter, "NOT (name = 'a')");
    assert.equal(filter(group('OR', [group('AND', [a, b]), c])).filter, "(name = 'a' AND age = 1) OR active = true");
    assert.equal(filter(group('AND', [group('AND', [group('AND', [a])], true)])).filter, "NOT (name = 'a')");
    assert.equal(filter(group('AND', [])).filter, '');
    assert.equal(filter(group('AND', [condition('', '=', ''), group('OR', [])])).filter, '', 'empty rows and groups are left out');
});

test('errors of nested conditions are collected', () => {
    const result = filter(group('AND', [condition('name', '=', 'a'), group('OR', [condition('age', '=', 'x'), condition('nope', '=', '1')])]));
    assert.equal(result.errors.length, 2);
});

test('a kept filter is combined by AND', () => {
    const a = condition('name', '=', 'a');
    const b = condition('age', '=', '1');
    assert.equal(filter(group('AND', [a]), '_geoRadius(l, 1, 2, 3)').filter, "(_geoRadius(l, 1, 2, 3)) AND name = 'a'");
    assert.equal(filter(group('OR', [a, b]), 'x = 1').filter, "(x = 1) AND (name = 'a' OR age = 1)");
    assert.equal(filter(group('AND', []), 'x = 1').filter, 'x = 1');
});

// ---- reading filters ----

const parsed = text => QB.parse(text, fields);

test('reading filters into trees', () => {
    assert.deepEqual(parsed("name = 'O''Neill'"), group('AND', [condition('name', '=', "O'Neill")]));
    assert.deepEqual(parsed("friend.name = 'x' OR age > -1.5"), group('OR', [condition('friend.name', '=', 'x'), condition('age', '>', '-1.5')]));
    assert.deepEqual(parsed("a_b_c" === '' ? '' : "name IN ('a', 'b') AND tags NOT IN ('x')"), group('AND', [condition('name', 'IN', 'a, b'), condition('tags', 'NOT IN', 'x')]));
    assert.deepEqual(parsed('age BETWEEN 1 AND 2 AND active = false'), group('AND', [condition('age', 'BETWEEN', '1, 2'), condition('active', '=', 'false')]));
    assert.deepEqual(parsed('nick IS NOT NULL AND tags IS EMPTY'), group('AND', [condition('nick', 'IS NOT NULL'), condition('tags', 'IS EMPTY')]));
    assert.deepEqual(parsed("name = '' OR name != ''"), group('OR', [condition('name', 'IS EMPTY'), condition('name', 'IS NOT EMPTY')]), "= '' is IS EMPTY");
    assert.deepEqual(parsed('(((age = 1)))'), group('AND', [condition('age', '=', '1')]));
    assert.deepEqual(parsed(''), group('AND', []));
});

test('reading NOT and nested groups', () => {
    assert.deepEqual(parsed("NOT name = 'a'"), group('AND', [condition('name', '=', 'a')], true));
    assert.deepEqual(parsed("NOT (name = 'a' OR age = 1)"), group('OR', [condition('name', '=', 'a'), condition('age', '=', '1')], true));
    assert.deepEqual(parsed('NOT (NOT (age = 1))'), group('AND', [group('AND', [condition('age', '=', '1')], true)], true));
    assert.deepEqual(
        parsed("age = 1 AND (name = 'a' OR (nick = 'b' AND tags IN ('c')))"),
        group('AND', [condition('age', '=', '1'), group('OR', [condition('name', '=', 'a'), group('AND', [condition('nick', '=', 'b'), condition('tags', 'IN', 'c')])])])
    );
    // the same combinator inside is merged, as it means the same
    assert.deepEqual(parsed('age = 1 AND (age = 2 AND age = 3)'), group('AND', [condition('age', '=', '1'), condition('age', '=', '2'), condition('age', '=', '3')]));
    assert.deepEqual(parsed('age = 1 AND NOT (age = 2 AND age = 3)'), group('AND', [condition('age', '=', '1'), group('AND', [condition('age', '=', '2'), condition('age', '=', '3')], true)]));
});

test('reading patterns: prefixes and suffixes become STARTS WITH and ENDS WITH', () => {
    assert.deepEqual(parsed("name LIKE 'Ho%'"), group('AND', [condition('name', 'STARTS WITH', 'Ho')]));
    assert.deepEqual(parsed("name LIKE '%oma'"), group('AND', [condition('name', 'ENDS WITH', 'oma')]));
    assert.deepEqual(parsed("name LIKE '50\\%\\_\\\\%'"), group('AND', [condition('name', 'STARTS WITH', '50%_\\')]));
    assert.deepEqual(parsed("name LIKE 'H_ma'"), group('AND', [condition('name', 'LIKE', 'H_ma')]));
    assert.deepEqual(parsed("name LIKE '%om%'"), group('AND', [condition('name', 'LIKE', '%om%')]), 'both: a LIKE');
    assert.deepEqual(parsed("name LIKE '%'"), group('AND', [condition('name', 'LIKE', '%')]));
    assert.deepEqual(parsed("name NOT LIKE 'Ho%'"), group('AND', [condition('name', 'NOT LIKE', 'Ho%')]));
    assert.deepEqual(parsed("tags LIKE 're%'"), group('AND', [condition('tags', 'LIKE', 're%')]), 'lists have no STARTS WITH');
    assert.deepEqual(parsed("name NOT CONTAINS 'x'"), group('AND', [condition('name', 'NOT CONTAINS', 'x')]));
});

test('filters the builder can\'t show exactly aren\'t read', () => {
    for (const text of [
        '_geoRadius(loc, 1, 2, 3)',
        "unknown = 'x'",
        "secret = 'x'",
        "name = 1",
        "age = '1'",
        "active = 'true'",
        "tags = 'x'",
        "name > 'a'",
        "active IN (true)",
        "name IN ('a,b')",
        "name IN (' a')",
        "name IN ('')",
        "age = ''",
        "name CONTAINS ''",
        "name = 'line\nbreak'",
        "name = 'a' and age = 1",
        'age = 1e3',
        "name = \"a\"",
        "name = 'unterminated",
        "(name = 'a'",
        "name = 'a')",
        "name = 'a' AND",
        "NOT",
        "age NOT BETWEEN 1 AND 2",
        "name IS NOT 'a'",
        "name LIKE 1",
        'age BETWEEN 20 AND 10',
    ]) {
        assert.throws(() => parsed(text), Error, text);
    }
});

// ---- round trips ----

/** what parse() makes of a tree: merged and collapsed groups, written as it is read */
function normalize(node, root = true) {
    if (node.kind === 'condition') {
        const field = node.field.trim();
        const type = fields.find(field).type;
        let {op, value} = node;
        if (QB.withoutValue(op)) {
            value = '';
        } else if (op === 'IN' || op === 'NOT IN' || op === 'BETWEEN') {
            value = QB.splitList(value).map(v => QB.isNumberType(type) ? v.trim() : v).join(', ');
        } else if (QB.isNumberType(type)) {
            value = value.trim();
        } else if ((op === 'LIKE') && type === 'string') {
            // a prefix or a suffix without other wildcards is read as STARTS WITH or ENDS WITH
            const literal = /^(?:[^%_\\]|\\[\\%_])+$/;
            const unescape = text => text.replace(/\\([\\%_])/g, '$1');
            if (value.endsWith('%') && literal.test(value.slice(0, -1))) {
                [op, value] = ['STARTS WITH', unescape(value.slice(0, -1))];
            } else if (value.startsWith('%') && literal.test(value.slice(1))) {
                [op, value] = ['ENDS WITH', unescape(value.slice(1))];
            }
        }
        return {kind: 'condition', field, op, value};
    }
    let children = node.children.map(child => normalize(child, false)).filter(Boolean);
    if (!children.length) {
        return root ? {kind: 'group', combinator: 'AND', negated: false, children: []} : null;
    }
    // a non-negated group of one: its child
    if (!node.negated && children.length === 1 && !root) {
        return children[0];
    }
    // a negated group of only a non-negated group: that group negated
    if (node.negated && children.length === 1 && children[0].kind === 'group' && !children[0].negated) {
        return {...children[0], negated: true};
    }
    // the same combinator inside is merged
    children = children.flatMap(child => child.kind === 'group' && !child.negated && child.combinator === node.combinator && child.children.length > 1 ? child.children : [child]);
    const combinator = children.length === 1 ? 'AND' : node.combinator;
    if (root && !node.negated && children.length === 1 && children[0].kind === 'group') {
        return children[0];
    }
    return {kind: 'group', combinator, negated: node.negated, children};
}

test('random trees: written, read and written again give the same filter and tree', () => {
    const random = prng(7);
    const docs = documents(random, 30);
    for (let i = 0; i < 3000; i++) {
        const original = tree(random, docs);
        const first = filter(original);
        assert.deepEqual(first.errors, []);
        let read;
        try {
            read = parsed(first.filter);
        } catch (e) {
            assert.fail(`not read: ${first.filter} (${e.message})`);
        }
        assert.equal(filter(read).filter, first.filter, `written again: ${first.filter}`);
        assert.deepEqual(read, normalize(original), `the tree of: ${first.filter}`);
    }
});

test('random filters: every filter read is written as it means', () => {
    // filters written by hand: with more spaces, without needless parentheses
    const random = prng(11);
    const docs = documents(random, 20);
    for (let i = 0; i < 1000; i++) {
        const text = filter(tree(random, docs)).filter;
        // outside of the quoted values
        const spaced = text.split(/('(?:[^']|'')*')/).map((piece, j) => j % 2 ? piece : piece.replace(/ (AND|OR) /g, '   $1\t').replace(/\(/g, '( ')).join('');
        assert.equal(filter(parsed(spaced)).filter, text, spaced);
    }
});

// ---- the fixture of the PHP test ----

test('the fixture of the PHP test is up to date', () => {
    const file = path.join(__dirname, '..', 'fixtures', 'query-builder.json');
    const current = JSON.parse(fs.readFileSync(file, 'utf8'));
    assert.deepEqual(current, JSON.parse(JSON.stringify(fixture())), 'outdated: run tests/js/generate-fixtures.js');
});

// ---- PHP code ----

test('PHP strings', () => {
    assert.equal(QB.phpString('a'), "'a'");
    assert.equal(QB.phpString("name = 'a'"), '"name = \'a\'"');
    assert.equal(QB.phpString("a = '$x'"), "'a = \\'$x\\''");
    assert.equal(QB.phpString('back\\slash'), "'back\\\\slash'");
});
