@php
    $tenantName = (string) ($tenant->name ?? $tenant->slug ?? 'Tenant');
    $journey = is_array($journey ?? null) ? $journey : [];
    $plan = is_array($journey['plan'] ?? null) ? (array) $journey['plan'] : [];
    $activeNow = is_array($journey['active_now'] ?? null) ? (array) $journey['active_now'] : [];
    $availableNext = is_array($journey['available_next'] ?? null) ? (array) $journey['available_next'] : [];
    $purchasable = is_array($journey['purchasable'] ?? null) ? (array) $journey['purchasable'] : [];
    $plansPayload = is_array($plans ?? null) ? $plans : [];
    $billingNote = (string) data_get($plansPayload, 'content.billing_note', '');

    $accessRequest = $access_request ?? null;
    $accessMeta = is_object($accessRequest) ? (array) ($accessRequest->metadata ?? []) : [];
    $preferredPlanKey = strtolower(trim((string) ($accessMeta['preferred_plan_key'] ?? '')));
    $addonsInterest = is_array($accessMeta['addons_interest'] ?? null) ? (array) $accessMeta['addons_interest'] : [];

    $planCards = is_array($plansPayload['plan_cards'] ?? null) ? (array) $plansPayload['plan_cards'] : [];
    $planLabelByKey = [];
    foreach ($planCards as $card) {
        if (is_array($card) && filled($card['plan_key'] ?? null)) {
            $planLabelByKey[strtolower(trim((string) $card['plan_key']))] = (string) ($card['label'] ?? $card['plan_key']);
        }
    }

    $addonsConfig = (array) config('product_surfaces.plans.addons', []);
    $addonLabelByKey = [];
    foreach ($addonsConfig as $key => $addon) {
        if (is_array($addon)) {
            $addonLabelByKey[strtolower(trim((string) $key))] = (string) ($addon['name'] ?? $key);
        }
    }
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

            @if($billingNote !== '')
                <section class="fb-panel mt-6">
                    <div class="fb-panel-head">
                        <div>
                            <div class="fb-panel-title">Billing</div>
                            <div class="fb-panel-copy">{{ $billingNote }}</div>
                        </div>
                    </div>
                    <div class="fb-panel-body space-y-3">
                        @if($preferredPlanKey !== '' || $addonsInterest !== [])
                            <div class="text-sm text-[var(--fb-text-secondary)]">
                                <div class="font-semibold text-[var(--fb-text-primary)]">Your interest (from request)</div>
                                @if($preferredPlanKey !== '')
                                    <div class="mt-1">Preferred tier: <span class="font-semibold text-[var(--fb-text-primary)]">{{ $planLabelByKey[$preferredPlanKey] ?? \Illuminate\Support\Str::title(str_replace('_', ' ', $preferredPlanKey)) }}</span></div>
                                @endif
                                @if($addonsInterest !== [])
                                    <div class="mt-1">Add-ons of interest:
                                        <span class="font-semibold text-[var(--fb-text-primary)]">
                                            {{ collect($addonsInterest)->map(fn ($key) => $addonLabelByKey[strtolower(trim((string) $key))] ?? \Illuminate\Support\Str::title(str_replace('_', ' ', (string) $key)))->implode(', ') }}
                                        </span>
                                    </div>
                                @endif
                            </div>
                        @endif

                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('platform.plans') }}" class="fb-btn fb-btn-secondary">Compare plans</a>
                            <a href="{{ route('platform.contact', ['intent' => 'billing']) }}" class="fb-btn fb-btn-secondary">Talk to sales</a>
                        </div>
                    </div>
                </section>
            @endif
        </div>
    </flux:main>
</x-layouts::app.sidebar>
