import test from 'node:test';
import assert from 'node:assert/strict';
import { AsanaPreservationClient, PreservationError, retryAfterMs } from './asana-preservation-client.mjs';

const envelope = { tenantId: 7, connectionId: 9, connectionTenantId: 7, connectionProvider: 'asana', connectionStatus: 'connected', moduleEnabled: true, importEnabled: true, workspaceGid: 'w1', allowedWorkspaceGids: ['w1'], scopes: ['tasks:read', 'stories:read', 'attachments:read'], actor: { userId: 11, tenantId: 7, isActive: true, membershipActive: true, emailVerified: true, role: 'owner' } };
const parent = { tenantId: 7, connectionId: 9, workspaceGid: 'w1', gid: 'p1', resourceType: 'project' };
const taskParent = { ...parent, gid: 't1', resourceType: 'task' };
const page = (data, offset = null) => ({ status: 200, body: JSON.stringify({ data, next_page: offset === null ? null : { offset } }) });

function harness(transport, options = {}) {
  const captures = new Map(), checkpoints = [], gaps = [], events = [], calls = [], waits = [];
  const sink = { capturePage: async value => { events.push('capture'); captures.set(value.captureKey, value); }, saveCheckpoint: async value => { events.push('checkpoint'); checkpoints.push(value); }, recordGap: async value => { gaps.push(value); } };
  const client = new AsanaPreservationClient({ envelope, accessToken: 'synthetic-token-not-a-credential', transport: async request => { calls.push(request); return transport(request, calls.length); }, sink,
    revalidate: async () => true,
    wait: async ms => { waits.push(ms); }, now: () => Date.parse('2026-10-11T00:00:00Z'), random: () => 0, ...options });
  return { client, captures, checkpoints, gaps, events, calls, waits, sink };
}

test('strict envelope, parent and checkpoint boundaries fail before transport', async () => {
  const h = harness(() => page([]));
  assert.throws(() => new AsanaPreservationClient({ envelope: { ...envelope, connectionTenantId: 100 }, accessToken: 'token', transport() {}, sink: h.sink }), error => error.code === 'connection_boundary_mismatch');
  assert.throws(() => new AsanaPreservationClient({ envelope: { ...envelope, actor: { ...envelope.actor, membershipActive: false } }, accessToken: 'token', transport() {}, sink: h.sink }), error => error.code === 'actor_not_authorized');
  assert.throws(() => new AsanaPreservationClient({ envelope: { ...envelope, actor: { ...envelope.actor, isActive: false } }, accessToken: 'token', transport() {}, sink: h.sink }), error => error.code === 'actor_not_authorized');
  await assert.rejects(h.client.enumerate({ family: 'tasks', parent: { ...parent, tenantId: 100 } }), error => error.code === 'parent_boundary_mismatch');
  await assert.rejects(h.client.enumerate({ family: 'tasks', parent, checkpoint: { version: 1, identity: 'other', binding: {} } }), error => error.code === 'checkpoint_boundary_mismatch');
  assert.equal(h.calls.length, 0);
});

test('capture commits before checkpoint; bounded enumeration resumes and replay uses stable capture keys', async () => {
  const h = harness(request => new URL(request.url).searchParams.has('offset') ? page([{ gid: 't2', resource_type: 'task' }]) : page([{ gid: 't1', resource_type: 'task' }], 'next'), { maxPagesPerCall: 1 });
  const first = await h.client.enumerate({ family: 'tasks', parent, fields: ['notes', 'html_notes'] });
  assert.equal(first.status, 'checkpointed');
  assert.equal(first.checkpoint.nextOffset, 'next');
  assert.deepEqual(h.events, ['capture', 'checkpoint']);
  const resumed = await h.client.enumerate({ family: 'tasks', parent, fields: ['notes', 'html_notes'], checkpoint: first.checkpoint });
  assert.equal(resumed.status, 'complete');
  assert.equal(resumed.checkpoint.observedRecordOccurrences, 2);
  const before = h.captures.size;
  await h.client.enumerate({ family: 'tasks', parent, fields: ['notes', 'html_notes'], checkpoint: first.checkpoint });
  assert.equal(h.captures.size, before);
  assert.ok(h.calls.every(request => request.method === 'GET' && request.url.startsWith('https://app.asana.com/api/1.0/')));
  assert.doesNotMatch(JSON.stringify([...h.captures.values()]), /synthetic-token/);
});

