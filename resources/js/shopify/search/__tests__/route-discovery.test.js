import test from "node:test";
import assert from "node:assert/strict";
import { ActionSearchProvider } from "../ActionSearchProvider.js";
import { registerRouteDiscoveryDocuments } from "../defaultShopifySearchActions.js";

test("route discovery registration turns route docs into executable page actions", () => {
  const provider = new ActionSearchProvider();

  registerRouteDiscoveryDocuments(
    [
      {
        id: "page:settings",
        title: "Settings",
        subtitle: "Open settings",
        section: "pages",
        keywords: ["settings", "shipping"],
        breadcrumbs: ["Settings"],
        aliases: ["shopify.app.settings"],
        entityType: "page",
        execute: {
          type: "navigate",
          url: "/shopify/app/settings",
        },
      },
    ],
    { baseQuery: "?shop=demo.myshopify.com&host=abc" },
    provider
  );

  const docs = provider.snapshot();
  assert.equal(docs.length, 1);
  assert.equal(docs[0].id, "page:settings");
  assert.equal(docs[0].section, "pages");
  assert.equal(typeof docs[0].execute, "function");
});
