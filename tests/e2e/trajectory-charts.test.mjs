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

test('monthly bars use closing balances, preserving leap days and partial months',async()=>{
  const { monthlyBalances }=await import('../../resources/js/trajectory/chart-series.js');
  const rows=[{date:'2028-02-28',cash_cents:100},{date:'2028-02-29',cash_cents:150},{date:'2028-03-01',cash_cents:-20},{date:'2028-03-12',cash_cents:0}];
  assert.deepEqual(monthlyBalances(rows),[rows[1],rows[3]]);
  assert.deepEqual(monthlyBalances([...rows].reverse()),[rows[1],rows[3]]);
  assert.deepEqual(monthlyBalances([]),[]);
  assert.equal(rows.length,4);
});

test('actual vs projected keeps current-month observations distinct and missing history blank',async()=>{
  const { actualProjectedBalances }=await import('../../resources/js/trajectory/chart-series.js');
  const rows=actualProjectedBalances([
    {date:'2028-01-31',cash_cents:100},
    {date:'2028-02-15',cash_cents:0},
    {date:'2028-03-01',cash_cents:999},
    {date:'2028-01-20',cash_cents:null},
  ],[{date:'2028-02-15',cash_cents:-10},{date:'2028-02-29',cash_cents:-50},{date:'2028-03-12',cash_cents:-80}]);
  assert.equal(rows.find(r=>r.month==='2028-01').actual.cash_cents,100);
  assert.equal(rows.find(r=>r.month==='2028-01').projected,null);
  const current=rows.find(r=>r.month==='2028-02');
  assert.equal(current.actual.cash_cents,0);
  assert.equal(current.projected.cash_cents,-50);
  assert.equal(current.projected.date,'2028-02-29');
  assert.equal(rows.find(r=>r.month==='2028-03').actual,null);
  assert.equal(rows.find(r=>r.month==='2027-12').actual,null);
  assert.deepEqual(actualProjectedBalances([],[]),[]);
});

test('income runway keeps outflows distinct and defaults the projection to matching prior-year income', async()=>{
  const { incomeRunwaySeries }=await import('../../resources/js/trajectory/chart-series.js');
  const rows=[
    {date:'2026-09-30',last_year_income_cents:800000,plan_income_cents:600000,recent_income_cents:500000,outflow_cents:700000},
    {date:'2026-10-31',last_year_income_cents:null,plan_income_cents:650000,recent_income_cents:500000,outflow_cents:550000},
  ];
  const previous=incomeRunwaySeries(rows);
  assert.deepEqual(previous.projected,[800000,null]);
  assert.deepEqual(previous.lastYear,[800000,null]);
  assert.deepEqual(previous.outflows,[700000,550000]);
  assert.deepEqual(previous.gaps,[100000,null]);
  assert.equal(previous.sameAsLastYear,true);
  const plan=incomeRunwaySeries(rows,'plan');
  assert.deepEqual(plan.projected,[600000,650000]);
  assert.deepEqual(plan.gaps,[-100000,100000]);
  assert.equal(plan.sameAsLastYear,false);
});

test('previous-year comparison aligns calendar months and preserves missing evidence', async()=>{
  const { previousYearSeries }=await import('../../resources/js/trajectory/chart-series.js');
  const months=[
    {month:'2025-01',income_cents:100000,spending_cents:70000,ids:[1],closed_month:true},
    {month:'2025-02',income_cents:90000,spending_cents:80000,ids:[2],closed_month:true},
    {month:'2026-01',income_cents:120000,spending_cents:60000,ids:[3],closed_month:true},
    {month:'2026-02',income_cents:110000,spending_cents:75000,ids:[4],closed_month:false},
  ];
  const spending=previousYearSeries(months,2026);
  assert.deepEqual(spending.current.slice(0,3),[60000,75000,null]);
  assert.deepEqual(spending.previous.slice(0,3),[70000,80000,null]);
  assert.equal(spending.currentTotal,135000);
  assert.equal(spending.previousTotal,150000);
  assert.equal(spending.currentCount,2);
  assert.equal(spending.previousCount,2);
  assert.equal(spending.comparableCount,1);
  assert.equal(spending.comparableCurrentTotal,60000);
  assert.equal(spending.comparablePreviousTotal,70000);
  assert.deepEqual(spending.rows[0].current.ids,[3]);
  const income=previousYearSeries(months,2026,'income');
  assert.deepEqual(income.current.slice(0,2),[120000,110000]);
  assert.deepEqual(income.previous.slice(0,2),[100000,90000]);
  const net=previousYearSeries(months,2026,'net');
  assert.equal(net.current[0],60000);
  assert.equal(net.previous[0],30000);
});
