import { actionSearchProvider } from "./ActionSearchProvider.js";

function slugify(value) {
  return String(value || "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 64);
}

export function registerSearchActions(scope, actions, provider = actionSearchProvider) {
  const normalizedScope = String(scope || "shopify").trim() || "shopify";
  const list = Array.isArray(actions) ? actions : [actions];

  const normalizedActions = list
    .filter((action) => action && typeof action === "object")
    .map((action, index) => {
      const title = String(action.title || "Action").trim() || "Action";
      const fallbackId = `${normalizedScope}:${slugify(title)}:${index}`;

      return {
        ...action,
        id: String(action.id || fallbackId),
        title,
        section: String(action.section || "actions"),
        keywords: Array.isArray(action.keywords) ? action.keywords : [],
        aliases: Array.isArray(action.aliases) ? action.aliases : [],
        synonyms: Array.isArray(action.synonyms) ? action.synonyms : [],
        breadcrumbs: Array.isArray(action.breadcrumbs) ? action.breadcrumbs : [],
        entityLabels: Array.isArray(action.entityLabels) ? action.entityLabels : [],
        intentPhrases: Array.isArray(action.intentPhrases) ? action.intentPhrases : [],
        source: String(action.source || "explicit").trim() || "explicit",
      };
    });

  return provider.register(normalizedActions, normalizedScope);
}
