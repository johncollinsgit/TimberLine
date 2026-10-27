import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const file=new URL('../../scripts/everbranch-team/enterprise-mobile-navigation-browser.mjs',import.meta.url);
const source=fs.readFileSync(file,'utf8');
function helperFixture({open=false,aria=String(open),ready=true}={}) {
  const state={open,aria,ready,clicks:[],waits:0,lateToggle:false};
  const menuNode={id:'enterprise-mobile-navigation',showPopover(){},hasAttribute:name=>name==='popover'&&state.ready,matches:selector=>selector===':popover-open'&&state.open};
  const toggleNode={getAttribute:name=>name==='aria-expanded'?state.aria:name==='popovertarget'&&state.ready?menuNode.id:null};
  const doc={getElementById:id=>id==='enterprise-navigation-toggle'?toggleNode:menuNode};
  const toggle={isVisible:async()=>true,getAttribute:async name=>toggleNode.getAttribute(name),click:async()=>{assert.equal(state.ready,true,'visible toggle must be natively bound');assert.equal(state.aria,String(state.open),'opening must wait for queued presentation state');state.clicks.push('toggle');state.open=!state.open;state.aria=String(state.open);}};
  const target={isVisible:async()=>state.open,click:async()=>{assert.equal(state.open,true,'business navigation only clicks a visible menu item');state.clicks.push('target');state.open=false;state.lateToggle=true;}};
  const menu={isVisible:async()=>state.open,waitFor:async({state:desired})=>{assert.equal(desired,'visible');assert.equal(state.open,true,'closed menu was never reopened');},getByRole:()=>target};
  const page={locator:selector=>selector==='#enterprise-navigation-toggle'?toggle:menu,waitForFunction:async fn=>{
    state.waits++;
    // An independent scheduler delivers the pending mount/toggle before the next settled frame.
    state.ready=true;state.aria=String(state.open);state.lateToggle=false;
    const context={document:doc,String};vm.createContext(context);assert.equal(vm.runInContext('('+fn.toString()+')()',context),true,'presentation must reach its actual native state');
  }};
  return {state,page,target};
}
function load(text=source){const context={assert};vm.createContext(context);vm.runInContext(text.replace(/^import .*;\n/gm,'').replaceAll('export async function','async function')+'\nglobalThis.testExports={clickEnterpriseNavigation,openEnterpriseAction};',context);return context.testExports;}
test('closed popover with stale expanded=true is reopened before the original visible navigation click',async()=>{
 const f=helperFixture({open:false,aria:'true'});await load().clickEnterpriseNavigation(f.page,f.target);assert.deepEqual(f.state.clicks,['toggle','target']);
});
test('an already open popover is not toggled closed while choosing a mobile action',async()=>{
 const f=helperFixture({open:true});await load().openEnterpriseAction(f.page,'Priorities',{});assert.deepEqual(f.state.clicks,['target']);
});
test('queued close followed by another navigation and delayed native mount preserve visible control policy',async()=>{
 const f=helperFixture({open:false,aria:'false',ready:false}),helper=load();await helper.clickEnterpriseNavigation(f.page,f.target);assert.equal(f.state.open,false);assert.equal(f.state.aria,'true');await helper.clickEnterpriseNavigation(f.page,f.target);assert.deepEqual(f.state.clicks,['toggle','target','toggle','target']);
});
