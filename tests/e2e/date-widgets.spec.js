// @ts-check
/**
 * The date widgets of a record (views/view-record.html, assets/date-fields.js): dates stored inline are shown in
 * their time zones and changed into the JSON of the record. The storage is seeded by tests/e2e/seed.php (an
 * Appointment with dates).
 */
const {test, expect} = require('@playwright/test');

const STORAGE = process.env.E2E_STORAGE ?? '/tmp/e2e-storage';
const consoleUrl = q => '/view.php?' + new URLSearchParams({action: 'query', storage: STORAGE, q});

/** opens the appointment and starts editing it */
async function editAppointment(page) {
    await page.goto(consoleUrl('SELECT UUID FROM Appointment'));
    await page.locator('#query-result tbody a.uuid').first().click();
    await expect(page).toHaveURL(/action=view-record/);
    await page.locator('#button-edit').click();
    await expect(page.locator('#date-fields')).toBeVisible();
}

const row = (page, path) => page.locator('#date-rows tr', {has: page.locator('.date-path', {hasText: new RegExp('^' + path.replace(/[[\]]/g, '\\$&') + '$')})});

test('dates are shown in their time zones, follow the JSON and are dropped on cancel', async ({page}) => {
    await editAppointment(page);

    await expect(page.locator('#date-rows .date-path')).toHaveText(['at', 'reminders[0]', 'reminders[1]']);
    await expect(row(page, 'at').locator('.date-local')).toHaveValue('2026-03-10T09:30');
    await expect(row(page, 'at').locator('.date-zone')).toHaveValue('Europe/Berlin');
    await expect(row(page, 'at').locator('.date-utc')).toHaveText('2026-03-10T08:30:00.000000Z');
    await expect(row(page, 'reminders[0]').locator('.date-zone')).toHaveValue('Z');
    await expect(row(page, 'reminders[0]').locator('.date-class')).toHaveText('DateTime');
    await expect(row(page, 'reminders[1]').locator('.date-local')).toHaveValue('2026-03-10T07:00');

    // the JSON typed into the text: the widgets follow
    const textarea = page.locator('#textarea-data');
    await textarea.fill((await textarea.inputValue()).replace('2026-03-10T08:30:00.000000Z', '2026-03-10T10:30:00.000000Z'));
    await expect(row(page, 'at').locator('.date-local')).toHaveValue('2026-03-10T11:30');
    await textarea.fill('{"broken":');
    await expect(page.locator('#date-fields-invalid')).toBeVisible();

    // cancel drops the changes
    await page.locator('#button-cancel').click();
    await page.locator('#button-edit').click();
    await expect(row(page, 'at').locator('.date-local')).toHaveValue('2026-03-10T09:30');
});

test('a date is changed by its widgets and saved', async ({page}) => {
    await editAppointment(page);
    const textarea = page.locator('#textarea-data');
    const before = await textarea.inputValue();

    // the time: in Berlin, so 10:00 is 09:00 UTC
    const at = row(page, 'at');
    await at.locator('.date-local').fill('2026-03-11T10:00');
    await at.locator('.date-local').dispatchEvent('change');
    await expect(at.locator('.date-utc')).toHaveText('2026-03-11T09:00:00.000000Z');
    expect(await textarea.inputValue()).toContain('"at":{"__datetime":"2026-03-11T09:00:00.000000Z","tz":"Europe\\/Berlin","class":"DateTimeImmutable"}');

    // the time zone: the time stays, the point in time changes
    await at.locator('.date-zone').fill('America/New_York');
    await at.locator('.date-zone').dispatchEvent('change');
    await expect(at.locator('.date-utc')).toHaveText('2026-03-11T14:00:00.000000Z');

    // an unknown time zone isn't written
    const reminder = row(page, 'reminders[1]');
    await reminder.locator('.date-zone').fill('Mars/Olympus');
    await reminder.locator('.date-zone').dispatchEvent('change');
    await expect(reminder.locator('.date-zone')).toHaveClass(/is-invalid/);
    await expect(reminder.locator('.date-utc')).toHaveText('2026-03-10T01:15:00.000000Z');

    // only the date changed in the text
    const after = await textarea.inputValue();
    expect(after.replace(/"at":\{[^}]*\}/, '')).toBe(before.replace(/"at":\{[^}]*\}/, ''));

    await page.locator('#button-save').click();
    await expect(page.locator('body')).toContainText(/saved/i);

    // loaded as stored: OQL reads the new point in time
    await page.goto(consoleUrl('SELECT at, HOUR(at, \'America/New_York\') FROM Appointment'));
    await expect(page.locator('#query-result tbody tr').first().locator('td')).toHaveText(['2026-03-11T14:00:00.000000Z', '10']);
});
