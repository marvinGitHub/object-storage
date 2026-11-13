// @ts-check
/**
 * Saving a record on its page (view.php, action save-record): written as storing does (both files as a group, a new
 * revision, modified now, the checksum algorithm kept), and not over a change made since the page was loaded. The
 * storage is seeded by tests/e2e/seed.php (an Appointment).
 */
const {test, expect} = require('@playwright/test');

const STORAGE = process.env.E2E_STORAGE ?? '/tmp/e2e-storage';
const consoleUrl = q => '/view.php?' + new URLSearchParams({action: 'query', storage: STORAGE, q});

/** opens the appointment: its URL, revision and algorithm as shown */
async function openAppointment(page) {
    await page.goto(consoleUrl('SELECT UUID FROM Appointment'));
    await page.locator('#query-result tbody a.uuid').first().click();
    await expect(page).toHaveURL(/action=view-record/);
    const revision = Number((await page.locator('dd', {hasText: 'revision'}).innerText()).match(/revision (\d+)/)[1]);
    const algorithm = (await page.locator('dt:text-is("Checksum") + dd span').innerText()).trim();
    return {url: page.url(), uuid: new URL(page.url()).searchParams.get('uuid'), revision, algorithm};
}

test('saving writes a new revision, modified now, with the checksum algorithm of the object', async ({page}) => {
    const before = await openAppointment(page);

    await page.locator('#button-edit').click();
    const textarea = page.locator('#textarea-data');
    const data = JSON.parse(await textarea.inputValue());
    data.note = 'saved in the viewer';
    await textarea.fill(JSON.stringify(data));
    await page.locator('#button-save').click();
    await expect(page.locator('.alert-success')).toContainText('Record saved.');

    const after = await openAppointment(page);
    expect(after.revision).toBe(before.revision + 1);
    expect(after.algorithm).toBe(before.algorithm);
    // read as stored, the checksum verified
    await page.goto(consoleUrl(`SELECT note FROM Appointment WHERE UUID = '${after.uuid}' AND MODIFIED() > DATE_SUB(NOW(), 1, 'MINUTE')`));
    await expect(page.locator('#query-result tbody tr').first().locator('td')).toHaveText(['saved in the viewer']);
});

test('a duplicate is written with the checksum of its data and can be read', async ({page}) => {
    const original = await openAppointment(page);
    await page.goto('/view.php?' + new URLSearchParams({action: 'duplicate-record', storage: STORAGE, uuid: original.uuid}));
    const copy = await page.locator('a.uuid').first().innerText();
    expect(copy).not.toBe(original.uuid);

    // read as stored, the checksum verified
    await page.goto(consoleUrl(`SELECT COUNT(*) FROM Appointment WHERE UUID IN ('${original.uuid}', '${copy}')`));
    await expect(page.locator('#query-result tbody tr').first().locator('td')).toHaveText(['2']);
    await page.goto('/view.php?' + new URLSearchParams({action: 'view-record', storage: STORAGE, uuid: copy}));
    expect((await page.locator('dt:text-is("Checksum") + dd span').innerText()).trim()).toBe(original.algorithm);
});

test('a change made since the page was loaded is not overwritten', async ({page}) => {
    const shown = await openAppointment(page);

    // saved from a page showing an older revision
    const response = await page.request.post('/view.php?action=save-record', {
        form: {
            action: 'save-record', storage: STORAGE, uuid: shown.uuid, revision: String(shown.revision - 1),
            json: JSON.stringify({note: 'stale'}),
        },
    });
    const html = await response.text();
    expect(html).toContain('Record could not be saved.');
    expect(html).toContain('changed in the meantime');

    const now = await openAppointment(page);
    expect(now.revision).toBe(shown.revision);
    await page.goto(consoleUrl(`SELECT note FROM Appointment WHERE UUID = '${shown.uuid}'`));
    await expect(page.locator('#query-result tbody tr').first().locator('td')).not.toHaveText(['stale']);
});
