import assert from 'node:assert/strict';
import {expect} from '@playwright/test';

const money = cents => new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(cents / 100);
const usdBalances = rows => rows.filter(row => row.currency === 'USD' && Number.isSafeInteger(row.balance_cents) && row.balance_cents > 0
  && ['current','dated','recorded'].includes(row.balance_status));

export async function checkDebtCharts({page, panel, report}) {
  const charts = panel.locator('[data-pt-debt-charts]');
  await expect(charts).toBeVisible();
  const balances = usdBalances(report.inventory);
  const rates = balances.filter(row => Number.isSafeInteger(row.rate_millis) && row.rate_millis >= 0 && ['reported','confirmed'].includes(row.rate_status));
  const buttonsFor = id => charts.locator(`[data-action="pt-chart-detail"][data-chart="${id}"]`);
  for (const [id,accounts] of [['debt-balances',balances],['debt-rates',rates]]) {
    const keys = await buttonsFor(id).evaluateAll(nodes => Array.from(new Set(nodes.map(node => node.dataset.key))).sort());
    assert.deepEqual(keys, accounts.map(row => row.id).sort(), 'Only eligible debt inventory supplies chart controls');
  }
  const row = rates.find(item => item.name === 'Fictional detail card');
  assert.ok(row);
  for (const selector of ['.pt-ring-segment','.pt-chart-legend-button','.pt-chart-bar']) {
    const button = charts.locator(`${selector}[data-key="${row.id}"]`);
    if (selector === '.pt-ring-segment') {
      await button.evaluate(node => node.scrollIntoView({block:'center'}));
      const position = await button.evaluate(node => {
        const box = node.getBoundingClientRect();
        for (let degree = 0; degree < 360; degree += 5) {
          const angle = degree * Math.PI / 180;
          const x = box.width / 2 + box.width * .4 * Math.cos(angle);
          const y = box.height / 2 + box.height * .4 * Math.sin(angle);
          if (document.elementFromPoint(box.left + x,box.top + y) === node) return {x,y};
        }
        return null;
      });
      assert.ok(position, 'The visible ring segment has its own pointer hit area');
      await button.click({position});
    } else {
      await button.click();
    }
    const detail = page.locator('#tr-dialog');
    await expect(detail).toBeVisible();
    await expect(detail.locator('#tr-dialog-title')).toHaveText(row.name);
    await expect(detail).toContainText('Recorded balance');
    await expect(detail).toContainText(money(row.balance_cents));
    await expect(detail).toContainText(money(row.payment_cents));
    await expect(detail).toContainText('Annual interest rate');
    await expect(detail).toContainText((row.rate_millis / 1000) + '%');
    await expect(detail).toContainText('Interest this month');
    if (Number.isInteger(row.interest.cents)) await expect(detail).toContainText(money(row.interest.cents));
    if (row.ytd_statement_interest) await expect(detail).toContainText(money(row.ytd_statement_interest.cents));
    await detail.getByRole('button', {name:'Close dialog'}).click();
    await expect(detail).toBeHidden();
    await expect(button).toBeFocused();
  }
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, 'Debt charts fit the viewport');
}
