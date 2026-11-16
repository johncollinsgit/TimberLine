import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import {questionSummaryHtml, supportBusinessChoices, monthlyBudgetRecords} from '../../resources/js/trajectory/question-summary.js';

const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[c]));
const precise = value => value === null ? 'Unavailable' : `USD ${(value / 100).toFixed(2)}`;
const btn = (label, action, attrs = '') => `<button data-action="${action}" ${attrs}>${esc(label)}</button>`;
const render = summary => questionSummaryHtml({summary, esc, precise, btn});
const fixture = () => ({
  title: 'Questions about Example Workshop',
  context: 'Example household and business · selected year through today',
  answers: [
    {key:'family_received', question:'How much did the family receive from the business?', status:'partial', amount_cents:12345, text:'Net payroll and owner draws, counted once.', basis:'Reviewed bank receipts in the selected period.', missing:['The second bank statement.'], evidence_ids:[12, 18]},
    {key:'operating_plan', question:'What does the business need each month?', status:'draft', amount_cents:240000, text:'A draft operating budget, excluding debt and owner draws.', basis:'Monthly targets awaiting review.', missing:[], evidence_ids:[]},
    {key:'extra_borrowing', question:'How much extra debt was added this year?', status:'unavailable', amount_cents:null, text:'An opening balance for the same debts is needed.', basis:'Current balances alone do not establish a change.', missing:['Opening balances.'], evidence_ids:[]},
    {key:'outside_need', question:'How much income is needed outside the business?', status:'unavailable', amount_cents:null, text:null, basis:'Future business support is not confirmed.', missing:['Expected business support.'], evidence_ids:[]},
    {key:'business_debt', question:'What are the business debt payments?', status:'recorded', amount_cents:37500, text:'Known scheduled payments this month.', basis:'Confirmed payment notices.', missing:[], evidence_ids:[25]},
    {key:'family_debt', question:'What are the household debt payments?', status:'partial', amount_cents:15000, text:'Known payments only.', basis:'Confirmed schedules.', missing:['One card minimum.'], evidence_ids:[]},
    {key:'historical_costs', question:'What did it cost to operate last month?', status:'partial', amount_cents:180000, text:null, basis:'Recorded operating expenses; some statements are missing.', missing:['The supplier account.'], evidence_ids:[31]},
    {key:'potential_savings', question:'Where could spending be reduced?', status:'recorded', amount_cents:0, text:'No reviewed savings opportunities in this period.', basis:'Reviewed spending flags only.', missing:[], evidence_ids:[]},
  ],
  monthly: {
    title:'Monthly costs and projected gaps',
    columns:[{key:'operating_cents', label:'Operating costs', type:'money'}, {key:'debt_cents', label:'Debt payments', type:'money'}, {key:'support_cents', label:'Family support', type:'money'}, {key:'gap_cents', label:'Unfunded need', type:'money'}, {key:'basis', label:'Basis', type:'text'}],
    rows:[
      {label:'January 2028', status:'draft', operating_cents:120000, debt_cents:15000, support_cents:20000, gap_cents:35000, basis:'Draft budget'},
      {label:'February 2028', status:'partial', operating_cents:110000, debt_cents:null, support_cents:0, gap_cents:null, basis:'Incomplete plan'},
    ],
    notes:['Plans are separate from received money.', 'Missing debt payments are not treated as zero.'],
  },
});

