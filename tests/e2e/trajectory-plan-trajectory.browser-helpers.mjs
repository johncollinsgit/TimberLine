import assert from 'node:assert/strict';
import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
import {expect} from '@playwright/test';
import {checkDebtCharts} from './trajectory-debt-charts.browser-helpers.mjs';

const exec = promisify(execFile);
const money = cents => Number.isSafeInteger(cents)
  ? new Intl.NumberFormat('en-US', {style:'currency', currency:'USD'}).format(cents / 100)
  : 'Unavailable';

async function financialFingerprint(database) {
  const php = `
    $db=new PDO('sqlite:'.$argv[1]);
    $db->exec('PRAGMA query_only=ON');
    $hash=hash_init('sha256');
    foreach (['trajectory_accounts','trajectory_transactions','trajectory_allocations','trajectory_allocation_details','trajectory_records'] as $table) {
      $criteria=$table==='trajectory_records' ? " WHERE kind LIKE 'medical_%' OR kind IN ('asset','metal','debt','interest_statement','debt_balance_observation','payment_notice')" : '';
      foreach ($db->query('SELECT * FROM '.$table.$criteria.' ORDER BY id',PDO::FETCH_ASSOC) as $row) hash_update($hash,serialize($row));
    }
    echo hash_final($hash);
  `;
  return (await exec('php', ['-r', php, database])).stdout;
}

function responseFor(page, path, verb = 'GET') {
  return page.waitForResponse(response => response.request().method() === verb && new URL(response.url()).pathname.endsWith('/' + path));
}

async function primaryTab(page, key) {
  const phone = page.locator(`.vf-mobile-nav [data-tab="${key}"]`);
  await (await phone.isVisible() ? phone : page.locator(`.tr-primary-navigation [data-tab="${key}"]`)).click();
}

