// @ts-check
/**
 * The query builder of the viewer in a browser: building, nesting, moving with the mouse, errors, reading filters
 * back, the form and the builder together, saved queries. Run with tests/e2e/run.sh; the storage is seeded by
 * tests/e2e/seed.php:
 *
 * name     age   active  tags            friend
 * Anna     30    true    [red]           Homa
 * Homa     25    false   [green, blue]   Bob
 * Bob      -3    true    []              -
 * Carl     40    null    [red, blue]     Thomas
 * Thomas   50    true    (none)          Anna
 * O'Neill  12.5  false   ['x, y']        Carl
 */
const {test, expect} = require('@playwright/test');

const STORAGE = process.env.E2E_STORAGE ?? '/tmp/e2e-storage';
const searchUrl = (params = {}) => '/view.php?' + new URLSearchParams({action: 'index-search', storage: STORAGE, name: 'people', ...params});

const builder = page => page.locator('#builder');
const root = page => page.locator('#builder-conditions > .builder-root');
const rows = scope => scope.locator('.builder-condition');
const preview = page => page.locator('#builder-preview');
const children = group => group.locator(':scope > .builder-children');

async function openBuilder(page) {
    await page.getByRole('button', {name: 'Builder'}).click();
    await expect(builder(page)).toHaveClass(/show/);
}

/** sets a condition row: field, operator and (for operators with a value) the value */
async function setCondition(row, field, op, value) {
    await row.locator('.builder-field').fill(field);
    await row.locator('.builder-operator').selectOption(op);
    if (value !== undefined) {
        if (value === 'true' || value === 'false') {
            await row.locator('.builder-boolean').selectOption(value);
        } else {
            await row.locator('.builder-value').fill(value);
        }
    }
}

async function addCondition(group, field, op, value) {
    await group.locator(':scope > div > .builder-add-condition').click();
    const row = children(group).locator(':scope > .builder-condition').last();
    await setCondition(row, field, op, value);
    return row;
}

/**
 * A new group in a group; it starts with an empty condition, set to the first condition given. The group is marked,
 * so the locator finds it also after other groups are added or it is moved.
 */
let groups = 0;
async function addGroup(group, [field, op, value]) {
    await group.locator(':scope > div > .builder-add-group').click();
    const id = `group-${++groups}`;
    await children(group).locator(':scope > .builder-group').last().evaluate((element, id) => element.dataset.test = id, id);
    const inner = group.page().locator(`[data-test="${id}"]`);
    await setCondition(children(inner).locator(':scope > .builder-condition').first(), field, op, value);
    return inner;
}

