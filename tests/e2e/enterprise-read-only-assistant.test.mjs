import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
const script = await readFile(new URL('../../resources/views/enterprise/read-only-assistant-script.blade.php', import.meta.url), 'utf8');
class Node {
    constructor(tag = 'div', text = '') { this.tagName = tag; this.textContent = text; this.children = []; this.value = ''; this.hidden = false; this.disabled = false; }
    append(...nodes) { this.children.push(...nodes); }
    replaceChildren(...nodes) { this.children = nodes; }
    get options() { return this.children; }
    get selectedOptions() { return this.children.filter(row => row.value === this.value); }
    querySelector(selector) { return this.children.find(row => row.value === selector.match(/value="([^"]+)"/)[1]); }
    get text() { return this.textContent + this.children.map(row => row.text).join(' '); }
}
function fixture(mode = 'private') {
    const nodes = new Map(), calls = [];
    const $ = id => { if (!nodes.has(id)) nodes.set(id, new Node()); return nodes.get(id); };
    const context = vm.createContext({ $, URL, presentation: {}, viewer:'user-a',workspace:'tenant-a', area: 'area-a', section: 'ask', nextBase: '/enterprise', nextQuery: () => '?tenant=synthetic',
        location: { origin: 'https://workspace.invalid', href: 'https://workspace.invalid/enterprise-campus/' },
        data: { features: { read_only_assistant: true, read_only_costs: true, resources: true, briefing: true }, buildings: { items: [] } },
        transport: { mode, href: href => href },
        Option: class extends Node { constructor(text, value) { super('option', text); this.value = value; } },
        document: { createElement: tag => new Node(tag) },
        api: (url, body) => new Promise((resolve, reject) => calls.push({url, body, resolve, reject})),
    });
    $('read-only-intent').append(...['overview','building','costs'].map(value => { const node = new Node('option'); node.value = value; return node; }));
    $('read-only-intent').value = 'overview';
    vm.runInContext(script, context);
    const submit = () => $('read-only-form').onsubmit({preventDefault() {}});
    const result = (title = 'Authorized current source') => ({ reply: title, mode:'deterministic_records',provider_dispatch:false,scope:{user_id:'user-a',tenant_id:'tenant-a',area_id:'area-a'},sample_data: true, sources: [], comparisons: [], jobs: [], as_of: '2026-10-23T00:00:00Z', coverage: 'Checked sample', notice: 'No writes' });
    return { $, calls, context, submit, result };
}
test('explicit read uses only a bounded intent and scope; sample never dispatches', async () => {
    const f = fixture();
    assert.equal(f.calls.length, 0);
    const pending = f.submit();
    assert.deepEqual(JSON.parse(JSON.stringify(f.calls[0].body)), {area_id:'area-a', intent:'overview'});
    assert.equal(f.$('read-only-submit').disabled, true);
    f.calls[0].resolve(f.result()); await pending;
    assert.match(f.$('read-only-answer').text, /Authorized current source/);
    assert.equal(f.$('read-only-submit').disabled, false);
    const sample = fixture('sample'); await sample.submit(); assert.equal(sample.calls.length, 0);
});
test('actual reset suppresses same-area delayed financial evidence and clears old answer', async () => {
    const f = fixture(); const pending = f.submit();
    vm.runInContext('resetReadOnlyAssistant()', f.context);
    f.calls[0].resolve(f.result('OLD PRIVATE COST')); await pending;
    assert.equal(f.$('read-only-answer').text, '');
    assert.equal(f.$('read-only-assistant').hidden, true);
});
test('workspace change and newer selection cannot restore an old success or rejection', async () => {
    const f = fixture(); const old = f.submit();
    f.$('read-only-intent').value = 'building'; f.$('read-only-intent').onchange();
    f.$('read-only-building').value = 'current-building';
    const current = f.submit(); f.calls[1].resolve(f.result('NEW BUILDING')); await current;
    f.calls[0].reject(new Error('OLD PRIVATE ERROR')); await old;
    assert.match(f.$('read-only-answer').text, /NEW BUILDING/);
    assert.doesNotMatch(f.$('read-only-status').textContent, /OLD/);
    const changing = f.submit(); f.context.area = 'area-b';
    f.calls[2].resolve(f.result('FORMER AREA')); await changing;
    assert.doesNotMatch(f.$('read-only-answer').text, /FORMER AREA/);
});
test('current denial clears evidence, and current cost-role loss removes financial choice', async () => {
    const f = fixture(); const pending = f.submit();
    f.calls[0].reject(new Error('Access is no longer available')); await pending;
    assert.equal(f.$('read-only-answer').text, '');
    assert.match(f.$('read-only-status').textContent, /no longer available/);
    f.context.data.features.read_only_costs = false;
    vm.runInContext('renderReadOnlyAssistant()', f.context);
    assert.equal(f.$('read-only-cost-option').hidden, true);
    assert.equal(f.$('read-only-cost-option').disabled, true);
});
test('source content is text and links never use external or executable destinations', () => {
    const f = fixture();
    f.context.result = {...f.result('<img src=x onerror=alert(1)>'), sources: [
        {ref:'cost:a', title:'Safe source', url:'https://workspace.invalid/enterprise?selected=a', facts:{amount_minor:1500}},
        {ref:'cost:b', title:'Foreign source', url:'https://external.invalid/private', facts:{}},
        {ref:'cost:c', title:'Executable source', url:'javascript:alert(1)', facts:{}},
    ]};
    vm.runInContext('showReadOnlyAnswer(result)', f.context);
    const all = function* (node) { yield node; for (const child of node.children) yield* all(child); };
    const links = [...all(f.$('read-only-answer'))].filter(node => node.tagName === 'a');
    assert.equal(links.length, 1); assert.match(links[0].href, /^https:\/\/workspace.invalid\//);
    assert.match(f.$('read-only-answer').text, /<img src=x/);
    assert.equal([...all(f.$('read-only-answer'))].some(node => node.tagName === 'img'), false);
});

test('mismatched returned identity and an unexpected paid mode never display an answer', async () => {
    for (const delta of [{scope:{user_id:'other',tenant_id:'tenant-a',area_id:'area-a'}},{provider_dispatch:true},{mode:'model_selected_records'}]) {
        const f = fixture(), pending=f.submit();f.calls[0].resolve({...f.result('UNSAFE RESULT'),...delta});await pending;
        assert.equal(f.$('read-only-answer').text,'');assert.match(f.$('read-only-status').textContent,/does not match/);
    }
});
