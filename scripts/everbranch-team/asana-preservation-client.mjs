import { createHash } from 'node:crypto';
import { setTimeout as pause } from 'node:timers/promises';

const API = 'https://app.asana.com/api/1.0';
const hash = value => createHash('sha256').update(value).digest('hex');
const gid = value => typeof value === 'string' && /^[A-Za-z0-9_-]{1,128}$/.test(value);
const integer = value => Number.isSafeInteger(value) && value > 0;
const endpoints = {
  tasks: { parentType: 'project', scope: 'tasks:read', type: 'task', path: id => `/projects/${id}/tasks` },
  subtasks: { parentType: 'task', scope: 'tasks:read', type: 'task', path: id => `/tasks/${id}/subtasks` },
  stories: { parentType: 'task', scope: 'stories:read', type: 'story', path: id => `/tasks/${id}/stories` },
  attachments: { parentType: 'task', scope: 'attachments:read', type: 'attachment', path: () => '/attachments', query: id => ({ parent: id }) },
};

export class PreservationError extends Error {
  constructor(code, details = {}) {
    super(`Asana preservation: ${code}`);
    this.name = 'PreservationError'; this.code = code; this.details = details;
  }
}

function assert(condition, code) { if (!condition) throw new PreservationError(code); }
function abort(signal) { if (signal?.aborted) throw new PreservationError('cancelled'); }

export function validateEnvelope(input) {
  assert(input && integer(input.tenantId) && integer(input.connectionId), 'invalid_tenant_connection');
  assert(input.connectionTenantId === input.tenantId && input.connectionProvider === 'asana' && input.connectionStatus === 'connected', 'connection_boundary_mismatch');
  assert(input.moduleEnabled === true && input.importEnabled === true, 'import_not_enabled');
  assert(gid(input.workspaceGid) && Array.isArray(input.allowedWorkspaceGids) && input.allowedWorkspaceGids.includes(input.workspaceGid), 'workspace_not_authorized');
  assert(input.actor && integer(input.actor.userId) && input.actor.tenantId === input.tenantId && input.actor.isActive === true && input.actor.membershipActive === true && input.actor.emailVerified === true && ['owner', 'admin'].includes(input.actor.role), 'actor_not_authorized');
  assert(Array.isArray(input.scopes) && input.scopes.every(scope => typeof scope === 'string'), 'invalid_scopes');
  return Object.freeze({ tenantId: input.tenantId, connectionId: input.connectionId, workspaceGid: input.workspaceGid,
    actorUserId: input.actor.userId, scopes: Object.freeze([...input.scopes]) });
}

function bindParent(envelope, family, parent) {
  const endpoint = endpoints[family];
  assert(endpoint, 'unsupported_family');
  assert(parent && gid(parent.gid) && parent.resourceType === endpoint.parentType && parent.tenantId === envelope.tenantId && parent.connectionId === envelope.connectionId && parent.workspaceGid === envelope.workspaceGid, 'parent_boundary_mismatch');
  assert(envelope.scopes.includes(endpoint.scope), 'missing_required_read_scope');
  return endpoint;
}

export function retryAfterMs(headers, now = Date.now()) {
  const value = typeof headers?.get === 'function' ? headers.get('retry-after') : Object.entries(headers ?? {}).find(([key]) => key.toLowerCase() === 'retry-after')?.[1];
  if (value === undefined || value === null) return null;
  const text = String(value).trim();
  if (/^\d+(?:\.\d+)?$/.test(text)) return Math.ceil(Number(text) * 1000);
  const timestamp = Date.parse(text);
  return Number.isFinite(timestamp) ? Math.max(0, timestamp - now) : null;
}

/**
 * Pure source reader. Caller supplies an authorized grant and durable sink.
 * No environment/config/global token lookup, upstream writes, logs or CLI.
 * A sink must finish durable raw capture before saveCheckpoint can commit.
 */
export class AsanaPreservationClient {
  #envelope; #token; #transport; #sink; #wait; #now; #random; #limits; #revalidate;
  constructor({ envelope, accessToken, transport, sink, revalidate, wait = (ms, signal) => pause(ms, undefined, { signal }), now = Date.now, random = Math.random,
    maxRetries = 4, maxWaitMs = 30_000, maxPagesPerCall = 100, pageSize = 100 }) {
    this.#envelope = validateEnvelope(envelope);
    assert(typeof accessToken === 'string' && accessToken.length > 0 && !/[\r\n]/.test(accessToken), 'invalid_access_token');
    assert(typeof transport === 'function' && typeof wait === 'function' && typeof revalidate === 'function', 'invalid_transport');
    assert(sink && ['capturePage', 'saveCheckpoint', 'recordGap'].every(method => typeof sink[method] === 'function'), 'invalid_durable_sink');
    assert(Number.isInteger(maxRetries) && maxRetries >= 0 && maxRetries <= 10 && integer(maxWaitMs) && maxWaitMs <= 60_000 && integer(maxPagesPerCall) && maxPagesPerCall <= 1000 && integer(pageSize) && pageSize <= 100, 'invalid_limits');
    this.#token = accessToken; this.#transport = transport; this.#sink = sink; this.#wait = wait; this.#now = now; this.#random = random;
    this.#revalidate = revalidate;
    this.#limits = { maxRetries, maxWaitMs, maxPagesPerCall, pageSize };
  }