/** the names of the hits, sorted */
async function hits(page) {
    await expect(page.locator('main')).toContainText(/hits/);
    const names = await page.locator('.list-group-item dl').evaluateAll(lists => lists.map(dl => {
        const dt = [...dl.querySelectorAll('dt')].find(t => t.textContent.trim() === 'name');
        // var_export: 'O\'Neill'
        return dt.nextElementSibling.textContent.trim().slice(1, -1).replace(/\\'/g, "'");
    }));
    return names.sort();
}

/** drags an element by its handle and drops it with the pointer at a point */
async function drag(page, handle, to) {
    // mouse events outside of the viewport go nowhere: both points are measured in view (see the viewport)
    await handle.scrollIntoViewIfNeeded();
    to = await to();
    const from = await handle.boundingBox();
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(to.x, to.y, {steps: 8});
    await page.mouse.up();
}

/** a point in the lower or upper part of an element, measured when dragging */
function pointIn(locator, part = 'lower') {
    return async () => {
        const box = await locator.boundingBox();
        return {x: box.x + box.width / 2, y: box.y + box.height * (part === 'lower' ? 0.8 : 0.2)};
    };
}

test.beforeEach(async ({page}) => {
    await page.goto(searchUrl());
    await page.evaluate(() => localStorage.removeItem('object-storage-viewer:saved-queries'));
    await page.goto(searchUrl());
});

test('an empty builder searches everything', async ({page}) => {
    await openBuilder(page);
    await expect(rows(root(page))).toHaveCount(1);
    await expect(preview(page)).toHaveText('(no filter)');
    await expect(page.locator('#builder-errors')).toBeHidden();
    await page.getByRole('button', {name: 'Use and search'}).click();
    expect(await hits(page)).toEqual(["Anna", "Bob", "Carl", "Homa", "O'Neill", "Thomas"]);
});

test('conditions and nested, negated groups', async ({page}) => {
    await openBuilder(page);
    await setCondition(rows(root(page)).first(), 'friend.name', 'CONTAINS', 'om');
    const group = await addGroup(root(page), ['age', '>', '26']);
    await addCondition(group, 'tags', 'IN', 'blue');
    await expect(group.locator('.builder-combinator').first()).toHaveValue('OR');
    await expect(preview(page)).toHaveText("friend.name CONTAINS 'om' AND (age > 26 OR tags IN ('blue'))");

    await page.getByRole('button', {name: 'Use and search'}).click();
    expect(await hits(page)).toEqual(['Anna', 'Carl']);
    expect(new URL(page.url()).searchParams.get('filter')).toBe("friend.name CONTAINS 'om' AND (age > 26 OR tags IN ('blue'))");

    // negated, and the first condition changed
    await openBuilder(page);
    await setCondition(rows(root(page)).first(), 'friend.name', 'IS NOT NULL');
    await root(page).locator('.builder-group .builder-negate').first().check();
    await expect(preview(page)).toHaveText("friend.name IS NOT NULL AND NOT (age > 26 OR tags IN ('blue'))");
    await page.getByRole('button', {name: 'Use and search'}).click();
    expect(await hits(page)).toEqual(["O'Neill"]);
});

test('negative numbers, BETWEEN, booleans, lists with quotes', async ({page}) => {
    await openBuilder(page);
    await setCondition(rows(root(page)).first(), 'age', 'BETWEEN', '-5, 13');
    await expect(preview(page)).toHaveText('age BETWEEN -5 AND 13');
    await page.getByRole('button', {name: 'Use and search'}).click();
    expect(await hits(page)).toEqual(['Bob', "O'Neill"]);

    await openBuilder(page);
    await setCondition(rows(root(page)).first(), 'name', 'IN', "O'Neill, Bob");
    await addCondition(root(page), 'active', '!=', 'true');
    await expect(preview(page)).toHaveText("name IN ('O''Neill', 'Bob') AND active != true");
    await page.getByRole('button', {name: 'Use and search'}).click();
    expect(await hits(page)).toEqual(["O'Neill"]);
});

test('conditions that can\'t be written block searching and saving', async ({page}) => {
    await openBuilder(page);
    await setCondition(rows(root(page)).first(), 'age', '=', 'abc');
    await addCondition(root(page), 'name', 'STARTS WITH', '');
    await addCondition(root(page), 'nope', '=', 'x');
    await expect(page.locator('#builder-errors li')).toHaveCount(3);
    await expect(rows(root(page)).nth(2).locator('.builder-field-status')).toContainText('no field of the index');

    const before = page.url();
    const messages = [];
    page.on('dialog', dialog => {
        messages.push(dialog.message());
        dialog.accept();
    });
    await page.getByRole('button', {name: 'Use and search'}).click();
    await page.locator('#search-form button[type=submit]').click();
    await page.getByRole('button', {name: /Saved/}).click();
    await page.getByRole('button', {name: 'Save this query…'}).click();
    await expect.poll(() => messages.length).toBe(3);
    expect(messages[0]).toContain('isn\'t complete');
    expect(page.url()).toBe(before);
    await expect(page.locator('#saved-count')).toHaveText('0');
});

test('conditions and groups are moved with the mouse', async ({page}) => {
    await openBuilder(page);
    await setCondition(rows(root(page)).first(), 'name', '=', 'Anna');
    const group = await addGroup(root(page), ['name', '=', 'Bob']);
    await addCondition(group, 'name', '=', 'Carl');
    await expect(preview(page)).toHaveText("name = 'Anna' AND (name = 'Bob' OR name = 'Carl')");

    // into the group, after Carl
    const anna = rows(root(page)).first();
    await drag(page, anna.locator('.builder-handle'), pointIn(rows(group).last(), 'lower'));
    await expect(preview(page)).toHaveText("name = 'Bob' OR name = 'Carl' OR name = 'Anna'");

    // within the group, before Bob
    await drag(page, rows(group).last().locator('.builder-handle'), pointIn(rows(group).first(), 'upper'));
    await expect(preview(page)).toHaveText("name = 'Anna' OR name = 'Bob' OR name = 'Carl'");

    // Escape cancels
    const from = await rows(group).first().locator('.builder-handle').boundingBox();
    await page.mouse.move(from.x + 3, from.y + 3);
    await page.mouse.down();
    const to = await pointIn(rows(group).last(), 'lower')();
    await page.mouse.move(to.x, to.y, {steps: 5});
    await page.keyboard.press('Escape');
    await page.mouse.up();
    await expect(preview(page)).toHaveText("name = 'Anna' OR name = 'Bob' OR name = 'Carl'");

    // a group is moved with its conditions, but not into itself
    const inner = await addGroup(root(page), ['age', '>', '0']);
    await drag(page, inner.locator('.builder-handle').first(), pointIn(rows(inner).first(), 'lower'));
    await expect(preview(page)).toHaveText("(name = 'Anna' OR name = 'Bob' OR name = 'Carl') AND age > 0");
    await drag(page, inner.locator('.builder-handle').first(), pointIn(rows(group).first(), 'upper'));
    await expect(preview(page)).toHaveText("age > 0 OR name = 'Anna' OR name = 'Bob' OR name = 'Carl'");

    await page.getByRole('button', {name: 'Use and search'}).click();
    expect(await hits(page)).toEqual(['Anna', 'Bob', 'Carl', 'Homa', "O'Neill", 'Thomas']);
});

test('a filter is read back into groups and conditions', async ({page}) => {
    const filter = "friendName = 'Homa' OR (age >= 40 AND NOT (active = true))";
    await page.goto(searchUrl({run: '1', filter}));
    expect(await hits(page)).toEqual(['Anna', 'Carl']);

    await openBuilder(page);
    await expect(page.locator('#builder-kept')).toBeHidden();
    await expect(preview(page)).toHaveText(filter);
    await expect(root(page).locator(':scope > .builder-group-head .builder-combinator')).toHaveValue('OR');
    await expect(rows(root(page))).toHaveCount(3);
    const first = rows(root(page)).first();
    await expect(first.locator('.builder-field')).toHaveValue('friendName');
    await expect(first.locator('.builder-operator')).toHaveValue('=');
    await expect(first.locator('.builder-value')).toHaveValue('Homa');
    const negated = root(page).locator('.builder-group .builder-group');
    await expect(negated.locator('.builder-negate').first()).toBeChecked();
    await expect(rows(negated).first().locator('.builder-boolean')).toHaveValue('true');

    // STARTS WITH from LIKE, values with quotes and wildcards
    await page.goto(searchUrl({filter: "name LIKE 'O''N%' AND friendName LIKE '50\\%%'"}));
    await openBuilder(page);
    await expect(rows(root(page)).first().locator('.builder-operator')).toHaveValue('STARTS WITH');
    await expect(rows(root(page)).first().locator('.builder-value')).toHaveValue("O'N");
    await expect(rows(root(page)).nth(1).locator('.builder-value')).toHaveValue('50%');
    await expect(preview(page)).toHaveText("name LIKE 'O''N%' AND friendName LIKE '50\\%%'");
});

test('a filter the builder can\'t show is kept and combined', async ({page}) => {
    await page.goto(searchUrl({filter: "tags IN ('x, y')"}));
    await openBuilder(page);
    await expect(page.locator('#builder-kept')).toBeVisible();
    await expect(page.locator('#builder-kept-filter')).toHaveText("tags IN ('x, y')");
    await setCondition(rows(root(page)).first(), 'age', '>', '0');
    await expect(preview(page)).toHaveText("(tags IN ('x, y')) AND age > 0");
    await page.getByRole('button', {name: 'Use and search'}).click();
    expect(await hits(page)).toEqual(["O'Neill"]);

    // used once: kept once, not twice
    await openBuilder(page);
    await expect(page.locator('#builder-kept-filter')).toHaveText("(tags IN ('x, y')) AND age > 0");
    await page.getByRole('button', {name: 'Use in the form'}).click();
    await expect(page.locator('#filter')).toHaveValue("(tags IN ('x, y')) AND age > 0");

    await page.getByRole('button', {name: 'Remove it'}).click();
    await expect(preview(page)).toHaveText('(no filter)');
});

test('saved queries: save, open in the builder, replace, load', async ({page}) => {
    await page.goto(searchUrl({run: '1', filter: "name = 'Anna'"}));
    const prompts = [];
    page.on('dialog', dialog => {
        prompts.push([dialog.type(), dialog.defaultValue()]);
        // accept() without a text answers a prompt with '', not with its suggestion
        dialog.type() === 'prompt' ? dialog.accept(prompts.length === 1 ? 'E2E query' : dialog.defaultValue()) : dialog.accept();
    });
    await page.getByRole('button', {name: /Saved/}).click();
    await page.getByRole('button', {name: 'Save this query…'}).click();
    await expect(page.locator('#saved-count')).toHaveText('1');

    // open in the builder, change, save again: the name is suggested, replacing it
    await page.getByRole('button', {name: /Saved/}).click();
    await page.locator('.saved-edit').first().click();
    await expect(builder(page)).toHaveClass(/show/);
    await expect(rows(root(page)).first().locator('.builder-value')).toHaveValue('Anna');
    await rows(root(page)).first().locator('.builder-value').fill('Bob');
    await page.getByRole('button', {name: /Saved/}).click();
    await page.getByRole('button', {name: 'Save this query…'}).click();
    // the prompt with the name suggested, then the question whether to replace it
    await expect.poll(() => prompts.length).toBe(3);
    expect(prompts[1]).toEqual(['prompt', 'E2E query']);
    expect(prompts[2][0]).toBe('confirm');
    await expect(page.locator('#saved-count')).toHaveText('1');

    // loaded: searched, remembered in the URL and marked
    await page.goto(searchUrl());
    await page.getByRole('button', {name: /Saved/}).click();
    await page.locator('.saved-load').first().click();
    expect(await hits(page)).toEqual(['Bob']);
    const url = new URL(page.url());
    expect(url.searchParams.get('filter')).toBe("name = 'Bob'");
    expect(url.searchParams.get('saved')).toBe('E2E query');
    await page.getByRole('button', {name: /Saved/}).click();
    await expect(page.locator('.saved-load.fw-semibold')).toContainText('E2E query');
});

test('the form and the builder together', async ({page}) => {
    await openBuilder(page);
    await setCondition(rows(root(page)).first(), 'name', '=', 'Anna');
    // typed into the form: only that part of the builder follows
    await page.locator('#sort').fill('friendName:desc');
    await page.locator('#sort').blur();
    const sort = page.locator('#builder-sort .builder-sort-row select');
    await expect(sort.first()).toHaveValue('friendName');
    await expect(sort.nth(1)).toHaveValue('desc');
    await expect(rows(root(page)).first().locator('.builder-value')).toHaveValue('Anna');

    // the Search button of the form takes the open builder
    await page.locator('#search-form button[type=submit]').click();
    expect(await hits(page)).toEqual(['Anna']);
    const url = new URL(page.url());
    expect(url.searchParams.get('filter')).toBe("name = 'Anna'");
    expect(url.searchParams.get('sort')).toBe('friendName:desc');
});

test('sort, facets and search on named by paths', async ({page}) => {
    await page.goto(searchUrl({sort: 'friend.name:asc', facets: 'friend.name, tags'}));
    await openBuilder(page);
    await expect(page.locator('#builder-sort select').first()).toHaveValue('friendName');
    await expect(page.locator('#builder-facet-friendName')).toBeChecked();
    await expect(page.locator('#builder-facet-tags')).toBeChecked();
    await page.getByRole('button', {name: 'Use in the form'}).click();
    await expect(page.locator('#sort')).toHaveValue('friendName:asc');
    await expect(page.locator('#facets')).toHaveValue('friendName, tags');

    // unknown ones are kept, marked
    await page.goto(searchUrl({facets: 'nope'}));
    await openBuilder(page);
    await expect(page.locator('#builder-facets .text-danger')).toHaveText('nope');
    await page.getByRole('button', {name: 'Use in the form'}).click();
    await expect(page.locator('#facets')).toHaveValue('nope');
});

test('operators and values follow the type of the field', async ({page}) => {
    await openBuilder(page);
    const row = rows(root(page)).first();
    const options = () => row.locator('.builder-operator option').allTextContents();
    await row.locator('.builder-field').fill('active');
    expect(await options()).toEqual(['=', '!=', 'IS NULL', 'IS NOT NULL']);
    await expect(row.locator('.builder-boolean')).toBeVisible();
    await expect(row.locator('.builder-value')).toBeHidden();
    await row.locator('.builder-field').fill('tags');
    expect(await options()).toEqual(['IN', 'NOT IN', 'CONTAINS', 'NOT CONTAINS', 'LIKE', 'NOT LIKE', 'IS EMPTY', 'IS NOT EMPTY']);
    await row.locator('.builder-field').fill('friend.age');
    expect(await options()).toContain('BETWEEN');
    await expect(row.locator('.builder-field-status')).toContainText('field friendAge');
    await row.locator('.builder-operator').selectOption('IS NULL');
    await expect(row.locator('.builder-value')).toBeHidden();
    await expect(preview(page)).toHaveText('friend.age IS NULL');
    await row.locator('.builder-field').fill('friend.friend.name');
    await expect(row.locator('.builder-field-status a')).toHaveAttribute('href', /addPath=friend\.friend\.name/);
});

test('the PHP code of the search', async ({page}) => {
    await page.goto(searchUrl({filter: "name = 'O''Neill'", sort: 'age:desc'}));
    await page.getByRole('button', {name: 'PHP'}).click();
    await expect(page.locator('#php-code-text')).toContainText(`$search->get('people')->search(new Request(`);
    await expect(page.locator('#php-code-text')).toContainText(`filter: "name = 'O''Neill'",`);
    await expect(page.locator('#php-code-text')).toContainText(`sort: ['age:desc'],`);
});
