import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { classifyParity } from './parity-status.mjs';
const matrix = JSON.parse(readFileSync(new URL('../../docs/architecture/everbranch-team-mobile-parity.json', import.meta.url)));
const summary = JSON.parse(readFileSync(new URL('../../docs/architecture/everbranch-team-parity-status-summary.json', import.meta.url)));
const row = (section, requirement) => matrix.rows.find(row => row.source_section === section && row.requirement === requirement);
test('classification preserves every supplied clause and every milestone exactly once', () => {
  assert.equal(matrix.rows.length, 643); assert.equal(new Set(matrix.rows.map(row => row.id)).size, 643); assert.equal(new Set(matrix.rows.map(row => row.source_section)).size, 36);
  assert.equal(Object.values(summary.counts).reduce((a, b) => a + b, 0), 643);
  assert.equal(Object.values(summary.by_milestone).flatMap(Object.values).reduce((a, b) => a + b, 0), 643);
  for (const item of matrix.rows) assert.ok(Object.keys(summary.counts).includes(item.delivery_status));
  assert.deepEqual(classifyParity(structuredClone(matrix.rows)).counts, summary.counts);
});
test('renderer-independent verification never claims mobile product or production acceptance', () => {
  for (const item of matrix.rows.filter(row => row.delivery_status === 'implemented_and_verified')) { assert.equal(item.verification_scope, 'server_invariant'); assert.ok(item.evidence.includes('tests/')); }
  assert.equal(summary.production_accepted, false); assert.equal(summary.native_renderer_implemented, false); assert.equal(summary.completion_percentage, null);
});
test('new local C behavior remains partial where full native or broader features are missing', () => {
  assert.equal(row(33, 'Recurring tasks').delivery_status, 'partial');
  assert.equal(row(13, 'Optional template').delivery_status, 'partial');
  assert.equal(row(11, 'Blocked-by relationship').delivery_status, 'partial');
  assert.equal(row(11, 'Hide inaccessible dependency details').delivery_status, 'implemented_and_verified');
});
test('unavailable scanners and hardware proof remain blocked rather than counted complete', () => {
  assert.equal(row(18, 'Security scan').delivery_status, 'blocked');
  assert.equal(row(31, 'Encrypted sensitive local database').delivery_status, 'blocked');
  assert.equal(row(28, 'Cold launch under 3 seconds target').delivery_status, 'blocked');
});
test('unverified inherited capabilities are not silently counted as delivered Team features', () => {
  assert.equal(row(3, 'Google sign-in').delivery_status, 'not_started');
  assert.equal(row(33, 'Workflow builder').delivery_status, 'not_started');
});

test('multiple workspace evidence stays partial until native and offline cache acceptance', () => {
  for (const [section, name] of [[3, 'List organizations/workspaces'], [3, 'Cross-tenant isolation'], [34, 'Workspace switch replaces projects/tasks/search/cache']]) {
    const item = row(section, name); assert.equal(item.delivery_status, 'partial'); assert.ok(item.evidence.includes('TeamWorkspaceSwitchTest.php')); assert.ok(item.evidence.includes('workspace-acceptance.mjs'));
  }
  assert.equal(summary.native_renderer_implemented, false);
});

test('follow-up and deep-link evidence remains scoped to local web and shared API', () => {
  for (const [section, name] of [[9, 'Follow-up task'], [18, 'Comment follow-up task'], [25, 'Task deep link'], [25, 'Copy task/project link']]) {
    const item=row(section,name); assert.equal(item.delivery_status,'partial'); assert.ok(item.evidence.includes('TeamFollowUpTest.php'));
  }
  assert.equal(row(25,'Project deep link').delivery_status,'partial');
  assert.equal(row(25,'OS share link').delivery_status,'not_started');
  assert.equal(summary.native_renderer_implemented,false);
});

test('project overview/status evidence preserves resources notifications and native gaps', () => {
  for (const [section,name] of [[13,'Project overview'],[14,'Prior status history'],[14,'Task/milestone references'],[25,'Project deep link']]) { const item=row(section,name); assert.equal(item.delivery_status,'partial'); assert.ok(item.evidence.includes('TeamProjectStatusTest.php')); }
  for(const name of ['Overview resources','Overview important files','Overview milestones','Notify members']) assert.equal(row(14,name).delivery_status,'not_started');
  assert.equal(summary.native_renderer_implemented,false);
});