test('Retry-After seconds and dates are honored; long delays defer without shortening', async () => {
  const h = harness((_, attempt) => attempt === 1 ? { status: 429, headers: { 'Retry-After': '2' }, body: '{"errors":[]}' } : page([]));
  assert.equal((await h.client.enumerate({ family: 'tasks', parent })).status, 'complete');
  assert.deepEqual(h.waits, [2000]);
  assert.equal(retryAfterMs({ 'retry-after': 'Sun, 11 Oct 2026 00:00:03 GMT' }, Date.parse('2026-10-11T00:00:00Z')), 3000);
  const deferred = harness(() => ({ status: 429, headers: { 'retry-after': '90' }, body: '{"errors":[]}' }));
  const result = await deferred.client.enumerate({ family: 'tasks', parent });
  assert.equal(result.status, 'partial');
  assert.equal(result.gaps[0].code, 'retry_deferred');
  assert.equal(result.gaps[0].retryAfterMs, 90000);
  assert.deepEqual(deferred.waits, []);
  assert.equal(deferred.checkpoints.length, 0);
});

test('cancel during retry retains prior checkpoint and does not issue another request', async () => {
  const controller = new AbortController();
  const h = harness(() => ({ status: 503, body: '{"errors":[]}' }), { wait: async () => { controller.abort(); } });
  const result = await h.client.enumerate({ family: 'tasks', parent, signal: controller.signal });
  assert.equal(result.status, 'cancelled');
  assert.equal(result.checkpoint.nextOffset, null);
  assert.equal(result.checkpoint.pages, 0);
  assert.equal(h.calls.length, 1);
  assert.equal(h.gaps[0].code, 'cancelled');
});

test('expired continuation restarts once with deduplicated raw captures and explicit reset evidence', async () => {
  let expired = false;
  const h = harness(request => {
    const offset = new URL(request.url).searchParams.get('offset');
    if (!offset) return page([{ gid: 't1' }], 'next');
    if (!expired) { expired = true; return { status: 400, body: '{"errors":[{"message":"offset has expired"}]}' }; }
    return page([{ gid: 't2' }]);
  });
  const result = await h.client.enumerate({ family: 'tasks', parent });
  assert.equal(result.status, 'complete');
  assert.equal(result.checkpoint.resets, 1);
  assert.equal(result.checkpoint.enumerationGeneration, 2);
  assert.equal(result.checkpoint.observedRecordOccurrences, 3);
  assert.equal(result.gaps[0].code, 'offset_reset');
  assert.equal(result.gaps[0].recovered, true);
  assert.equal(h.captures.size, 3);
});

test('task evidence isolates inaccessible and missing-scope families without claiming copied attachment bytes', async () => {
  const h = harness(request => request.url.includes('/stories') ? { status: 403, body: '{"errors":[{"message":"private synthetic details"}]}' } : page([]), { envelope: { ...envelope, scopes: ['tasks:read', 'stories:read'] } });
  const result = await h.client.preserveTaskEvidence({ parent: taskParent });
  assert.equal(result.status, 'partial');
  assert.equal(result.results.subtasks.status, 'complete');
  assert.equal(result.results.stories.gaps[0].code, 'inaccessible');
  assert.equal(result.results.attachments.gaps[0].code, 'missing_required_read_scope');
  assert.equal(h.calls.length, 2);
  assert.doesNotMatch(JSON.stringify(h.gaps), /private synthetic|token/);
  assert.match(result.limitation, /attachment bytes/);
});

test('transport failures have bounded retries and never expose injected errors or tokens', async () => {
  const h = harness(() => { throw new Error('Authorization synthetic-token and private source note'); }, { maxRetries: 2 });
  const result = await h.client.enumerate({ family: 'tasks', parent });
  assert.equal(result.gaps[0].code, 'transport_failed');
  assert.equal(h.calls.length, 3);
  assert.deepEqual(h.waits, [500, 1000]);
  assert.doesNotMatch(JSON.stringify(result), /Authorization|synthetic-token|private source/);
});

