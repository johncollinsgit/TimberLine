/**
 * Transport/storage-independent queue for the existing React/Capacitor client.
 * Production adapter must encrypt persisted records, derive the current session,
 * and apply server ACLs. This module does not persist credentials or register a worker.
 */
export class OfflineQueue {
  #actions = [];
  #scope;
  #running = false;
  constructor({ tenantId, userId }) {
    if (!tenantId || !userId) throw new Error('scope_required');
    this.#scope = { tenantId, userId };
  }
  get scope() { return { ...this.#scope }; }
  snapshot() { return structuredClone(this.#actions); }
  enqueue({ key, entityId, kind, payload, expectedVersion }) {
    if (!key || !entityId || !kind || !Number.isInteger(expectedVersion) || expectedVersion < 0) throw new Error('invalid_action');
    const input = structuredClone({ key, entityId, kind, payload, expectedVersion });
    const prior = this.#actions.find(a => a.input.key === key);
    if (prior) {
      if (JSON.stringify(prior.input) !== JSON.stringify(input)) throw new Error('idempotency_mismatch');
      return;
    }
    this.#actions.push({ scope: this.scope, input, attempts: 0, status: 'pending', nextAttemptAt: 0 });
  }
  discard(key) { this.#actions = this.#actions.filter(a => a.input.key !== key); }
  resolve(key, { expectedVersion, payload, replacementKey }) {
    const action = this.#actions.find(a => a.input.key === key);
    if (!action || action.status !== 'conflict' || !replacementKey || replacementKey === key) throw new Error('review_required');
    if (this.#actions.some(a => a.input.key === replacementKey)) throw new Error('idempotency_mismatch');
    if (!Number.isInteger(expectedVersion) || expectedVersion < 0) throw new Error('invalid_version');
    action.input = { ...action.input, key: replacementKey, expectedVersion, payload: structuredClone(payload) };
    action.status = 'pending';
    action.attempts = 0;
    action.nextAttemptAt = 0;
    delete action.server;
  }
  /** Remove protected data on signout, remote revocation, or tenant change. */
  clear() { this.#actions = []; }
  async flush({ currentScope, send, now = Date.now, onRevoked = () => {} }) {
    if (this.#running) return { status: 'busy' };
    if (currentScope.tenantId !== this.#scope.tenantId || currentScope.userId !== this.#scope.userId) {
      this.clear();
      await onRevoked();
      return { status: 'scope_changed' };
    }
    this.#running = true;
    try {
      // Preserve causal order; a conflict blocks later actions until reviewed/discarded.
      while (this.#actions.length) {
        const action = this.#actions[0];
        if (action.status !== 'pending' || action.nextAttemptAt > now()) return { status: action.status };
        action.attempts++;
        let response;
        try { response = await send(structuredClone(action)); }
        catch { response = { status: 503 }; }
        // A tenant switch during a request must never apply its late response.
        if (this.#actions[0] !== action) return { status: 'cancelled' };
        if (response.status >= 200 && response.status < 300) {
          this.#actions.shift();
          continue;
        }
        if ([401, 403, 404, 410].includes(response.status)) {
          this.clear();
          await onRevoked();
          return { status: 'access_removed' };
        }
        if (response.status === 409) {
          action.status = 'conflict';
          // Keep the original mobile payload, expose server version for explicit review.
          action.server = structuredClone(response.server ?? null);
          return { status: 'conflict' };
        }
        if (response.status === 429 || response.status >= 500) {
          const supplied = Number(response.retryAfterSeconds);
          const seconds = Number.isFinite(supplied) && supplied >= 0 && response.retryAfterSeconds != null
            ? supplied : Math.min(300, 2 ** Math.min(action.attempts, 8));
          action.nextAttemptAt = now() + seconds * 1000;
          return { status: 'retry_scheduled' };
        }
        action.status = 'failed';
        return { status: 'failed' };
      }
      return { status: 'synced' };
    } finally { this.#running = false; }
  }
}
