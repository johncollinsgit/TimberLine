import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

const source=await readFile(new URL('../../resources/js/enterprise/reviewer/tour.js',import.meta.url),'utf8');
const start=source.indexOf('        function position('),end=source.indexOf('        function spotlight()',start);
assert.ok(start>=0&&end>start,'Exercise the actual tour placement function');
const position=source.slice(start,end);

function fixture({width=720,height=450,targetTop=200,targetHeight=44,barBottom=171,tipHeight=188}={}){
    const internal={},nodes={bar:{getBoundingClientRect:()=>({bottom:barBottom})},highlight:{style:{},hidden:true},tip:{style:{},hidden:true,scrollTop:0,contains:element=>element===internal,getBoundingClientRect:()=>({width:355,height:tipHeight})},status:{textContent:''}};
    const context={document:{activeElement:null},active:true,visible:true,dialog:{open:false},anchor:{isConnected:true,getBoundingClientRect:()=>({left:208,width:494,top:targetTop,bottom:targetTop+targetHeight,height:targetHeight})},innerWidth:width,innerHeight:height,reveal:false,get:name=>nodes[name],clearSpotlight(){nodes.highlight.hidden=true;nodes.tip.hidden=true;},spotlight(){context.revealed=(context.revealed||0)+1;},Math};
    nodes.toggle={focus(options){context.document.activeElement=nodes.toggle;context.focusOptions=options;},scrollIntoView(options){context.scrollOptions=options;}};
    vm.createContext(context);vm.runInContext(position+'\nglobalThis.actual=position;',context);
    return {run:event=>context.actual(event),nodes,context,internal,tipHeight};
}
function bounds(f){const {tip}=f.nodes;return {top:parseFloat(tip.style.top),bottom:parseFloat(tip.style.top)+Math.min(f.tipHeight,parseFloat(tip.style.maxHeight)||f.tipHeight)};}

test('720px zoom-reflow hint uses available space below its target without covering the field',()=>{
    const f=fixture();f.run();assert.equal(f.nodes.tip.hidden,false);const tip=bounds(f);
    assert.ok(tip.top>=244+12);assert.ok(tip.bottom<=450-10);assert.equal(f.nodes.tip.style.overflowY,'auto');
});
test('hint uses an unobstructed full-size slot above the target when available',()=>{
    const f=fixture({height:800,targetTop:650,barBottom:100});f.run();const tip=bounds(f);
    assert.ok(tip.bottom<=650-12);assert.ok(tip.top>=110);assert.equal(f.nodes.tip.hidden,false);
});
test('an impossibly short viewport keeps the target clear and announces how to continue',()=>{
    const f=fixture({height:300,targetTop:210,barBottom:180});f.run();assert.equal(f.nodes.tip.hidden,true);assert.match(f.nodes.status.textContent,/Hide the tour/);assert.equal(f.nodes.highlight.hidden,false);
});
test('hiding an undersized hint transfers its focused control to the existing Hide tour button',()=>{
    const f=fixture({height:300,targetTop:210,barBottom:180});f.context.document.activeElement=f.internal;f.run();assert.equal(f.nodes.tip.hidden,true);assert.equal(f.context.document.activeElement,f.nodes.toggle);assert.equal(f.context.focusOptions.preventScroll,true);
});
test('scrolling the hint itself leaves its position and keyboard scroll unchanged',()=>{
    const f=fixture();f.run();f.nodes.tip.scrollTop=50;const before=JSON.stringify(f.nodes.tip.style);f.run({type:'scroll',target:f.internal});assert.equal(JSON.stringify(f.nodes.tip.style),before);assert.equal(f.nodes.tip.scrollTop,50);
});
test('resizing requests a fresh reveal without changing the selected screen or focus',()=>{
    const f=fixture();f.run({type:'resize'});assert.equal(f.context.reveal,true);assert.equal(f.context.revealed,1);
});
