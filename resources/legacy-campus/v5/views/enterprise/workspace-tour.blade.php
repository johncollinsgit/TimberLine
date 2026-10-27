{{-- Server-backed demonstration only; this partial never installs the public sample transport. --}}
@if(($enterprisePresentation['runtime'] ?? 'private') === 'private' && config('enterprise.runtime') === true && config('enterprise.tour_enabled', false) && config('enterprise.sample_data', false))
@include('campus-v5::enterprise.reviewer-tour', ['enterpriseWorkspaceTour' => true])
<script>{!! $legacyAssets->bytes('resources/js/enterprise/reviewer/tour.js') !!}</script>
<script>{!! $legacyAssets->bytes('resources/js/enterprise/reviewer/workspace-tour.js') !!}</script>
<script>
(() => {
    const boot = @json(array_merge($enterprisePresentation, ['enabled' => true, 'sample_data' => true]));
    EverbranchWorkspaceTour.mount(document.querySelector('[data-reviewer-tour-root]'), boot);
})();
</script>
@endif
