import { buildQueryTelemetry, trackCommandMenuEvent } from "./commandMenuTelemetry.js";

export const COMMAND_MENU_ROOT_SELECTOR = "[data-shopify-global-command-menu]";
export const COMMAND_MENU_DOCUMENTS_SELECTOR = "script[data-command-documents]";
export const COMMAND_MENU_CONTEXT_SELECTOR = "script[data-command-context]";
export const COMMAND_MENU_FORM_SELECTOR = "[data-command-form]";

const DEFAULT_PLACEHOLDER = "Search actions, pages, and Shopify tools";
const DEFAULT_CONTEXT_LABEL = "Commerce";
const EMBEDDED_QUERY_KEYS = [
  "embedded",
  "host",
  "shop",
  "hmac",
  "timestamp",
  "id_token",
  "locale",
  "session",
];

function asString(value, fallback = "") {
  const normalized = String(value ?? "").trim();
  return normalized === "" ? fallback : normalized;
}

function normalizeError(error) {
  if (error instanceof Error) {
    return error.message || error.name || "unknown_error";
  }

  return asString(error, "unknown_error");
}

function dispatchMountEvent(documentRef, detail) {
  if (!documentRef || typeof documentRef.dispatchEvent !== "function") {
    return;
  }

  if (typeof CustomEvent === "function") {
    documentRef.dispatchEvent(new CustomEvent("fb:command-menu:mount", { detail }));
    return;
  }

  documentRef.dispatchEvent({ type: "fb:command-menu:mount", detail });
}

export function readJsonScript(root, selector, fallback = {}) {
  const node = root && typeof root.querySelector === "function"
    ? root.querySelector(selector)
    : null;
  if (!node || typeof node.textContent !== "string") {
    return fallback;
  }

  try {
    return JSON.parse(node.textContent || "null") ?? fallback;
  } catch (_error) {
    return fallback;
  }
}

export function appendEmbeddedContext(url, baseQuery = "") {
  let target;
  try {
    target = new URL(String(url || ""), "http://local");
  } catch (_error) {
    return String(url || "");
  }

  const source = new URLSearchParams(String(baseQuery || ""));
  EMBEDDED_QUERY_KEYS.forEach((key) => {
    if (!source.has(key) || target.searchParams.has(key)) {
      return;
    }

    const value = source.get(key);
    if (value) {
      target.searchParams.set(key, value);
    }
  });

  if (target.origin === "http://local") {
    return `${target.pathname}${target.search}${target.hash}`;
  }

  return target.toString();
}

function firstResultUrl(payload) {
  const results = Array.isArray(payload?.results) ? payload.results : [];
  const first = results.find((row) => typeof row?.url === "string" && row.url.trim() !== "");
  return first ? String(first.url).trim() : "";
}

function navigateTo(url, windowRef) {
  const destination = asString(url);
  if (destination === "") {
    return;
  }

  try {
    if (windowRef?.top && windowRef.top !== windowRef && windowRef.top.location?.assign) {
      windowRef.top.location.assign(destination);
      return;
    }
  } catch (_error) {
    // Ignore cross-origin frame checks.
  }

  windowRef?.location?.assign?.(destination);
}

