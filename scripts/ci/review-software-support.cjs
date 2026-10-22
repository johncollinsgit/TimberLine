const fs = require('fs');
const path = require('path');
const { webkit } = require(process.env.SUPPORT_PLAYWRIGHT_MODULE || 'playwright');
(async () => {
  const browser = await webkit.launch({ headless: true });
  const reports = [];
  fs.mkdirSync('output/support-review', {recursive:true});
  for (const [device, width, height] of [['desktop',1280,960], ['ipad',820,1180], ['phone',390,844]]) {
    const page = await browser.newPage({viewport:{width,height}, reducedMotion:'reduce'});
    await page.route('**/*', route => route.abort());
    const errors=[];
    page.on('pageerror', e=>errors.push(e.message));
    for (const view of ['client','owner','thread']) {
      await page.setContent(fs.readFileSync(path.join('.tmp',`support-${view}.html`),'utf8'));
      const measurements=await page.evaluate(()=>({width:innerWidth, scroll:document.documentElement.scrollWidth, heading:document.querySelector('h1').textContent, labels:[...document.querySelectorAll('input:not([type=hidden]),select,textarea')].every(el=>!!el.labels?.length)}));
      if(measurements.scroll>width) throw Error(`${device}/${view} overflows: ${measurements.scroll}`);
      if(!measurements.labels) throw Error(`${device}/${view} missing form labels`);
      await page.keyboard.press('Tab');
      const focused=await page.evaluate(()=>document.activeElement.tagName);
      await page.screenshot({path:`output/support-review/${view}-${device}.png`,fullPage:true});
      reports.push({device,view,...measurements,keyboardFocus:focused,errors:[...errors]});
    }
    await page.close();
  }
  await browser.close();
  fs.writeFileSync('output/support-review/browser-results.json',JSON.stringify(reports,null,2));
  console.log(JSON.stringify(reports));
})().catch(e=>{console.error(e);process.exit(1)});
