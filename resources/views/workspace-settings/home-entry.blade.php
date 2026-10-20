@php
    $setupTenant = request()->attributes->get('current_tenant');
    $setupGuide = null;
    if ($setupTenant instanceof \App\Models\Tenant && auth()->user()) {
        $setupGuide = app(\App\Services\Onboarding\WorkspaceSetupGuide::class)->forTenant($setupTenant, auth()->user());
    }
@endphp
@if($setupGuide && !$setupGuide['guidance_hidden'] && $setupGuide['required_count'] > $setupGuide['required_complete'])
    <section class="mb-5 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-950" aria-label="Organization setup">
        <div><h2 class="font-semibold">Set up your organization</h2><p class="mt-1 text-sm">{{ $setupGuide['required_complete'] }} of {{ $setupGuide['required_count'] }} starting steps complete. Continue from where you left off.</p></div>
        <a class="inline-flex min-h-11 items-center rounded-lg bg-emerald-900 px-4 py-2 font-semibold text-white" href="{{ route('workspace-settings.setup', ['tenant' => $setupTenant->slug]) }}">Continue setup →</a>
    </section>
@endif
