import { registerSearchActions } from "./registerSearchActions.js";
import { adminShopifyUrl, createNavigateAction } from "./navigationHelpers.js";

function normalizeLabel(section) {
  const value = String(section || "").trim();
  if (value === "") {
    return "Page";
  }

  return value
    .replace(/[-_]/g, " ")
    .replace(/\b\w/g, (char) => char.toUpperCase());
}

function routeDocumentToAction(document, helpers) {
  const execute = document && typeof document.execute === "object" ? document.execute : null;
  const executeType = String(execute?.type || "").trim();
  const executeUrl = String(execute?.url || "").trim();

  if (executeType !== "navigate" || executeUrl === "") {
    return null;
  }

  const section = String(document.section || "pages");

  return {
    id: String(document.id || "").trim(),
    title: String(document.title || "").trim(),
    subtitle: String(document.subtitle || "").trim() || `Open ${normalizeLabel(section)}`,
    section: ["actions", "pages", "current-view", "recent"].includes(section) ? section : "pages",
    keywords: Array.isArray(document.keywords) ? document.keywords : [],
    aliases: Array.isArray(document.aliases) ? document.aliases : [],
    breadcrumbs: Array.isArray(document.breadcrumbs) ? document.breadcrumbs : [],
    entityType: String(document.entityType || "page"),
    execute: createNavigateAction(executeUrl, { appendContext: true, baseQuery: helpers.baseQuery }),
    executeKey: `navigate:${executeUrl}`,
    priority: section === "current-view" ? 380 : 300,
  };
}

export function registerRouteDiscoveryDocuments(routeDocuments, helpers = {}, provider) {
  const documents = (Array.isArray(routeDocuments) ? routeDocuments : [])
    .map((document) => routeDocumentToAction(document, helpers))
    .filter(Boolean);

  return registerSearchActions("shopify:route-discovery", documents, provider);
}

export function registerDefaultShopifyActions(context = {}, provider) {
  const baseQuery = String(context.baseQuery || window.location.search || "");
  const shopDomain = String(context.shopDomain || "").trim();

  const actions = [
    {
      id: "shopify:action:create-product",
      title: "Create product",
      subtitle: "Open Shopify Admin product creation",
      section: "actions",
      keywords: ["new product", "add product", "catalog"],
      aliases: ["product", "products"],
      breadcrumbs: ["Shopify tools"],
      entityType: "shopify-admin",
      execute: createNavigateAction(adminShopifyUrl(shopDomain, "/admin/products/new"), { appendContext: false }),
      executeKey: "admin:/products/new",
      priority: 520,
    },
    {
      id: "shopify:action:go-orders",
      title: "Go to orders",
      subtitle: "Open Shopify Admin orders",
      section: "actions",
      keywords: ["orders", "order list", "fulfillment"],
      aliases: ["orders"],
      breadcrumbs: ["Shopify tools"],
      entityType: "shopify-admin",
      execute: createNavigateAction(adminShopifyUrl(shopDomain, "/admin/orders"), { appendContext: false }),
      executeKey: "admin:/orders",
      priority: 500,
    },
    {
      id: "shopify:action:abandoned-checkouts",
      title: "View abandoned checkouts",
      subtitle: "Open abandoned checkouts in Shopify Admin",
      section: "actions",
      keywords: ["abandoned", "checkouts", "recover carts"],
      aliases: ["abandoned carts"],
      breadcrumbs: ["Shopify tools"],
      entityType: "shopify-admin",
      execute: createNavigateAction(adminShopifyUrl(shopDomain, "/admin/checkouts", { status: "abandoned" }), { appendContext: false }),
      executeKey: "admin:/checkouts?status=abandoned",
      priority: 490,
    },
    {
      id: "shopify:action:create-discount",
      title: "Create discount",
      subtitle: "Open discount creation in Shopify Admin",
      section: "actions",
      keywords: ["discount", "coupon", "promotion", "promo code"],
      aliases: ["new discount"],
      breadcrumbs: ["Shopify tools"],
      entityType: "shopify-admin",
      execute: createNavigateAction(adminShopifyUrl(shopDomain, "/admin/discounts/new"), { appendContext: false }),
      executeKey: "admin:/discounts/new",
      priority: 500,
    },
    {
      id: "shopify:action:settings-shipping",
      title: "Go to settings > shipping",
      subtitle: "Open shipping settings in Shopify Admin",
      section: "actions",
      keywords: ["shipping", "delivery", "rates", "settings shipping"],
      aliases: ["shipping settings"],
      breadcrumbs: ["Shopify tools", "Settings"],
      entityType: "shopify-admin",
      execute: createNavigateAction(adminShopifyUrl(shopDomain, "/admin/settings/shipping"), { appendContext: false }),
      executeKey: "admin:/settings/shipping",
      priority: 500,
    },
    {
      id: "shopify:action:open-current-page-section",
      title: "Open current page section",
      subtitle: "Jump to the first section on this page",
      section: "actions",
      keywords: ["current page", "section", "jump", "scroll"],
      aliases: ["open section"],
      breadcrumbs: ["Current view"],
      entityType: "ui-action",
      execute: () => {
        const section = document.querySelector(".app-shell-content section[id], .app-shell-content [data-setup-panel]");
        if (section instanceof HTMLElement) {
          section.scrollIntoView({ behavior: "smooth", block: "start" });
          section.focus?.();
        }
      },
      executeKey: "ui:current-section",
      priority: 470,
    },
    {
      id: "shopify:recent:suggested-settings",
      title: "Open settings",
      subtitle: "Suggested from recent workflow",
      section: "recent",
      keywords: ["settings", "preferences"],
      aliases: ["suggested settings"],
      breadcrumbs: ["Suggested"],
      entityType: "page",
      execute: createNavigateAction("/shopify/app/settings", { appendContext: true, baseQuery }),
      executeKey: "navigate:/shopify/app/settings",
      priority: 250,
    },
  ].filter((action) => typeof action.execute === "function");

  return registerSearchActions("shopify:default-actions", actions, provider);
}

export function buildCustomerIdAction(query, helpers = {}) {
  const normalized = String(query || "").trim().toLowerCase();
  const match = normalized.match(/(?:open|go to)?\s*customer\s*#?(\d{1,12})$/);
  if (!match) {
    return null;
  }

  const customerId = Number(match[1]);
  if (!Number.isFinite(customerId) || customerId <= 0) {
    return null;
  }

  const path = `/shopify/app/customers/manage/${customerId}`;

  return {
    id: `shopify:dynamic:customer:${customerId}`,
    title: `Open customer ${customerId}`,
    subtitle: "Open customer detail by id",
    section: "actions",
    keywords: ["customer", String(customerId), "open customer"],
    aliases: ["customer detail"],
    breadcrumbs: ["Customers"],
    entityType: "customer",
    execute: createNavigateAction(path, { appendContext: true, baseQuery: helpers.baseQuery }),
    executeKey: `navigate:${path}`,
    priority: 560,
  };
}
