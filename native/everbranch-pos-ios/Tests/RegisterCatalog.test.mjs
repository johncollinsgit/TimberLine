import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// Exercise the actual bundled page. Native UI tests cover layout/focus; this
// small DOM records catalog output and the identifiers sent over the bridge.
function page() {
    const elements = new Map();
    const element = id => {
        if (!elements.has(id)) elements.set(id, { innerHTML: '', style: {}, scrollTo() {}, focus() {}, addEventListener() {}, insertAdjacentHTML(_, html) { this.innerHTML += html; } });
        return elements.get(id);
    };
    const requests = [];
    const context = vm.createContext({
        document: { getElementById: element, querySelector: () => null },
        window: { webkit: { messageHandlers: { register: { postMessage: body => requests.push(JSON.parse(JSON.stringify(body))) } } } },
    });
    const html = readFileSync(new URL('../App/RegisterDemo/index.html', import.meta.url), 'utf8');
    const script = html.split('<script>')[1].split('</script>')[0].replace(/render\(\);\s*$/, '');
    vm.runInContext(script, context);
    vm.runInContext('render=()=>{};nav=()=>{};', context);
    return { run: code => vm.runInContext(code, context), output: () => element('products').innerHTML, requests };
}
const item = { id: 'gid://shopify/ProductVariant/47465552969987', price: 300, quantity: 2 };
const connected = { register: 'Reviewed show register', items: [item] };

test('connected catalog shows only available register products and excludes unapproved bundles', () => {
    const p = page();
    p.run(`window.updateLiveCatalog(${JSON.stringify(connected)});catalog()`);
    assert.match(p.output(), /Laurel &amp; Fig/);
    assert.doesNotMatch(p.output(), /Autumn No\. 9|Mix &amp; match|Build .* bundle/);
    assert.equal(p.run('products.flatMap(p=>p.variants).filter(v=>v.available).length'), 1);
});

test('server prices and quantity limits replace preview values without accepting unavailable additions', () => {
    const p = page();
    p.run(`window.updateLiveCatalog(${JSON.stringify(connected)});const laurel=products.find(p=>p.name==='Laurel & Fig');for(let i=0;i<5;i++)add(laurel.id,'47465552969987');add(products[0].id,products[0].variants[0].id)`);
    assert.equal(p.run('basket.length'), 1);
    assert.equal(p.run('basket[0].quantity'), 2);
    assert.equal(p.run('basket[0].price'), 300);
    p.run('openNativeCheckout()');
    assert.deepEqual(p.requests, [{ action: 'checkout', items: [{ variant_id: '47465552969987', quantity: 2 }] }]);
});

test('a later unavailable selection stays in the basket for explicit native review', () => {
    const p = page();
    p.run(`window.updateLiveCatalog(${JSON.stringify(connected)});add(products.find(p=>p.name==='Laurel & Fig').id,'47465552969987');window.updateLiveCatalog({register:'Reviewed show register',items:[]})`);
    assert.equal(p.run('basket.length'), 1);
    assert.equal(p.run('basket[0].quantity'), 1);
    p.run('openNativeCheckout()');
    assert.deepEqual(p.requests[0].items, [{ variant_id: '47465552969987', quantity: 1 }]);
});

test('disconnect restores preview state without retaining another register catalog', () => {
    const p = page();
    p.run(`window.updateLiveCatalog(${JSON.stringify(connected)});window.updateLiveCatalog(null);catalog()`);
    assert.match(p.output(), /Autumn No\. 9/);
    assert.match(p.output(), /Build 3 × 8oz candles bundle/);
    assert.equal(p.run('liveCatalog'), null);
});

test('completing the original sale clears the basket before the next customer', () => {
    const p = page();
    p.run(`window.updateLiveCatalog(${JSON.stringify(connected)});add(products.find(p=>p.name==='Laurel & Fig').id,'47465552969987');window.completeNativeSale()`);
    assert.equal(p.run('basket.length'), 0);
    assert.equal(p.run('tab'), 'sell');
    assert.equal(p.requests.length, 0, 'Clearing the selling surface never starts a payment');
});
