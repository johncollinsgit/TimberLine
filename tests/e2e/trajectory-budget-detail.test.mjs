import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
const source = (await readFile(new URL('../../resources/js/trajectory/budget-detail.js', import.meta.url), 'utf8')).replace("import './budget-detail.css';", '');
const {budgetDetailPreview, budgetInputCents} = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);

test('a target preview subtracts bank cash reports and pending once without touching source', () => {
  const row = {target_cents: 140000, bank_spent_cents: 30000, reported_cash_spent_cents: 50000, pending_cents: 1000};
  const before = structuredClone(row);
  assert.equal(budgetDetailPreview(row, {monthly_cents: 150000}).remaining_cents, 69000);
  assert.deepEqual(row, before);
});
test('unknown cash categorization history future and partial periods never become false budget left', () => {
  const base = {target_cents: 35000, bank_spent_cents: 0, pending_cents: 0};
  for (const pending of [{cash_spending_pending: true}, {category_review_pending: true}, {partial_period: true}, {bank_spent_cents: null}, {reasons: ['future_period']}]) {
    assert.equal(budgetDetailPreview({...base, ...pending}, {monthly_cents: 40000}).remaining_cents, null);
  }
  assert.equal(budgetDetailPreview({...base, bank_spent_cents: null, spent_cents: 50000, reported_cash_spent_cents: 50000}).remaining_cents, null);
  assert.equal(budgetDetailPreview({target_cents: 140000, spent_cents: 72048, reported_cash_spent_cents: 50000}).remaining_cents, 67952);
});
test('reserve target and contribution previews never turn a planned target into saved cash or allowance', () => {
  const row = {mode: 'fund', fund: {target_cents: 100000, saved_cents: 0, monthly_cents: 16667}};
  const preview = budgetDetailPreview(row, {target_cents: 120000, monthly_cents: 20000});
  assert.equal(preview.saved_cents, 0);assert.equal(preview.remaining_cents, null);assert.equal(preview.months_needed, 6);
  assert.equal(preview.still_to_save_cents, 120000);assert.equal(row.fund.target_cents, 100000);
});
test('dollar inputs retain cents and reject shorthand exponent negative and overprecision', () => {
  assert.equal(budgetInputCents('166.67'), 16667);assert.equal(budgetInputCents('0'), 0);assert.equal(budgetInputCents('1,400.00'),140000);assert.equal(budgetInputCents('12,345,678.90'),1234567890);
  for (const value of ['', '-1', '1e5', '2.999', '5k', '1,40', '1,,400', '1,2345']) assert.equal(budgetInputCents(value), null);
});