test('question answers preserve server amounts, source basis, coverage and true zero', () => {
  const html = render(fixture());
  assert.match(html, /Questions about Example Workshop/);
  assert.match(html, /USD 123\.45/);
  assert.match(html, /Net payroll and owner draws, counted once/);
  assert.match(html, /tr-question-status-partial">Partial/);
  assert.match(html, /tr-question-status-draft">Plan to review/);
  assert.match(html, /tr-question-status-recorded">Recorded/);
  assert.match(html, /tr-question-status-unavailable">Needs information/);
  assert.match(html, /Not established/);
  assert.match(html, /USD 0\.00/);
  assert.match(html, /Reviewed bank receipts/);
  assert.match(html, /Still to confirm/);
  assert.match(html, /1 thing to confirm/);
  assert.doesNotMatch(html, /Total support|Total debt and support/);
});

test('missing and malformed amounts never become a zero or a coerced financial answer', () => {
  for (const value of [null, undefined, '12345', NaN, Infinity, 1.5, Number.MAX_SAFE_INTEGER + 1]) {
    const html = render({answers:[{question:'What is established?', status:'partial', amount_cents:value}]});
    assert.match(html, /Not established/);
    assert.doesNotMatch(html, /USD 0\.00|NaN|Infinity|USD 123\.45/);
  }
  const html = render({answers:[{question:'What changed?', status:'recorded', amount_cents:-1200}]});
  assert.match(html, /USD -12\.00/);
});

test('monthly columns remain separate and unknown costs stay unavailable', () => {
  const html = render(fixture());
  assert.match(html, /<th scope="row">January 2028<\/th>/);
  assert.match(html, /<th scope="col">Operating costs<\/th>/);
  assert.match(html, /<th scope="col">Family support<\/th>/);
  assert.match(html, /<td class="tr-question-money">USD 1200\.00<\/td>/);
  assert.match(html, /<td class="tr-question-money">Unavailable<\/td>/);
  assert.match(html, /Missing debt payments are not treated as zero/);
  assert.match(html, /role="region" aria-labelledby="tr-question-monthly-title" tabindex="0"/);
  assert.doesNotMatch(html, /USD 1900\.00|USD 3500\.00|<tfoot/);
});

test('all supplied text is escaped and evidence actions only retain unique valid IDs', () => {
  const data = fixture();
  data.title = '<script>bad</script>';
  data.context = '<img src=x onerror=bad>';
  data.answers = [{question:'<b>Question</b>', status:'bad" onclick="bad', amount_cents:0, text:'<svg onload=bad>', basis:'<a href="bad">Source</a>', missing:['<script>missing</script>', null, {}], evidence_ids:[12,12,0,-1,1.5,'18',null,18]}];
  data.monthly.columns[0].label = '<img src=x>';
  data.monthly.rows[0].label = '<script>month</script>';
  data.monthly.rows[0].basis = '<script>basis</script>';
  data.monthly.notes = ['<script>note</script>'];
  const html = render(data);
  assert.doesNotMatch(html, /<script>|<img|<svg|<b>Question|onclick=/);
  assert.match(html, /&lt;script&gt;/);
  assert.match(html, /data-action="plan-evidence" data-ids="\[12,18\]"/);
  assert.match(html, /aria-label="View transactions: &lt;b&gt;Question&lt;\/b&gt;"/);
  assert.match(html, /tr-question-status-unavailable/);
});

test('empty or malformed sections do not manufacture facts or duplicate Month and Coverage columns', () => {
  assert.equal(render(null), '');
  assert.equal(render({}), '');
  assert.equal(render({answers:[null, {}, {question:''}], monthly:{columns:[], rows:[{}]}}), '');
  const data = fixture();
  data.answers = [];
  data.monthly.columns.push({key:'label', label:'Duplicate month', type:'text'}, {key:'status', label:'Duplicate status', type:'text'}, {key:'bad', label:'Unknown', type:'currency'}, {key:'debt_cents', label:'Duplicate debt', type:'money'});
  const html = render(data);
  assert.match(html, /Monthly costs and projected gaps/);
  assert.doesNotMatch(html, /Duplicate month|Duplicate status|Unknown<\/th>|Duplicate debt/);
  assert.equal((html.match(/<th scope="col">Month<\/th>/g) || []).length, 1);
  assert.equal((html.match(/<th scope="col">Coverage<\/th>/g) || []).length, 1);
});

test('overview integrates question answers before the existing detailed monthly plan', async () => {
  const app = await fs.readFile(new URL('../../resources/js/trajectory/app.js', import.meta.url), 'utf8');
  assert.match(app, /import \{ questionSummaryHtml, supportBusinessChoices, monthlyBudgetRecords \} from '\.\/question-summary\.js'/);
  const start = app.indexOf('function overview()');
  const end = app.indexOf('function wholeSpacePlanNotice()', start);
  const overview = app.slice(start, end);
  assert.ok(overview.indexOf('questionSummaryHtml(') < overview.indexOf('decisionSupportHtml('));
  assert.match(overview, /accountFilter===null\?questionSummaryHtml/);
});

test('a monthly plan without rows keeps the missing-information explanation instead of showing zero', () => {
  const data = fixture();
  data.answers = [];
  data.monthly.rows = [];
  data.monthly.notes = ['The operating plan still needs review.'];
  const html = render(data);
  assert.match(html, /Monthly figures are not established yet/);
  assert.match(html, /The operating plan still needs review/);
  assert.doesNotMatch(html, /USD 0\.00/);
});

test('business attribution uses only named authorized options and stays a picker without options', async () => {
  assert.deepEqual(supportBusinessChoices(null), [{value:'', label:'Choose a linked business'}]);
  assert.deepEqual(supportBusinessChoices([
    {value:41, label:'Example Workshop'}, {value:41, label:'Duplicate option'},
    {value:42, label:'Second Company'}, {value:43}, {value:'44', label:'Wrong ID shape'},
    {value:0, label:'Invalid'}, {value:45, label:''}, null,
  ]), [{value:'', label:'Choose a linked business'}, {value:41, label:'Example Workshop'}, {value:42, label:'Second Company'}]);
  const app = await fs.readFile(new URL('../../resources/js/trajectory/app.js', import.meta.url), 'utf8');
  assert.match(app, /def\.type==='business_space'\?supportBusinessChoices\(data\.support_business_options\)/);
  assert.match(app, /\['integer','account','business_space'\]\.includes\(def\.type\).*v=Number\(v\)/);
  assert.match(app, /data\.space\.kind==='household'&&data\.definitions\.company_support_source/);
});

// Browser checks run after Playwright installation, separately from the asset build's Node-only checks.
if (process.env.TRAJECTORY_QUESTION_BROWSER === '1') {
  test('question overview is readable, keyboard-operable and contained at desktop and mobile widths', async () => {
    const {chromium} = await import('playwright');
    const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
    const browser = await chromium.launch({headless:true, ...(executablePath ? {executablePath} : {})});
    try {
      const css = await fs.readFile(new URL('../../resources/js/trajectory/app.css', import.meta.url), 'utf8');
      for (const width of [1280, 390]) {
        const page = await browser.newPage({viewport:{width, height:1000}});
        const businessOptions = supportBusinessChoices([{value:41, label:'Example Workshop'}]);
        const picker = `<label for="support-business">Business providing support<select id="support-business" name="business_space_id">${businessOptions.map(option => `<option value="${esc(option.value)}">${esc(option.label)}</option>`).join('')}</select></label>`;
        await page.setContent(`<html><head><style>*{box-sizing:border-box}body{margin:0;background:white}h1,h2,h3,h4,p{margin:0}${css}</style></head><body><main class="tr-app">${render(fixture())}${picker}</main></body></html>`);
        assert.equal(await page.getByRole('heading', {name:'Questions about Example Workshop', exact:true}).count(), 1);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.equal(await page.locator('.tr-question-list>li').count(), 8);
        const businessPicker = page.getByRole('combobox', {name:'Business providing support'});
        assert.equal(await businessPicker.inputValue(), '');
        assert.equal(await businessPicker.getByRole('option', {name:'Choose a linked business'}).count(), 1);
        assert.equal(await businessPicker.getByRole('option', {name:'41', exact:true}).count(), 0);
        await businessPicker.selectOption('41');
        assert.equal(await businessPicker.inputValue(), '41');
        if (process.env.TRAJECTORY_QUESTION_PREVIEW_DIR) {
          await fs.mkdir(process.env.TRAJECTORY_QUESTION_PREVIEW_DIR, {recursive:true});
          await page.screenshot({path:path.resolve(process.env.TRAJECTORY_QUESTION_PREVIEW_DIR, `question-summary-${width}.png`), fullPage:true});
        }
        const detail = page.locator('.tr-question-details').first();
        const toggle = detail.locator('summary');
        await toggle.focus();
        await page.keyboard.press('Enter');
        assert.equal(await detail.evaluate(el => el.open), true);
        const evidence = detail.getByRole('button', {name:/View transactions:/});
        await evidence.focus();
        assert.equal(await evidence.evaluate(el => el === document.activeElement), true);
        assert.equal(await evidence.getAttribute('data-ids'), '[12,18]');
        const region = page.getByRole('region', {name:'Monthly costs and projected gaps'});
        await region.focus();
        assert.equal(await region.evaluate(el => el === document.activeElement), true);
        assert.equal(await page.locator('th[scope="row"]').count(), 2);
        assert.equal(await page.getByRole('columnheader', {name:'Operating costs', exact:true}).count(), 1);
        if (width === 390) {
          assert.equal(await region.evaluate(el => el.scrollWidth > el.clientWidth), true);
          assert.equal(await page.getByText('Scroll sideways for the full monthly breakdown.').isVisible(), true);
        }
        await page.close();
      }
    } finally {
      await browser.close();
    }
  });
}


test('budget month selection keeps December and Q1 versions separate even under the same source', () => {
  const records=[
    {id:1,kind:'budget',data:{source:'Example worksheet',monthly_cents:90000}},
    {id:2,kind:'budget',data:{source:'Example worksheet',monthly_cents:20000}},
    {id:3,kind:'budget',data:{source:'Reviewed rent',monthly_cents:10000}},
    {id:4,kind:'budget',data:{source:'Expired holiday target',monthly_cents:50000}},
    {id:5,kind:'debt',data:{source:'Example worksheet'}},
  ];
  const summary={monthly:{rows:[
    {month:'2028-12',budget_record_ids:[1,3]},
    {month:'2029-01',budget_record_ids:[2,3]},
  ]}};
  assert.deepEqual(monthlyBudgetRecords(records,summary,'2029-01').map(record=>record.id),[2,3]);
  assert.equal(monthlyBudgetRecords(records,summary,'2029-01').reduce((sum,record)=>sum+record.data.monthly_cents,0),30000);
  assert.deepEqual(monthlyBudgetRecords(records,summary,'2028-12','Example worksheet').map(record=>record.id),[1]);
  assert.deepEqual(monthlyBudgetRecords(records,summary,'2029-02'),[]);
  assert.deepEqual(monthlyBudgetRecords(records,null,'2029-01'),[]);
});
