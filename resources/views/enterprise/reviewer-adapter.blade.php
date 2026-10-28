{{-- Included only when a trusted controller supplies runtime=sample. Never chosen by URL/storage. --}}
<style>.enterprise-sample{padding-top:48px}.reviewer-ribbon{position:fixed;inset:0 0 auto;min-height:48px;background:#eae5d2;color:#4b583f;border-bottom:1px solid #d2ceba;z-index:50;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:7px 20px;font-size:10px}.reviewer-ribbon strong{letter-spacing:.08em}.reviewer-ribbon label{display:flex;align-items:center;gap:8px}.reviewer-ribbon select{padding:6px;font-size:11px;min-height:34px}.reviewer-ribbon button{min-height:34px;padding:6px 10px;background:transparent;border:1px solid #b7bea7;border-radius:4px;color:inherit}.reviewer-reset{border:1px solid #d6dece;border-radius:9px;max-width:calc(100vw - 24px);padding:25px;color:#233d32}.reviewer-reset::backdrop{background:#142a24b8}.reviewer-reset h2{font:25px Georgia,serif}.reviewer-reset p{font-size:13px;line-height:1.8}.reviewer-reset .actions{display:flex;gap:10px;flex-wrap:wrap}.reviewer-reset button{min-height:44px;padding:10px 16px;border:1px solid #cbd6bf;border-radius:5px;background:white;color:#244d3d}.reviewer-reset button:last-child{background:#244d3d;color:white}.reviewer-ribbon *:focus-visible,.reviewer-reset *:focus-visible{outline:3px solid #b18b2c;outline-offset:3px}@media(max-width:650px){.enterprise-sample{padding-top:82px}.reviewer-ribbon{min-height:82px;padding:8px 12px;flex-wrap:wrap;gap:5px}.reviewer-ribbon .reviewer-boundary{width:100%;font-size:9px}.reviewer-ribbon label{font-size:9px}.reviewer-ribbon select{max-width:145px}.reviewer-ribbon .reviewer-title{font-size:9px}}</style>
<div class="reviewer-ribbon" role="region" aria-label="Fictional sample controls"><span class="reviewer-title"><strong>SAMPLE DATA</strong> · Everbranch demonstration</span><span class="reviewer-boundary">{{ ($enterprisePresentation['reviewerAi']['enabled'] ?? false) === true ? 'Sample work stays in this tab. Confirmed AI shares selected fields; review comments are saved.' : (!empty($campusReviewContext) ? 'Fictional campus work stays in this tab. Review names and page comments are saved to the server; no messages or AI charges.' : 'This tab only. No real campus data, server writes, messages or AI charges.') }}</span><label>Sample perspective<select id="reviewer-perspective" aria-label="Sample perspective"><option value="manager">Department manager</option><option value="worker">Assigned worker</option><option value="requester">Student requester</option></select></label><button type="button" id="reviewer-reset-open">Reset sample</button></div>
<dialog class="reviewer-reset" id="reviewer-reset-dialog" aria-labelledby="reviewer-reset-title"><h2 id="reviewer-reset-title">Reset this tab's fictional sample?</h2><p>This clears your sample edits, notes, workflow runs and upload metadata. It does not affect Enterprise records, another tab's data, credentials or trial spending.</p><div class="actions"><button type="button" id="reviewer-reset-cancel">Keep my sample</button><button type="button" id="reviewer-reset-confirm">Reset this tab's sample</button></div></dialog>
<script>{!! file_get_contents(resource_path('js/enterprise/reviewer/job-sample.js')) !!}</script>
<script>{!! file_get_contents(resource_path('js/enterprise/reviewer/sample-adapter.js')) !!}</script>
<script>{!! file_get_contents(resource_path('js/enterprise/reviewer/tour.js')) !!}</script>
@if(array_key_exists('reviewerWorkspace', $enterprisePresentation))<script>{!! file_get_contents(resource_path('js/enterprise/reviewer/shared-work-command-map.js')) !!}</script><script>{!! file_get_contents(resource_path('js/enterprise/reviewer/shared-workspace-map.js')) !!}</script><script>{!! file_get_contents(resource_path('js/enterprise/reviewer/shared-workspace.js')) !!}</script><script>{!! file_get_contents(resource_path('js/enterprise/reviewer/shared-tour-navigation.js')) !!}</script>@endif
@if(array_key_exists('reviewerCollaboration', $enterprisePresentation))<script>{!! file_get_contents(resource_path('js/enterprise/reviewer/collaboration-client.js')) !!}</script><script>{!! file_get_contents(resource_path('js/enterprise/reviewer/review-name.js')) !!}</script>@endif
@if(array_key_exists('reviewerAttachments', $enterprisePresentation))<script>{!! file_get_contents(resource_path('js/enterprise/reviewer/workspace-upload-client.js')) !!}</script><script>{!! file_get_contents(resource_path('js/enterprise/reviewer/workspace-file-ui.js')) !!}</script>@endif
@if(array_key_exists('reviewerImages', $enterprisePresentation))<script>{!! file_get_contents(resource_path('js/enterprise/reviewer/workspace-image-client.js')) !!}</script>@endif
@if(array_key_exists('reviewerDownloads', $enterprisePresentation))<script>{!! file_get_contents(resource_path('js/enterprise/reviewer/workspace-download-client.js')) !!}</script>@endif
<script>{!! file_get_contents(resource_path('js/enterprise/transport.js')) !!}</script>
<script>
(() => {
    'use strict';
    const boot = @json($enterprisePresentation), adapter = EverbranchTransport.reviewerAdapter(boot), shared = adapter.mode === 'shared';
    const defaultArea = shared ? boot.reviewerWorkspace.selection.area_ids[0] : EverbranchSampleTransport.ids.area;
    document.body.classList.add('enterprise-sample');
    if (shared) {
      document.querySelector('.reviewer-boundary').textContent = 'Shared fictional work is saved on the server. Your review name grants no campus account access.';
      document.getElementById('reviewer-reset-open').hidden = true;
    }
// SIMULATED_REVIEWER_MOUNT_BEGIN
function mountReviewerSimulation(host, {adapter, dispatch, defaultArea, workspace}) {
    const doc=host.ownerDocument, get=id=>doc.getElementById(id);
    const area=get('demo-conversation-area'), department=get('demo-email-department'), question=get('demo-conversation-question');
    const subject=get('demo-email-subject'), body=get('demo-email-body'), log=get('demo-conversation-log'), status=get('demo-conversation-status');
    const confirm=get('demo-email-confirm'), preview=get('demo-email-preview'), previewButton=get('demo-email-preview-button');
    let session=null, focused=null, pendingPreview=null, disposed=false, epoch=0, busy=false, unsubscribe=()=>{};
    const drafts=new Map();
    const context=adapter.demoContext(defaultArea);
    for(const row of context.departments) for(const select of [area,department]) {const option=doc.createElement('option');option.value=row.id;option.textContent=row.name;select.append(option);}
    area.value=defaultArea;department.value=defaultArea;
    let stamp=adapter.demoContext(area.value).stamp;
    const visible=()=>host.isConnected&&!host.closest('[hidden]')&&!doc.hidden;
    const current=()=>!disposed&&visible()&&adapter.demoContext(area.value).stamp===stamp;
    const line=(text,kind='')=>{const node=doc.createElement('div');node.className='chat-turn '+kind;node.textContent=text;log.append(node);while(log.children.length>16)log.firstElementChild.remove();return node;};
    const invalidatePreview=()=>{pendingPreview=null;confirm.hidden=true;preview.hidden=true;preview.textContent='';};
    function stash(){drafts.set(area.value,{subject:subject.value,body:body.value,department:department.value,job:focused?.id||null});}
    function outbox(){const rows=adapter.demoOutbox();get('demo-email-outbox').replaceChildren();if(!rows.length)get('demo-email-outbox').textContent='No simulated deliveries.';for(const row of rows){const article=doc.createElement('article'),text=doc.createElement('p');text.textContent=`Simulated delivery to ${row.department}: ${row.subject}. No email was sent.`;article.append(text);get('demo-email-outbox').append(article);}}
    function reply(result){
        if(!current())return;
        invalidatePreview();const answer=line(result.reply);
        for(const candidate of result.candidates||[]){const button=doc.createElement('button');button.type='button';button.className='button';button.textContent=candidate.title;const choiceEpoch=epoch;button.onclick=async()=>{
            if(!current()||busy||choiceEpoch!==epoch)return;const turn=++epoch;busy=true;get('demo-conversation-submit').disabled=true;
            try{if(session)await session.choose(candidate.id);else{const result=await adapter.request('/enterprise/voice/jobs',{area_id:area.value,question:'Show the selected job',focus:candidate.id});if(current()&&turn===epoch)reply(result);}}
            catch(failure){if(current()&&turn===epoch)status.textContent=failure.message;}
            finally{if(current()&&turn===epoch){busy=false;get('demo-conversation-submit').disabled=false;}}
        };answer.append(button);}
        focused=result.job||null;previewButton.disabled=!focused;
        if(focused){const job=focused,link=doc.createElement('button');link.type='button';link.className='button';link.textContent='Open source project';link.onclick=()=>{if(current())dispatch({section:'projects',area:job.area_id,selected:job.project_id});};answer.append(link);
            const draft=drafts.get(area.value);if(draft?.job===job.id){subject.value=draft.subject;body.value=draft.body;department.value=draft.department;}else{subject.value='Job update: '+job.title;body.value=`${job.title}\nStatus: ${job.status}\nPriority: ${job.priority||'Not recorded'}\nLocation: ${job.location?.label||'Not recorded'}\nNext action: ${job.next_action||'Not recorded'}`;}
            stash();
        }
        status.textContent='Simulated answer from current fictional records. No AI charge or message sent.';
    }
    function mountVoice(){
        if(disposed||session||!globalThis.EverbranchVoiceJobs)return;
        session=globalThis.EverbranchVoiceJobs.mount(get('demo-voice-controls'),{
            context:()=>({runtime:'sample',tenantId:'sample-campus',userId:adapter.demoContext(area.value).user_id,areaId:area.value}),
            lookup:(input,options)=>{if(!current())throw Error('The demo context changed.');return adapter.request('/enterprise/voice/jobs',{...input,...(focused&&/^(?:what(?: is)? (?:its|the) (?:repair )?priority|priority)[?.!]*$/i.test(input.question)?{focus:focused.id}:{})},options);},
            openJob:async()=>current(),onReply:reply,
            onTranscript:text=>{if(current()){question.value=text;line(text,'question');}},
            onError:text=>{if(current()){focused=null;previewButton.disabled=true;invalidatePreview();status.textContent=text;}},
            Recognition:globalThis.SpeechRecognition||globalThis.webkitSpeechRecognition,Utterance:globalThis.SpeechSynthesisUtterance,synthesis:globalThis.speechSynthesis,
        });
        status.textContent='Type a job question or choose Talk. Browser speech may use its speech service; no paid AI or email is connected.';
    }
    get('demo-conversation-form').onsubmit=async event=>{
        event.preventDefault();if(!current()||busy)return;const text=question.value.trim();if(!text)return;
        const turn=epoch;busy=true;get('demo-conversation-submit').disabled=true;line(text,'question');
        try{if(session)await session.ask(text);else {const result=await adapter.request('/enterprise/voice/jobs',{area_id:area.value,question:text,...(focused&&/^(?:where is (?:it|that|this)|who is handling (?:it|that|this)|what(?: is)? (?:its|the) (?:status|priority|due date|next action)|when is (?:it|that|this) due|tell me more)[?.!]*$/i.test(text)?{focus:focused.id}:{})});if(current()&&turn===epoch)reply(result);}if(current()&&turn===epoch&&question.value.trim()===text)question.value='';}
        catch(failure){if(current()&&turn===epoch)status.textContent=failure.message;}
        finally{if(current()&&turn===epoch){busy=false;get('demo-conversation-submit').disabled=false;}}
    };
    get('demo-email-form').oninput=()=>{invalidatePreview();stash();};
    get('demo-email-form').onsubmit=event=>{
        event.preventDefault();if(!current()||!focused)return;
        try{pendingPreview=adapter.demoEmail({area_id:area.value,department_id:department.value,job_id:focused.id,subject:subject.value,body:body.value});preview.textContent=`DEMO PREVIEW\nTo department: ${pendingPreview.department}\nSubject: ${pendingPreview.subject}\n\n${pendingPreview.body}\n\nSimulated only. No external delivery.`;preview.hidden=false;confirm.hidden=false;}
        catch(failure){invalidatePreview();status.textContent=failure.message;}
    };
    confirm.onclick=()=>{if(!current()||!pendingPreview)return;try{adapter.demoDeliver({preview_id:pendingPreview.id,confirmed:true});invalidatePreview();outbox();status.textContent='Simulated delivery recorded in this page only. No email was sent.';}catch(failure){invalidatePreview();status.textContent=failure.message;}};
    area.onchange=()=>{if(disposed)return;epoch++;session?.contextChanged();focused=null;busy=false;get('demo-conversation-submit').disabled=false;question.value='';log.replaceChildren();invalidatePreview();previewButton.disabled=true;stamp=adapter.demoContext(area.value).stamp;const draft=drafts.get(area.value);subject.value=draft?.subject||'';body.value=draft?.body||'';department.value=draft?.department||area.value;status.textContent='Department changed. Ask about a current job before reviewing this draft.';};
    function suspend(){if(disposed)return;epoch++;busy=false;session?.stop();get('demo-conversation-submit').disabled=false;invalidatePreview();}
    const visibility=()=>{if(doc.hidden)suspend();}, departure=()=>suspend();
    const observer=new doc.defaultView.MutationObserver(()=>{if(!visible())suspend();});
    observer.observe(host.closest('#ask')||host,{attributes:true,attributeFilter:['hidden']});
    doc.addEventListener('visibilitychange',visibility);globalThis.addEventListener('pagehide',departure);
    function destroy(){if(disposed)return;disposed=true;epoch++;observer.disconnect();doc.removeEventListener('visibilitychange',visibility);globalThis.removeEventListener('pagehide',departure);session?.dispose();drafts.clear();unsubscribe();globalThis.removeEventListener('everbranch:voice-jobs-ready',mountVoice);host.hidden=true;question.value=subject.value=body.value='';log.replaceChildren();invalidatePreview();get('demo-email-outbox').replaceChildren();}
    unsubscribe=adapter.subscribeDemo(destroy);host.hidden=false;mountVoice();globalThis.addEventListener('everbranch:voice-jobs-ready',mountVoice);
    return Object.freeze({destroy});
}
// SIMULATED_REVIEWER_MOUNT_END

    function start() {
        const root = document.querySelector('[data-reviewer-tour-root]:not([data-priority-only="true"])');
        if (root && document.querySelector('.content')) document.querySelector('.content').prepend(root);
        const dispatch = detail => document.dispatchEvent(new CustomEvent('enterprise:navigate', {detail}));
        const sharedTour = shared ? EverbranchSharedTourNavigation.create({adapter, dispatch,
            currentArea:()=>document.querySelector('#areas [data-area].active')?.dataset.area || null,
            context:()=>location.href,
            notify:message=>{const status=document.getElementById('status');if(status)status.textContent=message;},
        }) : null;
        if (shared) {
            const ticketButton = root?.querySelector('[data-tour-email-cta]');
            if (ticketButton) {
                ticketButton.textContent = 'Open a current permitted ticket';
                if (ticketButton.nextElementSibling) ticketButton.nextElementSibling.textContent = 'This illustrative preview is separate from saved work. The button checks a currently permitted ticket; it sends no message.';
            }
        }
        const executive = ['executive','demo_manager'].includes(adapter.snapshot().persona);
        if (boot.reviewerSimulation === true && !shared && document.getElementById('reviewer-simulation')) {
            mountReviewerSimulation(document.getElementById('reviewer-simulation'), {adapter, dispatch, defaultArea, workspace:boot.identity.workspace});
            const tourAsk=root?.querySelector('[data-tour-scene="6"]');
            if(tourAsk){tourAsk.dataset.tourFeature='reviewer-simulation';const number=document.createElement('span');number.textContent='07';tourAsk.replaceChildren(number,document.createTextNode('Demo assistant'));}
        }
        EverbranchReviewerTour.mount(root, {
            ...(executive ? {priorityItems:EverbranchReviewerTour.executivePriorities} : {}),
            ...(boot.reviewerSimulation === true && !shared ? {describeScene(index, base) { return index === 6 ? {
                ...base, title:'Try the demo assistant', target:'reviewer-simulation', targets:['demo-conversation-form','demo-voice-controls','demo-email-form'],
                text:'Choose a department. Ask about a job, then follow up by text or Talk.',
                detail:'Review an editable demo email and choose Simulate send. Drafts and outbox stay on this page only; nothing is delivered.',
                cards:[['Current records','Only the current fictional perspective and department.'],['Voice or text','Talk is explicit. Stop and Interrupt control audio; typing is always available.'],['Demo delivery','Review the exact message and department, then explicitly Simulate send.']],
            } : base; }} : boot.reviewerAi?.enabled ? {describeScene(index, base) { return index === 6 ? {
                ...base, title:'Ask about fictional work', target:'proposal-ai-controls', targets:['proposal-ai-form','proposal-ai-controls'],
                text:'Type a question or start voice. Review the exact shared fields before each Send once action.',
                detail:'Answers use selected fictional records. Assistants remain Coming soon; browser voice availability varies.',
                cards:[['Review first','Inspect the question, current fields and any included prior turns.'],['Send once','One confirmed request uses the existing shared trial allowance.'],['Type or talk','Stop and Interrupt control local audio. Typed input remains available.']],
            } : base; }} : {}),
            priorityStorageKey:'everbranch-priorities-sample:'+boot.identity.workspace+':'+adapter.snapshot().persona,
            priorityStatus(item) {
                const manager = ['manager','executive','demo_manager'].includes(adapter.snapshot().persona);
                const restricted = ['reports','certificates','invoices','funds'].includes(item.id);
                return {available: !restricted || manager, status: restricted && !manager ? 'Unavailable in this sample perspective' : 'Browser simulation · server feature implemented, not hosted'};
            },
            onPriority(item) {
                if (['reports','certificates','invoices','funds'].includes(item.id) && !['manager','executive','demo_manager'].includes(adapter.snapshot().persona)) return {available:false,message:'Unavailable in this sample perspective'};
                dispatch({section:item.section, area:defaultArea, ...(['projects','requests'].includes(item.section)?{selected:null}:{})});
                return {available:true};
            },
            onNavigate(section) {
                if (shared && section === 'workflows') return {available:false,message:'Shared workflow editing is not available in this edition.'};
                if (section === 'workflows') return adapter.navigate(boot.routes.workflowWorkspace + '#area_id=' + EverbranchSampleTransport.ids.area + '&project_id=' + EverbranchSampleTransport.ids.project);
                dispatch({section, area: defaultArea, ...(['projects','requests'].includes(section)?{selected:null}:{})});
            },
            onSampleRequest() { if (shared) return sharedTour.open(); dispatch({section:'requests', area:defaultArea, selected:EverbranchSampleTransport.ids.request}); },
            ...(boot.routes.trial ? {onTryIt() { adapter.navigate(boot.routes.trial); }} : {}),
        });
        const chooser = document.getElementById('reviewer-perspective');
        const executiveOption=document.createElement('option');executiveOption.value='executive';executiveOption.textContent='Executive · read-only';chooser.prepend(executiveOption);
        if(boot.defaultPersona==='demo_manager'){const managerOption=document.createElement('option');managerOption.value='demo_manager';managerOption.textContent=shared?'Blaine · Demo admin':'Blaine · demo manager';chooser.prepend(managerOption);}
        if (shared) for (const option of [...chooser.options]) {
            if (!boot.reviewerPersonas.includes(option.value) || option.value === 'worker') option.remove();
        }
        chooser.value = adapter.snapshot().persona;
        chooser.onchange = () => {
            if (shared) {
                const active = adapter.snapshot();
                adapter.setSelection({persona:chooser.value,area_ids:active.area_ids,person_id:null});
                dispatch({section:chooser.value==='requester'?'requests':'projects',area:active.area_ids[0],selected:null});
                return;
            }
            adapter.setPersona(chooser.value);
            // Preserve the selected workflow/run and retained draft across perspectives.
            if(document.getElementById('enterprise-workflow')){location.reload();return;}
            const next=adapter.href(boot.routes.back+'#section='+(['executive','demo_manager'].includes(chooser.value)?'home':chooser.value==='requester'?'requester':'requests')+'&area_id='+EverbranchSampleTransport.ids.area);
            if(new URL(next,location.href).pathname===location.pathname){history.pushState(null,'',next);location.reload();}else adapter.navigate(next);
        };
        if(executive&&!document.getElementById('enterprise-workflow')){
            const pricing=document.createElement('button');pricing.type='button';pricing.className='nav-item';pricing.textContent='Pricing & billing · proposed';pricing.id='executive-pricing-nav';
            pricing.onclick=()=>{dispatch({section:'home',area:defaultArea});setTimeout(()=>document.getElementById('executive-software')?.scrollIntoView({block:'start',behavior:'instant'}),150);};
            document.querySelector('[aria-label="Workspace sections"]')?.append(pricing);
        }
        if (!shared) {
        const reset = document.getElementById('reviewer-reset-dialog'), open = document.getElementById('reviewer-reset-open');
        open.onclick = () => reset.showModal();
        document.getElementById('reviewer-reset-cancel').onclick = () => {reset.close();open.focus();};
        document.getElementById('reviewer-reset-confirm').onclick = () => {adapter.reset();reset.close();adapter.navigate(boot.routes.back);};
        reset.addEventListener('cancel', () => open.focus());
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once:true}); else start();
})();
</script>
