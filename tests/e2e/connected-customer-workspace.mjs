import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
const base = process.env.CONNECTED_CUSTOMER_QA_BASE_URL || 'http://127.0.0.1:8736';
assert.ok(['localhost','127.0.0.1'].includes(new URL(base).hostname), 'Use the isolated fictional local fixture only.');
const output = process.env.CONNECTED_CUSTOMER_QA_OUTPUT || '/tmp/connected-customer-browser';
await mkdir(output,{recursive:true});
const browser = await chromium.launch({headless:true});
const page = await browser.newPage({viewport:{width:1440,height:1000}});
const errors=[]; page.on('pageerror',error=>errors.push(error.message));
await page.route('**/*',route=>new URL(route.request().url()).origin===new URL(base).origin?route.continue():route.abort());
try {
  await page.goto(base+'/login?tenant=marketing-preview');
  await page.getByRole('textbox',{name:/email/i}).fill('marketing-preview@example.test');
  await page.getByLabel('Password',{exact:true}).fill('Preview-only-2026!');
  await page.getByRole('button',{name:/log in|sign in/i}).click();
  await page.waitForLoadState('networkidle');
  const checked=[];
  for (const width of [1440,390]) {
    await page.setViewportSize({width,height:1000});
    for (const [path,heading] of [
      ['/marketing/desk?tenant=marketing-preview&view=audiences','Marketing desk'],
      ['/workspace/customers/reconciliation?tenant=marketing-preview','Sources & reconciliation'],
      ['/workspace/customers?tenant=marketing-preview&query=Fictional','Customers'],
      ['/workspace/customers/1?tenant=marketing-preview&event_id=123','Fictional event buyer'],
      ['/marketing/event-audiences?tenant=marketing-preview&event_id=123','Event audiences'],
    ]) {
      const response=await page.goto(base+path);
      assert.equal(response.status(),200,path);
      await page.getByRole('heading',{name:heading,exact:true}).first().waitFor();
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false,`${path}@${width} horizontal overflow`);
      await page.screenshot({path:`${output}/${path.split('?')[0].replaceAll('/','-')}-${width}.png`,fullPage:true});
      checked.push(`${path}@${width}`);
    }
    await page.getByRole('button',{name:'Preview audience',exact:true}).click();
    await page.locator('#event-audience-status').filter({hasText:/Preview complete/}).waitFor({timeout:10000}).catch(async error=>{console.error(JSON.stringify({status:await page.locator('#event-audience-status').textContent(),errors}));throw error;});
    assert.ok((await page.locator('#event-audience-members').textContent()).includes('Fictional event buyer'));
    await page.selectOption('[name=kind]','previous_show_nearby');
    await page.selectOption('[name=series_id]',{label:'Fictional recurring show'});
    await page.locator('[name=postal_code]').fill('29201');
    assert.equal(await page.locator('[name=radius_miles]').inputValue(),'50');
    assert.equal(await page.locator('fieldset[data-audience-kinds="event_buyers,captured_contacts"]').isVisible(),false);
    await page.getByRole('button',{name:'Preview audience',exact:true}).click();
    await page.locator('#event-audience-status').filter({hasText:/Preview complete/}).waitFor({timeout:10000}).catch(async error=>{console.error(JSON.stringify({status:await page.locator('#event-audience-status').textContent(),errors}));throw error;});
    assert.ok((await page.locator('#event-audience-members').textContent()).includes('Approximately 0 miles'));
    await page.screenshot({path:`${output}/nearby-preview-${width}.png`,fullPage:true});
  }
  assert.deepEqual(errors,[]);
  console.log(JSON.stringify({checked,preview:'event buyers and approximate 50-mile saved locations',errors,output}));
} finally {await browser.close();}