async function checkDebtPlanning(page, origin, books, database) {
  const panel = page.locator('.pt-debt-section');
  const method = panel.locator('[name="pt-debt-method"]');
  const extra = panel.locator('[name="pt-debt-extra"]');
  const reservation = page.locator('.vf-payoff-reservation');
  const budgetReady = () => responseFor(page, 'budget-envelopes');
  const requestedChanges = [];
  page.on('request', request => {
    const path = new URL(request.url()).pathname;
    if (['PATCH','POST','DELETE','PUT'].includes(request.method()) && !path.endsWith('/debt-scenarios')) requestedChanges.push(path);
  });
  const initialBudget = budgetReady();
  await page.goto(origin + '/trajectory?tab=budget');
  const baseline = await (await initialBudget).json();
  assert.equal(baseline.debt_payoff_reservation, null);
  await expect(page.getByRole('region', {name:'Monthly budget totals', exact:true})).toBeVisible();

  async function openDebt(route = 'debt') {
    const loaded = responseFor(page, 'plan-context');
    const inventoryReady = responseFor(page, 'debt-scenarios', 'POST');
    if (route === 'navigation') {
      await page.goto(origin + '/trajectory?tab=planning');
      await expect(page.locator('[data-pt-monthly]')).toBeVisible();
      await expect(panel).toHaveCount(0);
      await primaryTab(page, 'debt');
    } else {
      await page.goto(origin + '/trajectory?tab=' + route);
    }
    const {debt_payoff:saved, household_trajectory:trajectory} = await (await loaded).json();
    await expect(panel).toBeVisible();
    await expect(page.locator('#tr-page-title')).toHaveText('Debt');
    await expect(page.locator('[data-tab="debt"][aria-current="page"]')).toHaveCount(2);
    await expect(page.locator('[data-pt-monthly],.pt-legacy')).toHaveCount(0);
    assert.equal(new URL(page.url()).search, '?tab=debt');
    const inventoryReply = await inventoryReady;
    assert.ok(inventoryReply.ok(), await inventoryReply.text());
    return {saved, report:await inventoryReply.json(), monthly:trajectory.monthly};
  }
  const {saved, report, monthly} = await openDebt('navigation');
  await page.goBack();
  await expect(page.locator('[data-pt-monthly]')).toBeVisible();
  await expect(panel).toHaveCount(0);
  assert.equal(new URL(page.url()).search, '?tab=planning');
  await page.goForward();
  await expect(panel.locator('.pt-debt-account').first()).toBeVisible();
  assert.equal(new URL(page.url()).search, '?tab=debt');
  await checkDebtCharts({page,panel,report});
  const debts = new Map(report.inventory.map(item => [item.name, item]));
  assert.equal(debts.size, report.inventory.length, 'A dated card snapshot and its current account represent one debt');
  for (const [name,balance,apr,minimum] of [['Fictional card',100000,11000,4500],['Fictional detail card',131902,29000,8000]]) {
    const item = debts.get(name);
    assert.ok(item, name);
    assert.equal(item.balance_cents, balance, 'The latest account observation beats the older saved terms');
    assert.equal(item.rate_millis, apr);
    assert.equal(item.payment_cents, minimum);
    assert.equal(item.rate_status, 'confirmed');
    assert.equal(item.payment_status, 'confirmed');
    const card = panel.locator('.pt-debt-account').filter({has:page.getByText(name, {exact:true})});
    await expect(card).toHaveCount(1);
    const heading = card.locator('summary');
    await expect(heading).toContainText(money(balance));
    await expect(heading).toContainText(money(minimum));
    await heading.click();
    const details = card.locator('.pt-debt-facts');
    await expect(details).toBeVisible();
    await expect(details).toContainText('Interest this month');
    if (Number.isInteger(item.interest?.cents)) await expect(details).toContainText(money(item.interest.cents));
    await heading.click();
  }
  const booksBeforePreview = await financialFingerprint(database);
  assert.equal(booksBeforePreview, books);
  await method.selectOption('avalanche');
  await extra.fill('150');
  const previewReady = responseFor(page, 'debt-scenarios', 'POST');
  await panel.locator('[data-action="pt-debt-preview"]').click();
  const previewReply = await previewReady;
  assert.deepEqual(previewReply.request().postDataJSON(), {view:'household_plan',extra_cents:15000});
  assert.ok(previewReply.ok(), await previewReply.text());
  const preview = await previewReply.json();
  const impact = panel.locator('.pt-impact');
  const targetWithExtra = (monthly.historical_sustainable_cents ?? monthly.sustainable_cents) + 15000;
  assert.ok(Number.isSafeInteger(monthly.expected_income_cents));
  await expect(impact).toContainText('Monthly take-home with this extra');
  await expect(impact).toContainText(money(targetWithExtra));
  await expect(impact).toContainText(money(Math.abs(targetWithExtra - monthly.expected_income_cents)));
  const comparisons = new Map(preview.method_comparisons.map(item => [item.method,item]));
  for (const key of ['avalanche','snowball']) {
    assert.equal(comparisons.get(key)?.status, 'projected', JSON.stringify(preview.missing));
    assert.equal(comparisons.get(key).extra_monthly_cents, 15000, 'Both strategies compare equal extra dollars');
    await expect(panel).toContainText(money(comparisons.get(key).interest_cents));
  }
  assert.ok(comparisons.get('avalanche').interest_cents <= comparisons.get('snowball').interest_cents);
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, 'Debt inventory and comparison fit the viewport');
  assert.deepEqual(requestedChanges, [], 'A read-only payoff preview leaves workspace settings and ledger intact');

  let version = saved.version;
  async function apply(key, cents) {
    const savedReply = responseFor(page, 'workspace', 'PATCH');
    await panel.locator('[data-action="pt-debt-apply"]').click();
    const reply = await savedReply;
    assert.ok(reply.ok(), await reply.text());
    const payload = reply.request().postDataJSON();
    assert.equal(payload.debt_payoff_method, key);
    assert.equal(payload.debt_extra_cents, cents);
    assert.equal(payload.debt_payoff_version, version);
    const next = (await reply.json()).debt_payoff;
    assert.equal(next.method, key);
    assert.equal(next.extra_cents, cents);
    assert.equal(next.version, version + 1);
    version = next.version;
  }
  async function verifyBudget(key, cents, alreadyOnBudget = false) {
    if (!alreadyOnBudget) await primaryTab(page, 'budget');
    await expect(page.getByRole('region', {name:'Monthly budget totals', exact:true})).toBeVisible();
    if (cents > 0) await expect(reservation).toContainText(money(cents));
    else await expect(reservation).toHaveCount(0);
    const refreshed = budgetReady();
    await page.goto(origin + '/trajectory?tab=budget');
    const current = await (await refreshed).json();
    assert.deepEqual(current.rows, baseline.rows, 'A workspace reservation leaves individual category budgets intact');
    if (Number.isInteger(baseline.summary.remaining_cents)) assert.equal(current.summary.remaining_cents, baseline.summary.remaining_cents - cents);
    if (cents === 0) {
      assert.equal(current.debt_payoff_reservation, null);
      await expect(reservation).toHaveCount(0);
      return;
    }
    assert.equal(current.debt_payoff_reservation.method, key);
    assert.equal(current.debt_payoff_reservation.amount_cents, cents);
    await expect(reservation).toHaveCount(1);
    await expect(reservation).toContainText('Extra debt payoff');
    await expect(reservation).toContainText(money(cents));
  }
  await apply('avalanche', 15000);
  await verifyBudget('avalanche', 15000);
  const first = await openDebt();
  assert.equal(first.saved.method, 'avalanche');
  await expect(extra).toHaveValue(/^(?:150|150\.00)$/);
  await method.selectOption('snowball');
  await extra.fill('70');
  let hold, release;
  const waiting = new Promise(done => { hold = done; });
  const gate = new Promise(done => { release = done; });
  await page.route('**/workspace', async route => {
    if (route.request().method() !== 'PATCH') return route.fallback();
    hold();
    await gate;
    await route.continue();
  });
  const applying = apply('snowball', 7000);
  await Promise.race([waiting, applying.then(() => assert.fail('The replacement PATCH bypassed the navigation gate'))]);
  try {
    await primaryTab(page, 'budget');
    await expect(reservation).toContainText(money(15000));
  } finally {
    release();
  }
  await applying;
  await expect(reservation).toContainText(money(7000));
  await page.unroute('**/workspace');
  await verifyBudget('snowball', 7000, true);
  const second = await openDebt('planning&section=debt');
  assert.equal(second.saved.method, 'snowball');
  assert.equal(second.saved.extra_cents, 7000);
  await method.selectOption('minimum');
  await apply('minimum', 0);
  await verifyBudget('minimum', 0);
  assert.equal(requestedChanges.length, 3);
  assert.ok(requestedChanges.every(path => path.endsWith('/workspace')));
  assert.equal(await financialFingerprint(database), books, 'Payoff planning leaves bank entries, balances, debt records and medical records intact');
}

