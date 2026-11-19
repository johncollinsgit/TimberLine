import {readFile} from 'node:fs/promises';
import assert from 'node:assert/strict';
import {chromium, webkit} from '@playwright/test';

const raw = await readFile('resources/js/trajectory/budget-detail.js', 'utf8');
const script = raw.replace("import './budget-detail.css';", '').replaceAll('export function ', 'function ') + '\nwindow.createBudgetDetail=createBudgetDetail;';
const css = await readFile('resources/js/trajectory/budget-detail.css', 'utf8');
for (const engine of [chromium, webkit]) {
  const browser = await engine.launch();
  try {
    for (const width of [1180, 390]) {
      const page = await browser.newPage({viewport: {width, height: 900}}), errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.setContent('<html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><main id="trajectory"></main></body></html>');
      await page.addStyleTag({content: css}); await page.addScriptTag({content: script});
      await page.evaluate(() => {
        window.requests = []; window.changes = 0; window.entries = 0; window.ctx = {space_id: 7};
        window.budget = {id: 11, kind: 'budget', name: 'Groceries', version: 1, data: {category: 'groceries', monthly_cents: 140000, effective_on: '2026-11-01', ends_on: '2026-11-30', cash_spending_pending: false, category_review_pending: true, reported_cash_spent_cents: 50000, reported_cash_as_of: '2026-11-18', source: 'Fictional evidence'}};
        window.fuel = {id:13,kind:'budget',name:'Fuel',version:1,data:{category:'fuel',monthly_cents:35000,effective_on:'2026-11-01',ends_on:'2026-11-30',reviewed:true,category_review_pending:true}};
        window.goal = {id: 12, kind: 'goal', name: 'Travel reserve', version: 1, data: {target_cents: 100000, saved_cents: 0, monthly_cents: 16667, target_on: '2027-05-18', goal_type: 'savings', budget_category: 'travel'}};
        window.utilities = {id:21,kind:'budget',name:'Utilities line',version:1,data:{category:'utilities',monthly_cents:35000,effective_on:'2026-10-01',ends_on:null,reviewed:true}};
        const api = async (path, method = 'GET', data) => {
          window.requests.push({path, method, data});
          if (method === 'GET') return new Promise(resolve => {window.resolveRead = () => resolve({record: structuredClone(path.endsWith('/12') ? window.goal : path.includes('/13') ? window.fuel : path.includes('/21') ? window.utilities : window.budget), plan_context: 'a'.repeat(64), ...(path.includes('/21') ? {aggregate:{record_ids:[21,22,23],monthly_cents:57100,cash_spending_pending:false,category_review_pending:false}} : {})});});
          if (path.endsWith('/undo')) return {record: {...(path.includes('/13/') ? window.fuel : window.budget), version: 3}, undo: null};
          const record = structuredClone(path.endsWith('/12') ? window.goal : path.includes('/13') ? window.fuel : path.includes('/21') ? window.utilities : window.budget);
          record.version++; record.data = {...record.data, ...data.changes};
          return {record, undo: {event_id: 900, version: record.version}};
        };
        window.detail = window.createBudgetDetail({root: document.querySelector('#trajectory'), api,
          endpoint: (path, id) => `/spaces/${id}${path}`, getContext: () => window.ctx,
          esc: value => String(value ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c])),
          precise: value => '$' + (value / 100).toFixed(2), onChanged: () => window.changes++, onEntries: () => window.entries++});
        window.row = {label: 'Groceries', category: 'groceries', record_id: 11, record_ids: [11], target_cents: 140000,
          bank_spent_cents: 30000, reported_cash_spent_cents: 50000, reported_cash_as_of: '2026-11-18', pending_cents: 0, category_review_pending: true, reasons: ['category_review_pending'], suggestion: {month: '2026-10', amount_cents: 125000, partial: true}};
        window.detail.open(window.row, {month: '2026-11'});
      });
      await page.getByRole('heading', {name: 'Groceries', exact: true}).waitFor();
      assert.equal(await page.getByRole('dialog').isVisible(), true);
      await page.getByRole('button', {name: 'Use amount', exact: true}).click();
      assert.equal(await page.locator('[data-budget-money="monthly_cents"]').inputValue(), '1,250.00');
      assert.match(await page.locator('.tr-budget-suggestion').innerText(), /partial/);
      assert.equal((await page.evaluate(() => window.requests.filter(r => r.method !== 'GET'))).length, 0);
      assert.equal(await page.locator('input[type="range"]').count(), 0);
      await page.getByRole('button', {name:'Increase monthly budget by $10',exact:true}).click();
      assert.equal(await page.locator('[data-budget-money="monthly_cents"]').inputValue(), '1,260.00');
      await page.getByRole('button', {name:'Decrease monthly budget by $10',exact:true}).click();
      assert.equal(await page.locator('[data-budget-money="monthly_cents"]').inputValue(), '1,250.00');
      await page.locator('[data-budget-money="monthly_cents"]').fill('0');
      assert.equal(await page.getByRole('button', {name:'Decrease monthly budget by $10',exact:true}).isDisabled(), true);
      await page.getByRole('button', {name:'Increase monthly budget by $10',exact:true}).click();
      assert.equal(await page.locator('[data-budget-money="monthly_cents"]').inputValue(), '10.00');
      await page.locator('[data-budget-money="monthly_cents"]').fill('1500');
      assert.match(await page.locator('[data-budget-preview]').innerText(), /Budget\s+\$1500.00/);
      assert.match(await page.locator('[data-budget-preview]').innerText(), /Left · est.\s+\$700.00/);
      assert.equal(await page.getByRole('progressbar',{name:'Budget spent',exact:true}).getAttribute('aria-valuenow'),'53');
      assert.equal(await page.locator('.tr-budget-evidence').getAttribute('open'), null);
      assert.equal((await page.evaluate(() => window.requests.filter(r => r.method !== 'GET'))).length, 0);
      await page.evaluate(() => window.resolveRead());
      await page.getByRole('button', {name: 'Save', exact: true}).click();
      await page.getByRole('button', {name: 'Undo last edit'}).waitFor();
      assert.deepEqual(await page.evaluate(() => window.requests.find(r => r.method === 'PATCH').data), {version: 1, changes: {monthly_cents: 150000}, month: '2026-11', plan_context: 'a'.repeat(64)});
      await page.getByRole('button', {name: 'Undo last edit'}).click();
      await page.waitForFunction(() => window.changes === 2);
      assert.equal(await page.getByRole('progressbar',{name:'Budget spent',exact:true}).getAttribute('aria-valuenow'),'57','Undo updates the grocery usage bar');
      const geometry = await page.getByRole('dialog').evaluate(element => ({width: element.getBoundingClientRect().width, scroll: element.scrollWidth, client: element.clientWidth}));
      assert.ok(geometry.width <= width); assert.ok(geometry.scroll <= geometry.client + 1);
      assert.equal(await page.evaluate(() => window.requests.some(r => /analysis|dashboard/.test(r.path))), false);
      await page.getByRole('button', {name: 'Transactions'}).click();
      assert.equal(await page.evaluate(() => window.entries), 1);
      await page.evaluate(() => window.detail.open({mode: 'fund', category: 'travel', label: 'Travel reserve', record_id: 12, record_ids: [12], bank_spent_cents: 5000, pending_cents: 0,
        fund: {record_id: 12, version: 1, target_cents: 100000, saved_cents: 0, monthly_cents: 16667, target_on: '2027-05-18'}}, {month: '2026-11'}));
      assert.match(await page.locator('[data-budget-preview]').innerText(), /\$0.00 saved/);
      await page.getByRole('button',{name:'Increase monthly budget by $10',exact:true}).click();
      assert.equal(await page.locator('[data-budget-money="monthly_cents"]').inputValue(),'176.67');
      await page.locator('[data-budget-money="monthly_cents"]').fill('200');
      await page.locator('[data-budget-money="target_cents"]').fill('1200');
      assert.match(await page.locator('[data-budget-preview]').innerText(), /\$1200.00 to save/);
      await page.evaluate(() => window.resolveRead());
      await page.getByRole('button', {name: 'Save', exact: true}).click();
      await page.getByRole('button', {name: 'Undo last edit'}).waitFor();
      const fundSave = await page.evaluate(() => window.requests.filter(r => r.method === 'PATCH').at(-1));
      assert.deepEqual(fundSave.data.changes, {monthly_cents: 20000, target_cents: 120000, target_on: '2027-05-18'});
      assert.equal('saved_cents' in fundSave.data.changes, false);
      assert.match(await page.locator('[data-budget-preview]').innerText(), /\$0.00 saved/);
      await page.evaluate(() => window.detail.open({category:'utilities',label:'Utilities',record_id:null,record_ids:[21,22,23],target_cents:57100,planned_cents:57100,bank_spent_cents:10000,reported_cash_spent_cents:0,pending_cents:0,remaining_cents:47100},{month:'2026-11'}));
      assert.match(await page.locator('[data-budget-preview]').innerText(), /\$571.00/);
      await page.evaluate(() => window.resolveRead());
      assert.equal(await page.locator('[data-budget-money="monthly_cents"]').inputValue(), '571.00');
      assert.equal(await page.getByRole('button',{name:'Save',exact:true}).isEnabled(), true);
      assert.doesNotMatch(await page.getByRole('dialog').innerText(), /more than one plan|individual targets/);
      await page.locator('[data-budget-money="monthly_cents"]').fill('600');
      await page.getByRole('button',{name:'Save',exact:true}).click();
      await page.getByRole('button',{name:'Undo last edit'}).waitFor();
      const aggregateSave=await page.evaluate(()=>window.requests.filter(request=>request.method==='PATCH').at(-1));
      assert.match(aggregateSave.path,/\/21$/);assert.equal(aggregateSave.data.month,'2026-11');assert.equal(aggregateSave.data.changes.monthly_cents,60000);
      assert.equal(await page.evaluate(()=>window.utilities.data.monthly_cents),35000,'Preview/save never mutates a source line locally');
      assert.equal(await page.getByRole('progressbar',{name:'Budget spent',exact:true}).getAttribute('aria-valuenow'),'17','Combined category progress uses the edited total after saving');
      await page.evaluate(() => {
        window.detail.close(); window.ctx.month = '2026-11'; window.detail.open(window.row, {month: '2026-11'});
        const oldRead = window.resolveRead;
        window.ctx.month = '2026-12'; window.detail.contextChanged(); oldRead();
      });
      assert.equal(await page.getByRole('dialog').isVisible(), false);
      await page.evaluate(() => {
        window.ctx.month = '2026-11';
        window.detail.open({category:'fuel',label:'Fuel',record_ids:[],target_cents:35000,bank_spent_cents:0,reported_cash_spent_cents:0,pending_cents:0,reasons:['needs_split']},{month:'2026-11'});
      });
      assert.match(await page.locator('[data-budget-preview]').innerText(), /Needs split/);
      assert.equal(await page.getByRole('progressbar',{name:'Budget spent',exact:true}).count(),0,'Unresolved category spending cannot announce zero percent spent');
      await page.evaluate(() => window.detail.open({category:'fuel',label:'Fuel',record_id:13,record_ids:[13],target_cents:35000,bank_spent_cents:51083,reported_cash_spent_cents:0,pending_cents:0,category_review_pending:true,reasons:['needs_split','category_review_pending']},{month:'2026-11'}));
      assert.equal(await page.getByRole('progressbar',{name:'Budget spent',exact:true}).getAttribute('aria-valuenow'),'100');
      await page.locator('[data-budget-money="monthly_cents"]').fill('650');
      assert.equal(await page.getByRole('progressbar',{name:'Budget spent',exact:true}).getAttribute('aria-valuenow'),'79');
      assert.match(await page.locator('[data-budget-preview]').innerText(),/\$510.83\+/);
      assert.match(await page.locator('[data-budget-preview]').innerText(),/≤ \$139.17/);
      await page.evaluate(() => window.resolveRead());
      await page.getByRole('button',{name:'Decrease monthly budget by $10',exact:true}).click();
      assert.equal(await page.getByRole('progressbar',{name:'Budget spent',exact:true}).getAttribute('aria-valuenow'),'80');
      await page.getByRole('button',{name:'Increase monthly budget by $10',exact:true}).click();
      assert.equal(await page.getByRole('progressbar',{name:'Budget spent',exact:true}).getAttribute('aria-valuenow'),'79');
      await page.getByRole('button',{name:'Save',exact:true}).click();await page.getByRole('button',{name:'Undo last edit'}).waitFor();
      assert.equal(await page.getByRole('progressbar',{name:'Budget spent',exact:true}).getAttribute('aria-valuenow'),'79','Save preserves current recorded fuel progress');
      await page.getByRole('button',{name:'Undo last edit'}).click();await page.getByRole('button',{name:'Save',exact:true}).waitFor();
      assert.equal(await page.getByRole('progressbar',{name:'Budget spent',exact:true}).getAttribute('aria-valuenow'),'100','Undo restores fuel allowance and usage together');

      await page.evaluate(() => window.detail.open({category:'fuel',label:'Fuel',record_ids:[],target_cents:35000,bank_spent_cents:0,reported_cash_spent_cents:0,pending_cents:0,reasons:[]},{month:'2026-11'}));
      assert.equal(await page.getByRole('progressbar',{name:'Budget spent',exact:true}).getAttribute('aria-valuenow'),'0','Known zero spending retains its real zero progress');
      assert.deepEqual(errors, []); await page.close();
    }
  } finally {await browser.close();}
}
console.log('Budget detail: immediate desktop/mobile detail, plan-only preview/save/undo, and fund separation passed in Chromium and WebKit.');