  async #durable(method, value) {
    try { await this.#sink[method](structuredClone(value)); }
    catch { throw new PreservationError('archive_write_failed'); }
  }

  async #page({ route, query, binding, signal }) {
    for (let attempt = 0; attempt <= this.#limits.maxRetries; attempt++) {
      abort(signal);
      let authorized = false;
      try { authorized = await this.#revalidate(structuredClone(binding)); } catch { /* fail closed without exposing authorization errors */ }
      assert(authorized === true, 'authorization_revoked');
      abort(signal);
      let response;
      try {
        response = await this.#transport({ method: 'GET', url: `${API}${route}?${new URLSearchParams(query)}`,
          headers: { Authorization: `Bearer ${this.#token}`, Accept: 'application/json' }, signal });
      } catch {
        abort(signal);
        if (attempt === this.#limits.maxRetries) throw new PreservationError('transport_failed', { attempts: attempt + 1 });
        await this.#backoff(attempt, null, signal);
        continue;
      }
      abort(signal);
      assert(response && Number.isInteger(response.status) && typeof response.body === 'string', 'invalid_transport_response');
      await this.#durable('capturePage', { binding, request: { method: 'GET', route, query }, httpStatus: response.status,
        observedAt: new Date(this.#now()).toISOString(), rawResponse: response.body, bytes: Buffer.byteLength(response.body), sha256: hash(response.body),
        captureKey: hash(JSON.stringify([binding, route, query, response.status, hash(response.body)])), attempt });
      let payload;
      try { payload = JSON.parse(response.body); } catch { payload = null; }
      if (response.status >= 200 && response.status < 300) {
        assert(payload && Array.isArray(payload.data), 'invalid_page_payload');
        return payload;
      }
      if (response.status === 400 && query.offset && payload?.errors?.some(error => /offset/i.test(error?.message ?? '') && /expired|invalid/i.test(error?.message ?? ''))) throw new PreservationError('offset_expired');
      const errors = { 401: 'reconnect_required', 402: 'plan_blocked', 403: 'inaccessible', 404: 'unresolved_object' };
      if (errors[response.status]) throw new PreservationError(errors[response.status], { httpStatus: response.status });
      if (response.status !== 429 && response.status < 500) throw new PreservationError('request_rejected', { httpStatus: response.status });
      const retryMs = retryAfterMs(response.headers, this.#now());
      if (attempt === this.#limits.maxRetries) throw new PreservationError('retry_exhausted', { httpStatus: response.status, retryAfterMs: retryMs });
      await this.#backoff(attempt, retryMs, signal);
    }
    throw new PreservationError('retry_exhausted');
  }

