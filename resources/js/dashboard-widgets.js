import Chart from "chart.js/auto";

function readPayload(root) {
  const el = root.querySelector('[data-dashboard-payload]');
  if (!el) return null;
  try { return JSON.parse(el.textContent || "{}"); }
  catch { return null; }
}

function toPieDataset(counts) {
  const entries = Object.entries(counts || {});
  return {
    labels: entries.map(([k]) => k),
    values: entries.map(([, v]) => Number(v) || 0),
  };
}

function destroyIfExists(canvas) {
  if (!canvas) return;
  if (canvas.__chart) {
    canvas.__chart.destroy();
    canvas.__chart = null;
    return;
  }
  const existing = Chart.getChart(canvas);
  if (existing) {
    existing.destroy();
  }
}

function mountPie(canvas, counts) {
  if (!canvas) return;
  destroyIfExists(canvas);

  const { labels, values } = toPieDataset(counts);

  // If no data, show a subtle empty state (no chart)
  if (!labels.length || values.reduce((a, b) => a + b, 0) === 0) return;

  const chart = new Chart(canvas.getContext("2d"), {
    type: "pie",
    data: {
      labels,
      datasets: [{ data: values }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: "right",
          labels: { color: "rgba(255,255,255,0.75)" },
        },
        tooltip: {
          callbacks: {
            label: (ctx) => `${ctx.label}: ${ctx.raw}`,
          },
        },
      },
    },
  });

  canvas.__chart = chart;
  window.setTimeout(() => chart.resize(), 50);
}

function mountDashboard(root) {
  const payload = readPayload(root);
  if (!payload) return;

  const statusCanvas = root.querySelector('canvas[data-chart="status"]');
  const channelCanvas = root.querySelector('canvas[data-chart="channel"]');

  mountPie(statusCanvas, payload.statusCounts);
  mountPie(channelCanvas, payload.channelCounts);
}

function mountAnalytics() {
  const root = document.querySelector("[data-widget-root]");
  if (!root) return;
  const el = root.querySelector('[data-analytics-payload]');
  if (!el) return;
  let payload = null;
  try { payload = JSON.parse(el.textContent || "{}"); } catch { payload = null; }
  if (!payload) return;

  const typeCanvas = root.querySelector('canvas[data-analytics-chart="type"]');
  const statusCanvas = root.querySelector('canvas[data-analytics-chart="status"]');

  mountPie(typeCanvas, payload.typeCounts);
  mountPie(statusCanvas, payload.statusCounts);
}

// Expose a global so Livewire can re-mount after DOM swaps
window.__mfDashboardWidgetsMount = () => {
  const root = document.querySelector("[data-dashboard-root]");
  if (root) mountDashboard(root);
};

window.__mfAnalyticsWidgetsMount = () => {
  mountAnalytics();
};

document.addEventListener("DOMContentLoaded", () => {
  window.__mfDashboardWidgetsMount();
  window.__mfAnalyticsWidgetsMount();
});

// If Livewire is present, re-mount on updates
document.addEventListener("livewire:navigated", () => {
  window.__mfDashboardWidgetsMount();
  window.__mfAnalyticsWidgetsMount();
});

document.addEventListener("livewire:load", () => {
  window.__mfDashboardWidgetsMount();
  window.__mfAnalyticsWidgetsMount();
  if (window.Livewire?.hook) {
    window.Livewire.hook("message.processed", () => {
      window.__mfDashboardWidgetsMount();
      window.__mfAnalyticsWidgetsMount();
    });
  }
});

function observeWidgetGrid(root, mountFn) {
  if (!root || root.__mfObserver) return;
  const grid = root.querySelector("[data-widget-grid]");
  if (!grid) return;
  const observer = new MutationObserver(() => {
    window.requestAnimationFrame(mountFn);
  });
  observer.observe(grid, { childList: true, subtree: true });
  root.__mfObserver = observer;
}

document.addEventListener("DOMContentLoaded", () => {
  observeWidgetGrid(document.querySelector("[data-dashboard-root]"), window.__mfDashboardWidgetsMount);
  observeWidgetGrid(document.querySelector("[data-widget-root]"), window.__mfAnalyticsWidgetsMount);
});

document.addEventListener("livewire:navigated", () => {
  observeWidgetGrid(document.querySelector("[data-dashboard-root]"), window.__mfDashboardWidgetsMount);
  observeWidgetGrid(document.querySelector("[data-widget-root]"), window.__mfAnalyticsWidgetsMount);
});
