import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile, readFile, rm } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { inspectArchive, verifyManifest, paginationSummary, outputIdentity } from './verify-source-export.mjs';

async function fixture(t) {
  const root = await mkdtemp(path.join(os.tmpdir(), 'everbranch-archive-test-'));
  const source = path.join(root, 'source');
  await mkdir(source);
  t.after(() => rm(root, { recursive: true, force: true }));
  return { root, source, put: (name, data) => writeFile(path.join(source, name), JSON.stringify(data)) };
}

test('heterogeneous wrapper pages preserve byte hashes and one task across two projects', async t => {
  const f = await fixture(t);
  const task = { gid: 't1', resource_type: 'task', notes: 'Private synthetic passage', memberships: [{ project: { gid: 'p1' } }, { project: { gid: 'p2' } }] };
  await f.put('export-plan.json', { workspace_gid: 'w1', projects: [{ gid: 'p1', expected_memberships: 1 }, { gid: 'p2', expected_memberships: 1 }] });
  await f.put('users.json', { tool_result: { structuredContent: { data: [{ gid: 'u1', resource_type: 'user', email: 'private@example.invalid', workspaces: [{ gid: 'w1' }] }] } } });
  await f.put('task-list-p1-page-1.json', { request: { project: 'p1', offset: null }, tool_result: { structuredContent: { data: [task], next_page: null } } });
  await f.put('task-list-p2-page-1.json', { request: { project: 'p2', offset: null }, response: { data: [task], next_page: null } });
  const manifest = await inspectArchive(f.source);
  assert.equal(manifest.report.unique_task_count, 1);
  assert.equal(manifest.report.captured_project_task_memberships, 2);
  assert.equal(manifest.report.referenced_project_task_memberships, 2);
  assert.equal(manifest.report.workspace_selection_observed, true);
  assert.ok(manifest.report.project_coverage.every(project => project.count_matches && project.pagination === 'complete'));
  assert.equal(manifest.report.resources.attachment_bytes.coverage, 'missing_or_unverified');
  assert.equal((await verifyManifest(f.source, manifest)).verified, true);
  await writeFile(path.join(f.source, 'task-list-p1-page-1.json'), '{}');
  const verification = await verifyManifest(f.source, manifest);
  assert.equal(verification.verified, false);
  assert.equal(verification.failures[0].code, 'integrity_mismatch');
});

test('pagination detects missing, ambiguous and disconnected continuation and uncertain start', () => {
  const first = { file: 'page-1.json', request: { offset: null }, hasNext: true, next: { offset: 'opaque' } };
  const last = { file: 'page-2.json', request: { offset: 'opaque' }, hasNext: true, next: null };
  assert.equal(paginationSummary([first]).status, 'missing_continuation');
  assert.equal(paginationSummary([first, last]).status, 'complete');
  assert.equal(paginationSummary([first, last, { ...last, file: 'duplicate.json' }]).status, 'ambiguous_continuation');
  assert.equal(paginationSummary([{ ...first, request: null }, last]).status, 'terminal_chain_unverified_start');
  assert.equal(paginationSummary([{ ...first, next: null }, last]).status, 'disconnected_pages');
  assert.equal(paginationSummary([{ ...first, hasNext: false }]).status, 'unverified_pagination');
});

test('invalid JSON, forbidden capture and absent families remain visible without guessing completeness', async t => {
  const f = await fixture(t);
  await writeFile(path.join(f.source, 'broken.json'), '{');
  await f.put('forbidden.json', { http_status: 403, response: { errors: [{ message: 'Synthetic secret' }] } });
  const manifest = await inspectArchive(f.source);
  assert.deepEqual(manifest.report.issues.map(issue => issue.code).sort(), ['inaccessible', 'invalid_json']);
  assert.equal(manifest.report.failed_capture_count, 1);
  assert.equal(manifest.report.resources.stories.inaccessible_count, null);
  assert.equal(manifest.report.status, 'partial_source_archive');
});

