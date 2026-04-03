import test from "node:test";
import assert from "node:assert/strict";
import {
  buildSuggestedDocuments,
  isRecentEligibleDocument,
  materializeRecentDocuments,
  mergeRecentIds,
} from "../recentActions.js";

const actionDocuments = [
  {
    id: "shopify:action:create-product",
    title: "Create product",
    section: "actions",
    source: "explicit",
    executeKey: "navigate:/admin/products/new",
    priority: 520,
  },
  {
    id: "shopify:action:go-orders",
    title: "Go to orders",
    section: "actions",
    source: "explicit",
    executeKey: "navigate:/admin/orders",
    priority: 500,
  },
];

test("recent ids are merged and deduplicated in order", () => {
  const merged = mergeRecentIds(["a", "b", "c"], "b", 5);
  assert.deepEqual(merged, ["b", "a", "c"]);
});

test("stale recent ids are dropped when materializing recent docs", () => {
  const { recentDocuments, retainedIds } = materializeRecentDocuments(
    ["shopify:action:create-product", "missing:id"],
    actionDocuments
  );

  assert.equal(recentDocuments.length, 1);
  assert.equal(recentDocuments[0].id, "shopify:action:create-product");
  assert.deepEqual(retainedIds, ["shopify:action:create-product"]);
});

test("suggested docs are returned from pinned ids when available", () => {
  const suggested = buildSuggestedDocuments(
    actionDocuments,
    new Set(),
    ["shopify:action:go-orders"]
  );

  assert.equal(suggested.length, 1);
  assert.equal(suggested[0].id, "shopify:action:go-orders");
});

test("harvested button actions are not eligible for recents", () => {
  const eligible = isRecentEligibleDocument({
    id: "current-view:button:test",
    section: "current-view",
    source: "current-view-harvested",
    executeKey: "button:test",
  });

  assert.equal(eligible, false);
});

