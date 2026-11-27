import assert from 'node:assert/strict';
import test from 'node:test';
import {installmentAmounts, bridgeTarget, temporaryMedicalDraw} from '../../resources/js/trajectory/plan-scenario.js';

test('monthly payments distribute each received cent once, including indivisible totals', () => {
  const total = 123456;
  const split = installmentAmounts(total, 100);
  assert.deepEqual(split, {low:1234, high:1235, highCount:56, lowCount:44});
  assert.equal(split.low * split.lowCount + split.high * split.highCount, total);
  assert.equal(installmentAmounts(null, 100), null);
  assert.equal(installmentAmounts(total, 0), null);
});

test('a finite draw lowers the target only for its horizon and never below zero', () => {
  assert.equal(bridgeTarget(100050, 10000, 5000, 3), 95050);
  assert.equal(bridgeTarget(100050, 10000, 0, 6), 98384);
  assert.equal(bridgeTarget(100, 1200, 0, 12), 0);
  assert.equal(bridgeTarget(null, 200, 100, 3), null);
});

test('medical draws leave near-term payments intact and disclose the later repayment', () => {
  const scenario = {received:100005, count:100, months:3, bankAfterOtherDraws:15000, borrowed:10000};
  assert.deepEqual(temporaryMedicalDraw(scenario), {replacement:104, remainingMonths:97});
  assert.deepEqual(temporaryMedicalDraw({...scenario, borrowed:12000}), {error:'cash'});
  assert.deepEqual(temporaryMedicalDraw({...scenario, bankAfterOtherDraws:null}), {error:'cash'});
  assert.deepEqual(temporaryMedicalDraw({...scenario, count:3}), {error:'schedule'});
});
