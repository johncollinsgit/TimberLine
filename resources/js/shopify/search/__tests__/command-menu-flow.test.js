import test from "node:test";
import assert from "node:assert/strict";
import { ActionSearchProvider } from "../ActionSearchProvider.js";
import { registerSearchActions } from "../registerSearchActions.js";
import { buildCustomerIdAction } from "../defaultShopifySearchActions.js";
import { rankDocuments } from "../searchRanking.js";

test("newly registered feature action becomes searchable without changing search core", () => {
  const provider = new ActionSearchProvider();

  registerSearchActions(
    "feature:shipping",
    [
      {
        id: "feature:shipping:rates",
        title: "Go to settings > shipping",
        subtitle: "Open shipping settings",
        section: "actions",
        keywords: ["shipping", "rates", "settings"],
        aliases: ["shipping settings"],
        breadcrumbs: ["Settings", "Shipping"],
        execute: () => {},
        executeKey: "navigate:/admin/settings/shipping",
      },
    ],
    provider
  );

  const ranked = rankDocuments(provider.snapshot(), "shipping");
  assert.equal(ranked[0].id, "feature:shipping:rates");
});

test("dynamic customer action executes navigation with embedded context", () => {
  const originalWindow = globalThis.window;
  const destination = { value: "" };

  globalThis.window = {
    location: {
      origin: "https://app.forestrybackstage.com",
      search: "?shop=demo.myshopify.com&host=host-token&embedded=1",
      assign: (url) => {
        destination.value = String(url || "");
      },
    },
    top: null,
  };
  globalThis.window.top = globalThis.window;

  try {
    const action = buildCustomerIdAction("open customer 123", {
      baseQuery: "?shop=demo.myshopify.com&host=host-token&embedded=1",
    });

    assert.ok(action);
    action.execute();

    const url = new URL(destination.value, "https://app.forestrybackstage.com");
    assert.equal(url.pathname, "/shopify/app/customers/manage/123");
    assert.equal(url.searchParams.get("shop"), "demo.myshopify.com");
    assert.equal(url.searchParams.get("host"), "host-token");
    assert.equal(url.searchParams.get("embedded"), "1");
  } finally {
    globalThis.window = originalWindow;
  }
});
