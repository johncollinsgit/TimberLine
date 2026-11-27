/* Standalone fictional mobile shell check. No application API or customer records. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const base = path.resolve(__dirname, '../..');
function fixture() {
  let html = fs.readFileSync(path.join(base, 'resources/views/trajectory/index.blade.php'), 'utf8');
  html = html.replace(/^@php\([^\n]+\)\n/, '').replace(/@if\(isset\(\$trajectorySurface\)[\s\S]*?@endif/, '');
  html = html.replace(/@vite\([^\n]+\)/, '<link rel="stylesheet" href="/app.css"><link rel="stylesheet" href="/household-mobile.css"><link rel="stylesheet" href="/visual-finance.css"><link rel="stylesheet" href="/report-header.css">');
  html = html.replace(/data-spaces="[^"]*"/, 'data-spaces="[]"');
  // Render each named Blade container, rather than depending on loop order.
  // Both navigation surfaces exercise the same five production destinations.
  const destinations = [['overview','Home'], ['analysis','Analysis'], ['budget','Budget'], ['planning','Plan'], ['accounts','Accounts']];
  const primary = destinations.map(([key, label]) => `<button type="button" data-tab="${key}">${label}</button>`).join('');
  html = html.replace(/(<nav class="tr-tabs tr-primary-navigation"[^>]*>)[\s\S]*?<\/nav>/, `$1${primary}</nav>`);
  const tools = [['transactions','Review transactions'],['money-flow','Money flow'],['history','Cash flow & history'],['bills','Bills & goals'],['medical','Medical sharing'],['assets','Wealth & debt'],['business','Business'],['anomalies','Category anomalies'],['profit','Profit & loss'],['sales-tax','Sales tax'],['tax','Tax review'],['companies','Businesses'],['legacy-overview','Detailed forecast']];
  html = html.replace(/(<details class="tr-nav-tools">)[\s\S]*?<\/details>/, `$1<summary>More tools</summary><nav class="tr-tabs" aria-label="Financial tools">${tools.map(([key,label])=>`<button data-tab="${key}">${label}</button>`).join('')}</nav></details>`);
  html = html.replace(/(<select id="tr-range"[^>]*>)[\s\S]*?<\/select>/, `$1${['day','week','month','quarter','year','all','custom'].map(key => `<option value="${key}" ${key === 'month' ? 'selected' : ''}>${key}</option>`).join('')}</select>`);
  html = html.replace(/(<nav class="vf-mobile-nav"[^>]*>)[\s\S]*?<\/nav>/, `$1${primary}</nav>`);
  html = html.replace(/\{\{\s*route\('([^']+)'\)[^}]*\}\}/g, (_, route) => `/${route}`);
  html = html.replace(/\{\{\s*asset\('([^']+)'\)\s*\}\}/g, (_, asset) => `/${asset}`);
  html = html.replace(/\{\{[\s\S]*?\}\}/g, 'Fictional');
  html = html.replace('Loading your finance spaces…', '<section class="tr-panel"><h2>Fictional source review</h2><div class="tr-table-wrap"><table class="tr-table"><tr><th>Source</th><th>Observation date</th><th>Coverage status</th><th>Review action</th></tr><tr><td>Sample statement</td><td>Example date</td><td>Needs review</td><td>Open source</td></tr></table></div></section>');
  return html.replace('</body>', `<script type="module">import {initHouseholdMobile} from '/household-mobile.js';window.mobile=initHouseholdMobile(document.querySelector('#trajectory'));document.querySelector('#tr-context').textContent='Nov 1 – Nov 18 · Fictional baseline';document.querySelector('#tr-account-filter').insertAdjacentHTML('beforeend','<option value="4">Fictional checking</option>');document.querySelector('#tr-content').insertAdjacentHTML('afterbegin','<div class="vf-controls"><label>Year<select data-vf-input="year"><option>2028</option><option>2027</option></select></label></div>');window.mobile.syncControls();document.querySelectorAll('[data-tab]').forEach(b=>b.onclick=()=>document.querySelector('#tr-page-title').textContent=b.dataset.tab);document.querySelector('#tr-range').onchange=e=>{document.querySelector('#tr-date-fields').hidden=e.target.value!=='custom';};</script></body>`);
}
async function main() {
  const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
  const browser = await chromium.launch({headless: true, ...(executablePath ? {executablePath} : {})});
  try {
    const context = await browser.newContext({viewport: {width: 390, height: 844}});
    await context.route('http://trajectory.test/**', async route => {
      const name = new URL(route.request().url()).pathname;
      if (name === '/') return route.fulfill({contentType: 'text/html', body: fixture()});
      if (name === '/welcome.html') {
        const landing = fs.readFileSync(path.join(base, 'resources/views/trajectory/welcome.blade.php'), 'utf8').replace(/\{\{\s*route\('([^']+)'\)[^}]*\}\}/g, (_, name) => '/' + name).replace(/\{\{\s*asset\('([^']+)'\)\s*\}\}/g, (_, name) => '/' + name);
        return route.fulfill({contentType: 'text/html', body: landing});
      }
      if (['/app.css', '/household-mobile.css', '/visual-finance.css', '/report-header.css', '/household-mobile.js','/report-controls.js'].includes(name)) return route.fulfill({path: path.join(base, 'resources/js/trajectory', name.slice(1)), contentType: name.endsWith('.css') ? 'text/css' : 'application/javascript'});
      if (name === '/images/marketplace/studio.jpg') return route.fulfill({path: path.join(base, 'public', name)});
      return route.fulfill({status: 404, body: ''});
    });
    const page = await context.newPage();
    await page.goto('http://trajectory.test/');
    await page.waitForFunction(() => !!window.mobile);
    assert.equal(await page.locator('#tr-mobile-splash').isVisible(), true);
    await page.locator('#tr-splash-skip').click();
    assert.equal(await page.locator('#tr-mobile-splash').isVisible(), false);
    assert.ok(await page.locator('#tr-content').evaluate(node=>node.getBoundingClientRect().top<140),'Phone content begins below a compact header, not a filter stack');
    assert.equal(await page.locator('#tr-report-controls').isVisible(),false);
    assert.equal(await page.locator('#tr-mobile-top-actions #tr-add').count(),1);
    assert.equal(await page.locator('#tr-mobile-top-actions [data-action="vf-inbox"]').count(),1);
    assert.equal(await page.locator('#tr-content .vf-controls').count(),0);
    await page.evaluate(()=>{document.querySelector('#tr-company-label').hidden=false;document.querySelector('#tr-company').innerHTML='<option>Fictional Candle Co</option>';window.mobile.syncControls();});
    assert.match(await page.locator('#tr-mobile-period-label').textContent(),/^Fictional Candle Co · Nov 1/);
    await page.evaluate(()=>{document.querySelector('#tr-company-label').hidden=true;window.mobile.syncControls();});
    await page.getByRole('button',{name:'Dates and view options',exact:true}).click();
    assert.equal(await page.getByRole('dialog',{name:'Dates & view',exact:true}).isVisible(),true);
    assert.equal(await page.locator('#tr-analysis-controls [data-vf-input="year"]').isVisible(),true);
    await page.locator('[data-vf-input="year"]').selectOption('2027');
    await page.locator('#tr-range').selectOption('custom');
    await page.locator('#tr-start').fill('2028-01-01');await page.locator('#tr-end').fill('2028-01-31');
    await page.locator('#tr-account-filter').selectOption('4');
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#tr-report-controls').isVisible(),false);
    assert.equal(await page.locator('#tr-date-toggle').evaluate(node=>node===document.activeElement),true);
    await page.locator('#tr-date-toggle').click();
    assert.equal(await page.locator('#tr-start').inputValue(),'2028-01-01');
    assert.equal(await page.locator('#tr-account-filter').inputValue(),'4');
    await page.mouse.click(4,400);assert.equal(await page.locator('#tr-report-controls').isVisible(),false);
    await page.locator('#tr-date-toggle').click();await page.locator('#tr-range').selectOption('month');
    assert.equal(await page.locator('#tr-report-controls').isVisible(),false);
    await page.locator('#tr-date-toggle').focus();await page.evaluate(()=>window.mobile.syncControls());
    assert.equal(await page.locator('#tr-date-toggle').evaluate(node=>node===document.activeElement),true);
    for(const width of [320,768]){
      await page.setViewportSize({width,height:844});
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
      assert.ok(await page.locator('#tr-content').evaluate(node=>node.getBoundingClientRect().top<140));
      assert.equal(await page.locator('#tr-date-toggle').isVisible(),true);
    }
    await page.setViewportSize({width:390,height:844});
    assert.equal(await page.locator('.tr-table-wrap').evaluate(el => el.scrollWidth > el.clientWidth), true);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    assert.equal(await page.locator('#tr-mobile-drawer').getAttribute('inert'), '');
    await page.locator('#tr-mobile-menu').click();
    assert.equal(await page.locator('#tr-mobile-menu').getAttribute('aria-expanded'), 'true');
    assert.equal(await page.locator('#tr-mobile-drawer').getAttribute('role'), 'dialog');
    assert.equal(await page.locator('.tr-workspace').getAttribute('inert'), '');
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#tr-mobile-menu').getAttribute('aria-expanded'), 'false');
    assert.equal(await page.locator('#tr-mobile-menu').evaluate(el => el === document.activeElement), true);
    await page.locator('#tr-mobile-menu').click();
    await page.locator('#tr-mobile-close').focus();
    await page.keyboard.press('Shift+Tab');
    assert.equal(await page.locator('#tr-mobile-drawer').evaluate(el => el.contains(document.activeElement)), true);
    assert.deepEqual(await page.locator('.tr-primary-navigation [data-tab]').allTextContents(), ['Home','Analysis','Budget','Plan','Accounts']);
    assert.deepEqual(await page.locator('.vf-mobile-nav [data-tab]').allTextContents(), ['Home','Analysis','Budget','Plan','Accounts']);
    await page.locator('#tr-mobile-drawer [data-tab="budget"]').click();
    assert.equal(await page.locator('#tr-page-title').textContent(), 'budget');
    assert.equal(await page.locator('#tr-mobile-menu').getAttribute('aria-expanded'), 'false');
    assert.deepEqual(await page.locator('#tr-range option').evaluateAll(nodes => nodes.map(node => node.value)), ['day','week','month','quarter','year','all','custom']);
    await page.locator('.vf-mobile-nav [data-tab="analysis"]').click();
    assert.equal(await page.locator('#tr-page-title').textContent(), 'analysis');
    assert.equal(await page.locator('#tr-mobile-menu').getAttribute('aria-expanded'), 'false');
    await page.locator('#tr-mobile-menu').click();
    await page.locator('.tr-nav-tools > summary').click();
    await page.locator('#tr-mobile-drawer [data-tab="legacy-overview"]').click();
    assert.equal(await page.locator('#tr-page-title').textContent(), 'legacy-overview');
    await page.locator('#tr-mobile-menu').click();
    await page.locator('#tr-mobile-drawer [data-tab="accounts"]').click();
    assert.equal(await page.locator('#tr-page-title').textContent(), 'accounts');
    assert.equal(await page.locator('#tr-mobile-menu').getAttribute('aria-expanded'), 'false');
    await page.locator('#tr-mobile-menu').click();
    await page.mouse.click(383, 400);
    assert.equal(await page.locator('#tr-mobile-menu').getAttribute('aria-expanded'), 'false');
    await page.evaluate(() => document.querySelector('#tr-content').setAttribute('aria-busy', 'false'));
    await page.reload();
    await page.waitForFunction(() => !!window.mobile);
    assert.equal(await page.locator('#tr-mobile-splash').isVisible(), false);
    await page.setViewportSize({width: 1200, height: 900});
    await page.waitForFunction(() => !document.querySelector('#tr-mobile-drawer').hasAttribute('inert'));
    assert.equal(await page.locator('#tr-mobile-menu').isVisible(), false);
    assert.equal(await page.locator('#tr-mobile-drawer').getAttribute('inert'), null);
    assert.equal(await page.locator('#tr-report-controls').isVisible(),true);
    assert.equal(await page.locator('#tr-date-toggle').isVisible(),false);
    await page.getByRole('button',{name:'Report options',exact:true}).click();
    assert.equal(await page.locator('#tr-analysis-controls .vf-controls [data-vf-input="year"]').isVisible(),true);
    assert.equal(await page.locator('#tr-account-filter').inputValue(),'');
    await page.setViewportSize({width: 390, height: 844});
    await page.emulateMedia({reducedMotion: 'reduce'});
    await page.evaluate(() => { sessionStorage.clear(); });
    await page.reload();
    await page.waitForFunction(() => !!window.mobile);
    assert.equal(await page.locator('#tr-mobile-splash').isVisible(), false);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await page.goto('http://trajectory.test/welcome.html');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    assert.equal(await page.getByRole('link', {name: 'Contact us', exact: true}).getAttribute('href'), '/platform.contact');
    assert.equal(await page.locator('img').first().getAttribute('src'), '/images/marketplace/studio.jpg');
    assert.equal(await page.getByRole('link', {name: 'Privacy', exact: true}).getAttribute('href'), '/legal.privacy');
    console.log('Fictional mobile/landing assertions passed: five primary destinations, phone navigation, splash, skip, drawer, focus, overlay, reporting periods, reduced motion, local image and genuine contact/legal links.');
  } finally { await browser.close(); }
}
main().catch(error => {console.error(error);process.exitCode = 1;});