test('malformed pagination, workspace mismatch and archive-write failure cannot advance checkpoint', async () => {
  const malformed = harness(() => ({ status: 200, body: '{"data":[]}' }));
  assert.equal((await malformed.client.enumerate({ family: 'tasks', parent })).gaps[0].code, 'invalid_pagination_metadata');
  assert.equal(malformed.checkpoints.length, 0);
  const mismatch = harness(() => page([{ gid: 't1', workspace: { gid: 'other' } }]));
  assert.equal((await mismatch.client.enumerate({ family: 'tasks', parent })).gaps[0].code, 'source_workspace_mismatch');
  assert.equal(mismatch.checkpoints.length, 0);
  const failed = harness(() => page([]), { sink: { capturePage: async () => { throw new Error('private filesystem path'); }, saveCheckpoint: async () => assert.fail('must not advance'), recordGap: async () => {} } });
  await assert.rejects(failed.client.enumerate({ family: 'tasks', parent }), error => error instanceof PreservationError && error.code === 'archive_write_failed' && !error.message.includes('private'));
});

test('pagination cycles spanning resumptions remain detectable from durable cursor hashes', async () => {
  const h = harness(() => page([{ gid: 't1' }], 'repeat'), { maxPagesPerCall: 1 });
  const first = await h.client.enumerate({ family: 'tasks', parent });
  const second = await h.client.enumerate({ family: 'tasks', parent, checkpoint: first.checkpoint });
  const third = await h.client.enumerate({ family: 'tasks', parent, checkpoint: second.checkpoint });
  assert.equal(third.status, 'partial');
  assert.equal(third.gaps[0].code, 'pagination_cycle');
  assert.equal(h.calls.length, 2);
});

test('per-request authorization revalidation prevents another page after revoked membership or import gate', async () => {
  let checks = 0;
  const h = harness(() => page([{ gid: 't1' }], 'next'), { revalidate: async () => ++checks === 1 });
  const result = await h.client.enumerate({ family: 'tasks', parent });
  assert.equal(result.status, 'partial');
  assert.equal(result.gaps[0].code, 'authorization_revoked');
  assert.equal(result.checkpoint.nextOffset, 'next');
  assert.equal(h.calls.length, 1);
  assert.throws(() => new AsanaPreservationClient({ envelope: { ...envelope, moduleEnabled: false } }), error => error.code === 'import_not_enabled');
});

test('checkpoint identity includes the verified actor and oversized retry headers safely defer', async () => {
  const firstActor = harness(() => page([], 'next'), { maxPagesPerCall: 1 });
  const checkpoint = (await firstActor.client.enumerate({ family: 'tasks', parent })).checkpoint;
  assert.equal(checkpoint.binding.actorUserId, 11);
  const otherActor = harness(() => page([]), { envelope: { ...envelope, actor: { ...envelope.actor, userId: 12 } } });
  await assert.rejects(otherActor.client.enumerate({ family: 'tasks', parent, checkpoint }), error => error.code === 'checkpoint_boundary_mismatch');
  assert.equal(otherActor.calls.length, 0);
  const huge = harness(() => ({ status: 429, headers: { 'Retry-After': '9'.repeat(500) }, body: '{"errors":[]}' }));
  const deferred = await huge.client.enumerate({ family: 'tasks', parent });
  assert.equal(deferred.gaps[0].code, 'retry_deferred');
  assert.equal(deferred.gaps[0].retryAfterOutOfRange, true);
  assert.equal(deferred.gaps[0].retryAt, null);
  assert.equal(huge.calls.length, 1);
});

test('async caller mutations cannot redirect a validated parent on later pages or evidence families', async () => {
  const mutableParent = { ...parent };
  const h = harness((_, call) => {
    mutableParent.gid = 'unauthorized-other-parent';
    return call === 1 ? page([{ gid: 't1' }], 'next') : page([]);
  });
  assert.equal((await h.client.enumerate({ family: 'tasks', parent: mutableParent })).status, 'complete');
  assert.ok(h.calls.every(request => new URL(request.url).pathname === '/api/1.0/projects/p1/tasks'));
  const mutableTask = { ...taskParent };
  const evidence = harness(() => { mutableTask.gid = 'different-task'; return page([]); });
  await evidence.client.preserveTaskEvidence({ parent: mutableTask });
  assert.ok(evidence.calls.every(request => request.url.includes('/tasks/t1/') || new URL(request.url).searchParams.get('parent') === 't1'));
});
