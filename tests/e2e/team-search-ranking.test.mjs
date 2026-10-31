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
  document.body = { text: 'Dynamic notes', toString() { return this.text; } };
  engine.prepare(document); document.body.text = 'Changed evidence';
  assert.equal(engine.search([document], 'evidence')[0], document);
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