  async #backoff(attempt, requestedMs, signal) {
    const delay = requestedMs ?? Math.ceil(Math.min(16_000, 500 * 2 ** attempt) * (1 + this.#random() * 0.2));
    if (!Number.isFinite(delay) || delay > this.#limits.maxWaitMs) {
      const retryDate = new Date(this.#now() + delay);
      throw new PreservationError('retry_deferred', { retryAfterMs: Number.isSafeInteger(delay) ? delay : null,
        retryAt: Number.isFinite(retryDate.getTime()) ? retryDate.toISOString() : null, retryAfterOutOfRange: !Number.isSafeInteger(delay) });
    }
    abort(signal);
    try { await this.#wait(delay, signal); } catch { abort(signal); throw new PreservationError('wait_failed'); }
    abort(signal);
  }

  async enumerate({ family, parent, fields = ['gid'], checkpoint = null, signal }) {
    const endpoint = bindParent(this.#envelope, family, parent);
    assert(Array.isArray(fields) && fields.length > 0 && fields.every(field => typeof field === 'string' && /^[a-zA-Z0-9_.]+$/.test(field)), 'invalid_fields');
    const selectedFields = [...new Set(['gid', ...fields])].sort();
    const binding = { tenantId: this.#envelope.tenantId, connectionId: this.#envelope.connectionId, workspaceGid: this.#envelope.workspaceGid, actorUserId: this.#envelope.actorUserId, family, parentGid: parent.gid };
    const queryBase = { ...endpoint.query?.(parent.gid), limit: String(this.#limits.pageSize), opt_fields: selectedFields.join(',') };
    const identity = hash(JSON.stringify([binding, queryBase]));
    if (checkpoint) {
      assert(checkpoint.version === 1 && checkpoint.identity === identity && JSON.stringify(checkpoint.binding) === JSON.stringify(binding), 'checkpoint_boundary_mismatch');
      assert((checkpoint.nextOffset === null || typeof checkpoint.nextOffset === 'string') && Number.isInteger(checkpoint.pages) && checkpoint.pages >= 0 && Number.isInteger(checkpoint.observedRecordOccurrences) && checkpoint.observedRecordOccurrences >= 0 && Number.isInteger(checkpoint.resets) && checkpoint.resets >= 0 && checkpoint.resets <= 1 && checkpoint.enumerationGeneration === checkpoint.resets + 1 && typeof checkpoint.done === 'boolean' && Array.isArray(checkpoint.cursorHashes) && checkpoint.cursorHashes.every(value => /^[0-9a-f]{64}$/.test(value)), 'invalid_checkpoint');
    }
    let state = checkpoint ? structuredClone(checkpoint) : { version: 1, identity, binding, nextOffset: null, pages: 0, observedRecordOccurrences: 0, resets: 0, enumerationGeneration: 1, done: false, cursorHashes: [] };
    if (state.done) return { status: 'complete', checkpoint: state, gaps: [] };
    const gaps = [], visited = new Set(state.cursorHashes);
    try {
      for (let pages = 0; pages < this.#limits.maxPagesPerCall; pages++) {
        abort(signal);
        const cursor = hash(state.nextOffset ?? '<first>');
        assert(!visited.has(cursor), 'pagination_cycle');
        visited.add(cursor);
        const query = { ...queryBase, ...(state.nextOffset ? { offset: state.nextOffset } : {}) };
        let payload;
        try { payload = await this.#page({ route: endpoint.path(binding.parentGid), query, binding, signal }); }
        catch (error) {
          if (error.code === 'offset_expired' && state.resets < 1) {
            const gap = { binding, code: 'offset_reset', recovered: true };
            await this.#durable('recordGap', gap); gaps.push(gap);
            state = { ...state, nextOffset: null, resets: state.resets + 1, enumerationGeneration: state.enumerationGeneration + 1, cursorHashes: [] };
            visited.clear();
            await this.#durable('saveCheckpoint', state);
            continue;
          }
          throw error;
        }
        assert(payload.data.every(row => row && gid(row.gid) && (!row.resource_type || row.resource_type === endpoint.type)), 'invalid_source_record');
        assert(payload.data.every(row => !row.workspace?.gid || row.workspace.gid === binding.workspaceGid), 'source_workspace_mismatch');
        assert(Object.hasOwn(payload, 'next_page') && (payload.next_page === null || typeof payload.next_page?.offset === 'string' && payload.next_page.offset.length > 0), 'invalid_pagination_metadata');
        // Raw response is durable at this point. Only now advance continuation.
        const next = { ...state, nextOffset: payload.next_page?.offset ?? null, pages: state.pages + 1,
          observedRecordOccurrences: state.observedRecordOccurrences + payload.data.length, done: payload.next_page === null, cursorHashes: [...visited] };
        await this.#durable('saveCheckpoint', next);
        state = next;
        if (state.done) return { status: 'complete', checkpoint: state, gaps };
      }
      return { status: 'checkpointed', checkpoint: state, gaps };
    } catch (error) {
      if (!(error instanceof PreservationError)) throw new PreservationError('unexpected_failure');
      if (error.code === 'archive_write_failed') throw error;
      const gap = { binding, code: error.code, ...error.details, recovered: false };
      await this.#durable('recordGap', gap); gaps.push(gap);
      return { status: error.code === 'cancelled' ? 'cancelled' : 'partial', checkpoint: state, gaps };
    }
  }

  async preserveTaskEvidence({ parent, fields = {}, checkpoints = {}, signal }) {
    const fixedParent = structuredClone(parent);
    const results = {};
    for (const family of ['subtasks', 'stories', 'attachments']) {
      if (signal?.aborted) break;
      try { results[family] = await this.enumerate({ family, parent: fixedParent, fields: fields[family] ?? ['gid'], checkpoint: checkpoints[family] ?? null, signal }); }
      catch (error) {
        if (!(error instanceof PreservationError) || error.code !== 'missing_required_read_scope') throw error;
        const gap = { binding: { tenantId: this.#envelope.tenantId, connectionId: this.#envelope.connectionId, workspaceGid: this.#envelope.workspaceGid, actorUserId: this.#envelope.actorUserId, family, parentGid: fixedParent.gid }, code: error.code, recovered: false };
        await this.#durable('recordGap', gap);
        results[family] = { status: 'partial', checkpoint: null, gaps: [gap] };
      }
    }
    return { status: Object.keys(results).length === 3 && Object.values(results).every(result => result.status === 'complete') ? 'complete_enumeration' : 'partial', results,
      limitation: 'Task-family enumeration does not prove all history, nested descendants, rich fields or attachment bytes were preserved.' };
  }
}