export async function checkPlanTrajectory({context, origin, width, engine, database}) {
  const page = await context.newPage();
  const requests = [];
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('request', request => requests.push({path:new URL(request.url()).pathname, method:request.method()}));
  await page.setViewportSize({width, height:950});
  await page.route('**/dashboard?*', route => route.abort());
  const contextReply = page.waitForResponse(response => new URL(response.url()).pathname.endsWith('/plan-context'));
  await page.goto(origin + '/trajectory?tab=planning');
  const planContext = await (await contextReply).json();
  const report = planContext.household_trajectory;
  const plan = page.locator('.pt-trajectory');
  await expect(plan).toBeVisible();
  assert.equal(requests.filter(request => request.path.endsWith('/plan-context')).length, 1);
  assert.ok(report);
  const targetCents = report.monthly.historical_sustainable_cents ?? report.monthly.sustainable_cents;
  const target = money(targetCents);
  await expect(plan.locator('[data-pt-target]')).toHaveText(target);
  await expect(plan.locator('.pt-earnings')).toContainText(money(report.monthly.expected_income_cents));
  await expect(plan.locator('.pt-cash')).toContainText(money(report.cash.observed_balance_cents));
  const ring = plan.locator('[data-pt-chart="plan-target-composition"]');
  const spending = plan.locator('[data-pt-chart="plan-spending-pace"]');
  const fixed = ring.locator('.pt-chart-legend-button[data-key="fixed"]');
  const variable = ring.locator('.pt-chart-legend-button[data-key="variable"]');
  const detail = page.locator('#tr-dialog');
  const endDetail = async button => {
    await detail.getByRole('button', {name:'Close dialog'}).click();
    await expect(detail).toBeHidden();
    await expect(button).toBeFocused();
  };
  await expect(ring.locator('.pt-ring-center')).toContainText(target);
  await expect(ring.locator('.pt-chart-legend-button')).toHaveCount(3);
  const writeCount = requests.filter(request => !['GET','HEAD'].includes(request.method)).length;
  await fixed.focus();
  await fixed.press('Enter');
  await expect(detail.locator('#tr-dialog-title')).toHaveText('Scheduled bills and debt');
  const medicalIds = new Set(report.medical.bills.map(bill => bill.id));
  const fixedItem = planContext.planning.fixed_records.find(record => !medicalIds.has(record.record_id) && record.monthly_cents > 0);
  assert.ok(fixedItem, 'The monthly fixture provides a scheduled payment');
  await expect(detail).toContainText(fixedItem.name);
  await expect(detail).toContainText(money(fixedItem.monthly_cents));
  await endDetail(fixed);
  const ringFace = ring.locator('.pt-ring');
  await ringFace.scrollIntoViewIfNeeded();
  const bounds = await ringFace.boundingBox();
  const middleAngle = Math.PI * report.monthly.fixed_cents / targetCents;
  assert.ok(bounds && middleAngle > 0, 'The fixed-cost sector is drawn');
  const position = {
    x:bounds.width * (0.5 + 0.43 * Math.sin(middleAngle)),
    y:bounds.height * (0.5 - 0.43 * Math.cos(middleAngle)),
  };
  if (engine === 'webkit') await ringFace.tap({position});
  else await ringFace.click({position});
  await expect(detail.locator('#tr-dialog-title')).toHaveText('Scheduled bills and debt');
  await endDetail(ring.locator('.pt-ring-segment[data-key="fixed"]'));
  await variable.click();
  await expect(detail).toContainText('Spending used for the historical average');
  const historicalMonth = report.monthly.history.find(row => report.monthly.estimate_months.includes(row.month));
  assert.ok(historicalMonth, 'The monthly fixture supplies an observed historical basis');
  await expect(detail).toContainText(money(historicalMonth.flexible_cents));
  await endDetail(variable);
  const basis = plan.locator('[name="pt-basis"]');
  await basis.selectOption('saved');
  await expect(ring.locator('.pt-ring-center')).toContainText(money(report.monthly.override_sustainable_cents));
  await expect(variable).toContainText(money(report.monthly.flexible_cents));
  await variable.click();
  await expect(detail).toContainText('Saved monthly estimate');
  await expect(detail).toContainText(money(report.monthly.historical_flexible_cents));
  await endDetail(variable);
  await basis.selectOption('history');
  await expect(ring.locator('.pt-ring-center')).toContainText(target);
  const medical = ring.locator('.pt-chart-legend-button[data-key="medical"]');
  await medical.click();
  await expect(detail).toContainText(money(report.monthly.medical_installments_cents));
  await endDetail(medical);
  await expect(spending.locator('.pt-chart-bar')).toHaveCount(report.pace.length);
  const prior = report.pace[1];
  assert.equal(prior.spending_cents, 40390, 'Prior-year economic costs include the card interest once');
  assert.equal(prior.debt_service_cents, 7000);
  assert.equal(prior.card_payment_cents, 9500);
  await expect(spending.locator(`button[data-key="${prior.year}"] .pt-bar-fill`)).toBeVisible();
  assert.ok(report.pace.some(row => row.spending_cents === null), 'Missing history stays visible beside measured periods');
  for (const row of report.pace) {
    const button = spending.locator(`button[data-key="${row.year}"]`);
    await expect(button).toContainText(money(row.spending_cents));
    await button.click();
    await expect(detail.locator('#tr-dialog-title')).toContainText(String(row.year));
    await expect(detail).toContainText('Recorded spending after refunds');
    await expect(detail).toContainText('Card payments');
    await expect(detail).toContainText(money(row.card_payment_cents));
    await expect(detail).toContainText('Loan payments');
    await expect(detail).toContainText(money(row.debt_service_cents));
    await expect(detail).toContainText('Financing interest included in spending');
    await expect(detail).toContainText(money(row.interest_cents));
    await endDetail(button);
  }
  assert.equal(requests.filter(request => !['GET','HEAD'].includes(request.method)).length, writeCount, 'Opening chart details reads the current Plan without a write or scenario request');
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, 'Monthly charts fit the viewport');
  await expect(plan.locator('.pt-history tbody tr')).toHaveCount(report.monthly.history.length);

  const books = await financialFingerprint(database);
  const bridge = plan.locator('.pt-scenario');
  await bridge.locator('[name="months"]').selectOption('12');
  await bridge.locator('[name="cash"]').fill('0');
  const asset = bridge.getByRole('checkbox', {name:/Fictional saleable trailer/i});
  await asset.check();
  await expect(bridge.locator('[data-pt-bridge-result]')).toContainText(money(Math.max(0, targetCents - Math.floor(55000 / 12))));
  await expect(plan.locator('[data-pt-target]')).toHaveText(target);
  assert.equal(requests.filter(request => ['POST','PUT','PATCH','DELETE'].includes(request.method)).length, 0);

  const editMetal = bridge.getByRole('button', {name:'Edit sale estimate for Fictional silver holding'});
  await editMetal.click();
  const metalDialog = page.locator('#tr-dialog');
  await expect(metalDialog.locator('[name="name"]')).toHaveValue('Fictional silver holding');
  await expect(metalDialog.locator('[name="plan_net_per_item_cents"]')).toBeEditable();
  await metalDialog.getByRole('button', {name:'Close dialog'}).click();

  const receipts = plan.locator('.pt-receipts');
  const source = receipts.locator('select[name="receipts"]');
  const group = source.locator('option').filter({hasText:/samaritan/i});
  await expect(group).toHaveCount(1);
  await source.selectOption(await group.getAttribute('value'));
  await expect(receipts.locator('[data-pt-received]')).toContainText('$291.34 received in 2 posted deposits');
  await receipts.locator('input[name="installments"]').fill('100');
  await expect(receipts.locator('[data-pt-allocation]')).toHaveText('34 payments of $2.92 and 66 payments of $2.91.');
  await bridge.getByText('Model temporary use of medical funds', {exact:true}).click();
  await bridge.locator('[name="medical-cash"]').fill('100');
  await expect(bridge.locator('[data-pt-bridge-result]')).toContainText('$1.14 extra per month for 88 months');
  await expect(plan.locator('[data-pt-target]')).toHaveText(target);
  await bridge.locator('[name="medical-cash"]').fill('0');
  const saved = page.waitForResponse(response => ['POST','PATCH'].includes(response.request().method()) && /\/records(?:\/\d+)?$/.test(new URL(response.url()).pathname));
  await receipts.getByRole('button', {name:'Save payment plan'}).click();
  const saveResponse = await saved;
  assert.ok(saveResponse.ok(), 'Saving the fixture receipt schedule returned ' + saveResponse.status() + ': ' + await saveResponse.text());
  const payload = saveResponse.request().postDataJSON();
  assert.equal(payload.kind, 'receipt_payment_plan');
  assert.equal(payload.data.installments, 100);
  assert.deepEqual(Object.keys(payload.data).sort(), ['installments','receipt_month','source_key']);
  await expect(receipts.locator('[data-pt-save-status]')).toHaveText('Saved');
  await page.reload();
  await expect(receipts.locator('input[name="installments"]')).toHaveValue('100');
  await expect(receipts.locator('[data-pt-allocation]')).toHaveText('34 payments of $2.92 and 66 payments of $2.91.');
  await expect(plan.locator('[data-pt-target]')).toHaveText(target);
  assert.equal(await financialFingerprint(database), books, 'A planning allocation leaves bank balances, postings, medical records and assets intact');

  for (const [action, inputName] of [['Add an asset','plan_sale_proceeds_cents'], ['Add metals','plan_net_per_item_cents']]) {
    await plan.getByRole('button', {name:action, exact:true}).click();
    const dialog = page.locator('#tr-dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator(`[name="${inputName}"]`)).toBeVisible();
    assert.equal(await dialog.locator(`[name="${inputName}"]`).evaluate(input => input.required), false);
    await dialog.getByRole('button', {name:'Close dialog'}).click();
  }
  await expect(page.locator('#tr-plan-outlook')).toBeHidden();
  assert.equal(await page.evaluate(() => getComputedStyle(document.querySelector('.tr-workspace'),'::after').animationName), 'none');
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
  await page.screenshot({path:`/tmp/trajectory-clarity-qa/plan-${engine}-${width}.png`, fullPage:true});
  const previousControls = page.locator('details.pt-legacy > summary');
  await previousControls.evaluate(node => node.scrollIntoView({block:'center'}));
  await previousControls.click();
  await expect(page.locator('#tr-plan-outlook')).toBeVisible();
  await page.locator('.tr-plan-stats [data-kind="costs"]').click();
  await expect(page.locator('#tr-dialog')).toBeVisible();
  await page.locator('#tr-close').click();
  await page.emulateMedia({reducedMotion:'no-preference'});
  assert.equal(await page.evaluate(() => getComputedStyle(document.querySelector('.tr-workspace'),'::after').animationName), 'tr-page-sheen');
  assert.equal(await page.evaluate(() => getComputedStyle(document.querySelector('.tr-workspace'),'::after').pointerEvents), 'none');
  assert.equal(requests.filter(request => request.path.endsWith('/dashboard')).length, 0, 'Direct household Plan stays on its authorized lightweight context');
  assert.equal(requests.filter(request => request.path.endsWith('/debt-scenarios')).length, 0, 'Monthly Plan only loads its own report');
  const accountId = planContext.account_options[0].id;
  await page.goto(origin + '/trajectory?tab=debt&account_id=' + accountId);
  await expect(page.getByText('Household debt uses all accounts', {exact:true})).toBeVisible();
  await expect(page.locator('.pt-debt-section,[data-tab="debt"]:visible')).toHaveCount(0);
  assert.equal(requests.filter(request => request.path.endsWith('/debt-scenarios')).length, 0, 'An account filter cannot request household debt');
  const cleared = responseFor(page, 'debt-scenarios', 'POST');
  await page.getByRole('button', {name:'Show all accounts'}).click();
  assert.ok((await cleared).ok());
  await expect(page.locator('.pt-debt-section')).toBeVisible();
  assert.equal(new URL(page.url()).searchParams.has('account_id'), false);
  const spaces = await page.locator('#trajectory').evaluate(node => JSON.parse(node.dataset.spaces));
  const business = spaces.find(space => space.kind === 'business');
  assert.ok(business, 'Fixture includes a distinct business');
  const previews = requests.filter(request => request.path.endsWith('/debt-scenarios')).length;
  await page.goto(origin + '/trajectory?tab=debt&space=' + business.id);
  await expect(page.getByText('Household debt', {exact:true})).toBeVisible();
  await expect(page.locator('.pt-debt-section,[data-tab="debt"]:visible')).toHaveCount(0);
  assert.equal(requests.filter(request => request.path.endsWith('/debt-scenarios')).length, previews, 'Business scope cannot request household payoff figures');
  assert.deepEqual(errors, []);
  await page.close();

  const legacy = await context.newPage();
  await legacy.setViewportSize({width,height:950});
  const dashboard = legacy.waitForResponse(response => new URL(response.url()).pathname.endsWith('/dashboard'));
  await legacy.goto(origin + '/trajectory?tab=transactions');
  assert.ok((await dashboard).ok());
  await expect(legacy.locator('#tr-content')).toHaveAttribute('aria-busy','false');
  const phonePlan = legacy.locator('.vf-mobile-nav [data-tab="planning"]');
  await (await phonePlan.isVisible() ? phonePlan : legacy.locator('.tr-primary-navigation [data-tab="planning"]')).click();
  await expect(legacy.locator('.pt-trajectory [data-pt-target]')).toHaveText(target);
  await expect(legacy.locator('.pt-receipts input[name="installments"]')).toHaveValue('100');
  await checkDebtPlanning(legacy, origin, books, database);
  await legacy.close();
}
