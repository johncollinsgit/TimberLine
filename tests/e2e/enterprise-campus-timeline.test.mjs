import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const html=fs.readFileSync(new URL('../../resources/demos/lees-mcrae-campus.html',import.meta.url),'utf8');
const begin=html.indexOf('function timelineDay('),end=html.indexOf('function calendar(tasks)',begin);assert(begin>0&&end>begin);
const source=html.slice(begin,end),esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function fixture(tasks=[]){const c={data:{tasks,people:{}},esc};vm.createContext(c);vm.runInContext(source,c);return c;}
const task=(id,extra={})=>({id,title:`Visible ${id}`,start_on:null,due_on:'2026-10-21',dependencies:[],...extra});
test('strict literal Gregorian dates reject rollover timestamp and timezone coercion',()=>{
 const c=fixture();for(const v of [null,0,{},'0000-01-01','2026-02-29','1900-02-29','2026-04-31','2026-13-01','2026-00-01','2026-01-00','2026-1-01','2026-10-21T00:00:00Z',' 2026-10-21'])assert.equal(c.timelineDay(v),null);
 for(const v of ['0001-01-01','2000-02-29','2024-02-29','2026-10-21','9999-12-31'])assert.equal(c.timelineDay(v).value,v);
 assert.equal(c.timelineDay('2024-03-01').ordinal-c.timelineDay('2024-02-28').ordinal,2);assert.equal(c.timelineDay('2026-01-01').ordinal-c.timelineDay('2025-12-31').ordinal,1);
 assert.doesNotMatch(source,/new Date|Date\.|demo_date|Date\(/);
});
test('due-only milestones never invent starts spans or dependency durations',()=>{
 const tasks=[task('a')],c=fixture(tasks),before=JSON.stringify(tasks),rows=c.timelineRows(tasks,tasks),render=c.taskTimeline(tasks);
 assert.equal(rows[0].kind,'milestone');assert.equal(rows[0].start,null);assert.match(render,/Due 2026-10-21\. Start not recorded/);assert.match(render,/timeline-milestone/);assert.doesNotMatch(render,/<span class="timeline-span"/);assert.equal(JSON.stringify(tasks),before);
});
test('actual recorded dates render chronological spans including same-day and long bounded geometry',()=>{
 const tasks=[task('a',{start_on:'2026-10-01'}),task('b',{start_on:'2026-10-21'}),task('c',{start_on:'0001-01-01',due_on:'9999-12-31'})],c=fixture(tasks),rows=c.timelineRows(tasks,tasks),render=c.taskTimeline(tasks);
 assert.deepEqual(Array.from(rows,r=>r.kind),['span','span','span']);assert.match(render,/Recorded start 2026-10-01; due 2026-10-21/);assert.equal((render.match(/<span class="timeline-span"/g)||[]).length,3);assert.doesNotMatch(render,/NaN|Infinity|undefined/);
 for(const p of render.matchAll(/(?:left|width):([\d.]+)%/g))assert(Number(p[1])>=0&&Number(p[1])<=100);
});
test('undated invalid and inverted rows remain visible and are not plotted or repaired',()=>{
 const tasks=[task('none',{due_on:null}),task('start',{start_on:'2026-10-10',due_on:null}),task('bad',{due_on:'2026-02-30'}),task('reverse',{start_on:'2026-10-22'})],c=fixture(tasks),before=JSON.stringify(tasks),rows=c.timelineRows(tasks,tasks),render=c.taskTimeline(tasks);
 assert.deepEqual(Array.from(rows,r=>r.kind),['undated','undated','invalid','invalid']);for(const t of tasks)assert.match(render,new RegExp(`data-task="${t.id}"`));assert.match(render,/Dates not recorded/);assert.match(render,/Due date not recorded/);assert.match(render,/Start is after due date/);assert.doesNotMatch(render,/<span class="timeline-(?:span|milestone)"/);assert.equal(JSON.stringify(tasks),before);
});
test('dependencies use current authorized records only with exact open or complete status',()=>{
 const tasks=[task('a',{dependencies:[{id:'b',title:'Untrusted label'},{id:'hidden',title:'Secret blocker'},{id:'b'},{id:'a'}],is_blocked:true}),task('b',{title:'<Safe authorized name>',completed_at:'2026-10-20'})],c=fixture(tasks),render=c.taskTimeline([tasks[0]]);
 assert.match(render,/Depends on <button class="link" data-task="b">&lt;Safe authorized name&gt;<\/button> \(complete\)/);assert.equal((render.match(/data-task="b"/g)||[]).length,1);assert.doesNotMatch(render,/data-task="hidden"|Secret blocker|Untrusted label|Restricted dependency/);
 tasks[1].completed_at=null;assert.match(c.taskTimeline([tasks[0]]),/\(open\)/);
 assert.equal(c.timelineRows([task('hidden')],tasks).length,0);assert.doesNotMatch(c.taskTimeline([task('a',{title:'Forged row'})]),/Forged row/);
});
test('real permission projection remains the task boundary and timeline reads never modify saved graph receipts or session',async()=>{
 const begin=html.indexOf('const OFFLINE_FIXTURE='),end=html.indexOf('const icons=',begin),storage=new Map();const c={structuredClone,URL,Response,location:{href:'https://timeline.example.test/'},sessionStorage:{getItem:k=>storage.get(k)??null,setItem:(k,v)=>storage.set(k,v)},esc};vm.createContext(c);vm.runInContext(html.slice(begin,end),c);vm.runInContext(source,c);
 const call=async(path,body)=>{const r=await c.fetch('/api/'+path,body?{method:'POST',body:JSON.stringify(body)}:{});assert.equal(r.status,200);return r.json();};
 for(const persona of ['staff','manager','admin','leader']){
  await call('persona',{persona});const data=await call('state');c.data=data;const before=vm.runInContext('JSON.stringify(offline)',c),stored=JSON.stringify([...storage]);const rendered=c.taskTimeline(data.tasks);
  assert.equal(c.timelineRows(data.tasks,data.tasks).length,data.tasks.length);assert.equal(c.timelineRows(data.tasks,data.tasks).filter(r=>r.kind==='milestone').length,data.tasks.length);
  if(persona==='staff'){assert(!data.tasks.some(t=>t.id==='r2'));assert.doesNotMatch(rendered,/data-task="r2"/);}
  assert.equal(vm.runInContext('JSON.stringify(offline)',c),before);assert.equal(JSON.stringify([...storage]),stored);
 }
});
test('Timeline retains ordinary authorized detail binding and explicit keyboard scroll without global scheduling hooks',()=>{
 assert.match(html,/data-layout="timeline"/);assert.match(html,/layout==='timeline'\?taskTimeline\(tasks\):calendar\(tasks\)/);assert.match(html,/querySelectorAll\('\[data-task\]'\).*openTask\(b.dataset.task\)/);assert.match(source,/tabindex="0" role="region"/);assert.match(source,/data-task="\$\{esc\(r.task.id\)\}"/);assert.doesNotMatch(source,/fetch\(|sessionStorage|localStorage|OFFLINE_FIXTURE|offline\.|gantt-scroll|is_blocked/);
});
test('actual keyboard handler scrolls the current bounded region without consuming task controls or modified keys',()=>{
 const c=fixture(),scroll={scrollWidth:760,clientWidth:300,scrollLeft:0};let prevented=0;
 const press=(key,extra={})=>c.timelineScrollKeydown({key,currentTarget:scroll,target:scroll,preventDefault(){prevented++;},...extra});
 press('ArrowRight');assert.equal(scroll.scrollLeft,64);press('End');assert.equal(scroll.scrollLeft,460);press('ArrowRight');assert.equal(scroll.scrollLeft,460);press('Home');assert.equal(scroll.scrollLeft,0);press('ArrowLeft');assert.equal(scroll.scrollLeft,0);assert.equal(prevented,5);
 for(const extra of [{target:{}},{altKey:true},{ctrlKey:true},{metaKey:true},{shiftKey:true}])press('ArrowRight',extra);press('Enter');assert.equal(scroll.scrollLeft,0);assert.equal(prevented,5);
 assert.match(html,/querySelector\('\.timeline-scroll'\)\?\.addEventListener\('keydown',timelineScrollKeydown\)/);
});
