import Fuse from "fuse.js";
import {
  normalizeList,
  normalizeQueryIntent,
  normalizeText,
} from "./queryNormalization.js";

export const SECTION_ORDER = ["actions", "current-view", "pages", "recent"];

export const SOURCE_PRECEDENCE = [
  "explicit",
  "current-view-explicit",
  "route-discovery",
  "current-view-harvested",
  "recent",
];

const SECTION_LIMITS = {
  actions: 8,
  "current-view": 6,
  pages: 8,
  recent: 6,
};

const SOURCE_WEIGHT = SOURCE_PRECEDENCE.reduce((carry, source, index) => {
  carry[source] = (SOURCE_PRECEDENCE.length - index) * 16;
  return carry;
}, {});

const DEDUPE_SOURCE_WEIGHT = SOURCE_PRECEDENCE.reduce((carry, source, index) => {
  carry[source] = (SOURCE_PRECEDENCE.length - index) * 100000;
  return carry;
}, {});

function sectionWeight(section) {
  const index = SECTION_ORDER.indexOf(String(section || ""));
  if (index === -1) {
    return 0;
  }

  return (SECTION_ORDER.length - index) * 8;
}

function sourceWeight(source) {
  const key = String(source || "").trim().toLowerCase();
  return SOURCE_WEIGHT[key] || 0;
}

function executeKind(executeKey) {
  const key = String(executeKey || "").trim().toLowerCase();
  if (key === "") {
    return "unknown";
  }

  if (!key.includes(":")) {
    return key;
  }

  return key.split(":")[0];
}

