import test from 'node:test';
import assert from 'node:assert/strict';
import {bankProviderName, simplefinAssignments, simplefinMappingFields} from '../../resources/js/trajectory/bank-providers.js';

test('the SimpleFIN mapping leaves unassigned accounts out and retains explicit sign and snapshot choices', () => {
  const accounts=[{id:'cash'},{id:'card'},{id:'unassigned'}];
  const values=new Map([['bank-0','12'],['kind-0','cash'],['balance-0','cash'],['reuse-0','42'],['bank-1','13'],['kind-1','credit'],['balance-1','-1']]);
  assert.deepEqual(simplefinAssignments(accounts,values),[
    {id:'cash',space_id:12,kind:'cash',balance_multiplier:1,reuse_account_id:42},
    {id:'card',space_id:13,kind:'credit',balance_multiplier:-1},
  ]);
  assert.equal(bankProviderName('simplefin'),'SimpleFIN');
  assert.equal(bankProviderName('plaid'),'Plaid');
});

test('no account type or debt sign is inferred and cash cannot be inverted', () => {
  const account=[{id:'a'}];
  assert.throws(()=>simplefinAssignments(account,new Map([['bank-0','12']])));
  assert.throws(()=>simplefinAssignments(account,new Map([['bank-0','12'],['kind-0','cash'],['balance-0','-1']])));
});

test('mapping escapes provider names and presents the reported balance and replacement decision', () => {
  const esc=value=>String(value).replaceAll('<','&lt;').replaceAll('>','&gt;');
  const html=simplefinMappingFields([{name:'<script>bank</script>',reported_balance_cents:-10000}],[],[],{esc,precise:value=>String(value),field:(name,label)=>`${name} ${label}`});
  assert.ok(!html.includes('<script>'));
  assert.ok(html.includes('-10000'));
  assert.ok(html.includes('Existing account'));
  assert.ok(html.includes('Confirm the current balance'));
});

test('a zero debt balance can be connected without guessing a future sign convention',()=>{
  assert.deepEqual(simplefinAssignments([{id:'zero',reported_balance_cents:0}],new Map([['bank-0','12'],['kind-0','credit'],['balance-0','zero']])),[{id:'zero',space_id:12,kind:'credit',balance_multiplier:1}]);
});
