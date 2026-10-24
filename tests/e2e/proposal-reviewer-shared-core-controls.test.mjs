import assert from 'node:assert/strict';import test from 'node:test';import vm from 'node:vm';import {readFile} from 'node:fs/promises';import {webcrypto} from 'node:crypto';
const read=rel=>readFile(new URL('../../'+rel,import.meta.url),'utf8');
const [app,next,shared,transport,views]=await Promise.all(['resources/views/enterprise/app.blade.php','resources/views/enterprise/next-script.blade.php','resources/js/enterprise/reviewer/shared-workspace.js','resources/js/enterprise/transport.js','resources/js/enterprise/work-views.js'].map(read));
const remember=app.slice(app.indexOf('  function rememberNavigation('),app.indexOf('  function restoreNavigation('));const url=next.slice(next.indexOf('  function rememberUrl()'),next.indexOf('  function resetNext()'));
const A='10000000-0000-4000-8000-000000000001',B='20000000-0000-4000-8000-000000000001',P='30000000-0000-4000-8000-000000000001';
function fixture(){const calls=[],values=new Map();let attempts=0;const boot={runtime:'sample',identity:{tenantSlug:'fictional'},routes:{back:'https://workspace.invalid/private/review/campus',data:'/enterprise/data?tenant=fictional',enterprise:'/enterprise'},reviewerWorkspace:{enabled:true,schema_version:1,workspace_id:'90000000-0000-4000-8000-000000000001',browser_context:'8'.repeat(64),seed_sha256:'c'.repeat(64),policy_sha256:'d'.repeat(64),expires_at:'2099-01-01T00:00:00Z',binding:{version_hash:'a'.repeat(64),build_id:'synthetic',source_hash:'b'.repeat(64)},selection:{persona:'demo_manager',area_ids:[A],person_id:null},endpoints:{projection:'/private/review/workspace/projection',commands:'/private/review/workspace/commands',status:'/private/review/workspace/command-status',facts:'/private/review/workspace/facts'},csrf:'synthetic-noncredential'}};const location={href:boot.routes.back+'#section=requests',origin:'https://workspace.invalid',pathname:'/private/review/campus'};const history={state:null,pushState(state,title,to){calls.push(to);location.href=to;},replaceState(state,title,to){calls.push(to);location.href=to;}};const c=vm.createContext({URL,URLSearchParams,TextEncoder,TextDecoder,AbortController,crypto:webcrypto,boot,calls,location,history,sessionStorage:{getItem:k=>values.get(k)??null,setItem:(k,v)=>values.set(k,v)},fetch(){attempts++;throw Error('No HTTP in navigation proof');}});for(const s of [shared,transport,views])vm.runInContext(s,c);vm.runInContext(`let area='${A}',section='projects',selected='${P}',view='list',map=false,workMonth='2026-10',reviewerMode=true,navigationPushPending=true;const $=()=>({value:''}),tenantSlug='fictional',baseTransport=EverbranchTransport.create(boot);const transport=Object.freeze({...baseTransport,replace:rememberNavigation});${remember}\n${url}\nglobalThis.hooks={save:rememberUrl,move(){area='${B}';section='roll_call';selected=null;navigationPushPending=true;},month(value){workMonth=value;navigationPushPending=true;},replace:rememberNavigation,mode:transport.mode};`,c);return{c,calls,location,get attempts(){return attempts;}};}

