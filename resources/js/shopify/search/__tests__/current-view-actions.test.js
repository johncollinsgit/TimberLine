import test from "node:test";
import assert from "node:assert/strict";
import { discoverCurrentViewActions } from "../currentViewActions.js";

class FakeElement {
  constructor({
    dataset = {},
    attributes = {},
    textContent = "",
    href = "",
    matches = [],
    disabled = false,
    style = {},
  } = {}) {
    this.dataset = dataset;
    this._attributes = new Map(Object.entries(attributes));
    this.textContent = textContent;
    this.href = href;
    this.disabled = disabled;
    this._matches = matches;
    this._style = {
      display: "block",
      visibility: "visible",
      ...style,
    };
  }

  hasAttribute(name) {
    return this._attributes.has(name);
  }

  getAttribute(name) {
    return this._attributes.get(name) ?? null;
  }

  matches(selector) {
    return String(selector || "")
      .split(",")
      .map((entry) => entry.trim())
      .filter(Boolean)
      .some((entry) => this._matches.includes(entry));
  }
}

class FakeAnchorElement extends FakeElement {}
class FakeButtonElement extends FakeElement {}

test("current-view harvesting includes explicit controls and excludes hidden/disabled/decorative", () => {
  const originalWindow = globalThis.window;
  const originalDocument = globalThis.document;
  const originalHTMLElement = globalThis.HTMLElement;
  const originalAnchor = globalThis.HTMLAnchorElement;
  const originalButton = globalThis.HTMLButtonElement;

  globalThis.HTMLElement = FakeElement;
  globalThis.HTMLAnchorElement = FakeAnchorElement;
  globalThis.HTMLButtonElement = FakeButtonElement;

  const explicitAction = new FakeAnchorElement({
    dataset: {
      searchId: "explicit:shipping",
      searchTitle: "Go to shipping",
    },
    attributes: {
      "data-search-action": "1",
      href: "/shopify/app/settings",
    },
    textContent: "Go to shipping",
    href: "/shopify/app/settings",
    matches: [".app-topbar-subnav-link"],
  });

  const strictFallback = new FakeAnchorElement({
    attributes: {
      href: "/shopify/app/rewards",
    },
    textContent: "Rewards",
    href: "/shopify/app/rewards",
    matches: [".app-topbar-subnav-link"],
  });

  const hiddenAction = new FakeAnchorElement({
    dataset: {
      searchTitle: "Hidden action",
    },
    attributes: {
      "data-search-action": "1",
      hidden: "hidden",
      href: "/shopify/app/hidden",
    },
    textContent: "Hidden action",
    href: "/shopify/app/hidden",
    matches: [".app-topbar-subnav-link"],
  });

  const disabledButton = new FakeButtonElement({
    dataset: {
      searchTitle: "Disabled button",
    },
    attributes: {
      "data-search-action": "1",
    },
    textContent: "Disabled button",
    disabled: true,
  });

  const decorativeAction = new FakeAnchorElement({
    dataset: {
      searchTitle: "Decorative action",
    },
    attributes: {
      "data-search-action": "1",
      role: "presentation",
      href: "/shopify/app/decorative",
    },
    textContent: "Decorative action",
    href: "/shopify/app/decorative",
    matches: [".app-topbar-subnav-link"],
  });

  globalThis.window = {
    location: {
      origin: "https://app.grovebud.com",
    },
    getComputedStyle(element) {
      return element._style;
    },
  };

  globalThis.document = {
    querySelectorAll(selector) {
      if (selector === "[data-search-action]") {
        return [explicitAction, hiddenAction, disabledButton, decorativeAction];
      }

      if (selector === ".app-topbar-subnav-link") {
        return [explicitAction, strictFallback, hiddenAction, decorativeAction];
      }

      if (selector === ".app-topbar-actions .app-topbar-action") {
        return [];
      }

      return [];
    },
    dispatchEvent() {},
  };

  try {
    const docs = discoverCurrentViewActions({
      createNavigateAction: () => () => {},
    });

    assert.equal(docs.some((doc) => doc.id === "explicit:shipping"), true);
    assert.equal(docs.some((doc) => doc.source === "current-view-harvested"), true);
    assert.equal(docs.some((doc) => doc.title === "Hidden action"), false);
    assert.equal(docs.some((doc) => doc.title === "Disabled button"), false);
    assert.equal(docs.some((doc) => doc.title === "Decorative action"), false);
  } finally {
    globalThis.window = originalWindow;
    globalThis.document = originalDocument;
    globalThis.HTMLElement = originalHTMLElement;
    globalThis.HTMLAnchorElement = originalAnchor;
    globalThis.HTMLButtonElement = originalButton;
  }
});