function normalizedDestination(executeKey) {
  const value = String(executeKey || "").trim().toLowerCase();
  if (value === "") {
    return "";
  }

  const target = value.includes(":") ? value.split(":").slice(1).join(":") : value;
  if (target === "") {
    return "";
  }

  return target.replace(/\?.*$/, "").replace(/#.*$/, "");
}

function dedupeKeys(document) {
  const title = normalizeText(document.title);
  const id = normalizeText(document.id);
  const destination = normalizedDestination(document.executeKey);
  const kind = executeKind(document.executeKey);
  const keys = [];

  if (id !== "") {
    keys.push(`id:${id}`);
  }

  if (title !== "" && destination !== "") {
    keys.push(`title-destination:${title}|${destination}`);
  }

  if (title !== "" && kind !== "") {
    keys.push(`title-kind:${title}|${kind}`);
  }

  return keys;
}

function qualityScore(document) {
  const priority = Number.isFinite(document.priority) ? Number(document.priority) : 100;
  const source = String(document.source || "").trim().toLowerCase();
  return (DEDUPE_SOURCE_WEIGHT[source] || 0) + (sectionWeight(document.section) * 1000) + priority;
}

function compareQuality(a, b) {
  const rankDelta = qualityScore(b) - qualityScore(a);
  if (rankDelta !== 0) {
    return rankDelta;
  }

  const titleDelta = String(a.title || "").localeCompare(String(b.title || ""));
  if (titleDelta !== 0) {
    return titleDelta;
  }

  return String(a.id || "").localeCompare(String(b.id || ""));
}

function containsPhrase(list, value) {
  const normalized = normalizeText(value);
  if (normalized === "") {
    return false;
  }

  return normalizeList(list).some((candidate) => candidate === normalized || candidate.includes(normalized));
}

export function dedupeDocuments(documents) {
  const sorted = (Array.isArray(documents) ? documents : [])
    .filter((document) => document && typeof document === "object")
    .sort(compareQuality);
  const seen = new Set();
  const kept = [];

  sorted.forEach((document) => {
    const keys = dedupeKeys(document);
    if (keys.length === 0) {
      return;
    }

    if (keys.some((key) => seen.has(key))) {
      return;
    }

    keys.forEach((key) => seen.add(key));
    kept.push(document);
  });

  return kept;
}

function buildFuseOptions() {
  return {
    includeScore: true,
    threshold: 0.42,
    ignoreLocation: true,
    minMatchCharLength: 2,
    keys: [
      { name: "title", weight: 0.34 },
      { name: "aliases", weight: 0.2 },
      { name: "keywords", weight: 0.15 },
      { name: "synonyms", weight: 0.12 },
      { name: "intentPhrases", weight: 0.08 },
      { name: "entityLabels", weight: 0.06 },
      { name: "breadcrumbs", weight: 0.03 },
      { name: "subtitle", weight: 0.02 },
    ],
  };
}

export function createSearchIndex(documents, { fuseFactory = Fuse } = {}) {
  const deduped = dedupeDocuments(documents);
  const fuse = new fuseFactory(deduped, buildFuseOptions());

  return {
    documents: deduped,
    fuse,
  };
}

function fuzzyScores(index, queryContext) {
  const scores = new Map();
  const terms = queryContext.expandedTerms.slice(0, 6);

  terms.forEach((term, termIndex) => {
    index.fuse.search(term, { limit: 120 }).forEach((entry) => {
      const fuseScore = typeof entry.score === "number" ? entry.score : 1;
      const base = Math.max(0, 680 - Math.round(fuseScore * 360));
      const exactTermBoost = termIndex === 0 ? 70 : 25;
      const candidate = base + exactTermBoost;
      const previous = scores.get(entry.item.id) || 0;
      if (candidate > previous) {
        scores.set(entry.item.id, candidate);
      }
    });
  });

  return scores;
}

function exactBoost(document, queryContext) {
  const query = queryContext.normalizedQuery;
  if (query === "") {
    return 0;
  }

  const title = normalizeText(document.title);
  const subtitle = normalizeText(document.subtitle);
  const aliases = normalizeList([
    ...(Array.isArray(document.aliases) ? document.aliases : []),
    ...(Array.isArray(document.synonyms) ? document.synonyms : []),
  ]);
  const keywords = normalizeList(document.keywords);
  const breadcrumbs = normalizeList(document.breadcrumbs);
  const entityLabels = normalizeList(document.entityLabels);
  const intentPhrases = normalizeList(document.intentPhrases);

  let boost = 0;

  if (title === query) {
    boost += 560;
  }

  if (aliases.includes(query)) {
    boost += 500;
  }

  if (keywords.includes(query)) {
    boost += 470;
  }

  if (title.startsWith(query)) {
    boost += 420;
  }

  if (queryContext.imperative && (title.startsWith(queryContext.imperative) || intentPhrases.includes(queryContext.imperative))) {
    boost += 260;
  }

  queryContext.normalizedTokens.forEach((token) => {
    if (token === "") {
      return;
    }

    if (title.includes(token)) {
      boost += 24;
    }

    if (aliases.some((alias) => alias.includes(token))) {
      boost += 16;
    }

    if (keywords.some((keyword) => keyword.includes(token))) {
      boost += 14;
    }
  });

  const strongMatch = title.includes(query)
    || aliases.some((value) => value.includes(query))
    || keywords.some((value) => value.includes(query))
    || entityLabels.some((value) => value.includes(query))
    || subtitle.includes(query);

  const breadcrumbOnly = !strongMatch && breadcrumbs.some((value) => value.includes(query));
  if (breadcrumbOnly) {
    boost -= 120;
  }

  if (query.includes("shipping")) {
    const genericSettings = title === "settings" && !keywords.includes("shipping") && !aliases.includes("shipping");
    if (genericSettings) {
      boost += 80;
      boost -= 160;
    }
  }

  return boost;
}

function baseRank(document) {
  const priority = Number.isFinite(document.priority) ? Number(document.priority) : 100;
  return priority + sectionWeight(document.section) + sourceWeight(document.source);
}

export function rankWithIndex(index, query, options = {}) {
  const queryContext = normalizeQueryIntent(query, {
    extraSynonyms: options.extraSynonyms || {},
  });

  if (queryContext.normalizedQuery === "") {
    return index.documents
      .map((document) => ({
        ...document,
        __rank: baseRank(document),
      }))
      .sort((a, b) => b.__rank - a.__rank)
      .map(({ __rank, ...document }) => document);
  }

  const fuzzyById = fuzzyScores(index, queryContext);

  return index.documents
    .map((document) => {
      const fuzzy = fuzzyById.get(document.id) || 0;
      const boost = exactBoost(document, queryContext);
      const rank = baseRank(document) + boost + fuzzy;
      const title = normalizeText(document.title);
      const weakSettingsIntent = title === "settings"
        && queryContext.normalizedTokens.some((token) => token === "shipping" || token === "settings");
      const hasMatch = fuzzy > 0 || boost > 0 || weakSettingsIntent;

      if (!hasMatch) {
        return null;
      }

      if (queryContext.normalizedQuery.length > 18 && fuzzy < 220 && boost < 180) {
        return {
          ...document,
          __rank: rank - 120,
        };
      }

      return {
        ...document,
        __rank: rank,
      };
    })
    .filter(Boolean)
    .sort((a, b) => b.__rank - a.__rank)
    .map(({ __rank, ...document }) => document);
}

export function rankDocuments(documents, query, options = {}) {
  const index = createSearchIndex(documents, options);
  return rankWithIndex(index, query, options);
}

export function groupDocuments(documents) {
  const grouped = new Map();
  SECTION_ORDER.forEach((section) => {
    grouped.set(section, []);
  });

  (Array.isArray(documents) ? documents : []).forEach((document) => {
    const section = SECTION_ORDER.includes(document.section) ? document.section : "actions";
    grouped.get(section).push(document);
  });

  return SECTION_ORDER
    .map((section) => ({
      section,
      items: (grouped.get(section) || []).slice(0, SECTION_LIMITS[section] || 6),
    }))
    .filter((group) => group.items.length > 0);
}
