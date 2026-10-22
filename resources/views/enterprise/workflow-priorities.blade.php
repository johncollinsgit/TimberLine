{{-- The full workflow canvas retains only the compact priorities controls.
     Return navigation is trusted server presentation; destination APIs recheck access. --}}
@if($enterpriseSample || (config('enterprise.runtime') === true && config('enterprise.tour_enabled', false) && config('enterprise.sample_data', false)))
<div class="erp-workflow-only">
@include('enterprise.reviewer-tour', ['enterpriseWorkspaceTour' => !$enterpriseSample, 'enterprisePriorityOnly' => true])
</div>
<style>.erp-workflow-only .ert-launch{padding:10px 24px;margin:0;border-radius:0;border-width:0 0 1px;justify-content:flex-end}.erp-workflow-only .ert-launch>div:first-child,.erp-workflow-only [data-tour-open],.erp-workflow-only>[data-tour-status]{display:none}.erp-workflow-only .ert-launch .ert-button{font:12px system-ui,sans-serif;min-height:44px}.erp-workflow-only .ert-launch .erp-launch-actions{width:auto}.erp-workflow-only .erp-dialog{font-family:system-ui,sans-serif}body.erp-tour-active .erp-workflow-only .ert-launch{display:none}@media(max-width:650px){.erp-workflow-only .ert-launch{padding:8px 14px;flex-direction:row}.erp-workflow-only .erp-launch-actions{width:auto}}</style>
<script>{!! file_get_contents(resource_path('js/enterprise/reviewer/tour.js')) !!}</script>
<script>
(() => {
    const boot = @json($workflowBoot),
        root = document.querySelector('[data-priority-only="true"]');
    if (!root) return;
    const sample = boot.runtime === 'sample',
        adapter = sample ? globalThis.EverbranchSampleTransport?.create(boot) : null;
    const safeBack = () => {
        try {
            const target = new URL(boot.routes?.back || boot.back, location.href);
            if (target.origin !== location.origin || target.username || target.password) return null;
            return target;
        } catch { return null; }
    };
    EverbranchReviewerTour.mountPriorities(root, {
        ...(sample && adapter?.snapshot().persona === 'executive' ? {priorityItems:EverbranchReviewerTour.executivePriorities} : {}),
        priorityStorageKey: sample ? 'everbranch-priorities-sample:'+boot.identity.workspace+':'+adapter?.snapshot().persona : `everbranch-priorities-private-v1:${safeBack()?.pathname.replace(/\/+$/, '') || '/'}:${boot.identity.workspace}:${boot.identity.viewer}`,
        priorityStatus(item) {
            const restricted = ['reports','certificates','invoices','funds'].includes(item.id),
                available = Boolean(safeBack()) && (!sample || (!restricted || ['manager','executive'].includes(adapter?.snapshot().persona)));
            return {available, status: sample ? (available ? 'Return to sample workspace · no live action' : 'Unavailable in this sample perspective') : 'Return to workspace · current permissions checked there'};
        },
        onPriority(item) {
            const target = safeBack();
            if (!target) return {available:false, message:'The workspace return route is unavailable.'};
            const values = new URLSearchParams(target.search || target.hash.slice(1));
            values.set('section', item.section);
            values.set('area_id', boot.scope.area_id);
            if (item.id === 'visibility') values.set('selected', boot.scope.project_id);
            if (sample) { target.search=''; target.hash=values.toString(); }
            else { target.hash=''; target.search=values.toString(); }
            if (sample) adapter.navigate(target.href); else location.assign(target.href);
            return {available:true};
        },
    });
})();
</script>
@endif
