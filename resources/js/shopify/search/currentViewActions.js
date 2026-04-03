function normalizeText(value) {
  return String(value || "").replace(/\s+/g, " ").trim();
}

function parseCsv(value) {
  return normalizeText(value)
    .split(",")
    .map((entry) => normalizeText(entry))
    .filter(Boolean);
}

function isVisible(element) {
  if (!(element instanceof HTMLElement)) {
    return false;
  }

  if (element.hasAttribute("hidden") || element.getAttribute("aria-hidden") === "true") {
    return false;
  }

  const role = String(element.getAttribute("role") || "").toLowerCase();
  if (role === "presentation" || role === "none") {
    return false;
  }

  const style = window.getComputedStyle(element);
  return style.display !== "none" && style.visibility !== "hidden";
}

function sameOriginAppPath(href) {
  const raw = normalizeText(href);
  if (raw === "") {
    return false;
  }

  try {
    const url = new URL(raw, window.location.origin);
    return url.origin === window.location.origin && url.pathname.startsWith("/shopify/app");
  } catch (_error) {
    return false;
  }
}

function isExplicitSearchAction(element) {
  const raw = String(element.getAttribute("data-search-action") || "").toLowerCase().trim();
  if (raw === "0" || raw === "false" || raw === "off" || raw === "no") {
    return false;
  }

  return element.hasAttribute("data-search-action");
}

function isStrictSearchableFallback(element) {
  if (!(element instanceof HTMLElement)) {
    return false;
  }

  const isTopbarActionLink = element.matches(".app-topbar-subnav-link, .app-topbar-actions .app-topbar-action");
  if (!isTopbarActionLink) {
    return false;
  }

  if (!(element instanceof HTMLAnchorElement)) {
    return false;
  }

  return sameOriginAppPath(element.getAttribute("href") || element.href);
}

function readDataset(element, key, legacyKey) {
  return normalizeText(element.dataset[key] || element.dataset[legacyKey] || "");
}

function buildIdentity(element, executeKey, source, index) {
  const explicitId = readDataset(element, "searchId", "searchActionId");
  if (explicitId !== "") {
    return explicitId;
  }

  const fallback = executeKey !== ""
    ? executeKey.toLowerCase().replace(/[^a-z0-9]+/g, "-")
    : `${source}-${index}`;

  return `current-view:${source}:${fallback}`.replace(/-+/g, "-");
}

function actionDescriptor(element) {
  if (element instanceof HTMLAnchorElement && (element.getAttribute("href") || element.href)) {
    return {
      execute: "navigate",
      executeKey: `navigate:${element.getAttribute("href") || element.href}`,
    };
  }

  const eventName = readDataset(element, "searchEvent", "searchActionEvent");
  if (eventName !== "") {
    return {
      execute: "event",
      eventName,
      executeKey: `event:${eventName}`,
    };
  }

  if (element instanceof HTMLButtonElement && !element.disabled && isExplicitSearchAction(element)) {
    return {
      execute: "button",
      executeKey: `button:${normalizeText(element.textContent)}`,
    };
  }

  return null;
}

export function discoverCurrentViewActions({ createNavigateAction }) {
  if (typeof document === "undefined") {
    return [];
  }

  const selectors = [
    "[data-search-action]",
    ".app-topbar-subnav-link",
    ".app-topbar-actions .app-topbar-action",
  ];

  const seen = new Set();

  return selectors
    .flatMap((selector) => Array.from(document.querySelectorAll(selector)))
    .filter((element) => element instanceof HTMLElement)
    .filter((element) => isVisible(element))
    .map((element, index) => {
      const explicit = isExplicitSearchAction(element);
      const strictFallback = !explicit && isStrictSearchableFallback(element);
      if (!explicit && !strictFallback) {
        return null;
      }

      const descriptor = actionDescriptor(element);
      if (!descriptor) {
        return null;
      }

      const title = readDataset(element, "searchTitle", "searchActionTitle")
        || normalizeText(element.getAttribute("aria-label") || element.textContent);
      if (title.length < 3 || title.length > 96) {
        return null;
      }

      let execute = null;
      if (descriptor.execute === "navigate") {
        execute = createNavigateAction(descriptor.executeKey.replace(/^navigate:/, ""));
      } else if (descriptor.execute === "event") {
        execute = () => {
          document.dispatchEvent(new CustomEvent(descriptor.eventName, { detail: { source: "command-menu" } }));
        };
      } else if (descriptor.execute === "button") {
        execute = () => element.click();
      }

      if (typeof execute !== "function") {
        return null;
      }

      const source = explicit ? "current-view-explicit" : "current-view-harvested";
      const id = buildIdentity(element, descriptor.executeKey, source, index);
      const dedupeKey = `${id}|${descriptor.executeKey}`;
      if (seen.has(dedupeKey)) {
        return null;
      }
      seen.add(dedupeKey);

      const subtitle = readDataset(element, "searchSubtitle", "searchActionSubtitle")
        || (explicit ? "Action available in this view" : "Current view navigation");
      const keywords = parseCsv(readDataset(element, "searchKeywords", "searchActionKeywords"));
      const aliases = parseCsv(readDataset(element, "searchAliases", "searchActionAliases"));
      const synonyms = parseCsv(readDataset(element, "searchSynonyms", "searchActionSynonyms"));
      const entityLabels = parseCsv(readDataset(element, "searchEntity", "searchActionEntity"));
      const intentPhrases = parseCsv(readDataset(element, "searchIntent", "searchActionIntent"));

      return {
        id,
        title,
        subtitle,
        section: "current-view",
        keywords,
        aliases,
        synonyms,
        entityLabels,
        intentPhrases,
        breadcrumbs: ["Current view"],
        entityType: "ui-action",
        source,
        execute,
        executeKey: descriptor.executeKey,
        priority: explicit ? 380 : 330,
      };
    })
    .filter(Boolean);
}

