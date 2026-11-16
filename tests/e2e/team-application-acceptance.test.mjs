import assert from 'node:assert/strict';
import { test } from 'node:test';
import { applicationAcceptanceLabel } from '../../resources/js/team/event-acceptance.mjs';

test('structured booking projection wins over stale reviewed or native values', () => {
  const item = { source: { connection_id: 'fictional' }, status: 'completed', data: {
    reviewed_fields: { acceptance_status: 'yes' }, calendar_acceptance_status: 'accepted',
    event_acceptance: { status: 'accepted', label: 'Accepted', basis: 'asana_tags' },
  } };
  assert.equal(applicationAcceptanceLabel(item), 'Accepted');
  for (const basis of ['asana_tags', 'source_unverified']) {
    item.data.event_acceptance = { status: null, label: 'Needs confirmation', basis };
    assert.equal(applicationAcceptanceLabel(item), 'Needs confirmation');
  }
  item.data.event_acceptance = null;
  assert.equal(applicationAcceptanceLabel(item), 'Needs confirmation');
});

test('compact null is authoritative and source candidates cannot become booking acceptance', () => {
  const item = { source: { connection_id: 'fictional' }, status: 'accepted', data: {
    acceptance_status: 'yes', reviewed_fields: { acceptance_status: 'yes' }, calendar_acceptance_status: null,
  } };
  assert.equal(applicationAcceptanceLabel(item), 'Needs confirmation');
  delete item.data.calendar_acceptance_status;
  delete item.data.reviewed_fields;
  item.data.fields = [{ field: 'acceptance_status', value: 'yes', review_state: 'needs_review' }];
  assert.equal(applicationAcceptanceLabel(item), 'Needs confirmation');
});

test('native and reviewed legacy wording remains separate from generic task stage', () => {
  for (const status of ['accepted', 'completed', 'declined', 'pending']) {
    assert.equal(applicationAcceptanceLabel({ status, data: {} }), 'Needs confirmation');
    assert.equal(applicationAcceptanceLabel({ status, data: { acceptance_status: 'Confirmed' } }), 'Confirmed');
    assert.equal(applicationAcceptanceLabel({ status, source: { connection_id: 'fictional' }, data: {
      reviewed_fields: { acceptance_status: 'Yes (via email on 1/17/25)' },
    } }), 'Yes (via email on 1/17/25)');
  }
});
