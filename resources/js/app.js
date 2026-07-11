import "./shopify/command-menu";
import "./bootstrap";
import "./public-premium-motion";

const contextualModules = [
  {
    key: "dashboardWidgets",
    selectors: ["[data-dashboard-root]", "[data-widget-root]"],
    load: () => import("./dashboard-widgets"),
    mountExport: "mountDashboardWidgetsNow",
  },
  {
    key: "onboardingWizard",
    selectors: ["[data-onboarding-wizard-root]"],
    load: () => import("./onboarding/wizard"),
    mountExport: "mountOnboardingWizardNow",
  },
  {
    key: "onboardingGate",
    selectors: ["[data-onboarding-gate-root]"],
    load: () => import("./onboarding/gate"),
    mountExport: "mountOnboardingGateNow",
  },
  {
    key: "publicTabs",
    selectors: ["[data-public-tabs]"],
    load: () => import("./public-tabs"),
    mountExport: "mountPublicTabsNow",
  },
  {
    key: "publicMobileNav",
    selectors: ["[data-public-mobile-nav]"],
    load: () => import("./public-mobile-nav"),
    mountExport: "mountPublicMobileNavNow",
  },
  {
    key: "publicProductDemo",
    selectors: ["[data-public-product-demo]"],
    load: () => import("./public-product-demo"),
    mountExport: "mountPublicProductDemoNow",
  },
  {
    key: "publicPhoneDemo",
    selectors: ["[data-public-phone-demo]"],
    load: () => import("./public-phone-demo"),
    mountExport: "mountPublicPhoneDemoNow",
  },
  {
    key: "publicBud",
    selectors: ["[data-public-bud]"],
    load: () => import("./public-bud"),
    mountExport: "mountPublicBudNow",
  },
  {
    key: "publicProblemGarden",
    selectors: ["[data-problem-garden]"],
    load: () => import("./public-problem-garden"),
    mountExport: "mountPublicProblemGardenNow",
  },
  {
    key: "publicDetailsCards",
    selectors: ["[data-clickable-details-card]"],
    load: () => import("./public-details-cards"),
    mountExport: "mountPublicDetailsCardsNow",
  },
  {
    key: "widgetDnd",
    selectors: ["[data-dashboard-root]", "[data-widget-root]"],
    load: () => import("./widget-dnd"),
    mountExport: "mountWidgetDndNow",
  },
  {
    key: "sidebarDnd",
    selectors: ["[data-sidebar-sortable]"],
    load: () => import("./sidebar-dnd"),
    mountExport: "mountSidebarDndNow",
  },
  {
    key: "ganttScroll",
    selectors: ["[data-gantt-scroll]"],
    load: () => import("./gantt-scroll"),
    mountExport: "mountGanttScrollNow",
  },
];

const contextualModuleState = new Map();

function domHasSelector(selectors) {
  return selectors.some((selector) => document.querySelector(selector));
}

function mountContextualModule(config) {
  if (!domHasSelector(config.selectors)) {
    return;
  }

  const state = contextualModuleState.get(config.key);
  if (state?.mount) {
    state.mount();
    return;
  }

  if (state?.promise) {
    state.promise.then((module) => {
      const mount = module?.[config.mountExport];
      if (typeof mount === "function") {
        mount();
      }
    });
    return;
  }

  const promise = config
    .load()
    .then((module) => {
      const mount = typeof module?.[config.mountExport] === "function"
        ? module[config.mountExport]
        : null;

      contextualModuleState.set(config.key, { mount, promise: null });

      if (mount) {
        mount();
      }

      return module;
    })
    .catch((error) => {
      contextualModuleState.delete(config.key);
      console.error(`Failed to load contextual module: ${config.key}`, error);
      throw error;
    });

  contextualModuleState.set(config.key, { mount: null, promise });
}

function mountContextualModules() {
  contextualModules.forEach((moduleConfig) => {
    mountContextualModule(moduleConfig);
  });
}

mountContextualModules();
document.addEventListener("DOMContentLoaded", mountContextualModules);
document.addEventListener("livewire:navigated", mountContextualModules);
document.addEventListener("livewire:load", mountContextualModules);
if (window.Livewire?.hook) {
  window.Livewire.hook("message.processed", mountContextualModules);
}

if (document.getElementById("shopify-messaging-root")) {
  import("./shopify/messaging");
}

if (document.getElementById("shopify-dashboard-root")) {
  import("./shopify/dashboard");
}

if (document.getElementById("shopify-responses-root")) {
  import("./shopify/responses");
}
