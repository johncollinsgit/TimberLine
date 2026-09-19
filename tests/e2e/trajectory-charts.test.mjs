import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cashTimeline, incomeSeries } from '../../resources/js/trajectory/chart-series.js';

test('observed cash and forecast share an anchor without inventing an observation',()=>{
  const history=[{date:'2026-09-18',cash_cents:120000},{date:'2026-09-19',cash_cents:100000},{date:'2026-09-22',cash_cents:80000}];
  const daily=[{date:'2026-09-19',cash_cents:95000,available_cents:85000},{date:'2026-09-20',cash_cents:90000,available_cents:80000}];
  const result=cashTimeline(history,daily);
  assert.deepEqual(result.observed,[120000,100000,null,null]);
  assert.deepEqual(result.cash,[null,100000,95000,90000]);
  assert.deepEqual(result.available,[null,100000,85000,80000]);
  assert.equal(result.observed[result.boundary],result.cash[result.boundary]);
  assert.equal(result.rows[2].observed,false);
  assert.equal(result.sameAvailable,false);
  assert.equal(history.length,3);
});

test('missing observations stay missing and zero is a valid observed balance',()=>{
  const daily=[{date:'2026-09-20',cash_cents:-100,available_cents:-100}];
  assert.equal(cashTimeline([],daily).boundary,null);
  assert.deepEqual(cashTimeline([{date:'2026-09-19',cash_cents:null}],daily).observed,[null]);
  const result=cashTimeline([{date:'2026-09-19',cash_cents:0}],daily);
  assert.equal(result.boundary,0);
  assert.deepEqual(result.cash,[0,-100]);
  assert.equal(result.sameAvailable,true);
});

test('scenario line shares observed anchor and does not fill missing forecasts',()=>{
  const result=cashTimeline([{date:'2026-09-19',cash_cents:10000}],[{date:'2026-09-20',cash_cents:5000,available_cents:4000},{date:'2026-09-21',cash_cents:4000,available_cents:3000}],[{available_cents:8000}]);
  assert.deepEqual(result.comparison,[10000,8000,null]);
});

test('income chart uses monthly plan rates, never cash balances or invented growth',()=>{
  const plan={history_days:90,historical_monthly_cents:400000,monthly:[{expected_income_cents:500000,required_income_cents:620000,planned_cash_cents:-500000},{expected_income_cents:500000,required_income_cents:620000,planned_cash_cents:-600000}]};
  const result=incomeSeries(plan);
  assert.deepEqual(result.expected,[500000,500000]);
  assert.deepEqual(result.required,[620000,620000]);
  assert.deepEqual(result.historical,[400000,400000]);
  assert.equal(result.sameHistorical,false);
  assert.deepEqual(incomeSeries({...plan,history_days:0}).historical,[null,null]);
  assert.equal(incomeSeries({...plan,historical_monthly_cents:500000}).sameHistorical,true);
});
