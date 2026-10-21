import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = vm.createContext({});
vm.runInContext(readFileSync(new URL('../../resources/js/pos-request-keys.js', import.meta.url), 'utf8'), context);
const { bind } = context.PosRequestKeys;
const oldKey = '11111111-1111-4111-8111-111111111111';
const freshKey = '22222222-2222-4222-8222-222222222222';

class Element {
    children = [];
    events = {};
    dataset = {};
    textContent = '';
    disabled = false;
    append(...items) { this.children.push(...items); }
    prepend(item) { this.children.unshift(item); }
    setAttribute() {}
    addEventListener(name, callback) { this.events[name] = callback; }
    remove() { this.removed = true; }
    querySelectorAll() { return this.buttons || []; }
}
const descendants = element => [element, ...element.children.flatMap(descendants)];
function fixture(status = 'succeeded') {
    const form = new Element();
    const submit = new Element(); form.buttons = [submit];
    const input = new Element(); input.value = freshKey; input.dataset.replay = 'false';
    const values = new Map([['scope', oldKey]]);
    return { form, submit, input, values, options: { form, input, scope: 'scope', freshKey,
        terminalStatuses: ['succeeded', 'failed', 'cancelled'], label: 'simulated refund', nextLabel: 'Prepare another simulated refund',
        storage: { getItem: key => values.get(key), setItem: (key, value) => values.set(key, value) },
        document: { createElement: () => new Element() },
        records: [{ id: 1, request_id: oldKey, status, url: '#refund-1' }],
    } };
}

test('lost successful refund response requires deliberate fresh key after showing the recorded outcome', async () => {
    const f = fixture();
    await bind(f.options);
    assert.equal(f.input.value, oldKey);
    assert.equal(f.submit.disabled, true);
    assert.ok(descendants(f.form).some(element => element.textContent.includes('already recorded as succeeded')));
    assert.ok(descendants(f.form).some(element => element.href === '#refund-1'));
    let prevented = false;
    f.form.events.submit({ preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    const prepare = descendants(f.form).find(element => element.textContent === 'Prepare another simulated refund');
    assert.ok(prepare);
    prepare.events.click();
    assert.equal(f.input.value, freshKey);
    assert.equal(f.values.get('scope'), freshKey);
    assert.equal(f.submit.disabled, false);
});

test('unknown request retains its key and cannot prepare a replacement refund', async () => {
    const f = fixture('unknown');
    await bind(f.options);
    assert.equal(f.input.value, oldKey);
    assert.equal(f.submit.disabled, true);
    assert.equal(f.values.get('scope'), oldKey);
    assert.equal(descendants(f.form).some(element => element.textContent === 'Prepare another simulated refund'), false);
});

test('saved sale outside the rendered recent list is recovered through exact read-only lookup', async () => {
    const f = fixture();
    f.options.records = [];
    const lookups = [];
    f.options.lookup = async key => { lookups.push(key); return { request_id: oldKey, id: 5, status: 'succeeded', url: '/pos/sales/5' }; };
    await bind(f.options);
    assert.deepEqual(lookups, [oldKey]);
    assert.equal(f.submit.disabled, true);
    assert.ok(descendants(f.form).some(element => element.href === '/pos/sales/5'));
    assert.equal(f.input.value, oldKey);
});

test('failed recovery lookup preserves the original key and prevents a replacement submission', async () => {
    const f = fixture();
    f.options.records = [];
    f.options.lookup = async () => { throw new Error('Response lost'); };
    await bind(f.options);
    assert.equal(f.input.value, oldKey);
    assert.equal(f.values.get('scope'), oldKey);
    assert.equal(f.submit.disabled, true);
    assert.ok(descendants(f.form).some(element => element.textContent.includes('Unable to check')));
});

test('an unrecorded request can retry with its original key and a blocked register stays blocked', async () => {
    const f = fixture();
    f.options.records = []; f.options.lookup = async () => null;
    await bind(f.options);
    assert.equal(f.input.value, oldKey);
    assert.equal(f.submit.disabled, false);
    f.options.blocked = true;
    await bind(f.options);
    assert.equal(f.submit.disabled, true);
});