test('implemented Buildings and Calendar sections persist through actual shared canonical history path',()=>{
 const f=fixture();for(const section of ['buildings','calendar']){vm.runInContext(`section='${section}';selected=null;navigationPushPending=true;`,f.c);f.c.hooks.month('2027-02');f.c.hooks.save();const q=new URLSearchParams(new URL(f.location.href).hash.slice(1));assert.equal(q.get('section'),section);assert.equal(q.get('area_id'),A);assert.equal(q.get('month'),'2027-02');assert.equal(new URL(f.location.href).search,'');}
 assert.equal(f.calls.length,2);assert.equal(f.attempts,0);
 // Execute the actual restore handler for history/reload; it must clear old facts and request a fresh projection.
 const restore=app.slice(app.indexOf('  function restoreNavigation('),app.indexOf("  globalThis.addEventListener('popstate'"));
 f.c.location.hash=new URL(f.location.href).hash;f.c.location.search='';f.c.loads=0;f.c.resets=0;
 vm.runInContext(`const allowedSections=['requests','projects','buildings','calendar'],navigation=new URLSearchParams(),campusToday=()=> '2026-10-24',rememberSelection=()=>{},resetNext=()=>resets++,load=()=>loads++;let navigationSearchBatch=false,navigationViewOverride=null;${restore}\nrestoreNavigation();`,f.c);
 assert.equal(vm.runInContext('section',f.c),'calendar');assert.equal(vm.runInContext('workMonth',f.c),'2027-02');assert.equal(f.c.loads,1);assert.equal(f.c.resets,1);
 // A new JS instance restores either URL without any cached graph/record payload.
 for(const url of f.calls){const fresh=fixture();fresh.location.href=url;fresh.location.hash=new URL(url).hash;fresh.location.search='';fresh.c.loads=0;fresh.c.resets=0;vm.runInContext(`const allowedSections=['requests','projects','buildings','calendar'],navigation=new URLSearchParams(),campusToday=()=> '2026-10-24',rememberSelection=()=>{},resetNext=()=>resets++,load=()=>loads++;let navigationSearchBatch=false,navigationViewOverride=null;${restore}\nrestoreNavigation();`,fresh.c);assert.equal(vm.runInContext('section',fresh.c),new URLSearchParams(new URL(url).hash.slice(1)).get('section'));assert.equal(vm.runInContext('workMonth',fresh.c),'2027-02');assert.equal(fresh.c.loads,1);assert.equal(fresh.attempts,0);}
});
test('new sections preserve exact publication path UUID persona and unsupported-feature guards',()=>{
 const f=fixture(),adapter=vm.runInContext('baseTransport',f.c),base='https://workspace.invalid/private/review/campus';
 for(const url of [base+'#section=assets',base+'#section=workflows',base+'#section=buildings&selected=invalid',base+'#section=calendar&area_id=invalid','https://other.invalid/private/review/campus#section=calendar','https://workspace.invalid/team#section=buildings',base+'?native=1#section=calendar',base+'#section=calendar&persona=manager'])assert.throws(()=>adapter.href(url));
 assert.throws(()=>vm.runInContext("EverbranchSharedWorkspace.create({...boot,reviewerWorkspace:{...boot.reviewerWorkspace,selection:{persona:'native_owner',area_ids:[boot.reviewerWorkspace.selection.area_ids[0]],person_id:null}}})",f.c));assert.equal(f.attempts,0);
});
const [mapSource,panels,hooksSource]=await Promise.all(['resources/js/enterprise/reviewer/shared-work-command-map.js','resources/views/enterprise/next-panels.blade.php','resources/views/enterprise/shared-work-script.blade.php'].map(read));
const plain=x=>JSON.parse(JSON.stringify(x));
class Node{
 constructor(tag='div'){this.tagName=tag;this.children=[];this.listeners=new Map();this.options=[];this.value='';this.hidden=false;this.disabled=false;this.textContent='';this.dataset={};this.focused=false;this.label={hidden:false};}
 append(...items){this.children.push(...items);}replaceChildren(...items){this.children=items;}closest(){return this.label;}focus(){this.focused=true;}addEventListener(k,f){this.listeners.set(k,f);}
 set innerHTML(html){this.html=html;if(this.tagName==='select'){this.options=[...html.matchAll(/<option value="([^"]*)">([^<]*)<\/option>/g)].map(m=>({value:m[1],textContent:m[2]}));this.value=this.options[0]?.value||'';}this.buttons=[...html.matchAll(/<button[^>]*data-(edit|cancel)-plan="([^"]*)"[^>]*>([^<]*)<\/button>/g)].map(m=>Object.assign(new Node('button'),{textContent:m[3],dataset:{[m[1]+'Plan']:m[2]}}));}
 get innerHTML(){return this.html||'';}querySelectorAll(s){return(this.buttons||[]).filter(b=>s==='[data-edit-plan]'?b.dataset.editPlan:s==='[data-cancel-plan]'?b.dataset.cancelPlan:false);}querySelector(s){return s==='h3'?this.heading:s==='[name=title]'?this.elements.title:null;}reset(){for(const f of Object.values(this.elements||{}))if(f&&typeof f==='object')f.value='';}
}
function planFixture(mode='shared'){
 const nodes=new Map(),calls=[],messages=[];let ids=0;const $=k=>{if(!nodes.has(k))nodes.set(k,new Node());return nodes.get(k);};const form=$('planning-form');form.heading=new Node('h3');form.elements={};
 const markup=panels.slice(panels.indexOf('<form id="planning-form"'),panels.indexOf('</form>'));
 for(const m of markup.matchAll(/<(input|select)[^>]*name="([^"]*)"[^>]*>/g)){const e=new Node(m[1]),id=m[0].match(/id="([^"]*)"/);form.elements[m[2]]=e;if(id)nodes.set(id[1],e);}Object.defineProperty(form.elements,'namedItem',{value:k=>form.elements[k]});$('planning-kind').options=[{value:'schedule'},{value:'contractor'},{value:'cost'}];
 const data={features:{planning:true},areas:[{id:A}],selected_area_id:A,projects:[{id:'project',name:'Captured project',version:7,archived:false}],shared:{workspace_id:'workspace',revision:1,workspace_digest:'e'.repeat(64),selection:{persona:'demo_manager',area_ids:[A],person_id:null}}};
 const result={items:[{id:'contractor',kind:'contractor',title:'Captured contractor',status:'active',version:6},{id:'plan',kind:'schedule',title:'Derived plan label',status:'planned',version:3,starts_on:'2026-11-01',ends_on:'2026-11-03',data:{project_id:'project',contractor_id:'contractor'},allowed_actions:['plan.update','plan.cancel']}],can_coordinate:true,can_finance:true,can_manage_costs:false,basis:'Server-held fictional plans',partial:false};
 const c=vm.createContext({$,document:{createElement:t=>new Node(t)},FormData:class{constructor(f){this.f=f;}get(k){const e=this.f.elements[k];return e?.disabled?null:e?.value??null;}},crypto:{randomUUID:()=> 'stable-request-'+(++ids)},esc:String,presentation:{reviewerWorkspace:{browser_context:'8'.repeat(64),binding:{version_hash:'a'.repeat(64)}}},transport:{mode},initialData:data,initialPlanning:result,calls,messages,message:(...a)=>messages.push(a)});vm.runInContext(mapSource,c);
 const capture=hooksSource.slice(hooksSource.indexOf('  function captureSharedPin('),hooksSource.indexOf('  const sharedRecovery'));
 const chunk=next.slice(next.indexOf('  async function loadPlanning()'),next.indexOf('  function chatMessage('));
 vm.runInContext(`const sharedWorkspace={};let area='${A}',section='contractors',selected=null,data=initialData,nextPlanning=initialPlanning,planningData=null,planningPending=null,planningBusy=false,pending=null,busy=false;const nextBase='/enterprise',nextQuery=()=>'?tenant=fictional',planningReceipts=new Map(),planningUncertain=new Set(),api=async()=>nextPlanning;${capture}\nasync function sharedCommand(body,context){const mapped=EverbranchSharedWorkCommandMap.map(body,context);calls.push({body:JSON.parse(JSON.stringify(body)),context,mapped});if(globalThis.reply==='applied')context.onApplied?.({status:'applied',receipt:{request_id:body.request_id}});else if(globalThis.reply==='unknown')pending={...body};}\n${chunk}\nglobalThis.hooks={load:loadPlanning,change(values){for(const[k,v]of Object.entries(values))$('planning-form').elements.namedItem(k).value=v;$('planning-form').listeners.get('input')?.();},submit:()=>$('planning-form').onsubmit({preventDefault(){},target:$('planning-form')}),open:typeof openSharedPlanEdit==='function'?openSharedPlanEdit:null,edit:()=>typeof planningSharedEdit==='undefined'?null:planningSharedEdit,refresh(rev){data=JSON.parse(JSON.stringify(data));data.shared.revision=rev;data.projects[0].version=70;nextPlanning=JSON.parse(JSON.stringify(nextPlanning));nextPlanning.items[0].version=60;nextPlanning.items[1].version=30;return loadPlanning();},setPending(v){pending=v;},readonly(){nextPlanning={...nextPlanning,can_coordinate:false};nextPlanning.items=nextPlanning.items.map(r=>({...r,allowed_actions:[]}));return loadPlanning();}};`,c);c.reply='unknown';
 return{c,nodes,calls,messages,form,load:()=>c.hooks.load(),editButton:()=>$('planning-list').querySelectorAll('[data-edit-plan]')[0],cancelEdit:()=>form.children.find(n=>n.id==='planning-edit-cancel'),get ids(){return ids;}};
}
test('visible Edit control prefills existing dates and maps same plan ID and captured FK versions to plan.update',async()=>{
 const f=planFixture();await f.load();const button=f.editButton();assert.ok(button,'Saved date block needs visible Edit control');assert.equal(button.textContent,'Edit date block');button.onclick();assert.equal(f.form.elements.starts_on.value,'2026-11-01');assert.equal(f.form.elements.ends_on.value,'2026-11-03');assert.equal(f.form.elements.starts_on.focused,true);const edit=f.c.hooks.edit(),ids=f.ids;f.c.hooks.change({starts_on:'2026-11-02',ends_on:'2026-11-05'});await f.c.hooks.submit();assert.equal(f.ids,ids,'Submit preserves identities');assert.equal(f.calls[0].body.id,'plan');assert.equal(f.calls[0].body.request_id,edit.request_id);assert.equal(f.calls[0].mapped.action,'plan.update');assert.equal(f.calls[0].mapped.expected_record_version,3);assert.deepEqual(plain(f.calls[0].mapped.payload),{area_id:A,project_id:'project',project_version:7,contractor_id:'contractor',contractor_version:6,starts_on:'2026-11-02',ends_on:'2026-11-05'});assert.equal(f.calls[0].context.pin.revision,1);
});
test('background reads cannot upgrade active edit pin or FK versions and preserve changed dates',async()=>{
 const f=planFixture();await f.load();f.editButton().onclick();f.c.hooks.change({starts_on:'2026-12-01',ends_on:'2026-12-04'});await f.c.hooks.refresh(9);assert.equal(f.form.elements.starts_on.value,'2026-12-01');await f.c.hooks.submit();assert.equal(f.calls[0].context.pin.revision,1);assert.equal(f.calls[0].mapped.expected_record_version,3);assert.equal(f.calls[0].mapped.payload.project_version,7);assert.equal(f.calls[0].mapped.payload.contractor_version,6);assert.equal(f.calls[0].mapped.payload.ends_on,'2026-12-04');
});
test('unknown save prevents replacement/discard/resubmit and retains exact editor identity and fields',async()=>{
 const f=planFixture();await f.load();f.editButton().onclick();f.c.hooks.change({ends_on:'2026-12-04'});await f.c.hooks.submit();const edit=f.c.hooks.edit(),ids=f.ids;f.cancelEdit().onclick();f.c.hooks.open('plan');await f.c.hooks.submit();assert.equal(f.c.hooks.edit(),edit);assert.equal(f.ids,ids);assert.equal(f.calls.length,1);assert.equal(f.form.elements.ends_on.value,'2026-12-04');
});
test('only matching verified apply clears editor; late older callback cannot clear newer editor',async()=>{
 const f=planFixture();await f.load();f.editButton().onclick();const old=f.c.hooks.edit();old.context.onApplied({status:'applied',receipt:{request_id:'foreign'}});assert.equal(f.c.hooks.edit(),old);f.cancelEdit().onclick();f.editButton().onclick();const current=f.c.hooks.edit();assert.notEqual(current.request_id,old.request_id);old.context.onApplied({status:'applied',receipt:{request_id:old.request_id}});assert.equal(f.c.hooks.edit(),current);f.c.reply='applied';await f.c.hooks.submit();assert.equal(f.c.hooks.edit(),null);assert.equal(f.form.heading.textContent,'Record a plan');
});
test('read-only and native planner gain no shared Edit controls; costs remain noneditable',async()=>{
 const f=planFixture();await f.load();await f.c.hooks.readonly();assert.equal(f.editButton(),undefined);assert.equal(f.form.hidden,true);assert.equal(f.nodes.get('cost-option').hidden,true);const native=planFixture('private');await native.load();assert.equal(native.editButton(),undefined);assert.equal(native.form.children.some(n=>n.id==='planning-edit-cancel'),false);
});
test('actual settlement hook invokes completion only after applied and retires callback once',async()=>{
 const settle=hooksSource.slice(hooksSource.indexOf('  async function settleSharedEdit('),hooksSource.indexOf('  async function recoverSharedEdit(')),events=[];
 const c=vm.createContext({events,EverbranchTransport:{setRefreshBlocker(){}},$:()=>({close(){}}),jobDialog:{open:false},message(){},load:async()=>{},proposalAiUi:null});vm.runInContext(`let sharedPrepared={},pending={},pendingUncertain=true,sharedCommandContext={onApplied:v=>events.push(v.receipt.request_id)},jobWizard={};${settle}\nglobalThis.settle=settleSharedEdit;`,c);
 assert.equal(await c.settle({status:'not_observed'}),false);assert.equal(events.length,0);assert.equal(await c.settle({status:'applied',stale_context:true,receipt:{request_id:'exact'}}),true);assert.deepEqual(events,['exact']);await c.settle({status:'applied',stale_context:true,receipt:{request_id:'again'}});assert.deepEqual(events,['exact']);
});

// Independent desired regressions. Original eight subject tests above remain byte-exact.
test('independent read-only background projection cannot silently turn an unsent existing-plan edit into plan.create', async () => {
    const f = planFixture(); await f.load(); f.editButton().onclick();
    const original = f.c.hooks.edit(); f.c.hooks.change({starts_on: '2026-12-01', ends_on: '2026-12-04'});
    await f.c.hooks.readonly();
    await vm.runInContext(`nextPlanning = {...nextPlanning, can_coordinate: true}; nextPlanning.items = nextPlanning.items.map(r => r.kind === 'schedule' ? {...r, allowed_actions: ['plan.update', 'plan.cancel']} : r); hooks.load();`, f.c);
    await f.c.hooks.submit();
    assert.equal(f.calls.length, 1);
    assert.equal(f.calls[0].body.id, original.id, 'Background read-only result lost the captured existing plan ID');
    assert.equal(f.calls[0].mapped.action, 'plan.update');
    assert.equal(f.calls[0].mapped.expected_record_version, original.version);
    assert.equal(f.calls[0].context.pin.revision, 1);
});
test('independent applied recovery must not discard newer editable date fields in the same captured editor', async () => {
    const f = planFixture(); await f.load(); f.editButton().onclick();
    f.c.hooks.change({ends_on: '2026-12-04'}); await f.c.hooks.submit();
    const sent = f.calls[0], controls = ['project_id', 'contractor_id', 'starts_on', 'ends_on'].map(k => f.form.elements[k]);
    if (controls.every(n => n.disabled)) {
        assert.equal(sent.body.ends_on, '2026-12-04'); return;
    }
    f.c.hooks.change({ends_on: '2026-12-09'});
    assert.equal(sent.body.ends_on, '2026-12-04', 'The original submitted body remains exact');
    sent.context.onApplied({status: 'applied', receipt: {request_id: sent.body.request_id}});
    assert.equal(f.form.elements.ends_on.value, '2026-12-09', 'A matching old receipt cleared newer editable unsent dates');
});

test('independent actual unknown send then actual recovery settlement preserves editable newer fields or locks them', async () => {
    const f = planFixture(); await f.load(); f.editButton().onclick();
    const actual = hooksSource.slice(hooksSource.indexOf('  async function settleSharedEdit('));
    vm.runInContext(`
        let sharedPrepared=null,sharedCommandContext=null,pendingUncertain=false,jobWizard=null,adapterPending=null;
        const EverbranchTransport={setRefreshBlocker(){}},jobDialog={open:false},proposalAiUi=null;
        const load=()=>hooks.load(); function refreshSharedRecovery(){};
        $('create-dialog').close=()=>{};
        sharedWorkspace.prepareCommand=async(pin,command)=>{adapterPending={command:JSON.parse(JSON.stringify(command))};return adapterPending;};
        sharedWorkspace.send=async()=>{throw Error('Synthetic connection lost after exact dispatch');};
        sharedWorkspace.pending=()=>adapterPending;
        sharedWorkspace.snapshot=()=>({phase:'unknown'});
        sharedWorkspace.recover=async()=>{const receipt={request_id:adapterPending.command.request_id};adapterPending=null;return{status:'applied',receipt};};
        ${actual}
        globalThis.actualRecover=recoverSharedEdit;
        globalThis.actualPending=()=>pending; globalThis.actualReady=()=>!busy&&sharedPrepared!==null;
    `,f.c);
    f.c.hooks.change({ends_on:'2026-12-04'}); await f.c.hooks.submit();
    for(let i=0;!f.c.actualReady()&&i<100;i++)await new Promise(r=>setImmediate(r));
    assert.ok(f.c.actualReady()); assert.ok(f.c.actualPending());
    const allLocked=['project_id','contractor_id','starts_on','ends_on'].every(k=>f.form.elements[k].disabled);
    if(!allLocked)f.c.hooks.change({ends_on:'2026-12-09'});
    await f.c.actualRecover();
    assert.equal(f.c.actualPending(),null,JSON.stringify(f.messages));
    if(!allLocked)assert.equal(f.form.elements.ends_on.value,'2026-12-09','Actual verified recovery cleared newer editable date fields');
    else assert.equal(f.c.hooks.edit(),null,'Verified exact old settlement completes the locked editor');
});

function actualRecoveryFixture(f,{prepareFailure=false,rejected=false}={}){
 const actual=hooksSource.slice(hooksSource.indexOf('  function refreshSharedRecovery('));
 const acknowledge=hooksSource.slice(hooksSource.indexOf('      if(!sharedPrepared||busy)return;'),hooksSource.indexOf('    });\n    sharedDraft='));
 vm.runInContext(`let sharedPrepared=null,sharedCommandContext=null,pendingUncertain=false,jobWizard=null,adapterPending=null,phase='idle',sendCount=0;
 const sharedRecoverButton={},sharedRetryButton={},sharedAcknowledgeButton={},sharedDraft={querySelector:()=>({})};
 const EverbranchTransport={setRefreshBlocker(){}},jobDialog={open:false},proposalAiUi=null,load=()=>hooks.load();$('create-dialog').close=()=>{};
 sharedWorkspace.prepareCommand=async(pin,command)=>{if(${prepareFailure})throw Error('Synthetic pre-dispatch storage refusal');adapterPending={command:JSON.parse(JSON.stringify(command))};return adapterPending;};
 sharedWorkspace.send=async()=>{sendCount++;phase=${rejected}? 'rejected':'unknown';throw Error('Synthetic retained outcome');};
 sharedWorkspace.pending=()=>adapterPending;sharedWorkspace.draft=()=>adapterPending?.command||null;sharedWorkspace.snapshot=()=>({phase});
 sharedWorkspace.acknowledgeRejection=()=>{adapterPending=null;phase='idle';};
 sharedWorkspace.recover=async()=>{const receipt={request_id:adapterPending.command.request_id};adapterPending=null;phase='idle';return{status:'applied',receipt};};
 ${actual}
 globalThis.actualRecover=recoverSharedEdit;globalThis.actualAcknowledge=async()=>{${acknowledge}};
 globalThis.actualReady=()=>!busy;globalThis.actualState=()=>({pending,sendCount,phase});`,f.c);
}
const turn=()=>new Promise(resolve=>setImmediate(resolve));
const inputs=f=>['project_id','contractor_id','starts_on','ends_on'].map(key=>f.form.elements[key]);
test('actual unknown send locks synchronously and actual recovery finally unlocks after exact settlement',async()=>{
 const f=planFixture();await f.load();f.editButton().onclick();actualRecoveryFixture(f);f.c.hooks.change({ends_on:'2026-12-04'});f.c.hooks.submit();
 assert.ok(inputs(f).every(n=>n.disabled),'No editable window while exact preparation or dispatch is awaiting');
 for(let i=0;!f.c.actualReady()&&i<100;i++)await turn();assert.ok(f.c.actualReady());assert.ok(f.c.actualState().pending);assert.ok(inputs(f).every(n=>n.disabled));
 await f.c.actualRecover();assert.equal(f.c.hooks.edit(),null);assert.equal(f.c.actualState().pending,null);assert.ok(inputs(f).every(n=>!n.disabled));assert.equal(f.nodes.get('planning-save').disabled,false);assert.equal(f.cancelEdit().disabled,false);assert.equal(f.c.actualState().sendCount,1);
});
test('actual pre-dispatch refusal unlocks captured editor without clearing fields identity or pin',async()=>{
 const f=planFixture();await f.load();f.editButton().onclick();const edit=f.c.hooks.edit();actualRecoveryFixture(f,{prepareFailure:true});f.c.hooks.change({ends_on:'2026-12-04'});f.c.hooks.submit();for(let i=0;!f.c.actualReady()&&i<100;i++)await turn();await turn();
 assert.equal(f.c.actualState().sendCount,0);assert.equal(f.c.hooks.edit(),edit);assert.equal(f.c.hooks.edit().context.pin.revision,1);assert.equal(f.form.elements.ends_on.value,'2026-12-04');assert.ok(inputs(f).every(n=>!n.disabled));f.cancelEdit().onclick();assert.equal(f.c.hooks.edit(),null);assert.ok(inputs(f).every(n=>!n.disabled));
});
test('actual acknowledged rejection releases locks without upgrading captured edit or issuing another command',async()=>{
 const f=planFixture();await f.load();f.editButton().onclick();const edit=f.c.hooks.edit();actualRecoveryFixture(f,{rejected:true});f.c.hooks.change({ends_on:'2026-12-04'});f.c.hooks.submit();for(let i=0;!f.c.actualReady()&&i<100;i++)await turn();assert.ok(inputs(f).every(n=>n.disabled));
 await f.c.actualAcknowledge();assert.equal(f.c.actualState().pending,null);assert.equal(f.c.hooks.edit(),edit);assert.equal(f.c.hooks.edit().context.pin.revision,1);assert.equal(f.c.hooks.edit().request_id,edit.request_id);assert.ok(inputs(f).every(n=>!n.disabled));assert.equal(f.c.actualState().sendCount,1);
});

test('failed background planning read suppresses controls without losing existing edit identity or unsent dates',async()=>{
 const f=planFixture();await f.load();f.editButton().onclick();const edit=f.c.hooks.edit();f.c.hooks.change({ends_on:'2026-12-04'});
 await vm.runInContext("nextPlanning=Promise.reject(Error('Synthetic denied planning read'));hooks.load();",f.c);assert.equal(f.form.hidden,true);assert.equal(f.c.hooks.edit(),edit);
 await vm.runInContext('nextPlanning=initialPlanning;hooks.load();',f.c);assert.equal(f.form.hidden,false);assert.equal(f.form.elements.ends_on.value,'2026-12-04');assert.equal(f.c.hooks.edit().context.pin.revision,1);assert.equal(f.calls.length,0);
});
test('optional planner lock synchronization is safe before planner lexical initialization',()=>{
 const start=next.indexOf('  function syncSharedPlanEditorLock('),end=next.indexOf('  function openSharedPlanEdit('),sync=next.slice(start,end);
 const c=vm.createContext({transport:{mode:'shared'},$:()=>null});assert.doesNotThrow(()=>vm.runInContext(`${sync}\nsyncSharedPlanEditorLock();let planningSharedEdit=null;const planningEditCancel=null;`,c));
});
