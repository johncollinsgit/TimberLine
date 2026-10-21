import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = vm.createContext({ Intl });
for (const filename of ['pos-register.js', 'pos-request-keys.js']) {
    vm.runInContext(readFileSync(new URL(`../../resources/js/${filename}`, import.meta.url), 'utf8'), context);
}
const { addItem, parseCashCents, bindScanner } = context.PosRegisterUI;
const item = { variant_id: 73, title: 'Fictional box', variant_title: 'Small', sku: 'BOX-73', price_cents: 2500, available_quantity: 5 };
class Element {
    constructor(tag = 'div') { this.tagName = tag.toUpperCase(); }
    children = [];
    dataset = {};
    events = {};
    className = '';
    value = '';
    textContent = '';
    append(...children) { this.children.push(...children); }
    prepend(child) { this.children.unshift(child); }
    remove() { this.removed = true; }
    focus() { this.focused = true; }
    setAttribute() {}
    set innerHTML(_) { throw new Error('Untrusted markup insertion is forbidden.'); }
    addEventListener(name, listener) { (this.events[name] ||= []).push(listener); }
    dispatch(name, event) { for (const listener of this.events[name] || []) listener(event); }
    querySelectorAll(selector) {
        const nodes = this.children.flatMap(child => [child, ...child.allChildren()]);
        return nodes.filter(node => selector === '.quantity' ? node.className.split(' ').includes('quantity')
            : selector === 'button[type="submit"]' ? node.tagName === 'BUTTON' && node.type === 'submit'
                : /^\[data-/.test(selector) ? Object.hasOwn(node.dataset, selector.slice(6, -1).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())) : false);
    }
    allChildren() { return this.children.flatMap(child => [child, ...child.allChildren()]); }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
}
const document = { createElement: tag => new Element(tag) };
function scanner(lookup) {
    const form = new Element('form'), input = new Element('input'), button = new Element('button'), feedback = new Element(), tbody = new Element('tbody');
    input.value = 'BOX-73'; let updates = 0;
    const bound = bindScanner({ form, input, button, feedback, tbody, lookup, update: () => updates++, document });
    return { form, input, button, feedback, tbody, bound, updates: () => updates };
}

test('cash input converts decimal dollars to exact bounded integer cents', () => {
    assert.equal(parseCashCents('30.00'), 3000);
    assert.equal(parseCashCents('0.01'), 1);
    assert.equal(parseCashCents('1000000.00'), 100000000);
    for (const value of ['1e2', '-1.00', '2.345', '', '1000000.01', '1,000.00']) assert.equal(parseCashCents(value), null);
});

test('a scanned out-of-browse item is inserted as text and subsequent scans increment its existing row', () => {
    const tbody = new Element('tbody');
    addItem({ tbody, item: { ...item, title: '<img src=x onerror=alert(1)>' }, document });
    assert.equal(tbody.querySelector('[data-product-title]').textContent, '<img src=x onerror=alert(1)>');
    assert.equal(tbody.querySelector('.quantity').value, '1');
    assert.equal(tbody.querySelector('[data-unit-price]').textContent, '$25.00');
    assert.equal(tbody.allChildren().find(node => node.type === 'hidden').name, 'lines[0][variant_id]');
    addItem({ tbody, item, document });
    assert.equal(tbody.querySelectorAll('[data-product]').length, 1);
    assert.equal(tbody.querySelector('.quantity').value, '2');
});

test('stock or response validation failures do not change the basket', () => {
    const tbody = new Element('tbody');
    addItem({ tbody, item: { ...item, available_quantity: 1 }, document });
    assert.throws(() => addItem({ tbody, item: { ...item, available_quantity: 1 }, document }), /exceeds/);
    assert.throws(() => addItem({ tbody, item: { ...item, price_cents: -1 }, document }), /review/);
    assert.equal(tbody.querySelector('.quantity').value, '1');
    assert.equal(tbody.querySelectorAll('[data-product]').length, 1);
});

test('scanner Enter prevents implicit checkout and a failed lookup adds nothing', async () => {
    const s = scanner(async () => { throw new Error('More than one item uses this SKU.'); });
    let prevented = false, stopped = false;
    s.input.dispatch('keydown', { key: 'Enter', preventDefault: () => prevented = true, stopPropagation: () => stopped = true });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(prevented, true); assert.equal(stopped, true);
    assert.equal(s.tbody.querySelectorAll('[data-product]').length, 0);
    assert.equal(s.updates(), 0);
    assert.match(s.feedback.textContent, /More than one/);
});

test('checkout cannot submit while a SKU lookup is pending', async () => {
    let resolveLookup;
    const s = scanner(() => new Promise(resolve => resolveLookup = resolve));
    const pending = s.bound.perform();
    let prevented = false;
    s.form.dispatch('submit', { preventDefault: () => prevented = true });
    assert.equal(prevented, true); assert.equal(s.button.disabled, true);
    resolveLookup(item); await pending;
    prevented = false;
    s.form.dispatch('submit', { preventDefault: () => prevented = true });
    assert.equal(prevented, false); assert.equal(s.button.disabled, false); assert.equal(s.updates(), 1);
});

test('finishing SKU lookup never releases a separate payment-request recovery guard', async () => {
    const s = scanner(async () => item);
    const submit = new Element('button'); submit.type = 'submit'; s.form.append(submit);
    const key = '11111111-1111-4111-8111-111111111111';
    const input = new Element('input'); input.value = key; input.dataset.replay = 'false';
    await context.PosRequestKeys.bind({ form: s.form, input, scope: 'recovery', freshKey: '22222222-2222-4222-8222-222222222222',
        records: [{ request_id: key, status: 'unknown', url: '/pos/sales/1' }], terminalStatuses: ['succeeded'], label: 'practice sale', nextLabel: 'New sale',
        document, storage: { getItem: () => key, setItem() {} } });
    await s.bound.perform();
    let prevented = false; s.form.dispatch('submit', { preventDefault: () => prevented = true });
    assert.equal(prevented, true); assert.equal(submit.disabled, true);
});
