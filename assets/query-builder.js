/**
 * The logic of the query builder of the viewer, without the page: the conditions and groups of a filter as a tree,
 * written as a filter in the language of Loupe (with NOT, LIKE and CONTAINS of the Loupe engine of this library),
 * and filters read back into a tree. Used by views/search-builder.html, tested with Node (tests/js).
 *
 * A tree is a group: {kind: 'group', combinator: 'AND'|'OR', negated: bool, children: [group|condition]}; a condition
 * is {kind: 'condition', field: 'name or path', op: 'operator of OPERATORS', value: 'the text of the value input'}.
 * IN and NOT IN take comma-separated values, BETWEEN two ("10, 20"), the IS operators none.
 *
 * The builder never changes what a filter means: a condition it can't write exactly is an error, not left out, and
 * a filter it can't show exactly isn't read (parse() throws), so the page keeps it as it is.
 */
(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.QueryBuilder = api;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /** the operators per type of field, in the order the builder offers them */
    const OPERATORS = {
        'string': ['=', '!=', 'CONTAINS', 'NOT CONTAINS', 'STARTS WITH', 'ENDS WITH', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL', 'IS EMPTY', 'IS NOT EMPTY'],
        'number': ['=', '!=', '>', '>=', '<', '<=', 'BETWEEN', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL'],
        'boolean': ['=', '!=', 'IS NULL', 'IS NOT NULL'],
        'array<string>': ['IN', 'NOT IN', 'CONTAINS', 'NOT CONTAINS', 'LIKE', 'NOT LIKE', 'IS EMPTY', 'IS NOT EMPTY'],
        'array<number>': ['IN', 'NOT IN', 'IS EMPTY', 'IS NOT EMPTY'],
    };
    const COMPARISONS = ['=', '!=', '>', '>=', '<', '<='];
    const PATTERNS = ['LIKE', 'NOT LIKE', 'CONTAINS', 'NOT CONTAINS'];
    const KEYWORDS = ['AND', 'OR', 'NOT', 'IN', 'IS', 'NULL', 'EMPTY', 'BETWEEN', 'LIKE', 'CONTAINS'];
    const NUMBER = /^-?\d+(\.\d+)?$/;

    const withoutValue = op => op.startsWith('IS ');
    const isNumberType = type => type === 'number' || type === 'array<number>';
    const quote = text => "'" + text.replace(/'/g, "''") + "'";
    // STARTS WITH and ENDS WITH become LIKE: \, % and _ of the value are taken as they are
    const likeEscape = text => text.replace(/[\\%_]/g, c => '\\' + c);
    const splitList = value => value.split(',').map(v => v.trim()).filter(v => v !== '');

    /**
     * Finds fields by their names or their paths ("friend.name" for a field "friendName" with this path).
     * @param fields [{name, path, type, filterable, sortable, searchable, reserved?}]
     */
    function createFields(fields) {
        const byName = new Map(fields.map(f => [f.name, f]));
        // names come first: a path that is also the name of a field names that field
        const byPath = new Map(fields.filter(f => !f.reserved && f.path !== f.name && !byName.has(f.path)).map(f => [f.path, f]));
        return {
            all: fields,
            find: text => byName.get(text) ?? byPath.get(text) ?? null,
        };
    }

    /**
     * The text of a condition, or why it can't be written.
     * @returns {{text: string}|{error: string}|{empty: true}} empty: a condition without a field, left out
     */
    function conditionText(condition, fields) {
        const name = condition.field.trim();
        if (name === '') {
            return {empty: true};
        }
        const field = fields.find(name);
        if (!field) {
            return {error: `"${name}" is no field of the index`};
        }
        if (!field.filterable) {
            return {error: `"${name}" isn't filterable`};
        }
        const op = condition.op;
        if (!(OPERATORS[field.type] ?? []).includes(op)) {
            return {error: `${op} isn't possible for "${name}" (${field.type})`};
        }
        if (withoutValue(op)) {
            return {text: `${name} ${op}`};
        }

        const value = condition.value ?? '';
        const single = text => {
            if (field.type === 'boolean') {
                return text === 'true' || text === 'false' ? {text} : {error: `"${name}" needs true or false`};
            }
            if (isNumberType(field.type)) {
                const trimmed = text.trim();
                return NUMBER.test(trimmed) ? {text: trimmed} : {error: `"${text}" is no number (${name})`};
            }
            if (text === '') {
                return {error: `a value is missing (${name})`};
            }
            if (/[\r\n]/.test(text)) {
                return {error: `a value can't contain a line break (${name})`};
            }
            return {text: quote(text)};
        };

        if (op === 'IN' || op === 'NOT IN') {
            const values = splitList(value);
            if (!values.length) {
                return {error: `values are missing (${name})`};
            }
            const written = values.map(single);
            const failed = written.find(w => w.error);
            return failed ?? {text: `${name} ${op} (${written.map(w => w.text).join(', ')})`};
        }
        if (op === 'BETWEEN') {
            const values = splitList(value);
            if (values.length !== 2) {
                return {error: `BETWEEN needs two numbers, e.g. "10, 20" (${name})`};
            }
            const [from, to] = values.map(single);
            if (from.error || to.error) {
                return from.error ? from : to;
            }
            // as in SQL, BETWEEN 20 AND 10 matches nothing: surely not what is meant
            if (Number(from.text) > Number(to.text)) {
                return {error: `BETWEEN needs the smaller number first (${name})`};
            }
            return {text: `${name} BETWEEN ${from.text} AND ${to.text}`};
        }
        if (op === 'STARTS WITH' || op === 'ENDS WITH') {
            const written = single(value);
            if (written.error) {
                return written;
            }
            return {text: `${name} LIKE ${quote(op === 'STARTS WITH' ? likeEscape(value) + '%' : '%' + likeEscape(value))}`};
        }
        if (PATTERNS.includes(op)) {
            const written = single(value);
            return written.error ? written : {text: `${name} ${op} ${written.text}`};
        }
        const written = single(value);
        return written.error ? written : {text: `${name} ${op} ${written.text}`};
    }

    /**
     * The filter of a group: its conditions and groups in their order, combined with its operator; a group of
     * several parts in parentheses, a negated one as NOT (…). Conditions without a field and empty groups are left
     * out.
     * @returns {{text: string, parts: number, negated: boolean, errors: string[]}|null} null: nothing to write
     */
    function groupText(group, fields) {
        const errors = [];
        const parts = []; // {text, inner}: inner, the result of a group
        for (const child of group.children) {
            if (child.kind === 'group') {
                const inner = groupText(child, fields);
                if (inner) {
                    errors.push(...inner.errors);
                    if (inner.text !== '') {
                        // in parentheses: several parts combined the other way; the same way needs none (a AND (b AND c))
                        const wrap = inner.parts > 1 && !inner.negated && inner.combinator !== group.combinator;
                        parts.push({text: wrap ? `(${inner.text})` : inner.text, inner});
                    }
                }
            } else {
                const written = conditionText(child, fields);
                if (written.error) {
                    errors.push(written.error);
                } else if (!written.empty) {
                    parts.push({text: written.text});
                }
            }
        }
        const combinator = group.combinator;
        if (!parts.length) {
            return errors.length ? {text: '', parts: 0, negated: false, combinator, errors} : null;
        }
        // a group of only a group is that group (negated: NOT of it), without parentheses of its own
        if (parts.length === 1 && parts[0].inner) {
            const inner = parts[0].inner;
            return group.negated
                ? {text: `NOT (${inner.text})`, parts: 1, negated: true, combinator, errors}
                : {text: inner.text, parts: inner.parts, negated: inner.negated, combinator: inner.combinator, errors};
        }
        const text = parts.map(part => part.text).join(` ${combinator} `);
        return group.negated
            ? {text: `NOT (${text})`, parts: 1, negated: true, combinator, errors}
            : {text, parts: parts.length, negated: false, combinator, errors};
    }

    /**
     * The filter of a tree, combined with a filter kept as it is (one the builder can't show) by AND.
     * @returns {{filter: string, errors: string[]}}
     */
    function buildFilter(tree, fields, kept = '') {
        const built = groupText(tree, fields);
        const errors = built?.errors ?? [];
        let filter = built?.text ?? '';
        kept = kept.trim();
        if (kept !== '' && filter !== '') {
            filter = `(${kept}) AND ` + (built.parts > 1 ? `(${filter})` : filter);
        } else if (kept !== '') {
            filter = kept;
        }
        return {filter, errors};
    }

    // ---- reading a filter ----

    function tokenize(text) {
        const tokens = [];
        const pattern = /\s+|('(?:[^']|'')*')|(-?\d+(?:\.\d+)?(?![\w.]))|(!=|>=|<=|=|>|<|\(|\)|,)|([A-Za-z_]\w*(?:\.[A-Za-z_]\w*)*)|(.)/gy;
        let match;
        let position = 0;
        while ((match = pattern.exec(text)) !== null && match[0] !== '') {
            position = pattern.lastIndex;
            const [, string, number, symbol, word, other] = match;
            if (string !== undefined) {
                tokens.push({type: 'value', value: string.slice(1, -1).replace(/''/g, "'"), kind: 'string'});
            } else if (number !== undefined) {
                tokens.push({type: 'value', value: number, kind: 'number'});
            } else if (symbol !== undefined) {
                tokens.push({type: 'symbol', value: symbol});
            } else if (word !== undefined) {
                if (KEYWORDS.includes(word)) {
                    tokens.push({type: 'keyword', value: word});
                } else if (word === 'true' || word === 'false') {
                    tokens.push({type: 'value', value: word, kind: 'boolean'});
                } else {
                    tokens.push({type: 'name', value: word});
                }
            } else if (other !== undefined) {
                throw new Error(`unexpected "${other}"`);
            }
        }
        // a failed match resets lastIndex: what is left after the last token can't be read
        if (position < text.length) {
            throw new Error(`unexpected "${text.slice(position, position + 10)}"`);
        }
        return tokens;
    }

    /**
     * Reads a filter into a tree; throws an Error for what the builder can't show exactly: other syntax (e.g. geo
     * filters), fields that aren't filterable fields of the index, operators or values that don't fit the type of
     * the field, values of IN with a comma or surrounding spaces, line breaks in values.
     */
    function parse(text, fields) {
        const tokens = tokenize(text);
        let i = 0;
        const is = (type, value) => tokens[i]?.type === type && (value === undefined || tokens[i].value === value);
        const expect = (type, value) => {
            if (!is(type, value)) {
                throw new Error(`expected ${value ?? type}${tokens[i] ? `, got "${tokens[i].value}"` : ' at the end'}`);
            }
            return tokens[i++];
        };

        // a group of the same combinator inside is merged into it
        const combine = (combinator, children) => children.length === 1 ? children[0] : {
            kind: 'group', combinator, negated: false,
            children: children.flatMap(child => child.kind === 'group' && child.combinator === combinator && !child.negated ? child.children : [child]),
        };
        const parseOr = () => {
            const children = [parseAnd()];
            while (is('keyword', 'OR')) {
                i++;
                children.push(parseAnd());
            }
            return combine('OR', children);
        };
        const parseAnd = () => {
            const children = [parseUnary()];
            while (is('keyword', 'AND')) {
                i++;
                children.push(parseUnary());
            }
            return combine('AND', children);
        };
        const parseUnary = () => {
            if (is('keyword', 'NOT')) {
                i++;
                const inner = parseUnary();
                // NOT of a group negates it; NOT of a condition (or of a negated group): a negated group holding it
                if (inner.kind === 'group' && !inner.negated) {
                    return {...inner, negated: true};
                }
                return {kind: 'group', combinator: 'AND', negated: true, children: [inner]};
            }
            if (is('symbol', '(')) {
                i++;
                const inner = parseOr();
                expect('symbol', ')');
                return inner;
            }
            return parseCondition();
        };

        const parseCondition = () => {
            const name = expect('name').value;
            const field = fields.find(name);
            if (!field || !field.filterable) {
                throw new Error(`"${name}" is no filterable field of the index`);
            }
            const type = field.type;
            const condition = (op, value) => {
                if (!(OPERATORS[type] ?? []).includes(op)) {
                    throw new Error(`${op} isn't possible for "${name}" (${type})`);
                }
                return {kind: 'condition', field: name, op, value};
            };
            // a value as the builder writes it for the type of the field
            const value = () => {
                const token = expect('value');
                const kind = type === 'boolean' ? 'boolean' : isNumberType(type) ? 'number' : 'string';
                if (token.kind !== kind) {
                    throw new Error(`the value ${token.kind === 'string' ? quote(token.value) : token.value} doesn't fit "${name}" (${type})`);
                }
                if (/[\r\n]/.test(token.value)) {
                    throw new Error('a value contains a line break');
                }
                return token.value;
            };

            if (is('symbol')) {
                const op = expect('symbol').value;
                if (!COMPARISONS.includes(op)) {
                    throw new Error(`unexpected "${op}"`);
                }
                const operand = value();
                // = '' is IS EMPTY for strings (the same documents in Loupe); the builder has no empty value
                if (operand === '' && type === 'string' && (op === '=' || op === '!=')) {
                    return condition(op === '=' ? 'IS EMPTY' : 'IS NOT EMPTY', '');
                }
                if (operand === '') {
                    throw new Error(`an empty value can't be shown (${name})`);
                }
                return condition(op, operand);
            }
            const negated = is('keyword', 'NOT') ? (i++, true) : false;
            const keyword = expect('keyword').value;
            if (keyword === 'IN') {
                expect('symbol', '(');
                const values = [value()];
                while (is('symbol', ',')) {
                    i++;
                    values.push(value());
                }
                expect('symbol', ')');
                // the builder writes values of IN comma-separated and trims them
                if (values.some(v => v.includes(',') || v !== v.trim() || v === '')) {
                    throw new Error(`a value of IN contains a comma or spaces around it, or is empty (${name})`);
                }
                return condition(negated ? 'NOT IN' : 'IN', values.join(', '));
            }
            if (keyword === 'LIKE' || keyword === 'CONTAINS') {
                const pattern = value();
                if (pattern === '') {
                    throw new Error(`an empty pattern can't be shown (${name})`);
                }
                if (keyword === 'LIKE' && !negated && type === 'string') {
                    // a prefix or a suffix without other wildcards: STARTS WITH and ENDS WITH of the builder
                    const literal = /^(?:[^%_\\]|\\[\\%_])+$/;
                    const unescape = text => text.replace(/\\([\\%_])/g, '$1');
                    if (pattern.endsWith('%') && literal.test(pattern.slice(0, -1))) {
                        return condition('STARTS WITH', unescape(pattern.slice(0, -1)));
                    }
                    if (pattern.startsWith('%') && literal.test(pattern.slice(1))) {
                        return condition('ENDS WITH', unescape(pattern.slice(1)));
                    }
                }
                return condition((negated ? 'NOT ' : '') + keyword, pattern);
            }
            if (negated) {
                throw new Error(`unexpected NOT ${keyword}`);
            }
            if (keyword === 'IS') {
                const not = is('keyword', 'NOT') ? (i++, true) : false;
                const what = expect('keyword').value;
                if (what !== 'NULL' && what !== 'EMPTY') {
                    throw new Error(`unexpected IS ${what}`);
                }
                return condition(`IS ${not ? 'NOT ' : ''}${what}`, '');
            }
            if (keyword === 'BETWEEN') {
                const from = value();
                expect('keyword', 'AND');
                const to = value();
                if (Number(from) > Number(to)) {
                    throw new Error(`BETWEEN ${from} AND ${to} has the greater number first (${name})`);
                }
                return condition('BETWEEN', `${from}, ${to}`);
            }
            throw new Error(`unexpected ${keyword}`);
        };

        if (!tokens.length) {
            return {kind: 'group', combinator: 'AND', negated: false, children: []};
        }
        const tree = parseOr();
        if (i < tokens.length) {
            throw new Error(`unexpected "${tokens[i].value}"`);
        }
        return tree.kind === 'group' ? tree : {kind: 'group', combinator: 'AND', negated: false, children: [tree]};
    }

    // ---- PHP code ----

    // filters quote strings with ': in double quotes, if nothing in them would be interpolated
    const phpString = value => value.includes("'") && !/[$"\\]/.test(value)
        ? '"' + value + '"'
        : "'" + value.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";

    return {OPERATORS, withoutValue, isNumberType, splitList, likeEscape, createFields, conditionText, groupText, buildFilter, tokenize, parse, phpString};
});
