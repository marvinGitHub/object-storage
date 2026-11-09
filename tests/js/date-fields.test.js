'use strict';
/**
 * Tests of the date widgets of the viewer (assets/date-fields.js). Run with Node 20 or newer, no packages needed:
 *
 *     docker run --rm -v "$PWD:/app" -w /app node:22-alpine node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const DF = require('../../assets/date-fields.js');

const date = (utc, tz = 'UTC', cls = 'DateTimeImmutable') => ({__datetime: utc, tz, class: cls});

test('dates are found by their path, also nested and in lists', () => {
    const data = {
        title: 'x',
        at: date('2026-01-01T09:00:00.000000Z', 'Europe/Berlin'),
        lines: [{at: date('2026-01-02T00:00:00.000000Z')}, {at: null}],
        almost: {__datetime: 'tomorrow', tz: 'UTC', class: 'DateTimeImmutable'},
        more: {...date('2026-01-01T00:00:00.000000Z'), extra: 1},
    };
    assert.deepEqual(DF.findDates(data, '__datetime').map(d => [DF.pathText(d.path), d.tz]), [['at', 'Europe/Berlin'], ['lines[0].at', 'UTC']]);
    assert.deepEqual(DF.findDates({at: {__dt: '2026-01-01T00:00:00.000000Z', tz: 'UTC', class: 'X'}}, '__dt').map(d => d.path), [['at']], 'another reserved key');
});

test('the time of a date in its time zone', () => {
    assert.equal(DF.toLocal('2026-01-01T09:00:00.123456Z', 'Europe/Berlin'), '2026-01-01T10:00:00.123');
    assert.equal(DF.toLocal('2026-07-01T09:00:00.000000Z', 'Europe/Berlin'), '2026-07-01T11:00', 'summer time');
    assert.equal(DF.toLocal('2026-01-01T00:00:00.000000Z', '+05:45'), '2026-01-01T05:45');
    assert.equal(DF.toLocal('2026-01-01T00:00:00.000000Z', '-03:00'), '2025-12-31T21:00');
    assert.equal(DF.toLocal('2026-07-01T00:00:00.000000Z', 'CEST', {CEST: 7200}), '2026-07-01T02:00', 'abbreviations by the offsets of the server');
    assert.equal(DF.toLocal('2026-01-01T00:00:00.000000Z', 'Mars/Olympus'), null);
    assert.equal(DF.toLocal('+10000-01-01T00:00:00.000000Z', 'UTC'), null, 'years an input can\'t show');
});

test('a time in a time zone as stored', () => {
    assert.equal(DF.fromLocal('2026-01-01T10:00:00.123', 'Europe/Berlin'), '2026-01-01T09:00:00.123000Z');
    assert.equal(DF.fromLocal('2026-07-01T11:00', 'Europe/Berlin'), '2026-07-01T09:00:00.000000Z');
    assert.equal(DF.fromLocal('2026-01-01T10:00:00.123456', 'UTC'), '2026-01-01T10:00:00.123456Z', 'microseconds kept');
    assert.equal(DF.fromLocal('2026-01-01T05:45', '+05:45'), '2026-01-01T00:00:00.000000Z');
    assert.equal(DF.fromLocal('2026-07-01T02:00', 'CEST', {CEST: 7200}), '2026-07-01T00:00:00.000000Z');
    // a gap: 02:30 doesn't exist in Berlin on 2026-03-29, moved forward as PHP does (03:30 CEST)
    assert.equal(DF.fromLocal('2026-03-29T02:30', 'Europe/Berlin'), '2026-03-29T01:30:00.000000Z');
    // an overlap: 02:30 is twice on 2026-10-25, the earlier one (CEST)
    assert.equal(DF.fromLocal('2026-10-25T02:30', 'Europe/Berlin'), '2026-10-25T00:30:00.000000Z');
    assert.equal(DF.fromLocal('2026-02-30T10:00', 'UTC'), null, 'a day that does not exist');
    assert.equal(DF.fromLocal('soon', 'UTC'), null);
    assert.equal(DF.fromLocal('2026-01-01T10:00', 'Mars/Olympus'), null);

    // back and forth
    for (const tz of ['Europe/Berlin', 'America/New_York', 'Asia/Kolkata', 'Australia/Lord_Howe', '+05:45']) {
        for (const utc of ['2026-01-01T00:00:00.000000Z', '2026-06-15T12:34:56.789000Z', '1969-07-20T20:17:40.000000Z']) {
            assert.equal(DF.fromLocal(DF.toLocal(utc, tz), tz), utc, `${utc} in ${tz}`);
        }
    }
});

test('a date is replaced in the JSON text, the rest stays as it is', () => {
    const text = '{"a":2.0,"at":{"__datetime":"2026-01-01T09:00:00.000000Z","tz":"Europe\\/Berlin","class":"App\\\\Date"},'
        + '"lines":[{"at":{"__datetime":"2026-01-02T00:00:00.000000Z","tz":"UTC","class":"DateTime"}}],"s":"\\u00e4 \\"x\\""}';
    const replaced = DF.replaceDate(text, ['lines', 0, 'at'], '__datetime', {utc: '2026-02-02T00:00:00.000000Z', tz: 'America/New_York', class: 'DateTime'});
    assert.equal(replaced, text.replace('{"__datetime":"2026-01-02T00:00:00.000000Z","tz":"UTC","class":"DateTime"}',
        '{"__datetime":"2026-02-02T00:00:00.000000Z","tz":"America\\/New_York","class":"DateTime"}'));
    const first = DF.replaceDate(text, ['at'], '__datetime', {utc: '2026-01-01T10:00:00.000000Z', tz: 'Europe/Berlin', class: 'App\\Date'});
    assert.ok(first.startsWith('{"a":2.0,"at":{"__datetime":"2026-01-01T10:00:00.000000Z","tz":"Europe\\/Berlin","class":"App\\\\Date"},'));
    assert.deepEqual(JSON.parse(first).lines, JSON.parse(text).lines);

    assert.equal(DF.replaceDate(text, ['a'], '__datetime', {utc: 'x', tz: 'UTC', class: 'X'}), null, 'not a date');
    assert.equal(DF.replaceDate(text, ['nope'], '__datetime', {utc: 'x', tz: 'UTC', class: 'X'}), null);
    assert.equal(DF.replaceDate('{"at":', ['at'], '__datetime', {utc: 'x', tz: 'UTC', class: 'X'}), null, 'not JSON');
    assert.deepEqual(DF.locate(' [1, {"k": [true, null]} ] ', [1, 'k', 0]), [12, 16]);
});
