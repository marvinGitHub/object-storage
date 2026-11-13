// @ts-check
/**
 * The query console of the viewer (OQL): running queries, results, errors, history. The storage is seeded by
 * tests/e2e/seed.php (people as stdClass, see query-builder.spec.js).
 */
const {test, expect} = require('@playwright/test');

const STORAGE = process.env.E2E_STORAGE ?? '/tmp/e2e-storage';
const consoleUrl = (q = null) => '/view.php?' + new URLSearchParams({action: 'query', storage: STORAGE, ...(q === null ? {} : {q})});

/** the cells of the result, row by row */
async function table(page) {
    return page.locator('#query-result tbody tr').evaluateAll(rows => rows.map(row => [...row.cells].map(cell => cell.textContent.trim())));
}

test.beforeEach(async ({page}) => {
    await page.goto(consoleUrl());
    await page.evaluate(() => localStorage.removeItem('object-storage-viewer:query-history'));
});

test('a query with a path into referenced objects, grouped and sorted', async ({page}) => {
    await page.goto(consoleUrl());
    await page.locator('#q').fill("SELECT friend.name AS friend, COUNT(*) AS n, MIN(age) AS youngest\nFROM stdClass\nWHERE friend IS NOT NULL\nGROUP BY friend.name\nORDER BY friend");
    await page.locator('#q').press('Control+Enter');
    await expect(page.locator('#query-summary')).toContainText('5 row(s)');
    await expect(page.locator('#query-result thead th')).toHaveText(['friend', 'n', 'youngest']);
    expect(await table(page)).toEqual([
        ['Anna', '1', '50'],
        ['Bob', '1', '25'],
        ['Carl', '1', '12.5'],
        ['Homa', '1', '30'],
        ['Thomas', '1', '40'],
    ]);
});

test('joins, NULL and links to the objects', async ({page}) => {
    await page.goto(consoleUrl("SELECT p.name, f, f.name FROM stdClass p LEFT JOIN stdClass f ON f = p.friend WHERE p.name IN ('Bob', 'Anna') ORDER BY p.name"));
    const rows = await table(page);
    expect(rows[0][0]).toBe('Anna');
    expect(rows[0][1]).toMatch(/^[0-9a-f-]{36}$/);
    expect(rows[0][2]).toBe('Homa');
    expect(rows[1]).toEqual(['Bob', 'NULL', 'NULL']);
    // the UUID links to the record
    await page.locator('#query-result tbody tr').first().locator('a.uuid').click();
    await expect(page).toHaveURL(/action=view-record/);
});

test('an error is shown with its position', async ({page}) => {
    await page.goto(consoleUrl('SELECT name, COUNT(*) FROM stdClass'));
    await expect(page.locator('#query-error')).toContainText('must be in GROUP BY or in an aggregate');
    await page.goto(consoleUrl('SELECT name FROM stdClass WHERE'));
    await expect(page.locator('#query-error')).toContainText('Unexpected end of the query (line 1, column 32)');
});

test('the history and the classes', async ({page}) => {
    await page.goto(consoleUrl('SELECT COUNT(*) FROM stdClass'));
    expect(await table(page)).toEqual([['6']]);
    await page.goto(consoleUrl());
    await page.getByRole('button', {name: /History/}).click();
    await expect(page.locator('#query-history .dropdown-item')).toHaveText(['SELECT COUNT(*) FROM stdClass']);
    await page.locator('#query-history .dropdown-item').first().click();
    await expect(page.locator('#q')).toHaveValue('SELECT COUNT(*) FROM stdClass');

    await page.locator('#q').fill('SELECT name FROM ');
    await page.locator('#q').press('End');
    await page.locator('.query-class', {hasText: 'stdClass'}).click();
    await expect(page.locator('#q')).toHaveValue('SELECT name FROM `stdClass`');
});

