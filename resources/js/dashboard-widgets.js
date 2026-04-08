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

  const styles = window.getComputedStyle(document.body);
  const textColor = `rgba(${styles.getPropertyValue("--mf-body-text").trim() || "244,244,245"}, 0.78)`;
  const palette = [
    styles.getPropertyValue("--mf-chart-1").trim() || "rgba(16,185,129,.88)",
    styles.getPropertyValue("--mf-chart-2").trim() || "rgba(245,158,11,.86)",
    styles.getPropertyValue("--mf-chart-3").trim() || "rgba(59,130,246,.82)",
    styles.getPropertyValue("--mf-chart-4").trim() || "rgba(168,85,247,.80)",
    styles.getPropertyValue("--mf-chart-5").trim() || "rgba(236,72,153,.80)",
    styles.getPropertyValue("--mf-chart-6").trim() || "rgba(34,197,94,.78)",
  ];
  const borderColor = `rgba(${styles.getPropertyValue("--mf-body-text").trim() || "244,244,245"}, 0.10)`;

  const chart = new Chart(canvas.getContext("2d"), {
    type: "pie",
    data: {
      labels,
      datasets: [{
        data: values,
        backgroundColor: labels.map((_, i) => palette[i % palette.length]),
        borderColor,
        borderWidth: 1,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: window.innerWidth < 768 ? "bottom" : "right",
          labels: {
            color: textColor,
            boxWidth: 12,
            boxHeight: 12,
            padding: 14,
            usePointStyle: true,
          },
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

function mountNow() {
  window.__mfDashboardWidgetsMount();
  window.__mfAnalyticsWidgetsMount();
  observeWidgetGrid(document.querySelector("[data-dashboard-root]"), window.__mfDashboardWidgetsMount);
  observeWidgetGrid(document.querySelector("[data-widget-root]"), window.__mfAnalyticsWidgetsMount);
}

export function mountDashboardWidgetsNow() {
  mountNow();
}

window.addEventListener("mf:theme-changed", () => {
  window.__mfDashboardWidgetsMount?.();
  window.__mfAnalyticsWidgetsMount?.();
});

// If Livewire is present, re-mount on updates
document.addEventListener("livewire:navigated", mountNow);

document.addEventListener("livewire:load", () => {
  mountNow();
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

document.addEventListener("DOMContentLoaded", mountNow);
mountNow();
