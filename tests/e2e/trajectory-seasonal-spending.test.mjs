import assert from 'node:assert/strict';
import {test} from 'node:test';
import {createVisualFinance} from '../../resources/js/trajectory/visual-finance.js';

test('home and budget show the recorded seasonal net, dates and partial coverage in the personal all-account view', () => {
  const context = {scope: 'personal', space_id: 7, account_id: null, from: '2027-12-01', through: '2027-12-10'};
  const ui = createVisualFinance({root: {}, getContext: () => context, esc: String,
    precise: cents => Number.isSafeInteger(cents) ? `$${(cents / 100).toFixed(2)}` : '—'});
  const report = {year: 2027, grain: 'month', range: {from: context.from, through: context.through}, scope: {basis: 'spending'}, summary: {}};
  const budgets = {month: '2027-12', rows: [], seasonal_spending: [{category: 'christmas', from: '2027-11-01', through: '2027-12-10', net_spent_cents: 1250, range_covered: false}]};
  ui.adoptSnapshot(report, budgets);
  for (const view of ['overview', 'budget']) {
    const html = ui.render(view);
    assert.match(html, /Christmas spending/);
    assert.match(html, /\$12\.50/);
    assert.match(html, /Nov 1.+Dec 10, 2027/);
    assert.match(html, /Partial account history/);
    assert.doesNotMatch(html, /Edisto spending/);
  }
  context.account_id = 1;
  assert.doesNotMatch(ui.render('budget'), /Seasonal spending/);
  context.account_id = null;
  context.scope = 'business';
  assert.doesNotMatch(ui.render('overview'), /Seasonal spending/);
});
