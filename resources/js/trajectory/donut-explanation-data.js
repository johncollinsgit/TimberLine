/** Shares describe only the positive, currently visible values in this chart. */
export function donutSliceSummary(chart, selection) {
  const index = selection?.index, datasetIndex = selection?.datasetIndex ?? 0;
  if (!Number.isInteger(index) || !Number.isInteger(datasetIndex) || index < 0 || datasetIndex < 0) return null;
  const datasets = chart?.data?.datasets ?? [];
  const visibleDataset = i => chart.isDatasetVisible?.(i) !== false;
  const visibleSlice = i => chart.getDataVisibility?.(i) !== false;
  const amount = datasets[datasetIndex]?.data?.[index];
  if (!visibleDataset(datasetIndex) || !visibleSlice(index) || typeof amount !== 'number' || !Number.isFinite(amount) || amount < 0) return null;
  const total = datasets.reduce((sum, dataset, di) => !visibleDataset(di) ? sum : sum + dataset.data.reduce((part, value, i) =>
    visibleSlice(i) && typeof value === 'number' && Number.isFinite(value) && value > 0 ? part + value : part, 0), 0);
  if (!(total > 0) || !Number.isFinite(total)) return null;
  const rawLabel = chart.data.labels?.[index] ?? datasets[datasetIndex].label ?? 'Selected slice';
  return {index, datasetIndex, label: Array.isArray(rawLabel) ? rawLabel.join(' ') : String(rawLabel), amount, total, share: amount / total};
}
