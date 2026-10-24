import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';
const source=await readFile(new URL('../../resources/views/enterprise/app.blade.php',import.meta.url),'utf8');
const begin=source.indexOf('  const requestTypeLabels='),end=source.indexOf('  function rows()',begin);
assert.ok(begin>=0&&end>begin,'Tests must use actual shared work-table and drawer handlers');
const handlers=source.slice(begin,end);
const plain=value=>JSON.parse(JSON.stringify(value));
const next=await readFile(new URL('../../resources/views/enterprise/next-script.blade.php',import.meta.url),'utf8');
const sharedFiles=await readFile(new URL('../../resources/views/enterprise/shared-files-bridge.blade.php',import.meta.url),'utf8');
const sharedComments=await readFile(new URL('../../resources/views/enterprise/shared-comments-bridge.blade.php',import.meta.url),'utf8');
function region(text,start,end){const a=text.indexOf(start),b=text.indexOf(end,a);assert.ok(a>=0&&b>a,'Actual privacy cleanup source must be available');return text.slice(a,b);}
const galleryCleanup=region(next,'  let attachmentGallery = null;','  @include(');
const filesCleanup=region(sharedFiles,'  let sharedFilesUi =','  const sharedFileUuid =')+region(sharedFiles,'  function clearSharedFiles()','  function sharedFilesTarget()');
const discussionCleanup=region(sharedComments,'  let sharedDiscussionUi =','  async function readSharedReviewName()');

