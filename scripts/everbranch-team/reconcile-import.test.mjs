import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { emptyImport, reconcilePage, reconcileAttachment } from './reconcile-import.mjs';
const trusted = { tenantId: 'fictional-a', workspaceId: 'fixture-workspace', actorId: 'fixture-owner', connectionId: 'fixture-grant', mappingVersion: 1, active: true, verified: true, entitled: true, importAllowed: true };
const project = { type: 'project', id: 'fixture-project', raw: { name: 'Fictional project', notes: 'Original historical notes. Amount 0.', html_notes: '<p>Original historical notes. Amount 0.</p>', privacy: 'private' } };
const task = { type: 'task', id: 'fixture-task', raw: { title: 'Fictional work', due_on: '2026-11-01', notes: 'Do not normalize this original.' } };
const page = (records, extra = {}) => ({ family: 'fixture', pageId: 'page-a', records, nextOffset: null, capturedAt: '2026-10-16T12:00:00Z', ...extra });
test('repeat pages and duplicate source records preserve unique identities and originals', () => {
  const first = reconcilePage(emptyImport(), trusted, page([project, task, task]));
  const second = reconcilePage(first, trusted, page([project, task, task]));
  assert.deepEqual(second, first); assert.equal(Object.keys(first.records).length, 2);
  assert.equal(first.counts.created, 2); assert.equal(first.counts.unchanged, 1);
  assert.equal(Object.values(first.records).find(r => r.type === 'project').versions[0].original.notes, project.raw.notes);
});
test('interrupted import resumes committed cursor and reconciles missing edges later', () => {
  const membership = { type: 'membership', id: 'fixture-edge', raw: { project_id: project.id, task_id: task.id } };
  const first = reconcilePage(emptyImport(), trusted, page([membership], { nextOffset: 'opaque-next' }));
  assert.equal(Object.values(first.issues).filter(i => i.status === 'open').length, 2);
  assert.throws(() => reconcilePage(first, trusted, page([project, task], { pageId: 'page-b' })), /pagination_gap/);
  const second = reconcilePage(structuredClone(first), trusted, page([project, task], { pageId: 'page-b', offset: 'opaque-next' }));
  assert.equal(Object.values(second.issues).filter(i => i.status === 'open').length, 0);
  assert.equal(second.checkpoints['["fixture",null]'].complete, true);
});
test('same task in two projects remains one source task', () => {
  const p2 = { ...project, id: 'fixture-project-2' };
  const state = reconcilePage(emptyImport(), trusted, page([project, p2, task,
    { type: 'membership', id: 'edge-a', raw: { task_id: task.id, project_id: project.id, position: 1 } },
    { type: 'membership', id: 'edge-b', raw: { task_id: task.id, project_id: p2.id, position: 9 } }]));
  assert.equal(Object.values(state.records).filter(r => r.type === 'task').length, 1);
  assert.equal(Object.values(state.records).filter(r => r.type === 'membership').length, 2);
});
test('delta evidence retains native changes and historical source versions for review', () => {
  let state = reconcilePage(emptyImport(), trusted, page([task]));
  const native = Object.values(state.records)[0]; native.nativeModified = true;
  state = reconcilePage(state, trusted, page([{ ...task, raw: { ...task.raw, notes: 'New source wording' } }], { family: 'delta', pageId: 'delta-1' }));
  const result = Object.values(state.records)[0];
  assert.equal(result.versions.length, 2); assert.equal(result.acceptedHash, native.acceptedHash);
  assert.equal(state.counts.conflicts, 1); assert.equal(Object.values(state.issues)[0].kind, 'native_source_conflict');
});
test('historical authors never gain login access; missing parent has exception evidence', () => {
  const state = reconcilePage(emptyImport(), trusted, page([{ type: 'identity', id: 'former-author', raw: { name: 'Fictional former author' } },
    { ...task, raw: { ...task.raw, parent_id: 'not-captured' } }]));
  const identity = Object.values(state.records).find(r => r.type === 'identity');
  assert.equal(identity.loginUserId, null); assert.equal(identity.accessGranted, false);
  assert.equal(Object.values(state.issues)[0].kind, 'unresolved_relationship');
});
test('attachment URLs are not byte copies and interrupted bytes remain failed until validated', () => {
  let state = reconcilePage(emptyImport(), trusted, page([task, { type: 'attachment', id: 'fixture-file', raw: { task_id: task.id, name: 'fictional.txt', external_link: 'https://example.invalid/file' } }]));
  const bytes = Buffer.from('fictional attachment'); const digest = createHash('sha256').update(bytes).digest('hex');
  assert.equal(Object.values(state.records).find(r => r.type === 'attachment').copyStatus, 'pending');
  state = reconcileAttachment(state, trusted, 'fixture-file', bytes.subarray(0, 3), digest, bytes.length);
  assert.equal(Object.values(state.records).find(r => r.type === 'attachment').copyStatus, 'failed');
  state = reconcileAttachment(state, trusted, 'fixture-file', bytes, digest, bytes.length);
  assert.equal(Object.values(state.records).find(r => r.type === 'attachment').copyStatus, 'bytes_verified_pending_storage_and_scan');
  assert.equal(Object.values(state.issues)[0].status, 'resolved');
});
test('changed pages, tenant/actor/connection switches and revoked/cancelled access fail closed', () => {
  const state = reconcilePage(emptyImport(), trusted, page([project]));
  assert.throws(() => reconcilePage(state, trusted, page([task])), /page_changed/);
  for (const field of ['tenantId', 'workspaceId', 'actorId', 'connectionId', 'mappingVersion']) assert.throws(() => reconcilePage(state, { ...trusted, [field]: 'other' }, page([project])), /binding_mismatch/);
  for (const field of ['active', 'verified', 'entitled', 'importAllowed']) assert.throws(() => reconcilePage(state, { ...trusted, [field]: false }, page([project])), /access_denied/);
  assert.throws(() => reconcilePage(state, { ...trusted, cancelled: true }, page([project])), /cancelled/);
});
test('validation failure is atomic and does not advance checkpoint', () => {
  const state = emptyImport();
  assert.throws(() => reconcilePage(state, trusted, page([project, { type: 'unknown', id: 'x', raw: {} }])), /invalid_record/);
  assert.deepEqual(state, emptyImport());
});
test('section from another project is quarantined in exception ledger', () => {
  const state = reconcilePage(emptyImport(), trusted, page([project, task,
    { type: 'section', id: 'wrong-section', raw: { project_id: 'another-project' } },
    { type: 'membership', id: 'invalid-edge', raw: { task_id: task.id, project_id: project.id, section_id: 'wrong-section' } }]));
  assert.ok(Object.values(state.issues).some(i => i.kind === 'section_project_mismatch'));
});
