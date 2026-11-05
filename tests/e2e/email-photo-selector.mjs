import assert from 'node:assert/strict';
import {build} from 'esbuild';
import {chromium} from 'playwright';

const root = new URL('../../', import.meta.url);
const script = await build({entryPoints: [new URL('resources/js/shopify/wholesale-messaging.tsx', root).pathname], bundle: true, format: 'iife', write: false, jsx: 'automatic', loader: {'.css': 'empty'}});
const pixel = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB1sAAAAASUVORK5CYII=', 'base64');
const origin = 'https://email-photo-fixture.invalid';
const sections = [
  {id: 'hero', type: 'image', imageUrl: `${origin}/old.png`, alt: 'Merchant hero description', href: 'https://example.invalid/wholesale'},
  {id: 'heading', type: 'heading', text: 'Welcome'},
  {id: 'studio', type: 'image', imageUrl: `${origin}/studio.png`, alt: 'Studio photo', href: 'https://example.invalid/studio'},
  {id: 'products', type: 'product_grid_4', heading: 'Candle choices', products: [0,1,2,3].map(index => ({title: `Candle ${index}`, imageUrl: `${origin}/product-${index}.png`, href: `https://example.invalid/products/${index}`, buttonLabel: 'View candle'}))},
  ...Array.from({length: 12}, (_, index) => ({id: `copy-${index}`, type: 'text', html: `Fixture copy ${index}`})),
];
let draft = {id: 1, name: 'Fixture email', subject: 'Fixture subject', sections, personalization: {first_name_token: '{{ first_name }}'}, revision: 1, rendered_html: '', locked_footer: true, sender: {from_email: 'sender@example.invalid', from_name: 'Fixture'}};
let photoNumber = 0, failResolve = false, holdResolve = false, releasePhoto, photoStarted;
const saves = [];
const browser = await chromium.launch({headless: true, ...(process.env.PHOTO_PICKER_CHROMIUM ? {executablePath: process.env.PHOTO_PICKER_CHROMIUM} : {})});
try {
  const page = await browser.newPage({viewport: {width: 1440, height: 1100}});
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('**/*', async route => {
    const request = route.request(), url = new URL(request.url());
    assert.equal(url.hostname, 'email-photo-fixture.invalid', 'All browser traffic stays in the fixture');
    const json = (body, status=200) => route.fulfill({status, contentType: 'application/json', body: JSON.stringify(body)});
    if (url.pathname === '/') {
      const bootstrap = {authorized: true, draft, context_token: 'fixture-context', endpoints: {save: '/save', test_send: '/never-send', photo_resolve: '/photo'}};
      return route.fulfill({contentType: 'text/html', body: `<html><body><div id="wholesale-email-messenger-root"></div><script id="wholesale-email-messenger-bootstrap" type="application/json">${JSON.stringify(bootstrap).replace(/</g,'\\u003c')}</script><script>window.__pickerCalls=[];window.__pickerMode='ok';window.shopify={idToken:async()=> 'fresh-fixture-token',intents:{invoke:async(intent,options)=>{window.__pickerCalls.push({intent,options});return {complete:Promise.resolve(window.__pickerMode==='closed'?{code:'closed'}:window.__pickerMode==='error'?{code:'error',message:'Fixture picker unavailable'}:{code:'ok',data:{ids:['gid://shopify/MediaImage/123']}})}}}};</script></body></html>`});
    }
    if (url.pathname.endsWith('.png')) return route.fulfill({contentType: 'image/png', body: pixel});
    if (url.pathname === '/photo') {
      assert.equal(request.headers().authorization, 'Bearer fresh-fixture-token');
      assert.deepEqual(request.postDataJSON(), {id: 'gid://shopify/MediaImage/123', context_token: 'fixture-context'});
      photoStarted?.();
      if (holdResolve) await new Promise(resolve => { releasePhoto = resolve; });
      if (failResolve) return json({ok: false, message: 'Fixture photo unavailable'}, 422);
      return json({ok: true, data: {id: 'gid://shopify/MediaImage/123', url: `${origin}/selected-${++photoNumber}.png`, alt: 'Library description'}});
    }
    if (url.pathname === '/save') {
      const body = request.postDataJSON();
      assert.equal(body.revision, draft.revision);
      draft = {...draft, ...body, revision: draft.revision + 1};
      saves.push(structuredClone(draft));
      return json({ok: true, data: draft});
    }
    throw new Error(`Unexpected fixture request: ${url.pathname}`);
  });
  const mount = async () => { await page.goto(origin); await page.addStyleTag({path: new URL('resources/js/shopify/wholesale-messaging.css', root).pathname}); await page.addScriptTag({content: script.outputFiles[0].text}); };
  const save = async () => { const count = saves.length; const response = page.waitForResponse(response => response.url() === `${origin}/save`); await page.getByRole('button', {name: 'Save draft', exact: true}).click(); await response; await page.waitForFunction(() => document.querySelector('.wem-notice')?.textContent?.includes('Draft saved')); assert.equal(saves.length, count + 1); };
  await mount();
  assert.equal(await page.getByLabel('Image URL', {exact: true}).count(), 0);
  await page.getByRole('button', {name: 'Change photo', exact: true}).click();
  await page.locator('.wem-inspector .wem-selected-photo img').filter({visible: true}).waitFor();
  await page.waitForFunction(() => document.querySelector('.wem-inspector .wem-selected-photo img')?.src.includes('selected-1.png'));
  assert.deepEqual(await page.evaluate(() => window.__pickerCalls[0]), {intent: 'pick:shopify/File', options: {data: {mediaTypes: ['MediaImage'], multiSelect: false}}});
  await save();
  assert.deepEqual(saves.at(-1).sections[0], {...sections[0], imageUrl: `${origin}/selected-1.png`});
  assert.deepEqual(saves.at(-1).sections.slice(1), sections.slice(1));

  await page.evaluate(() => {window.__pickerMode='closed';});
  await page.getByRole('button', {name: 'Change photo', exact: true}).click();
  await page.getByRole('button', {name: 'Save draft', exact: true}).waitFor({state: 'visible'});
  await save();
  assert.equal(saves.at(-1).sections[0].imageUrl, `${origin}/selected-1.png`);
  await page.evaluate(() => {window.__pickerMode='ok';});
  failResolve = true;
  await page.getByRole('button', {name: 'Change photo', exact: true}).click();
  await page.getByRole('alert').filter({hasText: 'Fixture photo unavailable'}).waitFor();
  await save();
  assert.equal(saves.at(-1).sections[0].imageUrl, `${origin}/selected-1.png`);
  failResolve = false;

  await page.locator('.wem-blocks button').filter({hasText: 'Candle choices'}).click();
  assert.equal(await page.getByLabel('Image URL', {exact: true}).count(), 0);
  await page.getByRole('button', {name: 'Change photo', exact: true}).nth(2).click();
  await page.waitForFunction(() => [...document.querySelectorAll('.wem-inspector .wem-selected-photo img')][2]?.src.includes('selected-2.png'));
  await save();
  const products = saves.at(-1).sections[3].products;
  assert.equal(products[2].imageUrl, `${origin}/selected-2.png`);
  assert.deepEqual(products.map((product,index)=>index===2?{...product,imageUrl:sections[3].products[2].imageUrl}:product), sections[3].products);

  await page.locator('.wem-blocks button').first().click();
  holdResolve = true;
  const requested = new Promise(resolve => { photoStarted = resolve; });
  await page.getByRole('button', {name: 'Change photo', exact: true}).click();
  await requested;
  assert.equal(await page.getByRole('button', {name: 'Save draft', exact: true}).isDisabled(), true);
  await page.locator('.wem-blocks button').filter({hasText: 'Studio photo'}).click();
  releasePhoto(); holdResolve = false;
  await page.waitForFunction(() => !document.querySelector('.wem-toolbar button:last-child')?.disabled);
  await save();
  assert.equal(saves.at(-1).sections[0].imageUrl, `${origin}/selected-3.png`);
  assert.deepEqual(saves.at(-1).sections[2], sections[2]);
  await mount();
  assert.equal(await page.locator('.wem-inspector .wem-selected-photo img').getAttribute('src'), `${origin}/selected-3.png`);
  await page.getByRole('button', {name: 'Mobile preview', exact: true}).click();
  await page.locator('.wem-canvas-panel.wem-mobile').waitFor();
  assert.deepEqual(errors, []);
  console.log('PASS email photos: native image-only picker, selection persistence, cancellation, failure recovery, product isolation, pending-save guard and section-switch race. No email sent.');
} finally { await browser.close(); }
