import { readFile, mkdir } from 'node:fs/promises';
import { resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, webkit } from 'playwright';
import assert from 'node:assert/strict';

// Real renderer/CSS with fictional records. The full authenticated calendar smoke
// calls this too, so the focused source regression remains part of protected CI.
export async function checkCalendarPresentation(engine, name, evidence) {
  await mkdir(evidence, { recursive: true });
  const [source, operationsCss, eventsCss] = await Promise.all(['resources/js/team/event-calendar.js', 'resources/css/team-operations.css', 'resources/css/team-events.css'].map(path => readFile(resolve(path), 'utf8')));
  const browser = await engine.launch();
  try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, timezoneId: 'Asia/Tokyo', reducedMotion: 'reduce' });
    const page = await context.newPage(), errors = [], requests = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => requests.push(request.url()));
    await page.clock.setFixedTime(new Date('2026-11-03T02:30:00Z'));
    await page.setContent('<body class="teams-premium"><main id="team-operations"><div id="calendar-host"></div></main><aside id="tw-controls-panel"></aside></body>');
    await page.addStyleTag({ content: operationsCss + '\n' + eventsCss });
    await page.evaluate(async moduleUrl => {
      const { renderEventCalendar, setEventCalendarTimezone, eventToday } = await import(moduleUrl);
      const event = (id, date, acceptance, extra = {}) => ({ id, title: id, status: 'accepted', starts_on: date, ends_on: date,
        calendar_people: [{ id: 'native:1', provider: 'teams', name: 'Fictional team member' }],
        data: { acceptance_status: acceptance, event_schedule: { timezone: 'America/New_York', sessions: [{ id: id + '-session', date, kind: 'selling' }], schedule_complete: true } }, ...extra });
      const items = [event('Accepted upcoming', '2026-11-03', ' yes '), event('Unreviewed upcoming', '2026-11-03', null),
        event('Accepted past', '2026-11-01', 'confirmed'), event('Unconfirmed past', '2026-11-01', null, { status: 'completed' }),
        event('Accepted today', '2026-11-02', 'accepted'), event('Declined', '2026-11-03', 'not accepted'),
        event('Source candidate', '2026-11-03', null), event('Source reviewed', '2026-11-03', null),
        event('Custom color', '2026-11-03', 'yes'), event('Invalid color', '2026-11-03', 'yes'),
        event('Ongoing event', '2026-11-01', 'yes'), event('Unknown ending', '2026-11-01', 'yes'),
        event('Due marker', '2026-11-04', null), event('Range marker', '2026-11-04', 'yes'), event('No dates', null, null)];
      const byName = Object.fromEntries(items.map(item => [item.id, item]));
      byName['Source candidate'].source = { connection_id: 'fictional' };
      byName['Source candidate'].data.acceptance_status = 'yes';
      byName['Source candidate'].data.fields = [{ field: 'acceptance_status', value: 'yes', review_state: 'needs_review' }];
      byName['Source reviewed'].source = { connection_id: 'fictional' };
      byName['Source reviewed'].data.calendar_acceptance_status = 'YES';
      byName['Custom color'].data.event_schedule.calendar_color = '#325b91';
      byName['Invalid color'].data.event_schedule.calendar_color = 'red';
      byName['Ongoing event'].ends_on = '2026-11-05';
      byName['Ongoing event'].data.event_schedule.sessions.push({ date: '2026-11-05', kind: 'selling' });
      byName['Unknown ending'].ends_on = null; byName['Unknown ending'].data.event_schedule.schedule_complete = false;
      byName['Due marker'].data.event_schedule.sessions = []; byName['Due marker'].data.source_schedule = { due_on: '2026-11-04' };
      byName['Range marker'].data.event_schedule.sessions = [];
      byName['No dates'].data.event_schedule.sessions = [];
      window.calendarOpened = []; window.calendarDates = { business: eventToday('America/New_York'), device: eventToday() };
      window.paintCalendar = inline => {
        document.getElementById('team-operations').classList.toggle('tops-inline-calendar', inline);
        const host = document.getElementById('calendar-host'); host.replaceChildren();
        setEventCalendarTimezone(inline ? 'inline' : 'standalone', 'America/New_York');
        renderEventCalendar(host, items, {}, 'fictional', item => window.calendarOpened.push(item.id), inline ? 'inline' : 'standalone');
      };
      window.paintCalendar(false);
    }, 'data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
    assert.deepEqual(await page.evaluate(() => window.calendarDates), { business: '2026-11-02', device: '2026-11-03' });
    for (const inline of [false, true]) {
      await page.evaluate(inline => window.paintCalendar(inline), inline);
      const grid = page.locator('.tops-event-calendar'), card = name => grid.locator('[data-calendar-event]').filter({ has: page.locator('strong', { hasText: new RegExp('^' + name + '$') }) }).first();
      assert.equal(await grid.locator('[aria-current="date"]').getAttribute('datetime'), '2026-11-02');
      await grid.locator('[data-calendar-date="2026-11-02"] .tops-calendar-today-label').getByText('Today', { exact: true }).waitFor();
      await page.getByText('Today · Nov 2, 2026 · New York', { exact: true }).waitFor();
      assert.equal(await page.getByRole('button', { name: 'Today', exact: true }).count(), 1);
      for (const label of ['Unreviewed upcoming', 'Unconfirmed past', 'Declined', 'Source candidate', 'Due marker']) {
        assert.equal(await card(label).getAttribute('data-calendar-booking'), 'unconfirmed', label);
        assert.match(await card(label).evaluate(element => getComputedStyle(element).backgroundImage), /repeating-linear-gradient/, label + ' stays striped');
        assert.equal(await card(label).evaluate(element => element.classList.contains('is-past-event')), false, label + ' is never faded as accepted');
      }
      for (const label of ['Accepted upcoming', 'Accepted past', 'Accepted today', 'Source reviewed', 'Custom color', 'Range marker', 'Ongoing event', 'Unknown ending']) {
        assert.equal(await card(label).getAttribute('data-calendar-booking'), 'confirmed', label);
        assert.equal(await card(label).evaluate(element => getComputedStyle(element).backgroundImage), 'none', label + ' has solid fill');
      }
      assert.equal(await card('Accepted past').evaluate(element => element.classList.contains('is-past-event')), true);
      assert.equal(await card('Accepted today').evaluate(element => element.classList.contains('is-past-event')), false);
      assert.equal(await card('Ongoing event').evaluate(element => element.classList.contains('is-past-event')), false);
      assert.equal(await card('Unknown ending').evaluate(element => element.classList.contains('is-past-event')), false);
      assert.notEqual(await card('Accepted past').evaluate(element => getComputedStyle(element).backgroundColor), await card('Accepted upcoming').evaluate(element => getComputedStyle(element).backgroundColor));
      assert.equal(await card('Custom color').evaluate(element => element.style.getPropertyValue('--event-color')), '#325b91');
      for (const label of ['Accepted upcoming', 'Unreviewed upcoming', 'Invalid color']) assert.equal(await card(label).evaluate(element => element.style.getPropertyValue('--event-color')), '#315b4a');
      const allButtons = grid.locator('button[data-calendar-event]'), count = await allButtons.count();
      await page.evaluate(() => { window.calendarOpened = []; });
      for (let index = 0; index < count; index++) await allButtons.nth(index).click();
      assert.equal((await page.evaluate(() => window.calendarOpened)).length, count, 'Every session, due marker and range marker opens');
      await page.getByRole('button', { name: 'No dates', exact: true }).click();
      assert.equal(await page.evaluate(() => window.calendarOpened.at(-1)), 'No dates');
      await page.getByLabel('Calendar colors').selectOption('person');
      assert.match(await card('Unconfirmed past').evaluate(element => getComputedStyle(element).backgroundImage), /repeating-linear-gradient/, 'Team colors retain booking state');
      await page.getByLabel('Calendar colors').selectOption('event');
      await page.getByLabel('Calendar month', { exact: true }).fill('2026-12');
      await page.getByLabel('Calendar month', { exact: true }).blur();
      assert.equal(await grid.locator('[aria-current="date"]').count(), 0);
      await page.getByRole('button', { name: 'Today', exact: true }).click();
      assert.equal(await page.getByLabel('Calendar month', { exact: true }).inputValue(), '2026-11');
      assert.equal(await grid.locator('[aria-current="date"]').getAttribute('datetime'), '2026-11-02');
      await page.getByRole('button', { name: '1 day', exact: true }).click();
      await page.getByLabel('Calendar first day', { exact: true }).fill('2026-11-05');
      await page.getByLabel('Calendar first day', { exact: true }).blur();
      assert.equal(await grid.locator('[aria-current="date"]').count(), 0);
      await page.getByRole('button', { name: 'Today', exact: true }).click();
      assert.equal(await page.getByLabel('Calendar first day', { exact: true }).inputValue(), '2026-11-02');
      assert.equal(await grid.locator('[aria-current="date"]').getAttribute('datetime'), '2026-11-02');
      await page.getByRole('button', { name: 'Month', exact: true }).click();
      for (const width of [1280, 390]) {
        await page.setViewportSize({ width, height: 900 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Calendar does not overflow the page');
        await page.screenshot({ path: join(evidence, `${name}-${inline ? 'inline' : 'standalone'}-${width}.png`), fullPage: true });
      }
    }
    await page.clock.setFixedTime(new Date('2026-11-03T06:30:00Z'));
    await page.getByRole('button', { name: 'Today', exact: true }).click();
    assert.equal(await page.locator('.tops-event-calendar [aria-current="date"]').getAttribute('datetime'), '2026-11-03', 'Today recomputes after local midnight');
    await page.getByText('Today · Nov 3, 2026 · New York', { exact: true }).waitFor();
    assert.deepEqual(errors, []); assert.deepEqual(requests, [], 'Presentation does not read or write any API');
    return { browser: name, bookingStates: true, allClickable: true, defaultAndCustomColors: true, businessLocalToday: true, desktopAndPhone: true };
  } finally { await browser.close(); }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const evidence = resolve(process.env.TEAM_CALENDAR_PRESENTATION_EVIDENCE || 'test-results/team-calendar-presentation'), checks = [];
  for (const [engine, name] of [[chromium, 'chromium'], [webkit, 'webkit']]) checks.push(await checkCalendarPresentation(engine, name, evidence));
  console.log(JSON.stringify({ checks }, null, 2));
}
