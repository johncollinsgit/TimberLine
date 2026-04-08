import Sortable from "sortablejs";

function getComponent(root) {
  const component = root?.closest("[wire\\:id]");
  if (!component || !window.Livewire) return null;
  return window.Livewire.find(component.getAttribute("wire:id"));
}

function remountCharts() {
  if (typeof window.__mfDashboardWidgetsMount === "function") {
    window.__mfDashboardWidgetsMount();
  }
  if (typeof window.__mfAnalyticsWidgetsMount === "function") {
    window.__mfAnalyticsWidgetsMount();
  }
}

function scheduleRemount() {
  window.requestAnimationFrame(() => {
    window.setTimeout(remountCharts, 30);
  });
}

function initGridSortable(root, saveMethod) {
  if (!root) return;
  const grid = root.querySelector("[data-widget-grid]");
  if (!grid || grid.__sortableInstance) return;

  grid.__sortableInstance = Sortable.create(grid, {
    animation: 180,
    ghostClass: "mf-widget-ghost",
    chosenClass: "mf-widget-chosen",
    dragClass: "mf-widget-drag",
    filter: ".mf-no-drag",
    preventOnFilter: true,
    group: {
      name: "widgets",
      pull: true,
      put: true,
    },
    onAdd: (evt) => {
      const id = evt?.item?.dataset?.widgetId;
      const component = getComponent(root);
      if (component && id) {
        component.call("addWidget", id);
      }
      evt.item?.remove();
      scheduleRemount();
    },
    onEnd: () => {
      const ordered = Array.from(grid.querySelectorAll("[data-widget]"))
        .map((n) => n.dataset.widgetId)
        .filter(Boolean);

      const component = getComponent(root);
      if (component) {
        component.call(saveMethod, ordered);
      }
      scheduleRemount();
    },
  });
}

function initLibrarySortable(root) {
  if (!root) return;
  const library = root.querySelector("[data-widget-library]");
  if (!library || library.__sortableInstance) return;

  library.__sortableInstance = Sortable.create(library, {
    animation: 150,
    group: {
      name: "widgets",
      pull: "clone",
      put: false,
    },
    sort: false,
    ghostClass: "mf-widget-ghost",
    chosenClass: "mf-widget-chosen",
    dragClass: "mf-widget-drag",
  });
}

function mountAll() {
  const dashboard = document.querySelector("[data-dashboard-root]");
  initGridSortable(dashboard, "saveOrder");
  initLibrarySortable(dashboard);

  const analytics = document.querySelector("[data-widget-root]");
  initGridSortable(analytics, "saveOrder");
  initLibrarySortable(analytics);
}

export function mountWidgetDndNow() {
  mountAll();
}

document.addEventListener("DOMContentLoaded", mountWidgetDndNow);
document.addEventListener("livewire:navigated", mountWidgetDndNow);
document.addEventListener("livewire:load", mountWidgetDndNow);
if (window.Livewire?.hook) {
  window.Livewire.hook("message.processed", mountWidgetDndNow);
}
mountWidgetDndNow();
