/**
 * Dates in the data of a record, edited with date widgets in the viewer (views/view-record.html).
 *
 * Dates are stored inline (see docs/serialization.md, "Dates"):
 *     {"__datetime": "2026-11-09T09:00:00.123456Z", "tz": "Europe/Berlin", "class": "DateTimeImmutable"}
 * The widgets show the time of the date in its own time zone (as loading it does) and write a changed date back into
 * the JSON text, replacing only that date: the rest of the text stays as it is (numbers like 2.0, escapes, order).
 *
 * Works in the browser (window.DateFields) and in Node (require), for the tests in tests/js/.
 */
(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.DateFields = api;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    const UTC = /^([+-]?\d{4,})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})\.(\d{6})Z$/;
    const OFFSET = /^([+-])(\d{2}):?(\d{2})$/;
    const LOCAL = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,6}))?)?$/;

    const pad = (n, width = 2) => String(n).padStart(width, '0');

    /** whether a value is a date as stored, with the reserved key */
    function isDate(value, reserved) {
        if (value === null || typeof value !== 'object' || Array.isArray(value)) {
            return false;
        }
        const keys = Object.keys(value);
        return keys.length === 3 && keys.includes(reserved) && keys.includes('tz') && keys.includes('class')
            && typeof value[reserved] === 'string' && typeof value.tz === 'string' && typeof value.class === 'string'
            && UTC.test(value[reserved]);
    }

    /**
     * The dates in data, depth first in the order of the text.
     * @returns {{path: (string|number)[], utc: string, tz: string, class: string}[]}
     */
    function findDates(data, reserved) {
        const found = [];
        const walk = (value, path) => {
            if (isDate(value, reserved)) {
                found.push({path, utc: value[reserved], tz: value.tz, class: value.class});
            } else if (Array.isArray(value)) {
                value.forEach((v, i) => walk(v, [...path, i]));
            } else if (value !== null && typeof value === 'object') {
                for (const [key, v] of Object.entries(value)) {
                    walk(v, [...path, key]);
                }
            }
        };
        walk(data, []);
        return found;
    }

    /** a path as text: createdAt, lines[1].at */
    const pathText = path => path.map((p, i) => typeof p === 'number' ? `[${p}]` : (i === 0 ? p : '.' + p)).join('');

    /**
     * The offset of a time zone at a point in time, in minutes east of UTC: a fixed offset (+05:45), a fixed one
     * given by the server (abbreviations like CEST, see fixed), or an identifier the browser knows (Europe/Berlin).
     * @param {number} ms milliseconds since 1970 UTC
     * @param {Object<string, number>} fixed time zones of fixed offsets, in seconds
     * @returns {number|null} null for an unknown time zone
     */
    function offsetAt(ms, tz, fixed = {}) {
        const match = OFFSET.exec(tz);
        if (match) {
            return (match[1] === '-' ? -1 : 1) * (Number(match[2]) * 60 + Number(match[3]));
        }
        if (tz.toUpperCase() === 'UTC' || tz.toUpperCase() === 'Z') {
            return 0;
        }
        if (Object.prototype.hasOwnProperty.call(fixed, tz)) {
            return fixed[tz] / 60;
        }
        let parts;
        try {
            parts = new Intl.DateTimeFormat('en-US', {
                timeZone: tz, hourCycle: 'h23', era: 'short',
                year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit',
            }).formatToParts(new Date(ms));
        } catch {
            return null;
        }
        const get = type => Number(parts.find(p => p.type === type).value);
        const era = parts.find(p => p.type === 'era')?.value;
        const year = era === 'BC' || era === 'B' ? 1 - get('year') : get('year');
        const wall = Date.UTC(0, get('month') - 1, get('day'), get('hour'), get('minute'), get('second'));
        const wallDate = new Date(wall);
        wallDate.setUTCFullYear(year);
        const seconds = Math.floor(ms / 1000) * 1000;
        return Math.round((wallDate.getTime() - seconds) / 60000);
    }

    /** milliseconds and the microseconds beyond them of a stored point in time */
    function parseUtc(utc) {
        const m = UTC.exec(utc);
        if (!m) {
            return null;
        }
        const date = new Date(Date.UTC(2000, Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5]), Number(m[6]), Number(m[7].slice(0, 3))));
        date.setUTCFullYear(Number(m[1]));
        return {ms: date.getTime(), micro: Number(m[7].slice(3)), year: Number(m[1])};
    }

    /**
     * The time of a stored date in a time zone, for an input of type datetime-local (with milliseconds); null if
     * the time zone is unknown or the year can't be shown (before 1 or after 9999).
     */
    function toLocal(utc, tz, fixed = {}) {
        const point = parseUtc(utc);
        if (!point) {
            return null;
        }
        const offset = offsetAt(point.ms, tz, fixed);
        if (offset === null) {
            return null;
        }
        const wall = new Date(point.ms + offset * 60000);
        const year = wall.getUTCFullYear();
        if (year < 1 || year > 9999) {
            return null;
        }
        // the shortest form, as browsers keep the value of the input: without zero milliseconds and seconds
        const seconds = wall.getUTCSeconds() || wall.getUTCMilliseconds()
            ? `:${pad(wall.getUTCSeconds())}` + (wall.getUTCMilliseconds() ? `.${pad(wall.getUTCMilliseconds(), 3)}` : '')
            : '';
        return `${pad(year, 4)}-${pad(wall.getUTCMonth() + 1)}-${pad(wall.getUTCDate())}T${pad(wall.getUTCHours())}:${pad(wall.getUTCMinutes())}${seconds}`;
    }

    /**
     * The stored point in time of a time in a time zone ("2026-01-01T10:00:00.5" in Europe/Berlin), in UTC with
     * microseconds; null if the time or the time zone is invalid. In a gap of daylight saving time, the time is
     * moved by the gap, in an overlap the earlier point is taken, as PHP does.
     */
    function fromLocal(local, tz, fixed = {}) {
        const m = LOCAL.exec(local);
        if (!m) {
            return null;
        }
        const fraction = (m[7] ?? '').padEnd(6, '0');
        const wall = new Date(Date.UTC(2000, Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5]), Number(m[6] ?? 0), Number(fraction.slice(0, 3))));
        wall.setUTCFullYear(Number(m[1]));
        if (wall.getUTCMonth() !== Number(m[2]) - 1 || wall.getUTCDate() !== Number(m[3])) {
            return null; // a day that doesn't exist
        }
        const wallMs = wall.getTime();
        // the offsets a day before and after: the points in time they give, if their offset is the one used
        const before = offsetAt(wallMs - 86400000, tz, fixed);
        const after = offsetAt(wallMs + 86400000, tz, fixed);
        if (before === null || after === null) {
            return null;
        }
        const candidates = [...new Set([before, after])]
            .map(offset => wallMs - offset * 60000)
            .filter(ms => wallMs - offsetAt(ms, tz, fixed) * 60000 === ms);
        // an overlap: the earlier; a gap: moved forward by it (the offset before the gap)
        const ms = candidates.length ? Math.min(...candidates) : wallMs - before * 60000;
        const date = new Date(ms);
        return `${pad(date.getUTCFullYear(), 4)}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}T${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}:${pad(date.getUTCSeconds())}.${pad(date.getUTCMilliseconds(), 3)}${fraction.slice(3)}Z`;
    }

    // ---- the JSON text ----

    /**
     * The position of the value at a path in a JSON text: [start, end); null if the path isn't there or the text
     * isn't JSON.
     */
    function locate(text, path) {
        let i = 0;
        const space = () => {
            while (i < text.length && ' \t\r\n'.includes(text[i])) {
                i++;
            }
        };
        const fail = () => {
            throw new SyntaxError('invalid JSON at ' + i);
        };
        const string = () => {
            const start = i;
            if (text[i] !== '"') {
                fail();
            }
            for (i++; i < text.length; i++) {
                if (text[i] === '\\') {
                    i++;
                } else if (text[i] === '"') {
                    i++;
                    return JSON.parse(text.slice(start, i));
                }
            }
            fail();
        };
        // skips a value; at depth < path.length, returns the span of the value at the rest of the path
        const value = depth => {
            space();
            const start = i;
            const wanted = path[depth];
            let found = null;
            if (text[i] === '{') {
                i++;
                space();
                if (text[i] === '}') {
                    i++;
                } else {
                    for (;;) {
                        space();
                        const key = string();
                        space();
                        if (text[i++] !== ':') {
                            fail();
                        }
                        const inner = value(depth + 1);
                        if (depth < path.length && key === String(wanted) && typeof wanted === 'string') {
                            found = inner;
                        }
                        space();
                        if (text[i] === ',') {
                            i++;
                        } else if (text[i] === '}') {
                            i++;
                            break;
                        } else {
                            fail();
                        }
                    }
                }
            } else if (text[i] === '[') {
                i++;
                space();
                if (text[i] === ']') {
                    i++;
                } else {
                    for (let index = 0; ; index++) {
                        const inner = value(depth + 1);
                        if (depth < path.length && index === wanted) {
                            found = inner;
                        }
                        space();
                        if (text[i] === ',') {
                            i++;
                        } else if (text[i] === ']') {
                            i++;
                            break;
                        } else {
                            fail();
                        }
                    }
                }
            } else if (text[i] === '"') {
                string();
            } else {
                const literal = /^(-?\d+(\.\d+)?([eE][+-]?\d+)?|true|false|null)/.exec(text.slice(i));
                if (!literal) {
                    fail();
                }
                i += literal[0].length;
            }
            return depth === path.length ? [start, i] : found;
        };
        try {
            const span = value(0);
            space();
            return i === text.length ? span : null;
        } catch {
            return null;
        }
    }

    /**
     * The JSON text with the date at a path replaced; null if there is no date at the path.
     * @param {{utc: string, tz: string, class: string}} date
     */
    function replaceDate(text, path, reserved, date) {
        const span = locate(text, path);
        if (!span) {
            return null;
        }
        let current;
        try {
            current = JSON.parse(text.slice(span[0], span[1]));
        } catch {
            return null;
        }
        if (!isDate(current, reserved)) {
            return null;
        }
        // the keys in the order of the text
        const replaced = {};
        for (const key of Object.keys(current)) {
            replaced[key] = key === reserved ? date.utc : key === 'tz' ? date.tz : date.class;
        }
        // as PHP writes it: slashes of class names escaped
        const json = JSON.stringify(replaced).replace(/\//g, '\\/');
        return text.slice(0, span[0]) + json + text.slice(span[1]);
    }

    return {isDate, findDates, pathText, offsetAt, toLocal, fromLocal, locate, replaceDate};
});