test('writing: asked before, a dry run, and never by a link', async ({page}) => {
    const dialogs = [];
    page.on('dialog', dialog => {
        dialogs.push(dialog.message());
        dialog.accept();
    });

    // a link (GET) doesn't write
    await page.goto(consoleUrl("UPDATE stdClass SET city = 'Nowhere'"));
    await expect(page.locator('#query-error')).toContainText('run from the form only');
    await page.goto(consoleUrl("SELECT COUNT(*) FROM stdClass WHERE city = 'Nowhere'"));
    expect(await table(page)).toEqual([['0']]);

    // a dry run: not asked, nothing written
    await page.locator('#q').fill("UPDATE stdClass SET city = 'Nowhere' WHERE age > 20");
    await page.locator('#dry-run').check();
    await page.getByRole('button', {name: 'Run'}).click();
    await expect(page.locator('#query-written')).toContainText('Dry run: 4 object(s) would be updated, nothing was written.');
    expect(dialogs).toEqual([]);

    // run: asked, written
    await page.locator('#dry-run').uncheck();
    await page.getByRole('button', {name: 'Run'}).click();
    await expect(page.locator('#query-written')).toContainText('4 object(s) updated.');
    expect(dialogs).toHaveLength(1);
    await expect(page.locator('#query-result tbody a.uuid')).toHaveCount(4);

    await page.goto(consoleUrl("SELECT name FROM stdClass WHERE city = 'Nowhere' ORDER BY UUID IS NULL, name"));
    expect(await table(page)).toEqual([['Anna'], ['Carl'], ['Homa'], ['Thomas']]);

    // and back, so the other tests find the seeded storage
    await page.locator('#q').fill("UPDATE stdClass SET city = NULL WHERE city = 'Nowhere'");
    await page.getByRole('button', {name: 'Run'}).click();
    await expect(page.locator('#query-written')).toContainText('4 object(s) updated.');
});

test('lists and maps: dates as their point in time, references linked', async ({page}) => {
    // a preview: nothing is kept
    await page.goto(consoleUrl());
    await page.locator('#q').fill("UPDATE stdClass SET newlist['today'] = DATETIME('2026-11-09T17:12:31.918708Z'), newlist['somebody'] = REF(friend.UUID) WHERE name = 'Anna';\nSELECT newlist FROM stdClass WHERE name = 'Anna'");
    await page.getByRole('button', {name: 'Preview'}).click();
    const cell = page.locator('#query-result-2 tbody td');
    await expect(cell).toContainText('"today":"2026-11-09T17:12:31.918708Z"');
    await expect(cell).not.toContainText('__datetime');
    await expect(cell.locator('a.uuid')).toHaveCount(1);
    await cell.locator('a.uuid').click();
    await expect(page).toHaveURL(/action=view-record/);
});

test('scripts: a transaction, a preview, an open transaction rolled back', async ({page}) => {
    const dialogs = [];
    page.on('dialog', dialog => {
        dialogs.push(dialog.message());
        dialog.accept();
    });

    // a preview: not asked, everything rolled back, the results of every statement shown
    await page.goto(consoleUrl());
    await page.locator('#q').fill("START TRANSACTION;\nUPDATE stdClass SET city = 'Nowhere' WHERE age > 20;\nSELECT COUNT(*) FROM stdClass WHERE city = 'Nowhere';\nCOMMIT;");
    await page.getByRole('button', {name: 'Preview'}).click();
    await expect(page.locator('#query-preview')).toContainText('everything was rolled back');
    await expect(page.locator('#query-transaction')).toContainText('START TRANSACTION');
    await expect(page.locator('#query-written-2')).toContainText('4 object(s) updated.');
    await expect(page.locator('#query-result-3 tbody td')).toHaveText('4');
    await expect(page.locator('#query-transaction-4')).toContainText('COMMIT (preview');
    expect(dialogs).toEqual([]);
    await page.goto(consoleUrl("SELECT COUNT(*) FROM stdClass WHERE city = 'Nowhere'"));
    expect(await table(page)).toEqual([['0']]);

    // a script ending with an open transaction: asked, rolled back
    await page.locator('#q').fill("SELECT COUNT(*) FROM stdClass; BEGIN; UPDATE stdClass SET city = 'Nowhere'");
    await page.getByRole('button', {name: 'Run'}).click();
    expect(dialogs).toHaveLength(1);
    await expect(page.locator('#query-rolled-back')).toContainText('it was rolled back');
    await page.goto(consoleUrl("SELECT COUNT(*) FROM stdClass WHERE city = 'Nowhere'"));
    expect(await table(page)).toEqual([['0']]);

    // a failing statement: the error at its place in the script, the statements before it shown
    await page.locator('#q').fill("SELECT COUNT(*) FROM stdClass;\nSELECT name FROM");
    await page.getByRole('button', {name: 'Run'}).click();
    await expect(page.locator('#query-error')).toContainText('Statement 2:');
    await expect(page.locator('#query-error')).toContainText('line 2');
    await expect(page.locator('#query-result')).toBeVisible();
});

