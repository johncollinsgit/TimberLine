import assert from 'node:assert/strict';
import {expect} from '@playwright/test';

const reportFields = ['#tr-space-tabs', '#tr-range', '#tr-account-filter', '.vf-segmented',
  '[data-vf-input="year"]', '[data-vf-input="comparison"]', '[data-vf-input="basis"]', '[data-vf-input="program"]'];

async function checkWidths(page, selectors) {
  await expect.poll(() => page.evaluate(selectors => {
    const results = [{name: 'Page', width: document.documentElement.scrollWidth, available: innerWidth}];
    for (const name of selectors) {
      const element = document.querySelector(name);
      const box = element.getBoundingClientRect();
      results.push({name, width: element.scrollWidth, available: element.clientWidth});
      results.push({name: name + ' right edge', width: box.right, available: innerWidth});
      results.push({name: name + ' left edge', width: -box.left, available: 0});
    }
    return results.filter(({width,available}) => width > available + 1);
  }, selectors), {message: 'Resized report and ledger controls fit their available width'}).toEqual([]);
}

export async function checkAnalysisHeader({context, origin, phone}) {
  const page = await context.newPage();
  const widths = phone ? [390, 320] : [1440, 1024];
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.setViewportSize({width: widths[0], height: phone ? 844 : 900});
  await page.goto(origin + '/trajectory?tab=analysis');
  await expect(page.locator('#tr-add')).toBeEnabled();
  await expect(page.locator('#vf-period-chart')).toBeVisible();
  const period = page.locator('select#tr-range');
  const account = page.locator('#tr-account-filter');
  const scope = page.locator('#tr-space-tabs');
  const grouping = page.locator('.vf-segmented');
  const options = page.getByRole('button', {name: 'Report options', exact: true});
  const dates = page.getByRole('button', {name: 'Dates and view options', exact: true});
  const panel = phone ? page.getByRole('dialog', {name: 'Dates & view'}) : page.locator('#tr-report-options');
  const trigger = phone ? dates : options;

  for (const width of widths) {
    await page.setViewportSize({width, height: phone ? 844 : 900});
    for (const selector of reportFields) await expect(page.locator(selector)).toHaveCount(1);
    await expect(trigger).toBeVisible();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await expect(phone ? options : dates).toBeHidden();
    const common = [scope, account, period, grouping];
    for (const control of common) {
      if (phone) await expect(control).toBeHidden();
      else await expect(control).toBeVisible();
    }
    await expect(page.locator('[data-vf-input="year"]')).toBeHidden();
    await checkWidths(page, ['.tr-header', '.vf-finance']);
    await trigger.click();
    await expect(panel).toBeVisible();
    await expect(period).toHaveAccessibleName('Reporting period');
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    for (const selector of reportFields.slice(4)) await expect(panel.locator(selector)).toBeVisible();
    await expect(panel.getByRole('button', {name: 'Organize categories', exact: true})).toBeVisible();
    await expect(panel.locator('#tr-compare')).toBeVisible();
    await expect(panel.locator('#tr-refresh')).toBeVisible();
    for (const control of common) await expect(control).toBeVisible();
    await expect(grouping.getByRole('button')).toHaveText(['Annual','Monthly','Weekly']);
    assert.deepEqual(await period.locator('option').evaluateAll(options => options.map(option => option.value)),
      ['day','week','month','quarter','year','all','custom']);
    await checkWidths(page, [phone ? '#tr-report-controls' : '#tr-report-options']);
    if (width === widths[0]) {
      const basis = panel.locator('[data-vf-input="basis"]');
      for (const value of ['cash','spending']) {
        const response = page.waitForResponse(response => {
          const url = new URL(response.url());
          return url.pathname.endsWith('/analysis') && url.searchParams.get('basis') === value;
        });
        await basis.selectOption(value);
        assert.equal((await (await response).json()).scope.basis, value);
        await expect(basis).toHaveValue(value);
        await expect(page.locator('.vf-finance')).toHaveAttribute('aria-busy', 'false');
      }
    }
    await page.keyboard.press('Escape');
    await expect(panel).toBeHidden();
    await expect(trigger).toBeFocused();
    if (phone) {
      await trigger.click();
      await page.locator('#tr-date-close').click();
      await expect(trigger).toBeFocused();
    }
    await page.locator('[data-action="vf-ledger-all"]').click();
    await expect(page.locator('.vf-ledger')).toBeVisible();
    await expect(page.locator('.vf-ledger tbody tr[data-entry-key]').first()).toBeVisible();
    await checkWidths(page, ['.tr-header', '.vf-ledger', '.vf-ledger-controls']);
    if (phone) await checkWidths(page, ['.vf-ledger .tr-review-table-wrap']);
    await page.locator('.vf-breadcrumbs [data-level="overview"]').click();
    await expect(page.locator('#vf-period-chart')).toBeVisible();
  }

  const references = await Promise.all(reportFields.map(selector => page.locator(selector).elementHandle()));
  await page.setViewportSize({width: phone ? 1024 : 390, height: 844});
  const otherTrigger = phone ? options : dates;
  await otherTrigger.click();
  for (const [index, selector] of reportFields.entries()) {
    await expect(page.locator(selector)).toHaveCount(1);
    assert.ok(await page.locator(selector).evaluate((node, original) => node === original, references[index]), selector + ' survives the breakpoint');
  }
  await page.keyboard.press('Escape');
  await expect(otherTrigger).toBeFocused();
  assert.deepEqual(errors, []);
  await page.close();
}
