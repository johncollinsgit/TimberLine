import test from "node:test";
import assert from "node:assert/strict";
import {
  COMMAND_MENU_FORM_SELECTOR,
  COMMAND_MENU_ROOT_SELECTOR,
  appendEmbeddedContext,
  createCommandMenuMountManager,
} from "../commandMenuMountManager.js";

function createFakeDocument({ root }) {
  const listeners = new Map();
  const dispatched = [];

  return {
    listeners,
    dispatched,
    querySelectorAll(selector) {
      if (selector === COMMAND_MENU_ROOT_SELECTOR) {
        return root ? [root] : [];
      }

      return [];
    },
    querySelector(selector) {
      if (selector === COMMAND_MENU_ROOT_SELECTOR) {
        return root;
      }

      return null;
    },
    addEventListener(name, callback) {
      listeners.set(name, callback);
    },
    dispatchEvent(event) {
      dispatched.push(event?.detail || event);
      return true;
    },
  };
}

function createFakeRoot({ routeDocuments = [], context = {} } = {}) {
  return {
    dataset: {
      placeholder: "Search actions, pages, and Shopify tools",
      contextLabel: "Commerce",
      searchEndpoint: "/shopify/app/api/search",
    },
    querySelector(selector) {
      if (selector === "script[data-command-documents]") {
        return {
          textContent: JSON.stringify(routeDocuments),
        };
      }

      if (selector === "script[data-command-context]") {
        return {
          textContent: JSON.stringify(context),
        };
      }

      return null;
    },
  };
}

test("mount manager mounts once, marks root ready, and survives repeated init", () => {
  const root = createFakeRoot({
    routeDocuments: [{ id: "page:home", execute: { type: "navigate", url: "/shopify/app" } }],
    context: { shopDomain: "demo.myshopify.com" },
  });
  const documentRef = createFakeDocument({ root });
  const trackCalls = [];
  let renderCount = 0;

  const manager = createCommandMenuMountManager({
    registerDefaultActions: () => () => {},
    registerRouteDiscoveryActions: () => () => {},
    renderMenu: () => {
      renderCount += 1;
      return () => {};
    },
    documentRef,
    windowRef: {
      location: {
        pathname: "/shopify/app",
        search: "?host=host-token&shop=demo.myshopify.com&embedded=1",
        origin: "https://app.theeverbranch.com",
      },
    },
    trackEvent: (name, payload) => {
      trackCalls.push({ name, payload });
    },
  });

  manager.init();
  manager.init();

  assert.equal(renderCount, 1);
  assert.equal(root.dataset.commandMenuReady, "1");
  assert.equal(trackCalls.some((call) => call.name === "command_menu_mount_succeeded"), true);
});

test("mount manager emits failure event and marks root unready when render fails", () => {
  const root = createFakeRoot();
  const documentRef = createFakeDocument({ root });
  const trackCalls = [];

  const manager = createCommandMenuMountManager({
    registerDefaultActions: () => () => {},
    registerRouteDiscoveryActions: () => () => {},
    renderMenu: () => {
      throw new Error("render exploded");
    },
    documentRef,
    windowRef: {
      location: {
        pathname: "/shopify/app",
        search: "",
        origin: "https://app.theeverbranch.com",
      },
    },
    trackEvent: (name, payload) => {
      trackCalls.push({ name, payload });
    },
  });

  manager.init();

  assert.equal(root.dataset.commandMenuReady, "0");
  assert.equal(trackCalls.some((call) => call.name === "command_menu_mount_failed"), true);
});

test("fallback submit navigates from search endpoint when mount is not ready", async () => {
  const root = createFakeRoot({
    routeDocuments: [{ id: "page:home", execute: { type: "navigate", url: "/shopify/app" } }],
  });
  root.dataset.commandMenuReady = "0";

  const documentRef = createFakeDocument({ root });
  const assigned = { value: "" };
  const trackCalls = [];
  const windowRef = {
    location: {
      pathname: "/shopify/app",
      search: "?host=host-token&shop=demo.myshopify.com&embedded=1",
      origin: "https://app.theeverbranch.com",
      assign(url) {
        assigned.value = String(url || "");
      },
    },
    top: null,
  };
  windowRef.top = windowRef;

  const manager = createCommandMenuMountManager({
    registerDefaultActions: () => () => {},
    registerRouteDiscoveryActions: () => () => {},
    renderMenu: () => () => {},
    documentRef,
    windowRef,
    fetchImpl: async () => ({
      async json() {
        return {
          results: [
            { url: "/shopify/app/customers/manage?shop=demo.myshopify.com&host=host-token&embedded=1" },
          ],
        };
      },
    }),
    trackEvent: (name, payload) => {
      trackCalls.push({ name, payload });
    },
  });

  manager.bindFallbackSubmit();

  const form = {
    matches(selector) {
      return selector === COMMAND_MENU_FORM_SELECTOR;
    },
    querySelector(selector) {
      if (selector === "[data-command-field]") {
        return { value: "customer" };
      }

      return null;
    },
  };

  let prevented = false;
  await documentRef.listeners.get("submit")({
    target: form,
    preventDefault() {
      prevented = true;
    },
  });

  assert.equal(prevented, true);
  assert.equal(assigned.value.includes("/shopify/app/customers/manage"), true);
  assert.equal(trackCalls.some((call) => call.name === "command_menu_fallback_navigation"), true);
});

test("appendEmbeddedContext keeps embedded params for relative URLs", () => {
  const url = appendEmbeddedContext(
    "/shopify/app/settings",
    "?shop=demo.myshopify.com&host=host-token&embedded=1"
  );

  assert.equal(url.includes("shop=demo.myshopify.com"), true);
  assert.equal(url.includes("host=host-token"), true);
  assert.equal(url.includes("embedded=1"), true);
});