test('syntax highlighting, the error marked, scrolling together', async ({page}) => {
    await page.goto(consoleUrl());
    const area = page.locator('#q');
    const layer = page.locator('#q-highlighted');

    await area.fill("SELECT UUID, LOWER(name) FROM stdClass WHERE age > :min -- old\nAND city = 'Bonn'");
    await expect(layer.locator('.oql-keyword')).toHaveText(['SELECT', 'FROM', 'WHERE', 'AND']);
    await expect(layer.locator('.oql-uuid')).toHaveText(['UUID']);
    await expect(layer.locator('.oql-function')).toHaveText(['LOWER']);
    await expect(layer.locator('.oql-parameter')).toHaveText([':min']);
    await expect(layer.locator('.oql-comment')).toHaveText(['-- old']);
    await expect(layer.locator('.oql-string')).toHaveText(["'Bonn'"]);
    // the text of the layer is the query: the characters are where the textarea has them
    expect((await layer.textContent()).trimEnd()).toBe(await area.inputValue());
    // the text of the textarea is transparent, the caret isn't
    expect(await area.evaluate(el => getComputedStyle(el).color)).toBe('rgba(0, 0, 0, 0)');

    // an error is marked where it is, until the query is edited
    await page.goto(consoleUrl('SELECT name, age\nFROM stdClass WHERE age = = 1'));
    await expect(page.locator('#query-error')).toContainText('line 2, column 27');
    await expect(layer.locator('.oql-error')).toHaveText(['=']);
    await area.press('End');
    await area.type('0');
    await expect(layer.locator('.oql-error')).toHaveCount(0);

    // a long query: the layer scrolls with the textarea
    await area.fill(Array.from({length: 40}, (_, i) => `-- line ${i}`).join('\n') + '\nSELECT 1 FROM stdClass');
    await area.evaluate(el => el.scrollTop = el.scrollHeight);
    await area.dispatchEvent('scroll');
    const [top, layerTop] = await page.evaluate(() => [document.getElementById('q').scrollTop, document.getElementById('q-highlighted').scrollTop]);
    expect(top).toBeGreaterThan(0);
    expect(layerTop).toBe(top);
});

/** a query that runs for minutes: every combination of the objects, 11 times */
const SLOW = 'SELECT COUNT(*) FROM stdClass a' + [...'bcdefghijk'].map(alias => ` JOIN stdClass ${alias} ON TRUE`).join('');

test('a maximum runtime stops the query', async ({page}) => {
    await page.goto(consoleUrl());
    await page.locator('#q').fill(SLOW);
    await page.locator('#timeout').fill('1');
    await page.locator('#q').press('Control+Enter');
    await expect(page.locator('#query-error')).toHaveText('The query was stopped after its maximum runtime of 1 s');
    await expect(page.locator('#query-running')).toHaveCount(0);
    // kept for the next run
    await expect(page.locator('#timeout')).toHaveValue('1');
    // no limit: not in the link
    await page.locator('#q').fill('SELECT COUNT(*) FROM stdClass');
    await page.locator('#timeout').fill('');
    await page.locator('#q').press('Control+Enter');
    await expect(page.locator('#query-summary')).toContainText('1 row(s)');
    expect(new URL(page.url()).searchParams.has('timeout')).toBe(false);
});

