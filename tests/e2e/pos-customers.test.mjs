import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = vm.createContext({});
vm.runInContext(readFileSync(new URL('../../resources/js/pos-customers.js', import.meta.url), 'utf8'), context);
const { bind, choices } = context.PosCustomers;
const key = '11111111-1111-4111-8111-111111111111';
const nextKey = '22222222-2222-4222-8222-222222222222';
const customer = { id: 73, name: 'Fictional Shopper', email: 'shopper@example.test', phone: null };
class Element {
    children = []; events = {}; value = ''; textContent = ''; hidden = false; disabled = false;
    append(...items) { this.children.push(...items); }
    replaceChildren(...items) { this.children = items; }
    addEventListener(name, listener) { (this.events[name] ||= []).push(listener); }
    dispatch(name, event = {}) { for (const listener of this.events[name] || []) listener(event); }
    focus() { this.focused = true; }
    set innerHTML(_) { throw new Error('Untrusted customer markup is forbidden.'); }
    querySelector(selector) { const match = selector.match(/^\[name="(.*)"\]$/); return this.children.find(child => child.name === match?.[1]); }
}
const document = { createElement: () => new Element() };
function setup(overrides = {}) {
    const refs = Object.fromEntries(['saleForm', 'selectedId', 'selectedName', 'selectedContact', 'clear', 'history', 'picker', 'open', 'close',
        'searchInput', 'searchButton', 'searchFeedback', 'results', 'createForm', 'createButton', 'newButton', 'createFeedback', 'recovery'].map(name => [name, new Element()]));
    refs.createForm.append(...['request_id', 'first_name', 'last_name', 'email', 'phone'].map(name => Object.assign(new Element(), { name, value: name === 'request_id' ? key : name === 'first_name' ? 'Fictional' : '' })));
    const stored = [];
    const config = { ...refs, historyUrl: '/pos/registers/4/customers/0/history?tenant=fictional', scope: 'opaque-create-uuid', freshKey: key,
        search: async () => ({ customers: [customer], has_more: false }), create: async () => ({ customer }), findCreation: async () => null,
        storage: { getItem: () => null, setItem: (scope, value) => stored.push([scope, value]), removeItem() {} }, newKey: () => nextKey, document, ...overrides };
    const bound = bind(config);
    return { ...refs, bound, stored, field: name => refs.createForm.querySelector(`[name="${name}"]`) };
}
const submitPrevented = form => { let prevented = false; form.dispatch('submit', { preventDefault: () => prevented = true }); return prevented; };

test('native search results use text content and never select a customer without a click', () => {
    const container = new Element(); let selected = null;
    choices({ container, customers: [{ ...customer, name: '<img src=x onerror=alert(1)>' }], select: value => selected = value, document });
    assert.equal(container.children[0].children[0].textContent, '<img src=x onerror=alert(1)>');
    assert.equal(selected, null);
    container.children[0].children[2].dispatch('click'); assert.equal(selected.id, 73);
});

test('customer search Enter cannot implicitly submit checkout and delayed results never override a newer explicit choice', async () => {
    let resolve; const s = setup({ search: () => new Promise(done => resolve = done) }); await s.bound.ready;
    s.searchInput.value = 'Fictional'; let prevented = false, stopped = false;
    s.searchInput.dispatch('keydown', { key: 'Enter', preventDefault: () => prevented = true, stopPropagation: () => stopped = true });
    assert.equal(prevented, true); assert.equal(stopped, true); assert.equal(submitPrevented(s.saleForm), true);
    s.bound.select({ ...customer, id: 99, name: 'Explicit newer choice' });
    resolve({ customers: [customer], has_more: false }); await new Promise(done => setImmediate(done));
    assert.equal(s.selectedId.value, '99'); assert.equal(s.selectedName.textContent, 'Explicit newer choice');
    assert.equal(submitPrevented(s.saleForm), false);
});

