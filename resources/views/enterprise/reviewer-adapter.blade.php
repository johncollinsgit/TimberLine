{{-- Included only when a trusted controller supplies runtime=sample. Never chosen by URL/storage. --}}
<style>.enterprise-sample{padding-top:48px}.reviewer-ribbon{position:fixed;inset:0 0 auto;min-height:48px;background:#eae5d2;color:#4b583f;border-bottom:1px solid #d2ceba;z-index:50;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:7px 20px;font-size:10px}.reviewer-ribbon strong{letter-spacing:.08em}.reviewer-ribbon label{display:flex;align-items:center;gap:8px}.reviewer-ribbon select{padding:6px;font-size:11px;min-height:34px}.reviewer-ribbon button{min-height:34px;padding:6px 10px;background:transparent;border:1px solid #b7bea7;border-radius:4px;color:inherit}.reviewer-reset{border:1px solid #d6dece;border-radius:9px;max-width:calc(100vw - 24px);padding:25px;color:#233d32}.reviewer-reset::backdrop{background:#142a24b8}.reviewer-reset h2{font:25px Georgia,serif}.reviewer-reset p{font-size:13px;line-height:1.8}.reviewer-reset .actions{display:flex;gap:10px;flex-wrap:wrap}.reviewer-reset button{min-height:44px;padding:10px 16px;border:1px solid #cbd6bf;border-radius:5px;background:white;color:#244d3d}.reviewer-reset button:last-child{background:#244d3d;color:white}.reviewer-ribbon *:focus-visible,.reviewer-reset *:focus-visible{outline:3px solid #b18b2c;outline-offset:3px}@media(max-width:650px){.enterprise-sample{padding-top:82px}.reviewer-ribbon{min-height:82px;padding:8px 12px;flex-wrap:wrap;gap:5px}.reviewer-ribbon .reviewer-boundary{width:100%;font-size:9px}.reviewer-ribbon label{font-size:9px}.reviewer-ribbon select{max-width:145px}.reviewer-ribbon .reviewer-title{font-size:9px}}</style>
<div class="reviewer-ribbon" role="region" aria-label="Fictional sample controls"><span class="reviewer-title"><strong>SAMPLE DATA</strong> · Everbranch demonstration</span><span class="reviewer-boundary">This tab only. No real campus data, server writes, messages or AI charges.</span><label>Sample perspective<select id="reviewer-perspective" aria-label="Sample perspective"><option value="manager">Department manager</option><option value="worker">Assigned worker</option><option value="requester">Student requester</option></select></label><button type="button" id="reviewer-reset-open">Reset sample</button></div>
<dialog class="reviewer-reset" id="reviewer-reset-dialog" aria-labelledby="reviewer-reset-title"><h2 id="reviewer-reset-title">Reset this tab's fictional sample?</h2><p>This clears your sample edits, notes, workflow runs and upload metadata. It does not affect Enterprise records, another tab's data, credentials or trial spending.</p><div class="actions"><button type="button" id="reviewer-reset-cancel">Keep my sample</button><button type="button" id="reviewer-reset-confirm">Reset this tab's sample</button></div></dialog>
<script>{!! file_get_contents(resource_path('js/enterprise/reviewer/sample-adapter.js')) !!}</script>
<script>{!! file_get_contents(resource_path('js/enterprise/reviewer/tour.js')) !!}</script>
<script>
(() => {
    'use strict';
    const boot = @json($enterprisePresentation), adapter = EverbranchSampleTransport.create(boot);
    document.body.classList.add('enterprise-sample');
    function start() {
        const root = document.querySelector('[data-reviewer-tour-root]:not([data-priority-only="true"])');
        if (root && document.querySelector('.content')) document.querySelector('.content').prepend(root);
        const dispatch = detail => document.dispatchEvent(new CustomEvent('enterprise:navigate', {detail}));
        const executive = adapter.snapshot().persona === 'executive';
        EverbranchReviewerTour.mount(root, {
            ...(executive ? {priorityItems:EverbranchReviewerTour.executivePriorities} : {}),
            priorityStorageKey:'everbranch-priorities-sample:'+boot.identity.workspace+':'+adapter.snapshot().persona,
            priorityStatus(item) {
                const manager = ['manager','executive'].includes(adapter.snapshot().persona);
                const restricted = ['reports','certificates','invoices','funds'].includes(item.id);
                return {available: !restricted || manager, status: restricted && !manager ? 'Unavailable in this sample perspective' : 'Browser simulation · server feature implemented, not hosted'};
            },
            onPriority(item) {
                if (['reports','certificates','invoices','funds'].includes(item.id) && !['manager','executive'].includes(adapter.snapshot().persona)) return {available:false,message:'Unavailable in this sample perspective'};
                dispatch({section:item.section, area:EverbranchSampleTransport.ids.area});
                return {available:true};
            },
            onNavigate(section) {
                if (section === 'workflows') return adapter.navigate(boot.routes.workflowWorkspace + '#area_id=' + EverbranchSampleTransport.ids.area + '&project_id=' + EverbranchSampleTransport.ids.project);
                dispatch({section, area: EverbranchSampleTransport.ids.area});
            },
            onSampleRequest() { dispatch({section:'requests', area:EverbranchSampleTransport.ids.area, selected:EverbranchSampleTransport.ids.request}); },
            ...(boot.routes.trial ? {onTryIt() { adapter.navigate(boot.routes.trial); }} : {}),
        });
        const chooser = document.getElementById('reviewer-perspective');
        const executiveOption=document.createElement('option');executiveOption.value='executive';executiveOption.textContent='Blaine · COO demo admin';chooser.prepend(executiveOption);
        chooser.value = adapter.snapshot().persona;
        chooser.onchange = () => {
            adapter.setPersona(chooser.value);
            const next=adapter.href(boot.routes.back+'#section='+(chooser.value==='executive'?'home':chooser.value==='requester'?'requester':'requests')+'&area_id='+EverbranchSampleTransport.ids.area);
            if(new URL(next,location.href).pathname===location.pathname){history.pushState(null,'',next);location.reload();}else adapter.navigate(next);
        };
        if(executive&&!document.getElementById('enterprise-workflow')){
            const pricing=document.createElement('button');pricing.type='button';pricing.className='nav-item';pricing.textContent='Pricing & billing · proposed';pricing.id='executive-pricing-nav';
            pricing.onclick=()=>{dispatch({section:'home',area:EverbranchSampleTransport.ids.area});setTimeout(()=>document.getElementById('executive-software')?.scrollIntoView({block:'start',behavior:'instant'}),150);};
            document.querySelector('[aria-label="Workspace sections"]')?.append(pricing);
        }
        const reset = document.getElementById('reviewer-reset-dialog'), open = document.getElementById('reviewer-reset-open');
        open.onclick = () => reset.showModal();
        document.getElementById('reviewer-reset-cancel').onclick = () => {reset.close();open.focus();};
        document.getElementById('reviewer-reset-confirm').onclick = () => {adapter.reset();reset.close();adapter.navigate(boot.routes.back);};
        reset.addEventListener('cancel', () => open.focus());
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once:true}); else start();
})();
</script>
