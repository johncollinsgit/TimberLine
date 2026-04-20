const listeners = new Set();

function emitChange() {
  listeners.forEach((listener) => {
    try {
      listener();
    } catch (error) {
      console.warn("shopify command search listener failed", error);
    }
  });
}

export class ActionSearchProvider {
  constructor() {
    this.registry = new Map();
    this.cachedSnapshot = [];
  }

  register(documents, scope = "global") {
    const normalizedScope = String(scope || "global").trim() || "global";
    const list = Array.isArray(documents) ? documents : [documents];
    const registeredIds = [];

    list.forEach((document) => {
      if (!document || typeof document !== "object") {
        return;
      }

      const id = String(document.id || "").trim();
      if (id === "") {
        return;
      }

      const section = String(document.section || "actions").trim() || "actions";
      const normalizedDocument = {
        ...document,
        id,
        section,
        _scope: normalizedScope,
      };

      this.registry.set(id, normalizedDocument);
      registeredIds.push(id);
    });

    if (registeredIds.length > 0) {
      this.cachedSnapshot = null;
      emitChange();
    }

    return () => {
      this.unregister(registeredIds);
    };
  }

  unregister(idsOrScope) {
    const ids = Array.isArray(idsOrScope) ? idsOrScope : [idsOrScope];
    let changed = false;

    ids.forEach((value) => {
      const normalized = String(value || "").trim();
      if (normalized === "") {
        return;
      }

      if (this.registry.delete(normalized)) {
        changed = true;
        return;
      }

      for (const [id, document] of this.registry.entries()) {
        if (String(document._scope || "") !== normalized) {
          continue;
        }

        this.registry.delete(id);
        changed = true;
      }
    });

    if (changed) {
      this.cachedSnapshot = null;
      emitChange();
    }
  }

  snapshot() {
    if (this.cachedSnapshot === null) {
      this.cachedSnapshot = Array.from(this.registry.values());
    }

    return this.cachedSnapshot;
  }

  clear() {
    if (this.registry.size === 0) {
      return;
    }

    this.registry.clear();
    this.cachedSnapshot = null;
    emitChange();
  }

  subscribe(listener) {
    listeners.add(listener);

    return () => {
      listeners.delete(listener);
    };
  }
}

export const actionSearchProvider = new ActionSearchProvider();
