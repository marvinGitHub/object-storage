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
