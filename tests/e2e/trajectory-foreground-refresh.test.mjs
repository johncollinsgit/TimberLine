import {test} from 'node:test';
import assert from 'node:assert/strict';
import {canRefreshFinance, createForegroundRefresh} from '../../resources/js/trajectory/foreground-refresh.js';

const ready = {visible:true,online:true,hasData:true,tab:'overview',scope:'personal',loading:false,editing:false,linking:false,restoring:false,oauth:false};

test('foreground reads stay in visible authorized chart views and never interrupt editing or sign-in', () => {
  for(const tab of ['overview','analysis','budget'])assert.equal(canRefreshFinance({...ready,tab}),true);
  for(const key of ['visible','online','hasData'])assert.equal(canRefreshFinance({...ready,[key]:false}),false,key);
  for(const key of ['loading','editing','linking','restoring','oauth'])assert.equal(canRefreshFinance({...ready,[key]:true}),false,key);
  for(const tab of ['transactions','accounts','planning','profit','sales-tax'])assert.equal(canRefreshFinance({...ready,tab}),false,tab);
  assert.equal(canRefreshFinance({...ready,scope:'both'}),false);
  assert.equal(canRefreshFinance({...ready,tab:'assets',hasMetals:true}),true);
  assert.equal(canRefreshFinance({...ready,tab:'assets',hasMetals:false}),false);
});

test('a user interaction or changed account/date/version discards an outstanding response; polling never overlaps', async () => {
  let context='household:7:2028-02:version1',resolve,reads=0,applied=0,editing=false;
  const run=createForegroundRefresh({eligible:()=>!editing,snapshot:()=>context,refresh:async current=>{reads++;await new Promise(done=>{resolve=done;});if(!current())return false;applied++;return true;}});
  const first=run();assert.equal(await run(),false);assert.equal(reads,1);
  context='business:8:2028-03:version2';resolve();assert.equal(await first,false);assert.equal(applied,0);
  const second=run();editing=true;resolve();assert.equal(await second,false);assert.equal(applied,0);
  assert.equal(await run(),false);assert.equal(reads,2);
  editing=false;const third=run();resolve();assert.equal(await third,true);assert.equal(applied,1);
});

test('a completed or failed poll invalidates callbacks from still-outstanding reads', async () => {
  let current;
  const run=createForegroundRefresh({eligible:()=>true,snapshot:()=>7,refresh:async check=>{current=check;throw Error('Network read failed');}});
  await assert.rejects(run,/Network read failed/);assert.equal(current(),false);
});

test('scrolling during a quiet read discards it instead of restoring the old position', async () => {
  let position=0,release,applied=0;
  const run=createForegroundRefresh({eligible:()=>true,snapshot:()=>JSON.stringify({position}),refresh:async current=>{await new Promise(done=>{release=done;});if(!current())return false;applied++;return true;}});
  const pending=run();position=240;release();assert.equal(await pending,false);assert.equal(applied,0);assert.equal(position,240);
});