test('workspace references cannot conceal mismatched source objects or unrecognized wrappers', async t => {
  const f = await fixture(t);
  await f.put('export-plan.json', { workspace_gid: 'w1' });
  await f.put('users.json', { response: { data: [{ gid: 'u1', resource_type: 'user', workspaces: [{ gid: 'w1' }] }] } });
  await f.put('project-detail-p1.json', { response: { data: { gid: 'p1', workspace: { gid: 'other' } } } });
  await f.put('content-only.json', { tool_result: { content: [{ type: 'text', text: 'Private unsupported wrapper' }] } });
  const manifest = await inspectArchive(f.source);
  assert.equal(manifest.report.workspace_reference_observed, true);
  assert.equal(manifest.report.source_binding.status, 'mismatch');
  assert.deepEqual(manifest.report.issues.map(issue => issue.code).sort(), ['source_workspace_mismatch', 'unrecognized_response_shape']);
});

test('reviewed provenance establishes first-page request only when pinned source bytes match', async t => {
  const f = await fixture(t);
  await f.put('active-projects-page-1.json', { tool_result: { structuredContent: { data: [{ gid: 'p1' }], next_page: { offset: 'next' } } } });
  await f.put('active-projects-page-2.json', { request: { archived: false, offset: 'next' }, response: { data: [], next_page: null } });
  const sha256 = createHash('sha256').update(await readFile(path.join(f.source, 'active-projects-page-1.json'))).digest('hex');
  await f.put('capture-provenance.json', { version: 1, authority: 'reviewed_coordinator_request_log', captures: { 'active-projects-page-1.json': { sha256, request: { archived: false, offset: null, limit: 100 } } } });
  const verified = await inspectArchive(f.source);
  assert.equal(verified.report.pagination[0].status, 'complete');
  assert.equal(verified.files.find(file => file.file === 'active-projects-page-1.json').request_origin, 'reviewed_sidecar');
  await f.put('active-projects-page-1.json', { tool_result: { structuredContent: { data: [{ gid: 'p2' }], next_page: { offset: 'next' } } } });
  const mismatch = await inspectArchive(f.source);
  assert.equal(mismatch.report.pagination[0].status, 'terminal_chain_unverified_start');
  assert.equal(mismatch.report.issues[0].code, 'capture_provenance_mismatch');
});

test('nested comment/subtask observations and reference IDs cannot become complete family snapshots', async t => {
  const f = await fixture(t);
  await f.put('task-detail-t1.json', { request: { comments_limit: 50 }, tool_result: { structuredContent: { data: { gid: 't1', resource_type: 'task', comments: [{ gid: 's1', text: 'Synthetic private comment', created_by: { gid: 'u1' } }], subtasks: [{ gid: 'child', name: 'Synthetic child' }], attachments: null,
    assignee: { gid: 'u2' }, tags: [{ gid: 'tag1' }], custom_fields: [{ gid: 'field1', display_value: 'Synthetic value' }], dependencies: [{ gid: 'dependency1' }] } } } });
  const manifest = await inspectArchive(f.source);
  assert.equal(manifest.report.unique_task_count, 1);
  assert.equal(manifest.report.resources.subtasks.nested_observed_records, 1);
  assert.equal(manifest.report.resources.stories.nested_observed_records, 1);
  assert.equal(manifest.report.resources.stories.primary_snapshot_records, 0);
  assert.equal(manifest.report.resources.users.distinct_records, 0);
  assert.equal(manifest.report.inventory_references.users.distinct_references, 2);
  assert.equal(manifest.report.inventory_references.tags.distinct_references, 1);
  assert.equal(manifest.report.resources.custom_fields.distinct_records, 0);
  assert.equal(manifest.report.inventory_references.dependencies.distinct_references, 1);
  const comments = manifest.report.nested_family_observations.find(value => value.family === 'stories');
  assert.equal(comments.declared_limit, 50);
  assert.equal(comments.coverage, 'observed_only');
  const attachments = manifest.report.nested_family_observations.find(value => value.family === 'attachments');
  assert.equal(attachments.returned_records, null);
  assert.equal(attachments.limitation, 'null_not_evidence_of_empty_family');
});

