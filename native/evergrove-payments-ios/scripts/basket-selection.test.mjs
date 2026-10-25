import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// Reuse the existing canonical UI test harness, then inspect nodes created by the
// ACTUAL bundled renderer. No browser, WebKit, network, or desktop is controlled.
const canonicalHarness = readFileSync(new URL('../../../tests/e2e/pos-browser-demo-ui.test.mjs', import.meta.url), 'utf8')
    .split("test('actual mounted register")[0].split('\n')
    .filter(line => !line.startsWith('import ') && !line.startsWith('const source =')).join('\n');
const sandbox = vm.createContext({ assert, vm, Intl,
    source: name => readFileSync(new URL(`../../../resources/${name}`, import.meta.url), 'utf8') });
vm.runInContext(canonicalHarness + '\nglobalThis.nativeHarness = harness; globalThis.nativeElement = Element;', sandbox);
const originalQuery = sandbox.nativeElement.prototype.querySelector;
sandbox.nativeElement.prototype.querySelector = function(selector) {
    if (selector === '.quantity-control > span') return this.all().find(el => el.className === 'quantity-control')?.children.find(el => el.tagName === 'span') || null;
    return originalQuery.call(this, selector);
};
const swift = readFileSync(new URL('../Sources/EvergrovePaymentsCore/PaymentAPITransport.swift', import.meta.url), 'utf8');
const script = swift.match(/public static let readOnlyScript = #"""([\s\S]*?)"""#/)[1];
function read(h) {
    return vm.runInNewContext(script, { document: {
        getElementById: id => h.ids[id],
        querySelectorAll: selector => {
            assert.equal(selector, '#basket-lines .basket-line');
            return h.ids['basket-lines'].children.filter(el => el.className === 'basket-line');
        },
    } });
}

test('fixed native script reads all four actual canonical renderer SKUs and quantities only', () => {
    const h = sandbox.nativeHarness();
    const skus = ['ALPHA-BC-250', 'ALPHA-FLYER-100', 'ALPHA-POSTER-A3', 'ALPHA-SHIRT'];
    skus.forEach((sku, index) => { h.sku(sku); h.engine.setQuantity(sku, index + 1); });
    h.render();
    const before = JSON.stringify(h.engine.snapshot());
    const selection = JSON.parse(read(h));
    assert.deepEqual(selection, { items: skus.map((sku, index) => ({ sku, quantity: index + 1 })) });
    assert.equal(JSON.stringify(h.engine.snapshot()), before, 'handoff cannot change basket or prepare a payment');
    assert.equal(h.engine.snapshot().attempts.length, 0);
    assert.equal(/postMessage|fetch\s*\(|window\.open|evaluateJavaScript/.test(script), false);
});

test('empty or pending canonical browser checkout cannot be handed to native review', () => {
    const h = sandbox.nativeHarness();
    assert.throws(() => read(h), /Basket unavailable/);
    h.sku('ALPHA-BC-250'); h.pay();
    assert.throws(() => read(h), /Basket unavailable/);
});
