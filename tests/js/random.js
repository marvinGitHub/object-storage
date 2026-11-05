'use strict';
/**
 * Random documents and filter trees of the query builder, from a seed (the same seed gives the same ones).
 * Used by the round trip tests and to generate tests/fixtures/query-builder.json, which the PHP test
 * QueryBuilderFiltersTest searches with Loupe and compares with a reference evaluation.
 */
const QB = require('../../assets/query-builder.js');

/** the fields of the index of the fixture; "friend.*" are paths into referenced objects (joins) */
const FIELDS = [
    {name: 'name', path: 'name', type: 'string', searchable: true, filterable: true, sortable: true},
    {name: 'nick', path: 'nick', type: 'string', searchable: false, filterable: true, sortable: false},
    {name: 'age', path: 'age', type: 'number', searchable: false, filterable: true, sortable: true},
    {name: 'score', path: 'score', type: 'number', searchable: false, filterable: true, sortable: false},
    {name: 'active', path: 'active', type: 'boolean', searchable: false, filterable: true, sortable: false},
    {name: 'tags', path: 'tags', type: 'array<string>', searchable: false, filterable: true, sortable: false},
    {name: 'friendName', path: 'friend.name', type: 'string', searchable: false, filterable: true, sortable: false},
    {name: 'friendAge', path: 'friend.age', type: 'number', searchable: false, filterable: true, sortable: false},
];

// values with what may go wrong: quotes, LIKE wildcards, backslashes, case, umlauts, spaces, look-alikes of keywords
const STRINGS = ['Anna', 'anna', 'ANNA', 'Bob', "O'Neill", "it''s", '50%', '5_0', 'a_b', 'back\\slash', '\\%', 'Ümlaut',
    'ümlaut', ' spaced ', 'two words', 'AND', 'NOT', 'null', 'true', '(x)', 'x, y', 'Ho%ma', 'Homa', 'Thomas', 'Emma'];
const NUMBERS = ['0', '1', '-1', '2', '10', '42', '-7.5', '3.25', '100', '1000'];
const TAGS = ['red', 'Red', 'green', 'blue', 'x%y', "it's", 'a_b', 'tag one', 'Ölig'];

