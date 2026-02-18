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
  }
}

function mountPie(canvas, counts) {
  if (!canvas) return;
  destroyIfExists(canvas);

  const { labels, values } = toPieDataset(counts);

  // If no data, show a subtle empty state (no chart)
  if (!labels.length || values.reduce((a, b) => a + b, 0) === 0) return;

  canvas.__chart = new Chart(canvas.getContext("2d"), {
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
}

function mountAll(root) {
  const payload = readPayload(root);
  if (!payload) return;

  const statusCanvas = root.querySelector('canvas[data-chart="status"]');
  const channelCanvas = root.querySelector('canvas[data-chart="channel"]');

  mountPie(statusCanvas, payload.statusCounts);
  mountPie(channelCanvas, payload.channelCounts);
}

// Expose a global so Livewire can re-mount after DOM swaps
window.__mfDashboardWidgetsMount = () => {
  const root = document.querySelector("[data-dashboard-root]");
  if (root) mountAll(root);
};

document.addEventListener("DOMContentLoaded", () => {
  window.__mfDashboardWidgetsMount();
});

// If Livewire is present, re-mount on updates
document.addEventListener("livewire:navigated", () => {
  window.__mfDashboardWidgetsMount();
});

document.addEventListener("livewire:load", () => {
  window.__mfDashboardWidgetsMount();
  if (window.Livewire?.hook) {
    window.Livewire.hook("message.processed", () => {
      window.__mfDashboardWidgetsMount();
    });
  }
});

let draggedEl = null;

document.addEventListener('dragstart', e => {
  const el = e.target.closest('[data-widget]');
  if (!el) return;

  draggedEl = el;
  el.classList.add('opacity-50');
});

document.addEventListener('dragend', () => {
  if (draggedEl) draggedEl.classList.remove('opacity-50');
  draggedEl = null;
});

document.addEventListener('dragover', e => {
  const target = e.target.closest('[data-widget]');
  if (!draggedEl || !target || draggedEl === target) return;

  e.preventDefault(); // REQUIRED
});

document.addEventListener('drop', e => {
  const target = e.target.closest('[data-widget]');
  if (!draggedEl || !target || draggedEl === target) return;

  e.preventDefault();

  const grid = target.parentElement;
  const draggedIndex = [...grid.children].indexOf(draggedEl);
  const targetIndex = [...grid.children].indexOf(target);

  if (draggedIndex < targetIndex) {
    grid.insertBefore(draggedEl, target.nextSibling);
  } else {
    grid.insertBefore(draggedEl, target);
  }
});
