import { useMemo, useSyncExternalStore } from "react";
import { actionSearchProvider } from "./ActionSearchProvider.js";
import { discoverCurrentViewActions } from "./currentViewActions.js";
import { buildCustomerIdAction } from "./defaultShopifySearchActions.js";
import { createNavigateAction } from "./navigationHelpers.js";
import { normalizeQueryIntent } from "./queryNormalization.js";
import {
  buildSuggestedDocuments,
  DEFAULT_MAX_RECENT,
  isRecentEligibleDocument,
  materializeRecentDocuments,
  mergeRecentIds,
} from "./recentActions.js";
import { createSearchIndex, groupDocuments, rankWithIndex } from "./searchRanking.js";

const RECENT_STORAGE_KEY = "fb:shopify:command-menu:recent";
const PINNED_SUGGESTION_IDS = [
  "shopify:action:create-product",
  "shopify:action:go-orders",
  "shopify:action:create-discount",
  "shopify:action:settings-shipping",
];

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

function writeRecentIds(ids) {
  const storage = safeLocalStorage();
  if (!storage) {
    return;
  }

  storage.setItem(RECENT_STORAGE_KEY, JSON.stringify(ids.slice(0, DEFAULT_MAX_RECENT)));
}

export function rememberRecentAction(documentOrId) {
  const isDocument = documentOrId && typeof documentOrId === "object";
  if (isDocument && !isRecentEligibleDocument(documentOrId)) {
    return;
  }

  const id = String(isDocument ? documentOrId.id : documentOrId || "").trim();
  if (id === "") {
    return;
  }

  const next = mergeRecentIds(readRecentIds(), id, DEFAULT_MAX_RECENT);
  writeRecentIds(next);
}

function useProviderDocuments() {
  return useSyncExternalStore(
    (listener) => actionSearchProvider.subscribe(listener),
    () => actionSearchProvider.snapshot(),
    () => []
  );
}

function normalizeDocument(document) {
  if (!document || typeof document !== "object" || typeof document.execute !== "function") {
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
    synonyms: Array.isArray(document.synonyms) ? document.synonyms : [],
    breadcrumbs: Array.isArray(document.breadcrumbs) ? document.breadcrumbs : [],
    entityLabels: Array.isArray(document.entityLabels) ? document.entityLabels : [],
    intentPhrases: Array.isArray(document.intentPhrases) ? document.intentPhrases : [],
    entityType: String(document.entityType || "action").trim() || "action",
    source: String(document.source || "explicit").trim() || "explicit",
    execute: document.execute,
    executeKey: String(document.executeKey || document.id || "").trim(),
    priority: Number.isFinite(document.priority) ? Number(document.priority) : 120,
  };
}

export function useActionSearch({ query, refreshToken = 0, baseQuery = "" } = {}) {
  const providerDocuments = useProviderDocuments();

  const currentViewDocuments = useMemo(() => discoverCurrentViewActions({
    createNavigateAction: (url) => createNavigateAction(url, { appendContext: true, baseQuery }),
  }), [refreshToken, baseQuery]);

  const dynamicDocument = useMemo(() => buildCustomerIdAction(query, { baseQuery }), [query, baseQuery]);

  const allDocuments = useMemo(() => (
    [
      ...providerDocuments,
      ...currentViewDocuments,
      ...(dynamicDocument ? [dynamicDocument] : []),
    ]
      .map(normalizeDocument)
      .filter(Boolean)
  ), [providerDocuments, currentViewDocuments, dynamicDocument]);

  const queryContext = useMemo(() => normalizeQueryIntent(query), [query]);

  const ranked = useMemo(() => {
    const { recentDocuments, retainedIds } = materializeRecentDocuments(readRecentIds(), allDocuments, {
      maxRecent: DEFAULT_MAX_RECENT,
    });
    const existingIds = readRecentIds();
    if (retainedIds.length !== existingIds.length) {
      writeRecentIds(retainedIds);
    }

    const recentLookup = new Set(recentDocuments.map((document) => document.id));
    const suggestedDocuments = queryContext.normalizedQuery === ""
      ? buildSuggestedDocuments(allDocuments, recentLookup, PINNED_SUGGESTION_IDS)
      : [];
    const index = createSearchIndex([
      ...allDocuments,
      ...recentDocuments,
      ...suggestedDocuments,
    ]);

    return rankWithIndex(index, query);
  }, [allDocuments, queryContext.normalizedQuery, query]);

  const grouped = useMemo(() => groupDocuments(ranked), [ranked]);

  return {
    results: ranked,
    groups: grouped,
    queryContext,
  };
}
