import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';
const source=await readFile(new URL('../../resources/js/enterprise/detail-placement.js',import.meta.url),'utf8');
function fixture(){const context=vm.createContext({});vm.runInContext(source,context);return context.EverbranchDetailPlacement;}
test('only Center/Side values persist; invalid, missing and inaccessible storage default safely',()=>{
 const prefs=fixture(),values=new Map(),store={getItem:key=>values.get(key),setItem:(key,value)=>values.set(key,value)},key=prefs.preferenceKey('private','workspace-1','42'),choice=prefs.create(store,key);
 assert.equal(choice.read(),'center');assert.equal(choice.save('side'),true);assert.equal(choice.read(),'side');assert.equal(choice.save('{"comment":"private"}'),false);assert.equal(values.get(key),'side');
 values.set(key,'unexpected');assert.equal(choice.read(),'center');assert.equal(prefs.create({getItem(){throw Error('Blocked');}},key).read(),'center');assert.equal(prefs.create(null,null).save('side'),true);
});
test('preference keys never merge actors, workspaces or sample/private runtimes',()=>{
 const prefs=fixture(),keys=[prefs.preferenceKey('private','a','1'),prefs.preferenceKey('private','a','2'),prefs.preferenceKey('private','b','1'),prefs.preferenceKey('sample','a','1')];assert.equal(new Set(keys).size,4);
 for(const args of [['unknown','a','1'],['private','a:1','2'],['private','','2'],['private','a','x'.repeat(101)]])assert.equal(prefs.preferenceKey(...args),null);
 const values=new Map(),storage={getItem:key=>values.get(key),setItem:(key,value)=>values.set(key,value)};prefs.create(storage,keys[0]).save('side');for(const key of keys.slice(1))assert.equal(prefs.create(storage,key).read(),'center');
});

test('approved runtime mounts isolate repeated IDs without storing tokenized URLs',()=>{
 const prefs=fixture();assert.equal(prefs.runtimeBoundary('/enterprise-campus/teams'),'campus');assert.equal(prefs.runtimeBoundary('/enterprise-owner/service-proposals/fictional-private-token/campus'),'owner');assert.equal(prefs.runtimeBoundary('/platform/demos/lees-mcrae'),'legacy-sample');assert.equal(prefs.runtimeBoundary('/teams'),'native');
 const campus=prefs.preferenceKey('private','1','1',prefs.runtimeBoundary('/enterprise-campus/teams')),owner=prefs.preferenceKey('private','1','1',prefs.runtimeBoundary('/enterprise-owner/anything'));
 assert.notEqual(campus,owner);assert.doesNotMatch(owner,/anything|service-proposals|fictional-private-token/);assert.equal(prefs.preferenceKey('private','1','1','unknown'),null);
});
