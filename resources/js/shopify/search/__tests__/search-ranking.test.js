import test from "node:test";
import assert from "node:assert/strict";
import {
  createSearchIndex,
  dedupeDocuments,
  rankDocuments,
  rankWithIndex,
} from "../searchRanking.js";

const baseDocuments = [
  {
    id: "action:create-product",
    title: "Create product",
    subtitle: "Open product creation",
    section: "actions",
    keywords: ["new product", "add product", "catalog"],
    aliases: ["product"],
    synonyms: ["new", "add"],
    breadcrumbs: ["Shopify tools"],
    intentPhrases: ["create", "open"],
    source: "explicit",
    executeKey: "navigate:/admin/products/new",
    priority: 520,
  },
  {
    id: "page:products",
    title: "Products",
    subtitle: "Open product list",
    section: "pages",
    keywords: ["catalog"],
    aliases: ["products"],
    breadcrumbs: ["Catalog"],
    source: "route-discovery",
    executeKey: "navigate:/shopify/app/products",
    priority: 320,
  },
  {
    id: "action:settings-shipping",
    title: "Go to settings > shipping",
    subtitle: "Open shipping settings",
    section: "actions",
    keywords: ["shipping", "delivery", "rates"],
    aliases: ["shipping settings"],
    breadcrumbs: ["Settings"],
    intentPhrases: ["go to", "open", "manage"],
    source: "explicit",
    executeKey: "navigate:/admin/settings/shipping",
    priority: 500,
  },
  {
    id: "page:settings",
    title: "Settings",
    subtitle: "Workspace settings",
    section: "pages",
    keywords: ["settings", "preferences"],
    aliases: ["prefs"],
    breadcrumbs: ["Settings", "Workspace"],
    source: "route-discovery",
    executeKey: "navigate:/shopify/app/settings",
    priority: 330,
  },
];

test("exact title and imperative intent rank action ahead of generic page", () => {
  const ranked = rankDocuments(baseDocuments, "new product");
  assert.equal(ranked[0].id, "action:create-product");
});

test("specific shipping action ranks above generic settings page", () => {
  const ranked = rankDocuments(baseDocuments, "shipping");
  assert.equal(ranked[0].id, "action:settings-shipping");
  assert.equal(ranked[1].id, "page:settings");
});

test("exact title beats breadcrumb-only match", () => {
  const docs = [
    {
      id: "page:rewards",
      title: "Rewards",
      subtitle: "Rewards overview",
      section: "pages",
      keywords: ["rewards"],
      aliases: [],
      breadcrumbs: ["Loyalty", "Shipping"],
      source: "route-discovery",
      executeKey: "navigate:/shopify/app/rewards",
      priority: 320,
    },
    {
      id: "page:shipping-breadcrumb",
      title: "Fulfillment tools",
      subtitle: "Workflow tools",
      section: "pages",
      keywords: ["fulfillment"],
      aliases: [],
      breadcrumbs: ["Settings", "Shipping"],
      source: "route-discovery",
      executeKey: "navigate:/shopify/app/tools",
      priority: 320,
    },
  ];

  const ranked = rankDocuments(docs, "rewards");
  assert.equal(ranked[0].id, "page:rewards");
});

test("dedupe keeps explicit action over discovered route duplicate", () => {
  const docs = [
    {
      id: "action:settings",
      title: "Settings",
      subtitle: "Open settings",
      section: "actions",
      source: "explicit",
      executeKey: "navigate:/shopify/app/settings",
      priority: 320,
    },
    {
      id: "page:settings",
      title: "Settings",
      subtitle: "Open settings",
      section: "pages",
      source: "route-discovery",
      executeKey: "navigate:/shopify/app/settings",
      priority: 520,
    },
  ];

  const deduped = dedupeDocuments(docs);
  assert.equal(deduped.length, 1);
  assert.equal(deduped[0].id, "action:settings");
});

test("search index is created once and reused across rank calls", () => {
  let fuseCreationCount = 0;

  class FakeFuse {
    constructor(documents) {
      this.documents = documents;
      fuseCreationCount += 1;
    }

    search(query) {
      if (query.includes("product")) {
        return [{ item: this.documents[0], score: 0.02 }];
      }

      return [];
    }
  }

  const index = createSearchIndex(baseDocuments, { fuseFactory: FakeFuse });
  rankWithIndex(index, "product");
  rankWithIndex(index, "new product");

  assert.equal(fuseCreationCount, 1);
});

