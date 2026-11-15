import assert from 'node:assert/strict';
import { test } from 'node:test';
import { performanceState, performanceURL } from '../../resources/js/team/event-reporting.mjs';

test('report links preserve tenant and source context without retaining private rows', () => {
  const state = performanceState('https://app.example/teams/app?tenant=forest&area=area-a&report_group=customer&report_from=2025-01-01&report_to=2025-12-31&report_customer_id=42&report_q=ada');
  const url = performanceURL('https://app.example/teams/app?tenant=forest&area=area-a', state);
  assert.equal(url.searchParams.get('tenant'), 'forest');
  assert.equal(url.searchParams.get('area'), 'area-a');
  assert.equal(performanceState(url).customer_id, '42');
  assert.equal(performanceState(url).group_by, 'customer');
  assert.equal(performanceState(url).q, 'ada');
  assert.equal(url.searchParams.has('rows'), false);
});
test('unrecognized display modes and pagination use bounded defaults', () => {
  const state = performanceState('https://example.test/?report_group=payroll&report_sort=unknown&report_page=-2&report_from=bad', new Date(2026, 10, 15));
  assert.equal(state.group_by, 'event'); assert.equal(state.sort, 'date'); assert.equal(state.page, 1); assert.equal(state.from, '2026-01-01');
  assert.equal(performanceState('https://example.test/?report_page=99999999').page, 100000);
});

test('adjustable weekend boundaries round trip with Friday through Sunday defaults', () => {
  const defaults = performanceState('https://example.test'); assert.equal(defaults.weekend_start, 5); assert.equal(defaults.weekend_days, 3);
  const custom = { ...defaults, weekend_start: 4, weekend_days: 4 }; const restored = performanceState(performanceURL('https://example.test', custom));
  assert.equal(restored.weekend_start, 4); assert.equal(restored.weekend_days, 4);
});
