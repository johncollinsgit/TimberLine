import Chart from 'chart.js/auto';

function mountPilotCharts() {
for (const root of document.querySelectorAll('[data-subscription-pilot]')) {
  const source = root.querySelector('[data-pilot-chart-data]');
  if (!source || root.dataset.mounted) continue;
  root.dataset.mounted = 'true';
  const data = JSON.parse(source.textContent);
  const options = { responsive: true, maintainAspectRatio: false, animation: !matchMedia('(prefers-reduced-motion: reduce)').matches,
    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, font: { size: 11 } } } },
    scales: { y: { beginAtZero: true, grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } } };
  new Chart(root.querySelector('[data-pilot-payments]'), {
    type: 'line', data: { labels: data.payments.labels, datasets: data.payments.datasets.map((set, i) => ({ ...set,
      borderColor: ['#166534', '#64748b', '#2563eb'][i], backgroundColor: ['#166534', '#64748b', '#2563eb'][i], borderWidth: 2,
      pointRadius: 4, spanGaps: false, tension: 0.15 })) }, options,
  });
  const members = data.members;
  const observed = (field, value) => members.filter(member => member[field] === value).length;
  new Chart(root.querySelector('[data-pilot-outcomes]'), {
    type: 'bar', data: { labels: ['Payment', 'Shopify order', 'Fulfillment'], datasets: [
      { label: 'Observed', backgroundColor: '#166534', data: [observed('payment_status', 'Observed payment'), observed('shopify_status', 'Observed order'), observed('fulfillment_status', 'Observed fulfillment')] },
      { label: 'Awaiting verification', backgroundColor: '#e7e5e4', data: [members.length - observed('payment_status', 'Observed payment'), members.length - observed('shopify_status', 'Observed order'), members.length - observed('fulfillment_status', 'Observed fulfillment')] },
    ] }, options: { ...options, scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true, max: members.length || 1, ticks: { stepSize: 1 }, grid: { color: '#f1f5f9' } } } },
  });
}
}

mountPilotCharts();
document.addEventListener('livewire:navigated', mountPilotCharts);
document.addEventListener('livewire:navigating', () => {
  for (const root of document.querySelectorAll('[data-subscription-pilot]')) {
    root.querySelectorAll('canvas').forEach(canvas => Chart.getChart(canvas)?.destroy());
    delete root.dataset.mounted;
  }
});
