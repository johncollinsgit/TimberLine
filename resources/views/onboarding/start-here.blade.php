@php
    $tenantName = (string) ($tenant->name ?? $tenant->slug ?? 'Tenant');
    $journey = is_array($journey ?? null) ? $journey : [];
    $plan = is_array($journey['plan'] ?? null) ? (array) $journey['plan'] : [];
    $activeNow = is_array($journey['active_now'] ?? null) ? (array) $journey['active_now'] : [];
    $availableNext = is_array($journey['available_next'] ?? null) ? (array) $journey['available_next'] : [];
    $purchasable = is_array($journey['purchasable'] ?? null) ? (array) $journey['purchasable'] : [];
    $billingInterest = is_array($journey['billing_interest'] ?? null) ? (array) $journey['billing_interest'] : [];
    $billingNextStep = is_array($journey['billing_next_step'] ?? null) ? (array) $journey['billing_next_step'] : [];
    $commercialSummary = is_array($journey['commercial_summary'] ?? null) ? (array) $journey['commercial_summary'] : [];
    $plansPayload = is_array($plans ?? null) ? $plans : [];
    $billingNote = (string) data_get($plansPayload, 'content.billing_note', '');

    $planCatalog = (array) config('module_catalog.plans', []);
    $addonCatalog = (array) config('module_catalog.addons', []);
    $planLabelByKey = collect($planCatalog)
        ->filter(fn ($definition) => is_array($definition))
        ->mapWithKeys(fn ($definition, $key) => [strtolower(trim((string) $key)) => (string) ($definition['display_name'] ?? $definition['label'] ?? $key)])
        ->all();
    $addonLabelByKey = collect($addonCatalog)
        ->filter(fn ($definition) => is_array($definition))
        ->mapWithKeys(fn ($definition, $key) => [strtolower(trim((string) $key)) => (string) ($definition['display_name'] ?? $definition['label'] ?? $key)])
        ->all();

    $billingReturn = strtolower(trim((string) request()->query('billing', '')));
@endphp

<x-layouts::app.sidebar title="Start Here">
    <flux:main>
        <div class="fb-workflow-shell">
            <header class="fb-workflow-header">
                <div class="fb-eyebrow">Start Here</div>
                <h1 class="fb-title-xl">{{ $tenantName }}</h1>
                <p class="fb-subtitle">A tenant-aware setup view built from your plan, add-ons, and module readiness.</p>

                <div class="fb-metric-grid">
                    <div class="fb-metric">
                        <div class="fb-metric-label">Plan</div>
                        <div class="fb-metric-value">{{ $plan['label'] ?? 'Unknown' }}</div>
                    </div>
                    <div class="fb-metric">
                        <div class="fb-metric-label">Active now</div>
                        <div class="fb-metric-value">{{ count($activeNow) }}</div>
                    </div>
                    <div class="fb-metric">
                        <div class="fb-metric-label">Setup next</div>
                        <div class="fb-metric-value">{{ count($availableNext) }}</div>
                    </div>
                    <div class="fb-metric">
                        <div class="fb-metric-label">Unlock next</div>
                        <div class="fb-metric-value">{{ count($purchasable) }}</div>
                    </div>
                </div>
            </header>

            <section class="fb-panel mb-6">
                <div class="fb-panel-head">
                    <div>
                        <div class="fb-panel-title">What happens next</div>
                        <div class="fb-panel-copy">{{ $billingNote !== '' ? $billingNote : 'Commercial lifecycle, billing truth, and setup guidance are unified here.' }}</div>
                    </div>
                </div>
                <div class="fb-panel-body">
                    <x-tenancy.commercial-lifecycle-summary
                        :commercial-summary="$commercialSummary"
                        :billing-interest="$billingInterest"
                        :billing-next-step="$billingNextStep"
                        :plan-label-by-key="$planLabelByKey"
                        :addon-label-by-key="$addonLabelByKey"
                        :billing-return="$billingReturn"
                    >
                        <div class="flex flex-wrap gap-2">
                            @if(is_array($billingNextStep['cta_route'] ?? null) && filled($billingNextStep['cta_route']['name'] ?? null))
                                <form method="POST" action="{{ route((string) $billingNextStep['cta_route']['name']) }}">
                                    @csrf
                                    <button type="submit" class="fb-btn fb-btn-primary">
                                        {{ $billingNextStep['cta_label'] ?? 'Continue' }}
                                    </button>
                                </form>
                            @elseif(filled($billingNextStep['cta_url'] ?? null))
                                <a href="{{ (string) $billingNextStep['cta_url'] }}" class="fb-btn fb-btn-primary">
                                    {{ $billingNextStep['cta_label'] ?? 'Continue' }}
                                </a>
                            @endif
                            <a href="{{ route('platform.plans') }}" class="fb-btn fb-btn-secondary">Compare plans</a>
                            <a href="{{ route('platform.contact', ['intent' => 'billing']) }}" class="fb-btn fb-btn-secondary">Talk to sales</a>
                        </div>
                    </x-tenancy.commercial-lifecycle-summary>
                </div>
            </section>

            <div class="fb-workflow-grid">
                <section class="min-w-0">
                    <div class="fb-panel">
                        <div class="fb-panel-head">
                            <div>
                                <div class="fb-panel-title">Available Now</div>
                                <div class="fb-panel-copy">Modules you can use today.</div>
                            </div>
                        </div>
                        <div class="fb-panel-body space-y-2">
                            @forelse(array_slice($activeNow, 0, 8) as $module)
                                <x-tenancy.module-state-card :module-state="$module" />
                            @empty
                                <div class="fb-state text-sm">No active modules are visible yet.</div>
                            @endforelse
                        </div>
                    </div>
                </section>

                <section class="min-w-0">
                    <div class="fb-panel">
                        <div class="fb-panel-head">
                            <div>
                                <div class="fb-panel-title">Setup Next</div>
                                <div class="fb-panel-copy">Included modules that still need setup.</div>
                            </div>
                        </div>
                        <div class="fb-panel-body space-y-2">
                            @forelse(array_slice($availableNext, 0, 8) as $module)
                                <x-tenancy.module-state-card :module-state="$module" />
                            @empty
                                <div class="fb-state text-sm">Included modules are already configured.</div>
                            @endforelse
                        </div>
                    </div>
                </section>

                <section class="min-w-0">
                    <div class="fb-panel">
                        <div class="fb-panel-head">
                            <div>
                                <div class="fb-panel-title">Unlock Next</div>
                                <div class="fb-panel-copy">Upgrade/add-on candidates (honest, entitlement-driven).</div>
                            </div>
                        </div>
                        <div class="fb-panel-body space-y-2">
                            @forelse(array_slice($purchasable, 0, 8) as $module)
                                <x-tenancy.module-upgrade-prompt
                                    :module-state="$module"
                                    store-route="marketing.modules"
                                    plans-route="platform.plans"
                                    contact-route="platform.contact"
                                />
                            @empty
                                <div class="fb-state text-sm">No upgrade candidates are currently highlighted.</div>
                            @endforelse
                        </div>
                    </div>
                </section>
            </div>

        </div>
    </flux:main>
</x-layouts::app.sidebar>
