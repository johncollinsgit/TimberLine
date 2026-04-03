export const DEFAULT_MAX_RECENT = 8;

export function isRecentEligibleDocument(document) {
  if (!document || typeof document !== "object") {
    return false;
  }

  const source = String(document.source || "").trim().toLowerCase();
  if (source === "current-view-harvested") {
    return false;
  }

  if (String(document.section || "").trim() === "recent") {
    return false;
  }

  const executeKey = String(document.executeKey || "").toLowerCase();
  if (executeKey.startsWith("button:")) {
    return false;
  }

  return String(document.id || "").trim() !== "";
}

export function mergeRecentIds(existingIds, nextId, maxRecent = DEFAULT_MAX_RECENT) {
  const id = String(nextId || "").trim();
  if (id === "") {
    return Array.isArray(existingIds) ? existingIds.slice(0, maxRecent) : [];
  }

  const existing = Array.isArray(existingIds) ? existingIds.map((value) => String(value || "")).filter(Boolean) : [];
  return [id, ...existing.filter((entry) => entry !== id)].slice(0, maxRecent);
}

export function materializeRecentDocuments(recentIds, allDocuments, { maxRecent = DEFAULT_MAX_RECENT } = {}) {
  const documents = Array.isArray(allDocuments) ? allDocuments : [];
  const ids = Array.isArray(recentIds) ? recentIds : [];
  const lookup = new Map(documents.map((document) => [document.id, document]));
  const retainedIds = [];

  const recentDocuments = ids
    .slice(0, maxRecent)
    .map((id, index) => {
      const normalizedId = String(id || "").trim();
      if (normalizedId === "") {
        return null;
      }

      const document = lookup.get(normalizedId);
      if (!document || !isRecentEligibleDocument(document)) {
        return null;
      }

      retainedIds.push(normalizedId);
      return {
        ...document,
        section: "recent",
        source: "recent",
        priority: 380 - index,
      };
    })
    .filter(Boolean);

  return {
    recentDocuments,
    retainedIds,
  };
}

export function buildSuggestedDocuments(allDocuments, existingIds, pinnedIds = []) {
  const docs = Array.isArray(allDocuments) ? allDocuments : [];
  const existing = existingIds instanceof Set ? existingIds : new Set();
  const pins = Array.isArray(pinnedIds) ? pinnedIds : [];

  const pinned = pins
    .map((id, index) => {
      const document = docs.find((entry) => entry.id === id);
      if (!document || !isRecentEligibleDocument(document) || existing.has(document.id)) {
        return null;
      }

      return {
        ...document,
        section: "recent",
        source: "recent",
        priority: 320 - index,
      };
    })
    .filter(Boolean);

  if (pinned.length > 0) {
    return pinned;
  }

  return docs
    .filter((document) => document.section === "actions" && isRecentEligibleDocument(document))
    .slice(0, 3)
    .map((document, index) => ({
      ...document,
      section: "recent",
      source: "recent",
      priority: 300 - index,
    }));
}