/** a PRNG from a seed (mulberry32) */
function prng(seed) {
    let a = seed >>> 0;
    const next = () => {
        a = (a + 0x6D2B79F5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
    const int = n => Math.floor(next() * n);
    return {
        next,
        int,
        chance: p => next() < p,
        pick: list => list[int(list.length)],
        some: (list, min, max) => {
            const count = min + int(max - min + 1);
            const copy = [...list];
            const chosen = [];
            while (chosen.length < count && copy.length) {
                chosen.push(copy.splice(int(copy.length), 1)[0]);
            }
            return chosen;
        },
    };
}

/**
 * Documents: {id, name, nick?, age?, score?, active?, tags?, friend?: id}; a missing key is a missing property,
 * null a property holding null.
 */
function documents(random, count) {
    const docs = [];
    for (let i = 0; i < count; i++) {
        const doc = {id: `d${i}`, name: random.pick(STRINGS)};
        const maybe = (key, value) => {
            const r = random.next();
            if (r < 0.12) {
                return; // missing
            }
            doc[key] = r < 0.22 ? null : value();
        };
        maybe('nick', () => random.chance(0.15) ? '' : random.pick(STRINGS));
        maybe('age', () => Number(random.pick(NUMBERS)));
        maybe('score', () => Number(random.pick(NUMBERS)));
        maybe('active', () => random.chance(0.5));
        const tags = random.next();
        if (tags < 0.15) {
            doc.tags = [];
        } else if (tags > 0.1 && tags < 0.9) {
            doc.tags = random.some(TAGS, 1, 3);
        }
        docs.push(doc);
    }
    // friends: references to other documents; documents without one have no friend.name
    for (const doc of docs) {
        if (random.chance(0.7)) {
            doc.friend = random.pick(docs).id;
        }
    }
    return docs;
}

/** a value for a condition, as typed into the value input of the builder */
function valueFor(random, field, op, docs) {
    const strings = docs.flatMap(d => [d.name, d.nick]).filter(v => typeof v === 'string' && v !== '');
    const string = () => random.chance(0.8) ? random.pick(strings) : random.pick(STRINGS);
    const tag = () => random.pick(TAGS);
    const number = () => random.pick(NUMBERS);
    const pieceOf = text => {
        const from = random.int(text.length);
        return text.slice(from, from + 1 + random.int(text.length - from));
    };
    const caseChanged = text => random.chance(0.3) ? text.toUpperCase() : random.chance(0.3) ? text.toLowerCase() : text;
    // a LIKE pattern from a value: % and _ for some characters, escaped wildcards of the value
    const likeOf = text => {
        let pattern = '';
        for (const c of text) {
            const r = random.next();
            pattern += r < 0.1 ? '_' : r < 0.15 ? '%' : QB.likeEscape(c);
        }
        if (random.chance(0.3)) pattern = '%' + pattern;
        if (random.chance(0.3)) pattern = pattern + '%';
        return caseChanged(pattern);
    };
    const isArray = field.type === 'array<string>';
    const one = isArray ? tag : field.type === 'string' ? string : number;

    switch (op) {
        case 'IN':
        case 'NOT IN':
            // values of IN are comma-separated: none with a comma or spaces around it
            return random.some((isArray ? TAGS : field.type === 'string' ? [...new Set(strings), ...STRINGS] : NUMBERS)
                .filter(v => !v.includes(',') && v === v.trim()), 1, 3).join(', ');
        case 'BETWEEN':
            // the smaller number first, as the builder demands
            return [number(), number()].sort((a, b) => Number(a) - Number(b)).join(', ');
        case 'CONTAINS':
        case 'NOT CONTAINS':
            return caseChanged(pieceOf(one()));
        case 'STARTS WITH':
            return caseChanged(one().slice(0, 1 + random.int(3)));
        case 'ENDS WITH':
            return caseChanged(one().slice(-1 - random.int(3)));
        case 'LIKE':
        case 'NOT LIKE':
            return likeOf(one());
        default:
            if (field.type === 'boolean') {
                return random.chance(0.5) ? 'true' : 'false';
            }
            return one();
    }
}

function condition(random, docs) {
    const field = random.pick(FIELDS);
    const op = random.pick(QB.OPERATORS[field.type]);
    // fields by name or, joined ones, by path
    const name = field.path.includes('.') && random.chance(0.5) ? field.path : field.name;
    return {kind: 'condition', field: name, op, value: QB.withoutValue(op) ? '' : valueFor(random, field, op, docs)};
}

function tree(random, docs, depth = 0) {
    const count = 1 + random.int(depth === 0 ? 4 : 3);
    const children = [];
    for (let i = 0; i < count; i++) {
        children.push(depth < 3 && random.chance(0.3) ? tree(random, docs, depth + 1) : condition(random, docs));
    }
    return {kind: 'group', combinator: random.chance(0.5) ? 'AND' : 'OR', negated: random.chance(0.25), children};
}

/** the fixture: documents, fields and filters, each with its tree and the filter the builder writes */
function fixture(seed = 20261105, documentCount = 60, filterCount = 600) {
    const random = prng(seed);
    const docs = documents(random, documentCount);
    const fields = QB.createFields(FIELDS);
    const filters = [];
    while (filters.length < filterCount) {
        const t = tree(random, docs);
        const {filter, errors} = QB.buildFilter(t, fields);
        if (errors.length) {
            throw new Error(`the generator made an invalid tree: ${errors.join('; ')}`);
        }
        if (filter !== '') {
            filters.push({tree: t, filter});
        }
    }
    return {seed, fields: FIELDS, documents: docs, filters};
}

module.exports = {FIELDS, STRINGS, NUMBERS, TAGS, prng, documents, tree, condition, fixture};
