import assert from 'node:assert/strict';
import {devices, expect} from '@playwright/test';
import {closeReportControls, selectReportRange, selectReportScope} from './trajectory-controls.browser-helpers.mjs';

export async function checkReviewedCategoryChanges({context, origin, fixture, payments, childId, reviewId, phone}) {
  const deviceContext = phone ? await context.browser().newContext({
    ...devices['iPhone 13'], storageState: await context.storageState(), reducedMotion: 'reduce',
  }) : context;
  if (phone) await deviceContext.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
  const page = await deviceContext.newPage();
  if (!phone) await page.setViewportSize({width: 1440, height: 600});
  const merchant = 'Zelle: Fictional Family';
  const badge = page.locator('.tr-assistant-launch [data-assistant-badge]');
  const notificationSummary = () => page.waitForResponse(response => {
    const url = new URL(response.url());
    return url.pathname.endsWith('/notifications') && url.searchParams.get('summary') === '1' && response.ok();
  }).then(response => response.json());
  async function assertCount(count) {
    if (count > 0) {
      await expect(badge).toBeVisible();
      await expect(badge).toHaveText(String(count));
    } else {
      await expect(badge).toBeHidden();
    }
    await expect(page.locator('.tr-assistant-launch')).toHaveAttribute('title', new RegExp('^' + count + ' transactions? need'));
  }
  const initialCounts = notificationSummary();
  const initialReport = page.waitForResponse(response => new URL(response.url()).pathname.endsWith('/analysis'));
  await page.goto(origin + '/trajectory?tab=analysis');
  const initial = await initialReport;
  const before = await initial.json();
  const baseline = await initialCounts;
  assert.ok(baseline.unread_count > 0 && baseline.review_count > 0);
  await assertCount(baseline.review_count);
  const key = id => before.entries.find(row => row.id === id)?.entry_key;
  assert.ok(payments.every(id => before.entries.find(row => row.id === id)?.category === 'family_reimbursements'));
  await expect(page.locator('#tr-add')).toBeEnabled();

  const shopping = page.locator('[data-action="vf-category"][data-key="shopping"]');
  if (!await shopping.isVisible()) await page.locator('.vf-more-slices').filter({has: shopping}).locator('summary').click();
  await shopping.click();
  await page.locator('[data-action="vf-subcategory"][data-key="family_reimbursements"]').click();
  await page.getByRole('button', {name: new RegExp(merchant)}).click();
  const ledger = page.locator('.vf-ledger');
  const query = ledger.getByRole('searchbox');
  const heading = ledger.getByRole('heading', {name: merchant, exact: true});
  const account = ledger.locator('[data-vf-input="budget-account"]');
  const sort = ledger.locator('[data-vf-input="sort"]');
  const rows = ledger.locator('tr[data-entry-key]');
  await expect(rows).toHaveCount(2);
  await account.selectOption(String(fixture.cash_id));
  await sort.selectOption('smallest');
  await query.fill('Fictional Family');
  await query.press('Tab');
  await expect(heading).toBeVisible();
  await expect(ledger.locator('.vf-section-heading')).toContainText('$64.79');
  const controls = [
    page.locator('.vf-finance'), ledger, heading, query, account, sort, page.locator('.vf-breadcrumbs'),
  ];
  const nodes = await Promise.all(controls.map(control => control.elementHandle()));
  const breadcrumbs = await controls.at(-1).innerText();
  const historyLength = await page.evaluate(() => history.length);
  const survivingRow = ledger.locator('tr[data-entry-key="' + key(payments[1]) + '"]');
  const survivor = await survivingRow.elementHandle();
  const reads = [], writes = [], errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('request', request => {
    const path = new URL(request.url()).pathname;
    if (request.method() === 'GET') reads.push(path);
    if (['PUT','PATCH','POST','DELETE'].includes(request.method()) && /\/transactions\/\d+/.test(path)) writes.push(path);
  });
  for (const [index, id] of payments.entries()) {
    const row = ledger.locator('tr[data-entry-key="' + key(id) + '"]');
    const dialog = page.getByRole('dialog', {name: 'Transaction details'});
    const control = phone ? dialog.locator('[data-vf-category]') : row.locator('[data-vf-category]');
    const opener = phone ? row.locator('.tr-list-merchant-mobile') : control;
    await opener.evaluate(node => node.scrollIntoView({block: 'center'}));
    const scroll = await page.evaluate(() => scrollY);
    if (index === 0) assert.ok(scroll > 0, 'The merchant ledger is scrolled before editing');
    if (phone) await opener.click();
    await control.focus();
    const savedRequest = page.waitForResponse(response => response.request().method() === 'PUT' && new URL(response.url()).pathname.endsWith('/transactions/' + id + '/attribution'));
    const updatedCounts = notificationSummary();
    await control.selectOption('christmas');
    const saved = await savedRequest;
    assert.equal(saved.status(), 200);
    assert.equal(saved.request().postDataJSON().lines[0].subcategory_record_id, null);
    assert.equal(saved.request().postDataJSON().lines[0].category, 'christmas');
    await expect(rows).toHaveCount(1 - index);
    assert.deepEqual(await updatedCounts, baseline, 'Changing an already reviewed purchase leaves the queue unchanged');
    await assertCount(baseline.review_count);
    if (phone) await expect(dialog).toBeHidden();
    for (const [position, controlNode] of controls.entries()) {
      assert.ok(await controlNode.evaluate((current, previous) => current === previous, nodes[position]), controlNode + ' stays mounted');
    }
    await expect(query).toHaveValue('Fictional Family');
    await expect(account).toHaveValue(String(fixture.cash_id));
    await expect(sort).toHaveValue('smallest');
    await expect(controls.at(-1)).toHaveText(breadcrumbs, {useInnerText: true});
    await expect(page.locator('#tr-back')).toBeVisible();
    assert.equal(await page.evaluate(() => history.length), historyLength);
    if (index === 0) {
      assert.ok(await survivingRow.evaluate((current, previous) => current === previous, survivor));
      await expect(survivingRow.locator(phone ? '.tr-list-merchant-mobile' : '[data-vf-category]')).toBeFocused();
      await expect(ledger.locator('.vf-section-heading')).toContainText('$43.12');
      assert.ok(Math.abs((await page.evaluate(() => scrollY)) - scroll) < 5, 'The first change preserves the ledger scroll position');
    } else {
      await expect(query).toBeFocused();
      await expect(ledger.getByText('No transactions match this view.', {exact: true})).toBeVisible();
      await expect(ledger.locator('.vf-section-heading')).toContainText('0 recorded entries');
      await expect(ledger.locator('.vf-section-heading')).toContainText('$0.00');
    }
  }
  assert.deepEqual(reads.filter(path => /\/(?:dashboard|visual-context)$/.test(path)), []);
  assert.deepEqual(writes, payments.map(id => '/trajectory/spaces/' + fixture.space_id + '/transactions/' + id + '/attribution'));
  assert.deepEqual(errors, []);
  const refreshed = await context.request.get(initial.url());
  assert.ok(refreshed.ok());
  const after = await refreshed.json();
  assert.equal(after.categories.find(group => group.key === 'christmas').amount_cents, 6479);
  assert.deepEqual(after.entries.filter(row => payments.includes(row.id)).map(row => row.category), ['christmas','christmas']);
  assert.equal(after.summary.recorded_spending_cents, before.summary.recorded_spending_cents);
  await page.locator('#tr-back').click();
  await expect(heading).toHaveCount(0);

  // Both engines exercise the same two fixture payments, with newly versioned details.
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  for (const id of payments) {
    const url = origin + '/trajectory/spaces/' + fixture.space_id + '/transactions/' + id + '/attribution';
    const current = await (await context.request.get(url)).json();
    const restored = await context.request.put(url, {headers: {'X-CSRF-TOKEN': csrf}, data: {
      version: current.version, lines: current.lines.map(line => ({...line, category: 'shopping', subcategory_record_id: childId})),
    }});
    assert.ok(restored.ok());
  }
  const reportReady = page.waitForResponse(response => new URL(response.url()).pathname.endsWith('/analysis') && response.ok());
  await page.goto(origin + '/trajectory?tab=analysis');
  const report = await (await reportReady).json();
  const purchase = report.entries.find(row => row.id === reviewId);
  assert.equal(purchase.category, 'uncategorized');
  await expect(page.locator('#tr-add')).toBeEnabled();
  await assertCount(baseline.review_count);
  const unassigned = page.locator('[data-action="vf-category"][data-key="uncategorized"]');
  if (!await unassigned.isVisible()) await page.locator('.vf-more-slices').filter({has:unassigned}).locator('summary').click();
  await unassigned.click();
  await page.locator('[data-action="vf-ledger-category"]').click();
  const transaction = ledger.locator('tr[data-entry-key="' + purchase.entry_key + '"]');
  await expect(transaction).toBeVisible();
  const mountedLedger = await ledger.elementHandle();
  const mountedSearch = await query.elementHandle();
  if (phone) await transaction.locator('.tr-list-merchant-mobile').click();
  const select = phone ? page.getByRole('dialog', {name:'Transaction details'}).locator('[data-vf-category]') : transaction.locator('[data-vf-category]');
  const updated = notificationSummary();
  await select.selectOption('groceries');
  const counts = await updated;
  assert.equal(counts.review_count, baseline.review_count - 1);
  assert.equal(counts.unread_count, baseline.unread_count, 'Reviewing an expense leaves unread notices alone');
  await assertCount(counts.review_count);
  await expect(transaction).toHaveCount(0);
  assert.ok(await ledger.evaluate((node, old) => node === old, mountedLedger), 'The ledger stays mounted as the Ask count updates');
  assert.ok(await query.evaluate((node, old) => node === old, mountedSearch), 'The search control keeps its identity');
  const reloaded = notificationSummary();
  await page.reload();
  assert.deepEqual(await reloaded, counts);
  await assertCount(counts.review_count);
  assert.deepEqual(errors, []);
  await checkBadgeDuringWorkspaceSwitch(page, origin, fixture);
  await page.close();
  if (phone) await deviceContext.close();
}

