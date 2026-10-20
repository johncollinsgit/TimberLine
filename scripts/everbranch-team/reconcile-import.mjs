/** Local destination reconciliation contract. No HTTP, credentials, invites or live writes. */
import { createHash } from 'node:crypto';
const hash = value => createHash('sha256').update(value).digest('hex');
function canonical(value) {
  if (Array.isArray(value)) return value.map(canonical);
  if (value && typeof value === 'object') return Object.fromEntries(Object.keys(value).sort().map(k => [k, canonical(value[k])]));
  return value;
}
export const sourceHash = value => hash(JSON.stringify(canonical(value)));
export const emptyImport = () => ({ binding: null, records: {}, checkpoints: {}, issues: {}, counts: { created: 0, unchanged: 0, versions: 0, conflicts: 0 } });
const allowedTypes = new Set(['project', 'section', 'task', 'comment', 'identity', 'attachment', 'membership', 'dependency']);
function bind(state, trusted) {
  if (!trusted.tenantId || !trusted.workspaceId || !trusted.actorId || !trusted.connectionId || trusted.active !== true || trusted.verified !== true || trusted.entitled !== true || trusted.importAllowed !== true) throw new Error('access_denied');
  if (trusted.cancelled) throw new Error('cancelled');
  const binding = sourceHash([trusted.tenantId, trusted.workspaceId, trusted.actorId, trusted.connectionId, trusted.mappingVersion]);
  if (state.binding && state.binding !== binding) throw new Error('binding_mismatch');
  return binding;
}
function key(trusted, type, id) { return JSON.stringify([trusted.tenantId, 'asana', trusted.workspaceId, type, id]); }
function issue(state, id, kind, sourceKey, details = {}) { state.issues[id] = { kind, sourceKey, status: 'open', ...details }; }
export function reconcilePage(input, trusted, page) {
  const state = structuredClone(input);
  state.binding = bind(state, trusted);
  if (!page.family || !page.pageId || !Array.isArray(page.records) || page.records.length > 100) throw new Error('invalid_page');
  const checkpointKey = JSON.stringify([page.family, page.parentId ?? null]);
  const prior = state.checkpoints[checkpointKey];
  const pageDigest = sourceHash(page);
  if (prior?.pageId === page.pageId) {
    if (prior.hash !== pageDigest) throw new Error('page_changed');
    return state;
  }
  if ((page.offset ?? null) !== (prior?.nextOffset ?? null) || prior?.complete) throw new Error('pagination_gap');
  for (const record of page.records) {
    if (!allowedTypes.has(record.type) || typeof record.id !== 'string' || !record.id || !record.raw || typeof record.raw !== 'object' || Array.isArray(record.raw)) throw new Error('invalid_record');
    const sourceKey = key(trusted, record.type, record.id);
    const digest = sourceHash(record.raw);
    const existing = state.records[sourceKey];
    if (existing?.versions.some(v => v.hash === digest)) { state.counts.unchanged++; continue; }
    const version = { hash: digest, original: structuredClone(record.raw), capturedAt: page.capturedAt ?? null };
    if (existing) {
      existing.versions.push(version); state.counts.versions++;
      if (existing.nativeModified) {
        state.counts.conflicts++;
        issue(state, `conflict:${sourceKey}:${digest}`, 'native_source_conflict', sourceKey, { sourceHash: digest });
      } else { existing.acceptedHash = digest; }
    } else {
      state.records[sourceKey] = { sourceKey, type: record.type, sourceId: record.id, tenantId: trusted.tenantId,
        versions: [version], acceptedHash: digest, nativeModified: false,
        // Former/source authors are attribution only. Never create user accounts or login grants.
        ...(record.type === 'identity' ? { loginUserId: null, accessGranted: false } : {}),
        ...(record.type === 'attachment' ? { copyStatus: 'pending', copiedBytes: null } : {}) };
      state.counts.created++;
    }
  }
  // Reconcile relationships after durable objects exist; unresolved edges grant no access.
  for (const record of Object.values(state.records)) {
    const raw = record.versions.find(v => v.hash === record.acceptedHash).original;
    const refs = [];
    if (record.type === 'task' && raw.parent_id) refs.push(['task', raw.parent_id]);
    if (record.type === 'section') refs.push(['project', raw.project_id]);
    if (['comment', 'attachment'].includes(record.type)) refs.push(['task', raw.task_id]);
    if (record.type === 'membership') refs.push(['task', raw.task_id], ['project', raw.project_id], ...(raw.section_id ? [['section', raw.section_id]] : []));
    if (record.type === 'dependency') refs.push(['task', raw.blocking_id], ['task', raw.blocked_id]);
    for (const [type, id] of refs) {
      const issueId = `edge:${record.sourceKey}:${type}:${id}`;
      if (!id || !state.records[key(trusted, type, id)]) issue(state, issueId, 'unresolved_relationship', record.sourceKey);
      else if (state.issues[issueId]) state.issues[issueId].status = 'resolved';
    }
    if (record.type === 'membership' && raw.section_id) {
      const section = state.records[key(trusted, 'section', raw.section_id)];
      if (section) {
        const sectionRaw = section.versions.find(v => v.hash === section.acceptedHash).original;
        if (sectionRaw.project_id !== raw.project_id) issue(state, `section:${record.sourceKey}`, 'section_project_mismatch', record.sourceKey);
      }
    }
  }
  // Checkpoint and records are returned together for one atomic adapter commit.
  // Caller must store raw evidence before this commit; this pure function proves no durability.
  state.checkpoints[checkpointKey] = { pageId: page.pageId, hash: pageDigest, nextOffset: page.nextOffset ?? null, complete: page.nextOffset == null };
  return state;
}
export function reconcileAttachment(input, trusted, sourceId, bytes, expectedHash, expectedSize) {
  bind(input, trusted);
  const state = structuredClone(input);
  const record = state.records[key(trusted, 'attachment', sourceId)];
  if (!record || !Buffer.isBuffer(bytes) || !Number.isSafeInteger(expectedSize) || expectedSize < 0 || !/^[a-f0-9]{64}$/.test(expectedHash)) throw new Error('invalid_attachment');
  const issueId = `bytes:${record.sourceKey}`;
  if (bytes.length !== expectedSize || hash(bytes) !== expectedHash) {
    issue(state, issueId, 'attachment_bytes_mismatch', record.sourceKey);
    record.copyStatus = 'failed';
    return state;
  }
  // This is byte validation, not storage/scanner success. Keep blocked pending a real adapter.
  record.copyStatus = 'bytes_verified_pending_storage_and_scan';
  record.copiedBytes = { size: bytes.length, sha256: expectedHash };
  if (state.issues[issueId]) state.issues[issueId].status = 'resolved';
  return state;
}