export function createCommandMenuMountManager({
  registerDefaultActions,
  registerRouteDiscoveryActions,
  renderMenu,
  documentRef = typeof document !== "undefined" ? document : null,
  windowRef = typeof window !== "undefined" ? window : null,
  fetchImpl = typeof fetch === "function" ? fetch.bind(globalThis) : null,
  trackEvent = trackCommandMenuEvent,
} = {}) {
  const mountedRoots = new WeakMap();
  let fallbackBound = false;

  const setMountState = (root, status, error = null) => {
    if (!root || !root.dataset) {
      return;
    }

    root.dataset.commandMenuReady = status === "success" ? "1" : "0";
    if (status === "success") {
      delete root.dataset.commandMenuError;
      return;
    }

    root.dataset.commandMenuError = normalizeError(error);
  };

  const emitMountTelemetry = (root, status, error = null) => {
    const detail = {
      status,
      error: status === "success" ? null : normalizeError(error),
      route: asString(windowRef?.location?.pathname, null),
    };

    dispatchMountEvent(documentRef, detail);
    trackEvent(
      status === "success" ? "command_menu_mount_succeeded" : "command_menu_mount_failed",
      detail
    );
  };

  const unmountRoot = (root) => {
    const mounted = mountedRoots.get(root);
    if (!mounted) {
      return;
    }

    try {
      mounted.unregisterDefaults?.();
      mounted.unregisterRoutes?.();
      mounted.cleanup?.();
    } finally {
      mountedRoots.delete(root);
      setMountState(root, "failure");
    }
  };

  const mountRoot = (root) => {
    if (!root || typeof root.querySelector !== "function" || !root.dataset) {
      return false;
    }

    if (mountedRoots.has(root)) {
      return true;
    }

    const placeholder = asString(root.dataset.placeholder, DEFAULT_PLACEHOLDER);
    const contextLabel = asString(root.dataset.contextLabel, DEFAULT_CONTEXT_LABEL);
    const baseQuery = asString(windowRef?.location?.search, "");
    const routeDocuments = readJsonScript(root, COMMAND_MENU_DOCUMENTS_SELECTOR, []);
    const context = readJsonScript(root, COMMAND_MENU_CONTEXT_SELECTOR, {});

    try {
      const unregisterDefaults = registerDefaultActions?.({
        baseQuery,
        shopDomain: context?.shopDomain || null,
      });
      const unregisterRoutes = registerRouteDiscoveryActions?.(routeDocuments, {
        baseQuery,
      });
      const cleanup = renderMenu?.(root, {
        placeholder,
        contextLabel,
        baseQuery,
      });

      mountedRoots.set(root, {
        unregisterDefaults,
        unregisterRoutes,
        cleanup,
      });
      setMountState(root, "success");
      emitMountTelemetry(root, "success");

      return true;
    } catch (error) {
      unmountRoot(root);
      setMountState(root, "failure", error);
      emitMountTelemetry(root, "failure", error);

      return false;
    }
  };

  const init = () => {
    if (!documentRef || typeof documentRef.querySelectorAll !== "function") {
      return;
    }

    const roots = Array.from(documentRef.querySelectorAll(COMMAND_MENU_ROOT_SELECTOR));
    roots.forEach((root) => {
      mountRoot(root);
    });
  };

  const executeFallbackSearch = async (root, query) => {
    if (!fetchImpl) {
      return "";
    }

    const endpoint = asString(root?.dataset?.searchEndpoint);
    if (endpoint === "") {
      return "";
    }

    const url = new URL(endpoint, windowRef?.location?.origin || "http://local");
    const normalizedQuery = asString(query);
    if (normalizedQuery !== "") {
      url.searchParams.set("q", normalizedQuery);
      url.searchParams.set("limit", "10");
    }

    const requestUrl = appendEmbeddedContext(
      url.toString(),
      asString(windowRef?.location?.search, "")
    );

    const response = await fetchImpl(requestUrl, {
      method: "GET",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
    });
    const payload = typeof response?.json === "function" ? await response.json() : null;
    let destination = firstResultUrl(payload);
    if (destination === "") {
      const routeDocuments = readJsonScript(root, COMMAND_MENU_DOCUMENTS_SELECTOR, []);
      const fallbackDoc = Array.isArray(routeDocuments)
        ? routeDocuments.find((row) => typeof row?.execute?.url === "string" && row.execute.url.trim() !== "")
        : null;
      destination = fallbackDoc ? String(fallbackDoc.execute.url).trim() : "";
    }

    return destination;
  };

  const fallbackSubmitHandler = async (event) => {
    const form = event?.target;
    if (!form || typeof form.matches !== "function" || !form.matches(COMMAND_MENU_FORM_SELECTOR)) {
      return;
    }

    if (!documentRef || typeof documentRef.querySelector !== "function") {
      return;
    }

    const root = documentRef.querySelector(COMMAND_MENU_ROOT_SELECTOR);
    if (!root || root.dataset?.commandMenuReady === "1") {
      return;
    }

    const field = typeof form.querySelector === "function"
      ? form.querySelector("[data-command-field]")
      : null;
    const query = asString(field?.value);

    event.preventDefault?.();

    try {
      const destination = await executeFallbackSearch(root, query);
      if (destination === "") {
        trackEvent("command_menu_submit_no_results", {
          source: "mount_fallback_submit",
          ...buildQueryTelemetry(query),
        });

        return;
      }

      trackEvent("command_menu_fallback_navigation", {
        source: "mount_fallback_submit",
        ...buildQueryTelemetry(query),
      });
      navigateTo(destination, windowRef);
    } catch (error) {
      trackEvent("command_menu_fallback_failed", {
        source: "mount_fallback_submit",
        error: normalizeError(error),
        ...buildQueryTelemetry(query),
      });
    }
  };

  const bindFallbackSubmit = () => {
    if (fallbackBound || !documentRef || typeof documentRef.addEventListener !== "function") {
      return;
    }

    fallbackBound = true;
    documentRef.addEventListener("submit", fallbackSubmitHandler);
  };

  return {
    init,
    mountRoot,
    unmountRoot,
    bindFallbackSubmit,
    fallbackSubmitHandler,
    _mountedRoots: mountedRoots,
  };
}
