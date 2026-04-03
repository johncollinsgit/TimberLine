import React from "react";
import { createRoot } from "react-dom/client";
import { GlobalCommandMenu } from "./search/GlobalCommandMenu.jsx";
import {
  registerDefaultShopifyActions,
  registerRouteDiscoveryDocuments,
} from "./search/defaultShopifySearchActions.js";

const mountedRoots = new WeakMap();

function readJsonScript(root, selector, fallback = {}) {
  const node = root.querySelector(selector);
  if (!(node instanceof HTMLScriptElement)) {
    return fallback;
  }

  try {
    return JSON.parse(node.textContent || "null") ?? fallback;
  } catch (_error) {
    return fallback;
  }
}

function mountCommandMenu(root) {
  if (!(root instanceof HTMLElement)) {
    return;
  }

  const mounted = mountedRoots.get(root);
  if (mounted) {
    mounted.unregister?.();
    mounted.reactRoot?.unmount?.();
    mountedRoots.delete(root);
  }

  const placeholder = String(root.dataset.placeholder || "Search actions, pages, and Shopify tools");
  const contextLabel = String(root.dataset.contextLabel || "Commerce");
  const baseQuery = window.location.search || "";
  const routeDocuments = readJsonScript(root, "script[data-command-documents]", []);
  const context = readJsonScript(root, "script[data-command-context]", {});

  const unregisterDefaults = registerDefaultShopifyActions({
    baseQuery,
    shopDomain: context.shopDomain || null,
  });
  const unregisterRoutes = registerRouteDiscoveryDocuments(routeDocuments, {
    baseQuery,
  });

  const reactRoot = createRoot(root);
  reactRoot.render(
    <React.StrictMode>
      <GlobalCommandMenu
        placeholder={placeholder}
        contextLabel={contextLabel}
        baseQuery={baseQuery}
      />
    </React.StrictMode>
  );

  mountedRoots.set(root, {
    reactRoot,
    unregister: () => {
      unregisterDefaults?.();
      unregisterRoutes?.();
    },
  });
}

function initCommandMenus() {
  document
    .querySelectorAll("[data-shopify-global-command-menu]")
    .forEach((root) => mountCommandMenu(root));
}

initCommandMenus();
document.addEventListener("livewire:navigated", initCommandMenus);
