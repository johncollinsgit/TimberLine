function normalizeText(value) {
  return String(value || "").replace(/\s+/g, " ").trim();
}

function isVisible(element) {
  if (!(element instanceof HTMLElement)) {
    return false;
  }

  if (element.hasAttribute("hidden") || element.getAttribute("aria-hidden") === "true") {
    return false;
  }

  const style = window.getComputedStyle(element);
  return style.display !== "none" && style.visibility !== "hidden";
}

export function discoverCurrentViewActions({ createNavigateAction }) {
  if (typeof document === "undefined") {
    return [];
  }

  const selectors = [
    ".app-topbar-subnav-link",
    ".app-topbar-actions .app-topbar-action",
    "[data-search-action]",
  ];

  const seen = new Set();

  return selectors
    .flatMap((selector) => Array.from(document.querySelectorAll(selector)))
    .filter((element) => element instanceof HTMLElement)
    .filter((element) => isVisible(element))
    .filter((element) => {
      if (element instanceof HTMLButtonElement) {
        return !element.disabled;
      }

      return true;
    })
    .map((element, index) => {
      const title = normalizeText(
        element.dataset.searchActionTitle || element.getAttribute("aria-label") || element.textContent
      );
      if (title.length < 3) {
        return null;
      }

      const keywords = normalizeText(element.dataset.searchActionKeywords)
        .split(",")
        .map((value) => normalizeText(value))
        .filter(Boolean);

      let execute = null;
      let executeKey = "";
      let subtitle = normalizeText(element.dataset.searchActionSubtitle);

      if (element instanceof HTMLAnchorElement && element.href) {
        execute = createNavigateAction(element.getAttribute("href") || element.href);
        executeKey = `href:${element.getAttribute("href") || element.href}`;
      } else if (element.dataset.searchActionEvent) {
        const eventName = element.dataset.searchActionEvent;
        execute = () => {
          document.dispatchEvent(new CustomEvent(eventName, { detail: { source: "command-menu" } }));
        };
        executeKey = `event:${eventName}`;
      } else if (element instanceof HTMLButtonElement) {
        execute = () => element.click();
        executeKey = `button:${title}:${index}`;
      }

      if (!execute) {
        return null;
      }

      const key = `${title.toLowerCase()}|${executeKey}`;
      if (seen.has(key)) {
        return null;
      }
      seen.add(key);

      if (subtitle === "") {
        subtitle = "Action available in the current view";
      }

      return {
        id: `current-view:${index}:${title.toLowerCase().replace(/[^a-z0-9]+/g, "-")}`,
        title,
        subtitle,
        section: "current-view",
        keywords,
        aliases: [],
        breadcrumbs: ["Current view"],
        entityType: "ui-action",
        execute,
        executeKey,
        priority: 340,
      };
    })
    .filter(Boolean);
}
