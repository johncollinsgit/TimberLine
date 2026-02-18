// resources/js/dashboard-charts.js
import Chart from 'chart.js/auto';

window.Chart = Chart; // lets you do `typeof Chart` in console
console.log('✅ dashboard-charts loaded', Chart?.version);

function getPayload() {
  const el = document.querySelector('script[data-dashboard-payload]');
  if (!el) return null;

  const raw = (el.textContent || '').trim();
  if (!raw) return null;

  try {
    return JSON.parse(raw);
  } catch (e) {
    console.warn('dashboard payload JSON parse failed:', e);
    return null;
  }
}

function renderPie(canvas, title, countsObj) {
  if (!canvas) return;

  // No data => clear any existing chart and bail
  const keys = countsObj ? Object.keys(countsObj) : [];
  if (!countsObj || keys.length === 0) {
    if (canvas._chart) {
      canvas._chart.destroy();
      canvas._chart = null;
    }
    return;
  }

  // Prevent dupes when Livewire re-renders
  if (canvas._chart) {
    canvas._chart.destroy();
    canvas._chart = null;
  }

  const labels = keys;
  const data = labels.map(k => Number(countsObj[k] ?? 0));

  canvas._chart = new Chart(canvas.getContext('2d'), {
    type: 'pie',
    data: {
      labels,
      datasets: [
        {
          label: title,
          data,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'bottom',
          labels: { boxWidth: 12 },
        },
        title: {
          display: false,
        },
      },
    },
  });
}

function initDashboardCharts() {
  const root = document.querySelector('[data-dashboard-root]');
  if (!root) return;

  const payload = getPayload();
  if (!payload) return;

  const statusCanvas = root.querySelector('canvas[data-chart="status"]');
  const channelCanvas = root.querySelector('canvas[data-chart="channel"]');

  renderPie(statusCanvas, 'Orders by Status', payload.statusCounts || {});
  renderPie(channelCanvas, 'Orders by Channel', payload.channelCounts || {});
}

// Run once on initial load
document.addEventListener('DOMContentLoaded', () => {
  initDashboardCharts();
});

// Livewire v3: re-run after DOM updates
document.addEventListener('livewire:init', () => {
  if (!window.Livewire?.hook) return;

  // v3 commit hook
  try {
    Livewire.hook('commit', ({ succeed }) => {
      succeed(() => {
        initDashboardCharts();
      });
    });
  } catch (e) {
    // fallback if hook signature differs
    console.warn('Livewire hook error:', e);
  }
});

// Also run after Livewire navigation (if you use it)
document.addEventListener('livewire:navigated', () => {
  initDashboardCharts();
});
