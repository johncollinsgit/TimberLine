import test from 'node:test';
import assert from 'node:assert/strict';
import { OfflineQueue } from '../../resources/js/team/offline-queue.mjs';
const scope = { tenantId: 'fictional-a', userId: 'fictional-member' };
const input = { key: 'offline-1', entityId: 'fictional-task', kind: 'task.complete', payload: { completed: true }, expectedVersion: 1 };
test('queue copies drafts, deduplicates identical actions and rejects changed retry keys', () => {
  const q = new OfflineQueue(scope);
  const draft = structuredClone(input); q.enqueue(draft); draft.payload.completed = false;
  q.enqueue(input);
  assert.equal(q.snapshot().length, 1); assert.equal(q.snapshot()[0].input.payload.completed, true);
  assert.throws(() => q.enqueue({ ...input, payload: { completed: false } }), /idempotency_mismatch/);
});
test('transient retry keeps key and order; Retry-After prevents immediate retry', async () => {
  const q = new OfflineQueue(scope); q.enqueue(input); q.enqueue({ ...input, key: 'offline-2' });
  const sent = [];
  assert.equal((await q.flush({ currentScope: scope, now: () => 1000, send: async a => { sent.push(a.input.key); return { status: 429, retryAfterSeconds: 10 }; } })).status, 'retry_scheduled');
  await q.flush({ currentScope: scope, now: () => 2000, send: async () => { throw new Error('must not send'); } });
  assert.deepEqual(sent, ['offline-1']);
  await q.flush({ currentScope: scope, now: () => 12000, send: async a => { sent.push(a.input.key); return { status: 200 }; } });
  assert.deepEqual(sent, ['offline-1', 'offline-1', 'offline-2']); assert.equal(q.snapshot().length, 0);
});
test('description conflicts preserve mobile input and require explicit fresh review/key', async () => {
  const q = new OfflineQueue(scope); q.enqueue({ ...input, payload: { description: 'mobile draft' } }); q.enqueue({ ...input, key: 'later' });
  let sent = 0;
  await q.flush({ currentScope: scope, send: async () => { sent++; return { status: 409, server: { version: 5, description: 'web text' } }; } });
  assert.equal(sent, 1); assert.equal(q.snapshot()[0].input.payload.description, 'mobile draft');
  assert.equal(q.snapshot()[0].server.description, 'web text');
  assert.throws(() => q.resolve(input.key, { expectedVersion: 5, payload: {}, replacementKey: input.key }), /review_required/);
  q.resolve(input.key, { expectedVersion: 5, payload: { description: 'reviewed merged text' }, replacementKey: 'review-1' });
  await q.flush({ currentScope: scope, send: async a => { assert.notEqual(a.input.key, input.key); return { status: 200 }; } });
  assert.equal(q.snapshot().length, 0);
});
for (const status of [401, 403, 404, 410]) test(`access removal ${status} purges protected queue and invokes cache eviction`, async () => {
  const q = new OfflineQueue(scope); q.enqueue(input); let cleared = 0;
  const result = await q.flush({ currentScope: scope, send: async () => ({ status }), onRevoked: () => { cleared++; } });
  assert.equal(result.status, 'access_removed'); assert.equal(cleared, 1); assert.deepEqual(q.snapshot(), []);
});
test('tenant switch evicts queued actions before transport', async () => {
  const q = new OfflineQueue(scope); q.enqueue(input); let sent = false;
  assert.equal((await q.flush({ currentScope: { ...scope, tenantId: 'fictional-b' }, send: () => { sent = true; } })).status, 'scope_changed');
  assert.equal(sent, false); assert.equal(q.snapshot().length, 0);
});
test('late response after signout is ignored; concurrent flush is serialized', async () => {
  const q = new OfflineQueue(scope); q.enqueue(input); let resolve;
  const pending = q.flush({ currentScope: scope, send: () => new Promise(r => { resolve = r; }) });
  assert.equal((await q.flush({ currentScope: scope, send: () => {} })).status, 'busy');
  q.clear(); resolve({ status: 200 }); assert.equal((await pending).status, 'cancelled');
});
test('network failure retains unsynced draft; permanent validation failure stays visible', async () => {
  const q = new OfflineQueue(scope); q.enqueue(input);
  assert.equal((await q.flush({ currentScope: scope, now: () => 0, send: () => { throw new Error('offline'); } })).status, 'retry_scheduled');
  assert.equal(q.snapshot()[0].input.key, input.key);
  assert.equal((await q.flush({ currentScope: scope, now: () => 10000, send: async () => ({ status: 422 }) })).status, 'failed');
  assert.equal(q.snapshot()[0].status, 'failed');
});
test('long Retry-After is never shortened to an earlier retry', async () => {
  const q = new OfflineQueue(scope); q.enqueue(input);
  await q.flush({ currentScope: scope, now: () => 0, send: async () => ({ status: 429, retryAfterSeconds: 172800 }) });
  assert.equal(q.snapshot()[0].nextAttemptAt, 172800000);
});
