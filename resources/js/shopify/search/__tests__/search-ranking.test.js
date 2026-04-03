import test from "node:test";
import assert from "node:assert/strict";
import { dedupeDocuments, rankDocuments } from "../searchRanking.js";

const documents = [
  {
    id: "action:create-product",
    title: "Create product",
    subtitle: "Open product creation",
    section: "actions",
    keywords: ["new product", "catalog"],
    aliases: ["product"],
    breadcrumbs: ["Shopify tools"],
    executeKey: "navigate:/admin/products/new",
    priority: 500,
  },
  {
    id: "page:orders",
    title: "Go to orders",
    subtitle: "Open orders dashboard",
    section: "pages",
    keywords: ["orders"],
    aliases: [],
    breadcrumbs: ["Orders"],
    executeKey: "navigate:/orders",
    priority: 350,
  },
  {
    id: "action:create-product",
    title: "Create product",
    subtitle: "Open product creation",
    section: "actions",
    keywords: ["new product", "catalog"],
    aliases: ["product"],
    breadcrumbs: ["Shopify tools"],
    executeKey: "navigate:/admin/products/new",
    priority: 500,
  },
];

test("dedupe removes duplicate action documents", () => {
  const deduped = dedupeDocuments(documents);
  assert.equal(deduped.length, 2);
});

test("ranking prioritizes exact and alias matches", () => {
  const rankedForProduct = rankDocuments(documents, "new product");
  assert.equal(rankedForProduct[0].id, "action:create-product");

  const rankedForOrders = rankDocuments(documents, "orders");
  assert.equal(rankedForOrders[0].id, "page:orders");
});
