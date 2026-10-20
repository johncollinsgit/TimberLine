import test from 'node:test';
import assert from 'node:assert/strict';
import {saveBankLinkSession, restoreBankLinkSession, clearBankLinkSession, bankOAuthReturnUrl, bankLinkExitMessage} from '../../resources/js/trajectory/bank-link-session.js';

const session = {link_token:'link-sandbox-original',link_session:'12345678-1234-4123-a123-123456789012',space_id:7,connection_id:19,expiration:'2099-01-01T00:00:00Z'};
function storage() {const items = new Map();return {items,getItem:key=>items.get(key)??null,setItem:(key,value)=>items.set(key,value),removeItem:key=>items.delete(key)};}

test('OAuth resume restores the same token, connection and authorized space only',()=>{
  const store=storage();saveBankLinkSession(store,session);
  assert.deepEqual(restoreBankLinkSession(store,[{id:2},{id:7}]),{version:1,...session});
  assert.equal(restoreBankLinkSession(store,[{id:2}]),null);
  assert.equal(store.items.size,0);
});

test('expired, corrupt or unavailable browser storage cannot start a replacement link',()=>{
  const store=storage();saveBankLinkSession(store,session);
  assert.equal(restoreBankLinkSession(store,[{id:7}],Date.parse('2099-01-02T00:00:00Z')),null);
  store.setItem('everbranch.trajectory.bank-link.v1','not-json');
  assert.equal(restoreBankLinkSession(store,[{id:7}]),null);
  assert.equal(restoreBankLinkSession(null,[{id:7}]),null);
  assert.throws(()=>saveBankLinkSession(null,session),/regular browser/);
});

test('revoked-item reconnect persists the server-selected new-item mode and clears completion state',()=>{
  const store=storage();saveBankLinkSession(store,{...session,connection_id:null});
  assert.equal(restoreBankLinkSession(store,[{id:7}]).connection_id,null);
  clearBankLinkSession(store);assert.equal(restoreBankLinkSession(store,[{id:7}]),null);
});

test('OAuth return passes the complete provider callback without accepting other destinations or extra query state',()=>{
  const callback='https://app.theeverbranch.com/trajectory?oauth_state_id=fictional-state';
  assert.equal(bankOAuthReturnUrl(callback),callback);
  for(const uri of ['https://app.theeverbranch.com/trajectory?space=7&oauth_state_id=test','https://app.theeverbranch.com/trajectory?oauth_state_id=test#fragment','https://app.theeverbranch.com/other?oauth_state_id=test','https://app.theeverbranch.com/trajectory?oauth_state_id='])assert.equal(bankOAuthReturnUrl(uri),null);
});

test('cancel, unsupported bank and expired-token exits explain next steps without exposing raw provider errors',()=>{
  assert.match(bankLinkExitMessage(null,{}),/canceled/);
  assert.match(bankLinkExitMessage(null,{status:'institution_not_supported'}),/import a statement/);
  assert.match(bankLinkExitMessage({error_code:'INVALID_LINK_TOKEN'},{}),/expired/);
  const error=bankLinkExitMessage({error_code:'UNKNOWN',error_message:'private-bank-evidence'},{});
  assert.doesNotMatch(error,/private-bank-evidence/);assert.match(error,/saved accounts are unchanged/);
});
