/**
 * Syntax highlighting of OQL (docs/query.md) for the query console of the viewer: splits a query into tokens like
 * the lexer of the library (src/melia/ObjectStorage/Query/Lexer.php) and writes them as HTML with a class per kind.
 * Nothing is lost: the text of the tokens put together is the query, also for unfinished or invalid queries (an
 * unterminated string or comment goes to the end). Tested with Node (tests/js/oql-highlight.test.js).
 */
(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.OqlHighlight = api;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    const KEYWORDS = ['SELECT', 'DISTINCT', 'FROM', 'AS', 'JOIN', 'INNER', 'LEFT', 'OUTER', 'ON', 'WHERE', 'GROUP', 'BY',
        'HAVING', 'ORDER', 'ASC', 'DESC', 'LIMIT', 'OFFSET', 'AND', 'OR', 'NOT', 'IS', 'NULL', 'IN', 'BETWEEN', 'LIKE',
        'TRUE', 'FALSE', 'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE', 'UUID'];
    const LITERALS = ['NULL', 'TRUE', 'FALSE'];
    const FUNCTIONS = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'LOWER', 'UPPER', 'LENGTH', 'TRIM', 'ABS', 'ROUND', 'SUBSTR',
        'COALESCE', 'IFNULL', 'REF', 'NOW', 'DATETIME', 'DATE', 'DATE_ADD', 'YEAR', 'MONTH', 'DAY', 'HOUR', 'MINUTE',
        'SECOND'];

    /**
     * The tokens of a query: {type, text, start}; types: keyword, literal (NULL, TRUE, FALSE), uuid (the keyword),
     * function, identifier, quoted (an identifier in backticks or double quotes), string, number, parameter,
     * operator, punctuation, comment, space, other.
     */
    function tokenize(query) {
        const tokens = [];
        const pattern = new RegExp([
            '(\\s+)',                                        // 1 space
            '(--[^\\n]*|\\/\\*[\\s\\S]*?(?:\\*\\/|$))',      // 2 comments, unterminated to the end
            "('(?:[^']|'')*(?:'|$))",                        // 3 strings, unterminated to the end
            '(`(?:[^`]|``)*(?:`|$)|"(?:[^"]|"")*(?:"|$))',   // 4 quoted identifiers
            '((?:\\d+(?:\\.\\d*)?|\\.\\d+)(?:[eE][+-]?\\d+)?)', // 5 numbers
            '(\\?|:[A-Za-z_][A-Za-z0-9_]*)',                 // 6 parameters
            '([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)',              // 7 words: keywords, functions, names
            '(<>|!=|<=|>=|\\|\\||[=<>+\\-*/])',              // 8 operators
            '([(),.;])',                                     // 9 punctuation
            '([\\s\\S])',                                    // 10 anything else
        ].join('|'), 'gy');

        let match;
        while (pattern.lastIndex < query.length && (match = pattern.exec(query)) !== null) {
            const [text, space, comment, string, quoted, number, parameter, word, operator, punctuation] = match;
            const start = match.index;
            let type;
            if (space !== undefined) type = 'space';
            else if (comment !== undefined) type = 'comment';
            else if (string !== undefined) type = 'string';
            else if (quoted !== undefined) type = 'quoted';
            else if (number !== undefined) type = 'number';
            else if (parameter !== undefined) type = 'parameter';
            else if (word !== undefined) {
                const upper = word.toUpperCase();
                // a function: its name before a parenthesis (also a keyword-like name as COUNT)
                const rest = query.slice(start + word.length);
                if (FUNCTIONS.includes(upper) && /^\s*\(/.test(rest)) type = 'function';
                else if ('UUID' === upper && !word.includes('\\')) type = 'uuid';
                else if (LITERALS.includes(upper)) type = 'literal';
                else if (KEYWORDS.includes(upper)) type = 'keyword';
                else type = 'identifier';
            } else if (operator !== undefined) type = 'operator';
            else if (punctuation !== undefined) type = 'punctuation';
            else type = 'other';
            tokens.push({type, text, start});
        }
        return tokens;
    }

    const escape = text => text.replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

    /**
     * The query as HTML: <span class="oql-…"> per token, spaces as they are. With an error position (an index of
     * the query), the token there is marked as oql-error, or the end if it is behind the last one.
     */
    function highlight(query, errorAt = null) {
        let html = '';
        let marked = false;
        for (const token of tokenize(query)) {
            const error = null !== errorAt && !marked && 'space' !== token.type && token.start + token.text.length > errorAt;
            if (error) {
                marked = true;
            }
            html += 'space' === token.type && !error
                ? escape(token.text)
                : `<span class="oql-${token.type}${error ? ' oql-error' : ''}">${escape(token.text)}</span>`;
        }
        if (null !== errorAt && !marked) {
            html += '<span class="oql-error oql-error-end"> </span>';
        }
        return html;
    }

    /**
     * The index of a character of a string from the offset of its byte in UTF-8, as positions of errors are given.
     */
    function indexOfByte(text, byteOffset) {
        let bytes = 0;
        let index = 0;
        for (const character of text) {
            if (bytes >= byteOffset) {
                return index;
            }
            const code = character.codePointAt(0);
            bytes += code < 0x80 ? 1 : code < 0x800 ? 2 : code < 0x10000 ? 3 : 4;
            index += character.length;
        }
        return index;
    }

    return {KEYWORDS, FUNCTIONS, tokenize, highlight, indexOfByte};
});
