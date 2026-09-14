import { test } from 'node:test';
import assert from 'node:assert/strict';
import { minor, percentShare } from '../../resources/js/trajectory/money.js';
test('currency entry never uses floating-point multiplication', () => {
  assert.equal(minor('-19.99'), -1999);
  assert.equal(minor('0.29'), 29);
  assert.equal(minor('999999999.99'), 99999999999);
  assert.throws(() => minor('1.005'));
  assert.throws(() => minor('1e6'));
});
test('percentage allocations round one side and preserve the original remainder', () => {
  const amount = -1999;
  const share = percentShare(amount, '50');
  assert.equal(share, -1000);
  assert.equal(amount - share, -999);
  assert.equal(percentShare(amount, '100'), amount);
  assert.throws(() => percentShare(amount, '101'));
});