async function checkBadgeDuringWorkspaceSwitch(page, origin, fixture) {
  const badge = page.locator('.tr-assistant-launch [data-assistant-badge]');
  let holdHousehold = false, householdWaiting = false, businessWaiting = false, contextWaiting = false;
  let finishHousehold, finishBusiness, finishContext;
  const householdGate = new Promise(resolve => {finishHousehold = resolve;});
  const businessGate = new Promise(resolve => {finishBusiness = resolve;});
  const contextGate = new Promise(resolve => {finishContext = resolve;});
  const summary = space => '**/spaces/' + space + '/notifications?summary=1';
  const counts = review_count => ({json:{review_count, unread_count:4}});
  await page.route(summary(fixture.space_id), async route => {
    if (!holdHousehold) return route.fulfill(counts(7));
    householdWaiting = true;
    await householdGate;
    await route.fulfill(counts(19));
  });
  await page.route(summary(fixture.company_id), async route => {
    businessWaiting = true;
    await businessGate;
    await route.fulfill(counts(2));
  });
  await page.route('**/spaces/' + fixture.company_id + '/visual-context*', async route => {
    contextWaiting = true;
    await contextGate;
    await route.fulfill({status:503, json:{message:'Fictional workspace details are unavailable.'}});
  });
  try {
    await page.goto(origin + '/trajectory?tab=analysis');
    await expect(badge).toHaveText('7');
    holdHousehold = true;
    await selectReportRange(page, 'week');
    await closeReportControls(page);
    await expect.poll(() => householdWaiting).toBe(true);
    await selectReportScope(page, 'business');
    await expect(badge).toBeHidden();
    await expect(badge).toBeEmpty();
    await expect(page.locator('.tr-assistant-launch')).not.toHaveAttribute('title', /7 transactions/);
    await expect.poll(() => contextWaiting && businessWaiting).toBe(true);

    finishBusiness();
    await expect(badge).toBeVisible();
    await expect(badge).toHaveText('2');
    const staleResponse = page.waitForResponse(response => response.url().includes('/spaces/' + fixture.space_id + '/notifications?summary=1'));
    finishHousehold();
    await (await staleResponse).finished();
    finishContext();
    await expect(page.locator('#tr-feedback')).toContainText('Fictional workspace details are unavailable.');
    await expect(badge).toHaveText('2');
    await expect(page.locator('.tr-assistant-launch')).toHaveAttribute('title', /^2 transactions/);
  } finally {
    finishHousehold(); finishBusiness(); finishContext();
  }
}
