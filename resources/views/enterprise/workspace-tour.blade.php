{{-- Server-backed demonstration only; this partial never installs the public sample transport. --}}
@if(($enterprisePresentation['runtime'] ?? 'private') === 'private' && config('enterprise.runtime') === true && config('enterprise.tour_enabled', false) && config('enterprise.sample_data', false))
@include('enterprise.reviewer-tour', ['enterpriseWorkspaceTour' => true])
<script>{!! file_get_contents(resource_path('js/enterprise/reviewer/tour.js')) !!}</script>
<script>{!! file_get_contents(resource_path('js/enterprise/reviewer/workspace-tour.js')) !!}</script>
<script>
(() => {
    const boot = @json(array_merge($enterprisePresentation, ['enabled' => true, 'sample_data' => true]));
    EverbranchWorkspaceTour.mount(document.querySelector('[data-reviewer-tour-root]'), boot);
})();
</script>
@endif