test('a running query is cancelled', async ({page}) => {
    await page.goto(consoleUrl());
    await page.locator('#q').fill(SLOW);
    await page.locator('#q').press('Control+Enter');
    await expect(page.locator('#query-running')).toBeVisible();
    // the query stays readable meanwhile
    expect(await page.locator('#q').evaluate(el => getComputedStyle(el).color)).not.toBe('rgba(0, 0, 0, 0)');
    await page.locator('#query-cancel').click();
    await expect(page.locator('#query-cancelled')).toBeVisible();
    await expect(page.locator('#q')).toHaveValue(SLOW);
    await expect(page.locator('#query-result')).toHaveCount(0);
    // the server is free again: the query was stopped
    await page.locator('#q').fill('SELECT COUNT(*) FROM stdClass');
    await page.locator('#q').press('Control+Enter');
    await expect(page.locator('#query-summary')).toContainText('1 row(s)', {timeout: 5000});
});

test('what a query cost: objects read, all of a class read', async ({page}) => {
    // REGEXP: no index finds the objects
    await page.goto(consoleUrl("SELECT name FROM stdClass WHERE name REGEXP 'a'"));
    await expect(page.locator('#query-summary .query-objects-read')).toHaveText(/· \d+ object\(s\) read/);
    await expect(page.locator('#query-summary .query-full-scan')).toHaveText(['all of stdClass read']);
});

test('the slow queries: by query and the latest, opened in the console, cleared', async ({page}) => {
    page.on('dialog', dialog => dialog.accept());
    const slowQueries = (view = null) => '/view.php?' + new URLSearchParams({action: 'slow-queries', storage: STORAGE, ...(view ? {view} : {})});

    // what the tests before logged goes first
    await page.goto(slowQueries());
    if (await page.locator('#slow-queries-clear').count()) {
        await page.locator('#slow-queries-clear button').click();
        await expect(page.locator('#slow-queries-cleared')).toBeVisible();
    }
    await expect(page.locator('#slow-queries-none')).toBeVisible();

    // stopped by its maximum runtime: always logged, twice the same query
    for (let i = 0; i < 2; i++) {
        await page.goto('/view.php?' + new URLSearchParams({action: 'query', storage: STORAGE, q: SLOW, timeout: '0.5'}));
        await expect(page.locator('#query-error')).toContainText('maximum runtime');
    }
    // fast: not logged
    await page.goto(consoleUrl('SELECT COUNT(*) FROM stdClass'));

    // from the console
    await page.locator('#slow-queries-link').click();
    const rows = page.locator('#slow-queries .slow-query-pattern');
    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toContainText('SELECT COUNT(*) FROM stdClass a JOIN stdClass b ON TRUE');
    await expect(rows.first().locator('td').nth(1)).toHaveText('2');
    await expect(rows.first()).toContainText('2× stopped');
    await expect(rows.first().locator('.slow-query-full-scan').first()).toHaveText('all of stdClass');

    await page.locator('#slow-queries-latest-tab').click();
    await expect(page.locator('#slow-queries .slow-query-entry')).toHaveCount(2);
    await expect(page.locator('#slow-queries .slow-query-entry').first()).toContainText('stopped');

    // opened in the console: the query as it ran
    await page.locator('#slow-queries .slow-query-explain').first().click();
    await expect(page.locator('#q')).toHaveValue('EXPLAIN ' + SLOW);
    await expect(page.locator('#query-result')).toContainText('all objects of the class');

    await page.goto(slowQueries('latest'));
    await page.locator('#slow-queries-clear button').click();
    await expect(page.locator('#slow-queries-none')).toBeVisible();
});
