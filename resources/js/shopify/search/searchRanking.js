import Fuse from "fuse.js";

export const SECTION_ORDER = ["actions", "current-view", "pages", "recent"];

function normalize(value) {
  return String(value || "").toLowerCase().trim();
}

function normalizeList(values) {
  if (!Array.isArray(values)) {
    return [];
  }

  return values
    .map((value) => normalize(value))
    .filter((value, index, list) => value !== "" && list.indexOf(value) === index);
}

function contains(list, query) {
  return list.some((value) => value === query || value.includes(query));
}

function exactPriority(document, query) {
  const title = normalize(document.title);
  const subtitle = normalize(document.subtitle);
  const keywords = normalizeList(document.keywords);
  const aliases = normalizeList(document.aliases);
  const breadcrumbs = normalizeList(document.breadcrumbs);

  if (title === query) {
    return 1000;
  }

  if (keywords.includes(query) || aliases.includes(query)) {
    return 960;
  }

  if (title.startsWith(query)) {
    return 910;
  }

  if (subtitle.includes(query) || contains(breadcrumbs, query)) {
    return 820;
  }

  return 0;
}

function sectionWeight(section) {
  const index = SECTION_ORDER.indexOf(String(section || ""));
  if (index === -1) {
    return 0;
  }

  return (SECTION_ORDER.length - index) * 8;
}

function dedupeKey(document) {
  return `${normalize(document.id)}|${normalize(document.title)}|${normalize(document.subtitle)}|${normalize(document.executeKey)}`;
}

export function dedupeDocuments(documents) {
  const seen = new Set();

  return (Array.isArray(documents) ? documents : []).filter((document) => {
    const key = dedupeKey(document);
    if (seen.has(key)) {
      return false;
    }

    seen.add(key);
    return true;
  });
}

function buildFuse(documents) {
  return new Fuse(documents, {
    includeScore: true,
    threshold: 0.4,
    ignoreLocation: true,
    minMatchCharLength: 2,
    keys: [
      { name: "title", weight: 0.45 },
      { name: "keywords", weight: 0.25 },
      { name: "aliases", weight: 0.15 },
      { name: "breadcrumbs", weight: 0.1 },
      { name: "subtitle", weight: 0.05 },
    ],
  });
}

export function rankDocuments(documents, query) {
  const deduped = dedupeDocuments(documents);
  const normalizedQuery = normalize(query);

  if (normalizedQuery === "") {
    return deduped
      .map((document) => ({
        ...document,
        __rank: (Number.isFinite(document.priority) ? Number(document.priority) : 100)
          + sectionWeight(document.section),
      }))
      .sort((a, b) => b.__rank - a.__rank)
      .map(({ __rank, ...document }) => document);
  }

  const fuse = buildFuse(deduped);
  const fuzzyScores = new Map(
    fuse.search(normalizedQuery).map((entry) => {
      const score = typeof entry.score === "number" ? entry.score : 1;
      return [entry.item.id, Math.max(0, 760 - Math.round(score * 320))];
    })
  );

  return deduped
    .map((document) => {
      const exact = exactPriority(document, normalizedQuery);
      const fuzzy = fuzzyScores.get(document.id) || 0;
      const rank = Math.max(exact, fuzzy);

      if (rank <= 0) {
        return null;
      }

      return {
        ...document,
        __rank: rank + sectionWeight(document.section),
      };
    })
    .filter(Boolean)
    .sort((a, b) => b.__rank - a.__rank)
    .map(({ __rank, ...document }) => document);
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
    .map((section) => ({ section, items: grouped.get(section) || [] }))
    .filter((group) => group.items.length > 0);
}
