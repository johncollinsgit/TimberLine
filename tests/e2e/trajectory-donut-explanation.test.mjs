import {test} from 'node:test';
import assert from 'node:assert/strict';
import {donutSliceSummary} from '../../resources/js/trajectory/donut-explanation-data.js';

test('slice share uses the visible positive chart population, retaining the actual selected amount', () => {
  const chart = {data: {labels: ['Groceries', 'Dining', 'Hidden source', 'Refund'], datasets: [{data: [12000, 3000, 85000, -500]}]}, getDataVisibility: i => i !== 2};
  const before = structuredClone(chart.data);
  assert.deepEqual(donutSliceSummary(chart, {index: 0, datasetIndex: 0}), {index: 0, datasetIndex: 0, label: 'Groceries', amount: 12000, total: 15000, share: .8});
  assert.equal(donutSliceSummary(chart, {index: 2}), null);
  assert.equal(donutSliceSummary(chart, {index: 3}), null);
  assert.deepEqual(chart.data, before);
});

test('missing values and an empty chart are unavailable rather than invented zero amounts', () => {
  for (const amount of [undefined, null, '1200', NaN, Infinity]) {
    assert.equal(donutSliceSummary({data: {datasets: [{data: [amount]}]}}, {index: 0}), null);
  }
  assert.equal(donutSliceSummary({data: {datasets: [{data: [0]}]}}, {index: 0}), null);
  assert.equal(donutSliceSummary({}, {index: -1}), null);
});

test('hidden datasets do not enlarge the displayed share or offer unavailable slices', () => {
  const chart = {data: {labels: [['Recorded', 'balance']], datasets: [{data: [100]}, {data: [900]}]}, isDatasetVisible: i => i === 0};
  assert.equal(donutSliceSummary(chart, {index: 0}).share, 1);
  assert.equal(donutSliceSummary(chart, {index: 0}).label, 'Recorded balance');
  assert.equal(donutSliceSummary(chart, {index: 0, datasetIndex: 1}), null);
});