function fixture(){
 const nodes=new Map(),events=new Map(),classes=new Set(),calls=[];
 const node=id=>{if(!nodes.has(id))nodes.set(id,{id,value:'',open:false,scrollTop:25,scrollLeft:60,children:[],dataset:{},isConnected:true,querySelectorAll(){return[];},setAttribute(){},replaceChildren(){this.children=[];this.textContent='';},addEventListener(name,fn){events.set(id+':'+name,fn);},focus(options){calls.push({focus:id,options});},showModal(){this.open=true;},close(){this.open=false;}});return nodes.get(id);};
 const context=vm.createContext({console,Map,URL,URLSearchParams,section:'requests',area:'a',selected:null,viewer:'1',data:{projects:[],requests:[],members:[{id:'2',name:'Fictional Avery'}]},workSortState:new Map(),workFeedbackHome:new Map(),workDetailReturn:null,rollCallReturn:null,catalogReturn:null,projectCatalog:null,scrollY:450,scrollX:0,discussionGeneration:4,activeDiscussionKey:'a:request:r',transport:{mode:'private'},$:node,
 esc:value=>String(value??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('"','&quot;'),
 document:{activeElement:node('opener'),documentElement:{classList:{add:x=>classes.add(x),remove:x=>classes.delete(x)}},dispatchEvent:e=>calls.push({event:e.type,detail:e.detail})},
 CustomEvent:class{constructor(type,{detail}){this.type=type;this.detail=detail;}},stashComposer(){calls.push({stash:true});},rememberSelection(){calls.push({remember:true});},rememberUrl(){calls.push({url:true});},beginNavigation(){calls.push({begin:true});},scrollTo:value=>calls.push({scroll:value}),detail(){vm.runInContext('showWorkDetail()',context);},
 });
 // Use the real teardown functions; spies model mounted UI objects and assert delegation/state retirement.
 context.fixtureGallery={destroy(){calls.push({galleryDestroyed:true});}};
 context.fixtureFilesUi={destroy(){calls.push({filesDestroyed:true});}};
 context.fixtureFilesScope={host:{remove(){calls.push({filesHostRemoved:true});}}};
 context.fixtureDiscussion={invalidate(options){calls.push({discussionInvalidated:plain(options)});}};
 vm.runInContext(galleryCleanup+filesCleanup+discussionCleanup,context);
 vm.runInContext('attachmentGallery=fixtureGallery; sharedFilesUi=fixtureFilesUi; sharedFilesScope=fixtureFilesScope; sharedFilesName={name:"Fictional reader"}; sharedDiscussionUi=fixtureDiscussion;',context);
 vm.runInContext(handlers,context);
 return{context,node,nodes,calls,events,classes,run:code=>vm.runInContext(code,context)};
}
test('columns use only current readable fields; task names and typed location text stay escaped',()=>{
 const f=fixture();f.context.data.projects=[{id:'p',name:'Fictional project',archived:false}];f.context.section='projects';
 assert.deepEqual(plain(f.run('workColumns()')),[['title','Project'],['department','Department'],['assignee','People assigned'],['status','Status'],['updated','Updated'],['due','Due']]);
 f.context.section='requests';f.context.data.requests=[{id:'r',title:'<Fictional>',status:'assigned',data:{location:'<Room>'},task:{priority:'urgent',assignee_id:2,due_on:'2026-11-03'}}];
 assert.deepEqual(plain(f.run('workColumns()')).map(([key])=>key),['title','type','priority','status','department','action','assignee','location','due']);
 assert.equal(f.run('workValue(data.requests[0],"assignee")'),'Fictional Avery');
 assert.equal(f.run('workCell(data.requests[0],"location")'),'&lt;Room>');
 assert.match(f.run('workCell(data.requests[0],"title")'),/&lt;Fictional>/);
 f.context.data.requests[0].task.assignee_id=999;assert.equal(f.run('workValue(data.requests[0],"assignee")'),'Assigned member · name unavailable');
 f.context.data.requests[0].task=null;assert.equal(f.run('workCell(data.requests[0],"assignee")'),'No linked task');
});
test('sorting is real, scoped, nonmutating and keeps unrecorded priorities last in both directions',()=>{
 const f=fixture();f.context.data.requests=[{id:'u',title:'Task 10',task:{priority:'urgent'}},{id:'n',title:'Task 2',task:{priority:'normal'}},{id:'x',title:'Task 1',task:null}];
 const original=JSON.stringify(f.context.data.requests);
 f.run('workSortState.set("a:requests",{key:"priority",direction:"asc"})');assert.deepEqual(plain(f.run('sortedWork(data.requests).map(r=>r.id)')),['u','n','x']);
 f.run('workSortState.set("a:requests",{key:"priority",direction:"desc"})');assert.deepEqual(plain(f.run('sortedWork(data.requests).map(r=>r.id)')),['n','u','x']);
 f.context.area='b';assert.deepEqual(plain(f.run('sortedWork(data.requests).map(r=>r.id)')),['x','n','u']);assert.equal(JSON.stringify(f.context.data.requests),original);
 f.context.data.requests=[{id:'later',title:'Later',due_on:'2026-12-01'},{id:'early',title:'Early',due_on:'2026-11-02'}];f.run('workSortState.set("b:requests",{key:"due",direction:"asc"})');assert.deepEqual(plain(f.run('sortedWork(data.requests).map(r=>r.id)')),['early','later']);
});
test('actual Close and Escape handlers clear private detail without replacing list, filter or sort and restore focus/scroll',()=>{
 const f=fixture();const list=f.node('request-list'),table=f.node('work-table-scroll'),opener=f.node('opener');f.node('search').value='Fictional';f.run('workSortState.set("a:requests",{key:"title",direction:"desc"})');
 f.run('openWorkRow("r",document.activeElement)');assert.equal(f.node('work-drawer').open,true);assert.equal(f.context.selected,'r');assert.ok(f.classes.has('work-detail-open'));
 table.scrollTop=0;table.scrollLeft=0;f.node('detail').textContent='Private fixture content';const generation=f.context.discussionGeneration;
 let prevented=false;f.events.get('work-drawer:cancel')({preventDefault(){prevented=true;}});
 assert.equal(prevented,true);assert.equal(f.node('work-drawer').open,false);assert.equal(f.node('detail').textContent,'');assert.equal(f.context.selected,null);assert.equal(f.context.activeDiscussionKey,null);assert.ok(f.context.discussionGeneration>generation);
 assert.equal(f.node('request-list'),list);assert.equal(f.node('search').value,'Fictional');assert.equal(f.run('workSort().direction'),'desc');assert.equal(table.scrollTop,25);assert.equal(table.scrollLeft,60);
 assert.ok(f.calls.some(c=>c.focus===opener.id&&c.options.preventScroll));assert.ok(f.calls.some(c=>c.scroll?.top===450));assert.ok(f.calls.some(c=>c.event==='enterprise:tour-context'&&c.detail.selected===null));assert.ok(!f.classes.has('work-detail-open'));
 assert.equal(f.calls.filter(c=>c.galleryDestroyed).length,1);assert.equal(f.calls.filter(c=>c.filesDestroyed).length,1);assert.equal(f.calls.filter(c=>c.filesHostRemoved).length,1);
 assert.deepEqual(plain(f.run('[attachmentGallery,sharedFilesUi,sharedFilesScope,sharedFilesName]')),[null,null,null,null]);
 assert.deepEqual(plain(f.run('[sharedFilesSerial,sharedFilesNameSerial,sharedDiscussionEpoch]')),[1,1,1]);
 assert.deepEqual(f.calls.find(c=>c.discussionInvalidated).discussionInvalidated,{stash:true});
});
test('the actual request audit handler ignores a delayed result after Close or a different row',async()=>{
 const f=fixture();f.context.section='requests';f.context.selected='r';f.context.data.requests=[{id:'r',title:'Fictional request',status:'submitted',data:{description:'Fixture only'},version:1}];
 f.context.data.features={};f.context.stashComposer=()=>{};f.context.mountDiscussion=()=>{};f.context.mountRequestOperations=()=>{};f.context.readDraft=()=>({});f.context.writeDraft=()=>{};f.context.message=()=>assert.fail('Stale history must not report errors');f.context.tenantSlug='fictional';f.context.urls={history:'/enterprise/requests'};
 let resolve;f.context.api=()=>new Promise(yes=>{resolve=yes;});
 const a=source.indexOf('  function detail()'),b=source.indexOf("  @include('enterprise.home-script')",a);assert.ok(a>=0&&b>a);vm.runInContext(source.slice(a,b),f.context);f.run('detail()');
 const request=f.node('show-history').onclick();f.run('closeWorkDetail()');f.context.selected='another-readable-row';f.node('history').innerHTML='Current row history';resolve({items:[{version:1,event:{action:'old'},at:'old',actor_id:'1'}]});await request;assert.equal(f.node('history').innerHTML,'Current row history');
});


test('intake priority sorts unassigned work; current task DTO wins and legacy omissions stay unrecorded',()=>{
 const f=fixture();f.context.data.requests=[{id:'legacy',title:'Legacy'},{id:'high',title:'Intake high',request_type:'plumbing',priority:'high',priority_source:'request'},{id:'task',title:'Assigned',request_type:'maintenance',priority:'low',requested_priority:'urgent',priority_source:'task',task:{priority:'low'}}];
 f.run('workSortState.set("a:requests",{key:"priority",direction:"asc"})');
 assert.deepEqual(plain(f.run('sortedWork(data.requests).map(r=>r.id)')),['high','task','legacy']);
 assert.equal(f.run('workValue(data.requests[1],"type")'),'Plumbing');
 assert.match(f.run('workCell(data.requests[1],"priority")'),/Saved intake priority/);
 assert.match(f.run('workCell(data.requests[2],"priority")'),/Current linked task priority/);
 assert.equal(f.run('workCell(data.requests[0],"priority")'),'Not recorded');
 assert.equal(f.run('requestTypeLabel({request_type:"constructor"})'),null);
 f.context.data.requests[2].priority=null;assert.equal(f.run('requestPriority(data.requests[2])'),null);
});

test('actual repeated detail cleanup retires each mounted gallery/file object once and advances stale-read fences',()=>{
 const f=fixture();f.run('clearAttachmentGallery();clearAttachmentGallery();invalidateSharedDiscussion();');
 assert.equal(f.calls.filter(c=>c.galleryDestroyed).length,1);assert.equal(f.calls.filter(c=>c.filesDestroyed).length,1);assert.equal(f.calls.filter(c=>c.filesHostRemoved).length,1);
 assert.deepEqual(plain(f.run('[attachmentGallery,sharedFilesUi,sharedFilesScope,sharedFilesName]')),[null,null,null,null]);
 assert.deepEqual(plain(f.run('[sharedFilesSerial,sharedFilesNameSerial,sharedDiscussionEpoch]')),[2,2,1]);
 assert.deepEqual(f.calls.find(c=>c.discussionInvalidated).discussionInvalidated,{stash:true});
});
