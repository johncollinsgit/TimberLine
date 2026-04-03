import { useMemo, useSyncExternalStore } from "react";
import { actionSearchProvider } from "./ActionSearchProvider.js";
import { discoverCurrentViewActions } from "./currentViewActions.js";
import { createNavigateAction } from "./navigationHelpers.js";
import { buildCustomerIdAction } from "./defaultShopifySearchActions.js";
import { groupDocuments, rankDocuments } from "./searchRanking.js";

const RECENT_STORAGE_KEY = "fb:shopify:command-menu:recent";
const MAX_RECENT = 8;

export const SECTION_TITLES = {
  actions: "Actions",
  pages: "Pages",
  "current-view": "Current view",
  recent: "Recent / suggested",
};

function safeLocalStorage() {
  if (typeof window === "undefined" || !window.localStorage) {
    return null;
  }

  return window.localStorage;
}

function readRecentIds() {
  const storage = safeLocalStorage();
  if (!storage) {
    return [];
  }

  try {
    const payload = JSON.parse(storage.getItem(RECENT_STORAGE_KEY) || "[]");
    return Array.isArray(payload) ? payload.map((value) => String(value || "")).filter(Boolean) : [];
  } catch (_error) {
    return [];
  }
}

export function rememberRecentAction(documentId) {
  const id = String(documentId || "").trim();
  if (id === "") {
    return;
  }

  const storage = safeLocalStorage();
  if (!storage) {
    return;
  }

  const next = [id, ...readRecentIds().filter((entry) => entry !== id)].slice(0, MAX_RECENT);
  storage.setItem(RECENT_STORAGE_KEY, JSON.stringify(next));
}

function useProviderDocuments() {
  return useSyncExternalStore(
    (listener) => actionSearchProvider.subscribe(listener),
    () => actionSearchProvider.snapshot(),
    () => []
  );
}

function normalizeDocument(document) {
  if (!document || typeof document !== "object") {
    return null;
  }

  if (typeof document.execute !== "function") {
    return null;
  }

  const id = String(document.id || "").trim();
  const title = String(document.title || "").trim();
  if (id === "" || title === "") {
    return null;
  }

  return {
    id,
    title,
    subtitle: String(document.subtitle || "").trim(),
    section: String(document.section || "actions").trim() || "actions",
    keywords: Array.isArray(document.keywords) ? document.keywords : [],
    aliases: Array.isArray(document.aliases) ? document.aliases : [],
    breadcrumbs: Array.isArray(document.breadcrumbs) ? document.breadcrumbs : [],
    entityType: String(document.entityType || "action").trim() || "action",
    execute: document.execute,
    executeKey: String(document.executeKey || document.id || "").trim(),
    priority: Number.isFinite(document.priority) ? Number(document.priority) : 120,
  };
}

function toRecentDocuments(allDocuments) {
  const lookup = new Map(allDocuments.map((document) => [document.id, document]));

  return readRecentIds()
    .map((id, index) => {
      const document = lookup.get(id);
      if (!document) {
        return null;
      }

      return {
        ...document,
        section: "recent",
        priority: 360 - index,
      };
    })
    .filter(Boolean);
}

export function useActionSearch({ query, refreshToken = 0, baseQuery = "" } = {}) {
  const providerDocuments = useProviderDocuments();

  const currentViewDocuments = useMemo(() => {
    return discoverCurrentViewActions({
      createNavigateAction: (url) => createNavigateAction(url, { appendContext: true, baseQuery }),
    });
  }, [refreshToken, baseQuery]);

  const dynamicDocument = useMemo(() => buildCustomerIdAction(query, { baseQuery }), [query, baseQuery]);

  const allDocuments = useMemo(() => {
    return [
      ...providerDocuments,
      ...currentViewDocuments,
      ...(dynamicDocument ? [dynamicDocument] : []),
    ]
      .map(normalizeDocument)
      .filter(Boolean);
  }, [providerDocuments, currentViewDocuments, dynamicDocument]);

  const ranked = useMemo(() => {
    const documents = [...allDocuments, ...toRecentDocuments(allDocuments)];
    return rankDocuments(documents, query);
  }, [allDocuments, query]);

  const grouped = useMemo(() => groupDocuments(ranked), [ranked]);

  return {
    results: ranked,
    groups: grouped,
  };
}
