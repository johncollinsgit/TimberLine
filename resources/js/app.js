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

if (document.getElementById("shopify-responses-root")) {
  import("./shopify/responses");
}