test('pending native creation blocks sale submit and a newer guest choice survives successful response', async () => {
    let resolve; const s = setup({ create: () => new Promise(done => resolve = done) }); await s.bound.ready;
    const request = s.bound.performCreate(); assert.equal(submitPrevented(s.saleForm), true);
    s.bound.select(null); resolve({ customer }); await request;
    assert.equal(s.selectedId.value, ''); assert.equal(s.selectedName.textContent, 'Guest checkout');
    assert.equal(s.createButton.disabled, true); assert.equal(s.newButton.hidden, false);
    assert.equal(submitPrevented(s.saleForm), false); assert.equal(s.stored.length, 1); assert.equal(s.stored[0][1], key);
});

test('explicit create and select uses only returned server ID and a separate later choice creates a fresh UUID', async () => {
    const s = setup(); await s.bound.ready; await s.bound.performCreate();
    assert.equal(s.selectedId.value, '73'); assert.equal(s.history.href, '/pos/registers/4/customers/73/history?tenant=fictional');
    s.newButton.dispatch('click'); assert.equal(s.field('request_id').value, nextKey); assert.equal(s.field('first_name').value, '');
    assert.equal(s.createButton.disabled, false);
    s.clear.dispatch('click'); assert.equal(s.selectedId.value, ''); assert.equal(s.history.hidden, true);
});

test('lost response reload recovers opaque creation UUID and offers existing customer without automatically selecting', async () => {
    let found; const s = setup({ storage: { getItem: () => key, setItem() {}, removeItem() {} }, findCreation: async value => { found = value; return customer; } });
    await s.bound.ready;
    assert.equal(found, key); assert.equal(s.createButton.disabled, true); assert.equal(s.newButton.hidden, false); assert.equal(s.selectedId.value, '');
    s.recovery.children[0].children[2].dispatch('click'); assert.equal(s.selectedId.value, '73');
});

test('uncertain creation preserves the same key and immutable terms while allowing a safe exact retry', async () => {
    const requests = []; let count = 0;
    const s = setup({ create: async input => { requests.push({ ...input }); if (++count === 1) throw new Error('Response lost'); return { customer }; } });
    await s.bound.ready; await s.bound.performCreate();
    assert.equal(s.field('first_name').readOnly, true); assert.equal(s.field('request_id').value, key);
    await s.bound.performCreate(); assert.deepEqual(requests[0], requests[1]); assert.equal(s.selectedId.value, '73');
    assert.ok(s.stored.every(([, value]) => /^[0-9a-f-]{36}$/.test(value)));
});

test('saved creation lookup failure blocks duplicate creation and sale until it can be recovered', async () => {
    const s = setup({ storage: { getItem: () => key }, findCreation: async () => { throw new Error('Unavailable'); } });
    await s.bound.ready; assert.equal(s.createButton.disabled, true); assert.equal(submitPrevented(s.saleForm), true);
    assert.match(s.createFeedback.textContent, /cannot be checked/);
});

test('browser storage denial does not prevent explicit in-page customer creation', async () => {
    Object.defineProperty(context, 'sessionStorage', { configurable: true, get: () => { throw new Error('Denied'); } });
    const s = setup({ storage: null }); await s.bound.ready; await s.bound.performCreate();
    assert.equal(s.selectedId.value, '73'); delete context.sessionStorage;
});


test('in-flight edits cannot change the immutable customer terms retried after a lost response', async () => {
    const requests = []; let reject;
    const s = setup({ create: input => { requests.push({ ...input }); return requests.length === 1 ? new Promise((_, fail) => reject = fail) : Promise.resolve({ customer }); } });
    await s.bound.ready; const pending = s.bound.performCreate();
    assert.equal(s.field('first_name').readOnly, true);
    s.field('first_name').value = 'Attempted in-flight edit'; reject(new Error('Response lost')); await pending;
    assert.equal(s.field('first_name').value, 'Fictional'); await s.bound.performCreate();
    assert.deepEqual(requests[0], requests[1]);
});