test('empty attachment pages establish only their explicit parent enumeration and singular connector comment cap is retained', async t => {
  const f = await fixture(t);
  await f.put('attachment-list-t1-page-1.json', { request: { parent: 't1', offset: null, limit: 100 }, response: { data: [], next_page: null } });
  await f.put('another-capture.json', { source_tool: 'asana_get_attachments', request: { parent: 't2', offset: null, limit: 100 }, response: { data: [], next_page: null } });
  await f.put('task-detail-t1.json', { request: { comment_limit: 50 }, response: { data: { gid: 't1', resource_type: 'task', comments: [] } } });
  const manifest = await inspectArchive(f.source);
  const attachmentGroups = manifest.report.pagination.filter(value => value.group.startsWith('attachments:'));
  assert.equal(attachmentGroups.length, 2);
  assert.deepEqual(attachmentGroups.map(value => value.group).sort(), ['attachments:parent=t1', 'attachments:parent=t2']);
  assert.ok(attachmentGroups.every(value => value.status === 'complete'));
  assert.equal(manifest.report.resources.attachments.distinct_records, 0);
  assert.equal(manifest.report.resources.attachments.coverage, 'missing_or_unverified');
  assert.equal(manifest.report.resources.attachment_bytes.coverage, 'missing_or_unverified');
  assert.equal(manifest.report.nested_family_observations[0].declared_limit, 50);
});

test('manifest rejects path traversal and notices missing or newly unsealed files', async t => {
  const f = await fixture(t);
  await f.put('one.json', {});
  const manifest = await inspectArchive(f.source);
  await f.put('two.json', {});
  const extra = await verifyManifest(f.source, manifest);
  assert.deepEqual(extra.unsealed_files, ['two.json']);
  assert.equal(extra.verified, false);
  assert.equal(extra.snapshot_verified, false);
  assert.equal(extra.verified_sealed_subset, true);
  await rm(path.join(f.source, 'one.json'));
  assert.equal((await verifyManifest(f.source, manifest)).failures[0].code, 'missing_file');
  await assert.rejects(verifyManifest(f.source, { manifest_version: 1, files: [{ file: '../outside.json' }] }), /Unsafe/);
});

test('output identity rejects Windows case aliases before a manifest can be overwritten', async t => {
  const f = await fixture(t);
  const first = await outputIdentity(path.join(f.root, 'Manifest.json'), 'win32');
  const second = await outputIdentity(path.join(f.root, '.', 'manifest.json'), 'win32');
  assert.equal(first.key, second.key);
  if (process.platform !== 'win32') return;
  await f.put('one.json', {});
  const script = fileURLToPath(new URL('./verify-source-export.mjs', import.meta.url));
  const result = spawnSync(process.execPath, [script, 'seal', '--source', f.source, '--manifest', path.join(f.root, 'Manifest.json'), '--report', path.join(f.root, 'manifest.json')], { encoding: 'utf8' });
  assert.equal(result.status, 1);
  await assert.rejects(readFile(path.join(f.root, 'Manifest.json')), { code: 'ENOENT' });
});

test('CLI seals privately, refuses overwrite, and stdout never includes source content', async t => {
  const f = await fixture(t);
  await f.put('users.json', { response: { data: [{ gid: 'u1', resource_type: 'user', name: 'Sensitive synthetic person', email: 'private@example.invalid' }] } });
  const script = fileURLToPath(new URL('./verify-source-export.mjs', import.meta.url));
  const args = ['--source', f.source, '--manifest', path.join(f.root, 'manifest.json'), '--report', path.join(f.root, 'report.json')];
  const sealed = spawnSync(process.execPath, [script, 'seal', ...args], { encoding: 'utf8' });
  assert.equal(sealed.status, 0, sealed.stderr);
  assert.doesNotMatch(sealed.stdout + sealed.stderr, /Sensitive|private@example/);
  const original = await readFile(path.join(f.root, 'manifest.json'), 'utf8');
  assert.equal(spawnSync(process.execPath, [script, 'seal', ...args]).status, 1);
  assert.equal(await readFile(path.join(f.root, 'manifest.json'), 'utf8'), original);
  await f.put('extra.json', {});
  const extras = spawnSync(process.execPath, [script, 'verify', ...args], { encoding: 'utf8' });
  assert.equal(extras.status, 2);
  assert.match(extras.stdout, /integrity_failed/);
  await f.put('users.json', { altered: true });
  const failed = spawnSync(process.execPath, [script, 'verify', ...args], { encoding: 'utf8' });
  assert.equal(failed.status, 2);
  assert.match(failed.stdout, /integrity_failed/);
});
