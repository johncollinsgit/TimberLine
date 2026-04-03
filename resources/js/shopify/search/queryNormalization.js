const TOKEN_NORMALIZATION = new Map([
  ["new", "create"],
  ["add", "create"],
  ["create", "create"],
  ["go", "go"],
  ["navigate", "go"],
  ["customers", "customer"],
  ["customer", "customer"],
  ["orders", "order"],
  ["order", "order"],
  ["discounts", "discount"],
  ["discount", "discount"],
  ["ship", "shipping"],
  ["delivery", "shipping"],
  ["prefs", "settings"],
  ["pref", "settings"],
  ["preferences", "settings"],
  ["settings", "settings"],
  ["setting", "settings"],
]);

const PHRASE_REWRITES = [
  { pattern: /\bgo\b(?!\s+to)/g, replacement: "go to" },
  { pattern: /\bnavigate(?:\s+to)?\b/g, replacement: "go to" },
  { pattern: /\bview\b/g, replacement: "view" },
  { pattern: /\bmanage\b/g, replacement: "manage" },
  { pattern: /\bedit\b/g, replacement: "edit" },
  { pattern: /\bopen\b/g, replacement: "open" },
];

const IMPERATIVE_PHRASES = [
  "create",
  "open",
  "go to",
  "view",
  "edit",
  "manage",
];

function tokenize(value) {
  return String(value || "")
    .toLowerCase()
    .replace(/[^a-z0-9\s>#/-]/g, " ")
    .replace(/\s+/g, " ")
    .trim()
    .split(" ")
    .filter(Boolean);
}

function singularize(token) {
  if (token.endsWith("ies") && token.length > 4) {
    return `${token.slice(0, -3)}y`;
  }

  if (token.endsWith("s") && token.length > 3 && !token.endsWith("ss")) {
    return token.slice(0, -1);
  }

  return token;
}

function applyPhraseRewrite(value) {
  return PHRASE_REWRITES.reduce((output, rule) => output.replace(rule.pattern, rule.replacement), value);
}

function normalizeToken(token, extraSynonyms = {}) {
  const normalized = String(token || "").toLowerCase().trim();
  if (normalized === "") {
    return "";
  }

  if (extraSynonyms[normalized]) {
    return String(extraSynonyms[normalized]).toLowerCase().trim();
  }

  if (TOKEN_NORMALIZATION.has(normalized)) {
    return String(TOKEN_NORMALIZATION.get(normalized));
  }

  const singular = singularize(normalized);
  if (extraSynonyms[singular]) {
    return String(extraSynonyms[singular]).toLowerCase().trim();
  }

  if (TOKEN_NORMALIZATION.has(singular)) {
    return String(TOKEN_NORMALIZATION.get(singular));
  }

  return singular;
}

export function normalizeText(value) {
  return String(value || "")
    .toLowerCase()
    .replace(/[^a-z0-9\s>#/-]/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

export function normalizeQueryIntent(rawQuery, { extraSynonyms = {} } = {}) {
  const base = applyPhraseRewrite(normalizeText(rawQuery));
  const rawTokens = tokenize(base);
  const normalizedTokens = rawTokens.map((token) => normalizeToken(token, extraSynonyms)).filter(Boolean);
  const normalizedQuery = normalizedTokens.join(" ").trim();
  const tokenSet = new Set([...rawTokens, ...normalizedTokens].filter(Boolean));

  const expandedTerms = Array.from(tokenSet);
  if (normalizedQuery !== "") {
    expandedTerms.unshift(normalizedQuery);
  }

  const imperative = IMPERATIVE_PHRASES.find((phrase) => normalizedQuery.startsWith(phrase)) || null;

  return {
    rawQuery: String(rawQuery || ""),
    normalizedQuery,
    rawTokens,
    normalizedTokens,
    expandedTerms: Array.from(new Set(expandedTerms)).filter(Boolean),
    imperative,
  };
}

export function normalizeList(values) {
  if (!Array.isArray(values)) {
    return [];
  }

  return Array.from(
    new Set(
      values
        .map((value) => normalizeText(value))
        .filter(Boolean)
    )
  );
}
