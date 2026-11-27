import assert from 'node:assert/strict';
import {devices, expect} from '@playwright/test';

export async function checkReviewedCategoryChanges({context, origin, fixture, payments, childId, phone}) {
  const deviceContext = phone ? await context.browser().newContext({
    ...devices['iPhone 13'], storageState: await context.storageState(), reducedMotion: 'reduce',
  }) : context;
  if (phone) await deviceContext.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
  const page = await deviceContext.newPage();
  if (!phone) await page.setViewportSize({width: 1440, height: 600});
  const merchant = 'Zelle: Fictional Family';
  const initialReport = page.waitForResponse(response => new URL(response.url()).pathname.endsWith('/analysis'));
  await page.goto(origin + '/trajectory?tab=analysis');
  const initial = await initialReport;
  const before = await initial.json();
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
    await control.selectOption('christmas');
    const saved = await savedRequest;
    assert.equal(saved.status(), 200);
    assert.equal(saved.request().postDataJSON().lines[0].subcategory_record_id, null);
    assert.equal(saved.request().postDataJSON().lines[0].category, 'christmas');
    await expect(rows).toHaveCount(1 - index);
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
  await page.close();
  if (phone) await deviceContext.close();
}
