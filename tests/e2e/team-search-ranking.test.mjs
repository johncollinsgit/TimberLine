import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const context = {};
vm.createContext(context);
vm.runInContext(fs.readFileSync(new URL('../../public/js/everbranch-search.js', import.meta.url), 'utf8'), context);
const engine = context.EverbranchSearch;
const fixture = JSON.parse(fs.readFileSync(new URL('../Fixtures/team-search-relevance.json', import.meta.url), 'utf8'));
for (const scenario of fixture.cases) test('shared ranking: ' + scenario.query, () => {
  assert.deepEqual(Array.from(engine.search(fixture.documents, scenario.query), item => item.id), scenario.ids);
});
test('deduplication, filters and exact-only short terms', () => {
  assert.equal(engine.search([...fixture.documents, fixture.documents[0]], 'erable').length, 1);
  assert.deepEqual(Array.from(engine.search(fixture.documents, 'pipe', { state: 'active' }), item => item.id), ['task:alpha', 'project:alpha']);
  assert.equal(engine.search(fixture.documents, 'pipo').length, 0);
});
test('deduplicates before filtering and preserves prefix, phrase and tie ordering', () => {
  const docs = [
    { id: 'task:2', type: 'task', title: 'Café maintenance', body: 'Copper pipe repair', completed: true },
    { id: 'task:2', type: 'task', title: 'Café maintenance', body: 'Copper pipe repair', completed: false },
    { id: 'task:1', type: 'task', title: 'Cafe maintenance', body: 'Copper pipe repair', completed: true },
    { id: 'task:3', type: 'task', title: 'Cafe maintenance', body: 'Copper repair pipe', completed: true },
  ];
  assert.equal(engine.search(docs, 'cafe maint', { state: 'active' }).length, 0);
  assert.deepEqual(Array.from(engine.search(docs, 'cafe maint', { state: 'closed' }), x => x.id), ['task:1', 'task:2', 'task:3']);
  assert.deepEqual(Array.from(engine.search(docs, '"copper pipe"'), x => x.id), ['task:1', 'task:2']);
  const sparse = []; sparse[1] = docs[0]; sparse[3] = docs[2];
  assert.deepEqual(Array.from(engine.search(sparse, 'cafe maint'), x => x.id), ['task:1', 'task:2']);
});
test('prepared search observes changed source fields and accepts a caller-parsed query', () => {
  const document = { id: 'task:mutable', identifier: 'TASK-1', type: 'task', title: 'Oak room', body: 'First notes' };
  engine.prepare(document);
  document.title = 'Maple room'; document.body = 'New inspection'; document.identifier = 'TASK-2'; document.updated_at = '2026-10-31T12:00:00Z';
  assert.equal(engine.search([document], 'oak').length, 0);
  assert.equal(engine.search([document], 'maple')[0], document);
  assert.equal(engine.search([document], 'inspection')[0], document);
  assert.equal(engine.search([document], 'TASK-2')[0], document);
  const parsed = { text: 'maple', terms: ['maple'], phrases: [], commandsOnly: false };
  assert(engine.score(document, parsed));
  parsed.phrases = Array(1);
  assert(engine.score(document, parsed));
  document.body = { text: 'Dynamic notes', toString() { return this.text; } };
  engine.prepare(document); document.body.text = 'Changed evidence';
  assert.equal(engine.search([document], 'evidence')[0], document);
});
test('repeated queries still observe live state, type and filter changes', () => {
  const first = { id: 'task:1', type: 'task', title: 'Oak room', completed: false, projects: ['north'] };
  const second = { id: 'task:2', type: 'task', title: 'Oak room', completed: false, projects: ['south'] };
  const docs = [first, second];
  assert.deepEqual(Array.from(engine.search(docs, 'oak'), x => x.id), ['task:1', 'task:2']);
  first.completed = true;
  assert.deepEqual(Array.from(engine.search(docs, 'oak'), x => x.id), ['task:2', 'task:1']);
  first.projects = ['south'];
  assert.equal(engine.search(docs, 'oak', { project: 'north' }).length, 0);
  assert.deepEqual(Array.from(engine.search(docs, 'oak', { state: 'active' }), x => x.id), ['task:2']);
  first.type = 'command';
  assert.deepEqual(Array.from(engine.search(docs, '> oak'), x => x.id), ['task:1']);
  first.type = 'task';
  assert.equal(engine.search(docs, '> oak').length, 0);
  second.title = 'Maple room';
  assert.deepEqual(Array.from(engine.search(docs, 'oak'), x => x.id), ['task:1']);
});
test('phrase requirements and public score arrays cannot reuse or alter another query rank', () => {
  const docs = [{ id: 'task:1', title: 'Copper repair pipe' }, { id: 'task:2', title: 'Copper pipe repair' }];
  assert.equal(engine.search(docs, 'copper pipe').length, 2);
  assert.deepEqual(Array.from(engine.search(docs, '"copper pipe"'), x => x.id), ['task:2']);
  const original = Array.from(engine.search(docs, 'copper pipe'), x => x.id);
  engine.score(docs[0], 'copper pipe')[0] = -100;
  assert.deepEqual(Array.from(engine.search(docs, 'copper pipe'), x => x.id), original);
});
test('caller result edits and changing sparse-array membership retain fresh results', () => {
  const first = { id: 'task:1', title: 'Oak room' }, second = { id: 'task:2', title: 'Oak room' };
  const docs = []; docs[1] = second; docs[3] = first;
  engine.search(docs, 'oak').reverse().pop();
  assert.deepEqual(Array.from(engine.search(docs, 'oak'), x => x.id), ['task:1', 'task:2']);
  delete docs[3];
  assert.deepEqual(Array.from(engine.search(docs, 'oak'), x => x.id), ['task:2']);
  docs[0] = { id: 'task:2', title: 'Maple room' };
  assert.equal(engine.search(docs, 'oak').length, 0);
  docs[0].title = 'Oak room';
  assert.equal(engine.search(docs, 'oak')[0], docs[0]);
});
test('10,000-document local index ranks useful results within 50ms p95', () => {
  const documents = Array.from({ length: 10000 }, (_, i) => ({ id: 'task:' + i, identifier: 'T' + i, type: 'task', title: 'Campus maintenance ' + i, body: 'Facilities inspection notes for Avery Hall' }));
  const durations = [];
  documents.forEach(engine.prepare);
  for (let i = 0; i < 20; i++) { const start = performance.now(); const result = engine.search(documents, 'campus maint'); durations.push(performance.now() - start); assert.equal(result.length, 10000); }
  durations.sort((a, b) => a - b);
  console.log('Local 10k search p95:', durations[18].toFixed(1), 'ms');
  assert(durations[18] < 50, '10k-document local p95 exceeds 50ms');
});
