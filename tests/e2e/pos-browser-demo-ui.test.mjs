import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = name => readFileSync(new URL(`../../resources/${name}`, import.meta.url), 'utf8');
const context = vm.createContext({ Intl });
for (const name of ['pos-register.js', 'pos-browser-demo-engine.js', 'pos-browser-demo.js']) vm.runInContext(source(`js/${name}`), context);
const UI = context.PosBrowserDemoUI;
class Element {
    constructor(tag = 'div') { this.tagName = tag; }
    children = []; dataset = {}; attributes = {}; listeners = {}; value = ''; hidden = false; disabled = false; checked = false; open = false; _text = '';
    classList = { add: () => {}, toggle: () => {} };
    set textContent(text) { this._text = String(text); this.children = []; }
    get textContent() { return this._text + this.children.map(child => child.textContent).join(' '); }
    set innerHTML(_) { throw new Error('Dynamic HTML is forbidden.'); }
    append(...items) { this.children.push(...items); }
    replaceChildren(...items) { this._text = ''; this.children = items; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    addEventListener(name, listener) { (this.listeners[name] ||= []).push(listener); }
    dispatch(name, extras = {}) { const event = { prevented: false, preventDefault() { this.prevented = true; }, ...extras }; for (const listener of this.listeners[name] || []) listener(event); return event; }
    querySelector(tag) { return this.all().find(el => el.tagName === tag) || null; }
    all() { return this.children.flatMap(child => [child, ...child.all()]); }
    focus() { this.focused = true; }
    showModal() { this.open = true; }
    close() { this.open = false; }
    scrollIntoView() {}
}
function harness(saved = null, storageFailure = false) {
    const ids = Object.fromEntries([...source('views/pos/browser-demo.blade.php').matchAll(/<([a-z]+)[^>]*\bid="([^"]+)"/g)].map(([, tag, id]) => [id, new Element(tag)]));
    ids['sku-form'].append(new Element('button'));
    const tabs = ['register', 'history', 'inventory', 'setup'].map(name => { ids[`tab-${name}`].dataset.tab = name; ids[`view-${name}`].dataset.view = name; return ids[`tab-${name}`]; });
    const tenders = ['card', 'cash'].map(value => Object.assign(new Element('input'), { value, checked: value === 'card' }));
    const document = { getElementById: id => ids[id], createElement: tag => new Element(tag), createElementNS: (_, tag) => new Element(tag),
        querySelectorAll: selector => selector === '[data-tab]' ? tabs : selector === '[data-view]' ? ['register', 'history', 'inventory', 'setup'].map(name => ids[`view-${name}`]) : selector === 'input[name="demo_tender"]' ? tenders : [],
        querySelector: selector => selector === 'input[name="demo_tender"]:checked' ? tenders.find(input => input.checked) : null };
    const memory = new Map([['unrelated-everbranch-key', 'KEEP'], ...(saved ? [[UI.STORAGE_KEY, saved]] : [])]);
    const operations = [], sessionStorage = { getItem: key => memory.get(key) ?? null, setItem: (key, value) => { operations.push(['set', key]); if (storageFailure) throw new Error('Denied'); memory.set(key, value); }, removeItem: key => { operations.push(['remove', key]); memory.delete(key); } };
    let prints = 0; const mounted = UI.mount({ document, host: { sessionStorage, print: () => prints++ } });
    const findButton = (id, label) => [ids[id], ...ids[id].all()].find(el => el.tagName === 'button' && el.textContent === label);
    const click = (id, label) => { const el = label ? findButton(id, label) : ids[id]; assert.ok(el, `Missing ${id}: ${label}`); assert.equal(el.disabled, false, `Disabled ${label}`); el.dispatch('click'); };
    const sku = code => { ids['sku-entry'].value = code; const event = ids['sku-form'].dispatch('submit'); assert.equal(event.prevented, true); };
    const pay = () => { const event = ids['checkout-form'].dispatch('submit'); assert.equal(event.prevented, true); };
    return { ...mounted, ids, tenders, memory, operations, click, sku, pay, prints: () => prints };
}

test('actual mounted register adds exact SKU, approves one identified card sale and renders its printable receipt/history', () => {
    const h = harness(); assert.equal(h.engine.snapshot().attempts.length, 0); assert.equal(h.ids['checkout-button'].disabled, true);
    h.sku('ALPHA-BC-250'); assert.equal(h.engine.snapshot().basket[0].quantity, 1); assert.equal(h.engine.snapshot().attempts.length, 0);
    h.ids['sale-customer'].value = 'demo-customer-1'; h.ids['sale-customer'].dispatch('change'); h.pay();
    assert.equal(h.engine.snapshot().activeAttemptId, 'demo-payment-1'); h.click('payment-activity', 'Approve demo card');
    assert.equal(h.engine.snapshot().catalog[0].stock, 19); assert.equal(h.engine.history('demo-customer-1').paidSaleCount, 1);
    h.click('payment-activity', 'View / print demo receipt'); assert.equal(h.ids['receipt-dialog'].open, true);
    assert.match(h.ids['demo-receipt'].textContent, /SIMULATION — NO MONEY MOVED/); assert.match(h.ids['demo-receipt'].textContent, /Jordan Sample/);
    h.click('receipt-print'); assert.equal(h.prints(), 1); h.click('receipt-close'); assert.equal(h.ids['receipt-dialog'].open, false);
    assert.equal(h.memory.get('unrelated-everbranch-key'), 'KEEP'); assert.ok(h.operations.every(([, key]) => key === UI.STORAGE_KEY));
});

test('unknown card restore blocks replacement, reconciles original once and keeps guest outside identified history', () => {
    const h = harness(); h.sku('ALPHA-POSTER-A3'); h.pay(); h.click('payment-activity', 'Simulate lost response');
    assert.equal(h.ids['checkout-button'].disabled, true); assert.equal(h.engine.snapshot().catalog[2].stock, 30);
    const restored = harness(h.memory.get(UI.STORAGE_KEY)); assert.equal(restored.ids['checkout-button'].disabled, true);
    assert.equal(restored.engine.snapshot().attempts[0].status, 'unknown'); restored.click('payment-activity', 'Reconcile original sale');
    assert.equal(restored.engine.snapshot().catalog[2].stock, 29); assert.equal(restored.engine.snapshot().attempts.length, 1);
    assert.equal(restored.engine.history('demo-customer-1').paidSaleCount, 0); assert.match(restored.ids['payment-activity'].textContent, /Demo sale confirmed/);
});

test('cash UI rejects underpayment, freezes correct change and manager simulation enables one partial refund', () => {
    const h = harness(); h.sku('ALPHA-POSTER-A3'); h.tenders[0].checked = false; h.tenders[1].checked = true; h.tenders[1].dispatch('change');
    h.ids['cash-received'].value = '1.00'; h.pay(); assert.equal(h.engine.snapshot().attempts.length, 0);
    h.ids['cash-received'].value = '20.00'; h.pay(); assert.equal(h.engine.receipt('demo-payment-1').changeCents, 51);
    assert.match(h.ids['payment-activity'].textContent, /Change \$0.51/);
    h.ids['demo-role'].value = 'manager'; h.ids['demo-role'].dispatch('change'); h.click('payment-activity', 'Rehearse partial refund');
    h.ids['refund-amount'].value = '5.00'; h.ids['refund-form'].dispatch('submit'); assert.equal(h.ids['refund-dialog'].open, false);
    assert.equal(h.engine.receipt('demo-payment-1').refundedCents, 500); assert.equal(h.engine.snapshot().catalog[2].stock, 29);
    h.receipt('demo-payment-1'); assert.match(h.ids['demo-receipt'].textContent, /Confirmed demo refunds \$5.00/);
});

test('invalid SKU cannot implicitly prepare checkout or mutate basket; generated customers stay fictional', () => {
    const h = harness(); h.sku('UNKNOWN-SKU'); assert.equal(h.engine.snapshot().attempts.length, 0); assert.equal(h.engine.snapshot().basket.length, 0);
    h.click('generate-customer'); assert.equal(h.engine.snapshot().customers.length, 3); assert.equal(h.engine.snapshot().selectedCustomerId, 'demo-customer-3');
    assert.equal(h.ids['sale-customer'].children.at(-1).textContent, 'Sample Customer 3');
});

test('storage failure shows memory warning and reset confirmation removes only the demo namespace', () => {
    const h = harness(null, true); h.sku('ALPHA-SHIRT'); assert.equal(h.ids['storage-warning'].hidden, false);
    h.click('reset-open'); assert.equal(h.ids['reset-dialog'].open, true); assert.equal(h.engine.snapshot().basket.length, 1);
    h.click('reset-close'); assert.equal(h.engine.snapshot().basket.length, 1);
    h.click('reset-open'); h.click('reset-confirm'); assert.equal(h.engine.snapshot().basket.length, 0);
    assert.equal(h.memory.get('unrelated-everbranch-key'), 'KEEP'); assert.ok(h.operations.every(([, key]) => key === UI.STORAGE_KEY));
});

test('tab keyboard navigation and denied browser storage access work without external requests', () => {
    const h = harness(); h.ids['tab-register'].dispatch('keydown', { key: 'ArrowRight' });
    assert.equal(h.ids['view-history'].hidden, false); assert.equal(h.ids['tab-history'].focused, true);
    const denied = {}; Object.defineProperty(denied, 'sessionStorage', { get() { throw new Error('Denied'); } });
    const store = UI.sessionStore(denied); assert.equal(store.available, false); assert.equal(store.save({}), false);
    assert.equal(/\b(fetch|XMLHttpRequest|WebSocket|sendBeacon)\s*\(/.test(source('js/pos-browser-demo.js')), false);
});
