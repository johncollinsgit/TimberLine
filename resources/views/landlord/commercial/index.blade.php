<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Landlord Commercial Config</h1>
    </x-slot>

    <div class="space-y-6">
        @if (session('status'))
            <section class="rounded-2xl border border-emerald-300/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
                {{ session('status') }}
            </section>
        @endif

        @if (session('status_error'))
            <section class="rounded-2xl border border-rose-300/20 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                {{ session('status_error') }}
            </section>
        @endif

        @if ($errors->any())
            <section class="rounded-2xl border border-rose-300/20 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                <p class="font-semibold">Validation blocked one or more changes.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-xs">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 shadow-[0_30px_80px_-50px_rgba(0,0,0,0.9)]">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Landlord</div>
                    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Commercial Control Center</div>
                    <p class="mt-2 text-sm text-emerald-50/70">
                        Configuration-first controls for plans, add-ons, templates, tenant assignments, and billing readiness placeholders.
                    </p>
                </div>
                <a
                    href="{{ route('landlord.dashboard') }}"
                    class="inline-flex items-center rounded-full border border-emerald-400/30 bg-emerald-500/15 px-4 py-2 text-xs font-semibold text-white/90"
                >
                    Back to Dashboard
                </a>
            </div>
        </section>

        <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
            <h2 class="text-lg font-semibold text-white">Billing Readiness</h2>
            <p class="mt-2 text-xs text-emerald-50/70">
                Configuration is ready for future billing mapping, but billing lifecycle is inactive in this phase.
            </p>
            <div class="mt-3 flex flex-wrap gap-2 text-[11px]">
                <span class="rounded-full border border-emerald-300/20 bg-white/5 px-2 py-1 text-emerald-100/85">
                    Mapping status: {{ (string) ($billingReadiness['mapping_status'] ?? 'missing') }}
                </span>
                <span class="rounded-full border border-emerald-300/20 bg-white/5 px-2 py-1 text-emerald-100/85">
                    Lifecycle: {{ (bool) ($billingReadiness['lifecycle_disabled'] ?? true) ? 'disabled' : 'enabled' }}
                </span>
                <span class="rounded-full border border-emerald-300/20 bg-white/5 px-2 py-1 text-emerald-100/85">
                    Provider priority: {{ implode(' > ', array_map('strtoupper', (array) ($billingReadiness['provider_priority'] ?? []))) ?: 'n/a' }}
                </span>
            </div>
            <div class="mt-3 grid gap-3 md:grid-cols-2">
                @foreach ((array) ($billingReadiness['providers'] ?? []) as $providerKey => $provider)
                    <article class="rounded-2xl border border-emerald-200/10 bg-white/5 p-4 text-sm text-emerald-50/80">
                        <div class="font-semibold text-white">{{ strtoupper((string) $providerKey) }}</div>
                        <div class="mt-1">Role: {{ (string) ($provider['role'] ?? 'n/a') }}</div>
                        <div>Status: {{ (string) ($provider['status'] ?? 'n/a') }}</div>
                    </article>
                @endforeach
            </div>
            <div class="mt-3 grid gap-3 lg:grid-cols-2">
                <article class="rounded-2xl border border-emerald-200/10 bg-white/5 p-4 text-xs text-emerald-50/80">
                    <div class="font-semibold text-white">Stripe tier mappings</div>
                    @foreach ((array) data_get($billingReadiness, 'stripe_mapping.tiers', []) as $tierKey => $tierMapping)
                        <div class="mt-1">
                            {{ $tierKey }}:
                            {{ (string) data_get($tierMapping, 'recurring_price_lookup_key', 'missing') }}
                            / {{ (string) data_get($tierMapping, 'setup_price_lookup_key', 'missing') }}
                        </div>
                    @endforeach
                </article>
                <article class="rounded-2xl border border-emerald-200/10 bg-white/5 p-4 text-xs text-emerald-50/80">
                    <div class="font-semibold text-white">Stripe add-on + support mappings</div>
                    @foreach ((array) data_get($billingReadiness, 'stripe_mapping.addons', []) as $addonKey => $addonMapping)
                        <div class="mt-1">
                            {{ $addonKey }}:
                            {{ (string) data_get($addonMapping, 'recurring_price_lookup_key', 'missing') }}
                        </div>
                    @endforeach
                    @foreach ((array) data_get($billingReadiness, 'stripe_mapping.support_tiers', []) as $supportTierKey => $supportTierMapping)
                        <div class="mt-1">
                            {{ $supportTierKey }}:
                            {{ (string) data_get($supportTierMapping, 'recurring_price_lookup_key', 'missing') }}
                        </div>
                    @endforeach
                </article>
            </div>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-xs text-emerald-50/70">
                <li>Checkout remains disabled.</li>
                <li>Only one guarded live subscription create/sync action is available (landlord-triggered, prerequisite-gated).</li>
                <li>Broad subscription update/cancel automation is still disabled.</li>
                <li>Billing mapping fields are metadata placeholders only.</li>
            </ul>
            <p class="mt-2 text-xs text-emerald-50/70">
                Guarded Stripe actions in this phase are landlord-triggered only:
                customer reference sync, subscription-prep metadata sync, and narrow live subscription create/sync.
            </p>
            @if ((array) ($billingReadiness['missing_global_requirements'] ?? []) !== [])
                <div class="mt-3 rounded-xl border border-amber-200/20 bg-amber-500/10 p-3 text-xs text-amber-100/90">
                    <p class="font-semibold">Billing readiness still has unresolved global mapping requirements:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-4">
                        @foreach ((array) ($billingReadiness['missing_global_requirements'] ?? []) as $missingRequirement)
                            <li>{{ $missingRequirement }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ((array) data_get($billingReadiness, 'stripe_mapping.tenant_override_fields', []) !== [])
                <p class="mt-3 text-xs text-emerald-50/70">
                    Expected tenant override keys for future billing mapping:
                    {{ implode(', ', (array) data_get($billingReadiness, 'stripe_mapping.tenant_override_fields', [])) }}.
                </p>
            @endif
            <p class="mt-3 text-xs text-emerald-50/70">
                Checkout remains disabled and broad lifecycle writes remain intentionally inactive in this phase.
            </p>
            <p class="text-xs text-emerald-50/70">
                Pre-billing activation requires completed staging evidence artifacts documented in:
                <code>docs/operations/staging-commercial-uat-runbook.md</code>,
                <code>docs/operations/staging-commercial-uat-evidence-template.md</code>, and
                <code>docs/operations/pre-billing-readiness-gate.md</code>.
            </p>
            <p class="text-xs text-emerald-50/70">
                Activation gate checklist:
                <code>docs/operations/billing-activation-checklist.md</code>.
            </p>
            @if ((array) ($billingReadiness['required_tenant_billing_fields'] ?? []) !== [])
                <p class="text-xs text-emerald-50/70">
                    Tenant billing mapping JSON must include:
                    {{ implode(', ', (array) ($billingReadiness['required_tenant_billing_fields'] ?? [])) }}.
                </p>
            @endif
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            <article class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
                <h2 class="text-lg font-semibold text-white">Plans (Starter / Growth / Pro)</h2>
                <div class="mt-4 space-y-4">
                    @foreach ($plans as $entry)
                        <form method="POST" action="{{ route('landlord.commercial.catalog.upsert', ['type' => 'plan']) }}" class="rounded-2xl border border-emerald-200/10 bg-white/5 p-4">
                            @csrf
                            <input type="hidden" name="entry_key" value="{{ $entry['entry_key'] }}">
                            <div class="grid gap-3 md:grid-cols-2">
                                <label class="text-xs text-emerald-100/70">
                                    Name
                                    <input name="name" value="{{ $entry['name'] }}" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-sm text-white">
                                </label>
                                <label class="text-xs text-emerald-100/70">
                                    Position
                                    <input name="position" value="{{ (int) ($entry['position'] ?? 100) }}" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-sm text-white">
                                </label>
                                <label class="text-xs text-emerald-100/70">
                                    Recurring (cents)
                                    <input name="recurring_price_cents" value="{{ $entry['recurring_price_cents'] ?? '' }}" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-sm text-white">
                                </label>
                                <label class="text-xs text-emerald-100/70">
                                    Setup (cents)
                                    <input name="setup_price_cents" value="{{ $entry['setup_price_cents'] ?? '' }}" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-sm text-white">
                                </label>
                            </div>
                            <label class="mt-3 block text-xs text-emerald-100/70">
                                Payload JSON
                                <textarea name="payload_json" rows="4" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-xs text-white">{{ json_encode((array) ($entry['payload'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
                            </label>
                            <button type="submit" class="mt-3 rounded-md border border-emerald-300/30 bg-emerald-500/20 px-3 py-1 text-xs font-semibold text-white">Save Plan</button>
                        </form>
                    @endforeach
                </div>
            </article>

            <article class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
                <h2 class="text-lg font-semibold text-white">Add-ons</h2>
                <div class="mt-4 space-y-4">
                    @foreach ($addons as $entry)
                        <form method="POST" action="{{ route('landlord.commercial.catalog.upsert', ['type' => 'addon']) }}" class="rounded-2xl border border-emerald-200/10 bg-white/5 p-4">
                            @csrf
                            <input type="hidden" name="entry_key" value="{{ $entry['entry_key'] }}">
                            <div class="grid gap-3 md:grid-cols-2">
                                <label class="text-xs text-emerald-100/70">
                                    Name
                                    <input name="name" value="{{ $entry['name'] }}" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-sm text-white">
                                </label>
                                <label class="text-xs text-emerald-100/70">
                                    Position
                                    <input name="position" value="{{ (int) ($entry['position'] ?? 100) }}" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-sm text-white">
                                </label>
                                <label class="text-xs text-emerald-100/70">
                                    Recurring (cents)
                                    <input name="recurring_price_cents" value="{{ $entry['recurring_price_cents'] ?? '' }}" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-sm text-white">
                                </label>
                                <label class="text-xs text-emerald-100/70">
                                    Setup (cents)
                                    <input name="setup_price_cents" value="{{ $entry['setup_price_cents'] ?? '' }}" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-sm text-white">
                                </label>
                            </div>
                            <label class="mt-3 block text-xs text-emerald-100/70">
                                Payload JSON
                                <textarea name="payload_json" rows="3" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-xs text-white">{{ json_encode((array) ($entry['payload'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
                            </label>
                            <button type="submit" class="mt-3 rounded-md border border-emerald-300/30 bg-emerald-500/20 px-3 py-1 text-xs font-semibold text-white">Save Add-on</button>
                        </form>
                    @endforeach
                </div>
            </article>
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            <article class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
                <h2 class="text-lg font-semibold text-white">Setup Packages</h2>
                <div class="mt-4 space-y-4">
                    @foreach ($setupPackages as $entry)
                        <form method="POST" action="{{ route('landlord.commercial.catalog.upsert', ['type' => 'setup_package']) }}" class="rounded-2xl border border-emerald-200/10 bg-white/5 p-4">
                            @csrf
                            <input type="hidden" name="entry_key" value="{{ $entry['entry_key'] }}">
                            <div class="grid gap-3 md:grid-cols-2">
                                <label class="text-xs text-emerald-100/70">
                                    Name
                                    <input name="name" value="{{ $entry['name'] }}" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-sm text-white">
                                </label>
                                <label class="text-xs text-emerald-100/70">
                                    Position
                                    <input name="position" value="{{ (int) ($entry['position'] ?? 100) }}" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-sm text-white">
                                </label>
                                <label class="text-xs text-emerald-100/70">
                                    Setup (cents)
                                    <input name="setup_price_cents" value="{{ $entry['setup_price_cents'] ?? '' }}" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-sm text-white">
                                </label>
                            </div>
                            <button type="submit" class="mt-3 rounded-md border border-emerald-300/30 bg-emerald-500/20 px-3 py-1 text-xs font-semibold text-white">Save Package</button>
                        </form>
                    @endforeach
                </div>
            </article>

            <article class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
                <h2 class="text-lg font-semibold text-white">Templates</h2>
                <div class="mt-4 space-y-4">
                    @foreach ($templates as $entry)
                        <section class="rounded-2xl border border-emerald-200/10 bg-white/5 p-4">
                            <form method="POST" action="{{ route('landlord.commercial.catalog.upsert', ['type' => 'template']) }}">
                                @csrf
                                <input type="hidden" name="entry_key" value="{{ $entry['entry_key'] }}">
                                <div class="grid gap-3 md:grid-cols-2">
                                    <label class="text-xs text-emerald-100/70">
                                        Name
                                        <input name="name" value="{{ $entry['name'] }}" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-sm text-white">
                                    </label>
                                    <label class="text-xs text-emerald-100/70">
                                        Position
                                        <input name="position" value="{{ (int) ($entry['position'] ?? 100) }}" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-sm text-white">
                                    </label>
                                </div>
                                <label class="mt-3 block text-xs text-emerald-100/70">
                                    Payload JSON
                                    <textarea name="payload_json" rows="5" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-xs text-white">{{ json_encode((array) ($entry['payload'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
                                </label>
                                <button type="submit" class="mt-3 rounded-md border border-emerald-300/30 bg-emerald-500/20 px-3 py-1 text-xs font-semibold text-white">Save Template</button>
                            </form>

                            <div class="mt-3 flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('landlord.commercial.templates.duplicate', ['entryKey' => $entry['entry_key']]) }}">
                                    @csrf
                                    <input name="new_key" placeholder="{{ $entry['entry_key'] }}_copy" class="rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-xs text-white">
                                    <button type="submit" class="ml-1 rounded-md border border-emerald-200/20 px-2 py-1 text-xs text-emerald-100">Duplicate</button>
                                </form>
                                @foreach (['active' => 'Activate', 'inactive' => 'Deactivate', 'archived' => 'Archive'] as $state => $label)
                                    <form method="POST" action="{{ route('landlord.commercial.templates.state', ['entryKey' => $entry['entry_key']]) }}">
                                        @csrf
                                        <input type="hidden" name="state" value="{{ $state }}">
                                        <button type="submit" class="rounded-md border border-emerald-200/20 px-2 py-1 text-xs text-emerald-100">{{ $label }}</button>
                                    </form>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>
            </article>
        </section>

        <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
            <h2 class="text-lg font-semibold text-white">Tenant Assignments and Overrides</h2>
            <p class="mt-1 text-sm text-emerald-50/70">
                Landlord is the source of truth for plan assignment, module/add-on access controls, template assignment, and pricing/usage overrides.
            </p>

            <div class="mt-4 space-y-6">
                @if ($tenants === [])
                    <article class="rounded-2xl border border-emerald-200/10 bg-white/5 p-4 text-sm text-emerald-50/80">
                        No tenants are available yet. Create or sync a tenant before running commercial assignment UAT.
                    </article>
                @endif
                @foreach ($tenants as $row)
                    @php
                        $tenant = $row['tenant'];
                        $tenantBillingReadiness = is_array($row['billing_readiness'] ?? null) ? (array) $row['billing_readiness'] : [];
                        $tenantBillingMissing = is_array($tenantBillingReadiness['missing_requirements'] ?? null)
                            ? (array) $tenantBillingReadiness['missing_requirements']
                            : [];
                        $tenantBillingReady = (bool) ($tenantBillingReadiness['ready_for_activation_prep'] ?? false);
                        $stripeCustomerSync = is_array($row['stripe_customer_sync'] ?? null) ? (array) $row['stripe_customer_sync'] : [];
                        $stripeCustomerSyncReady = (bool) ($stripeCustomerSync['ready'] ?? false);
                        $stripeCustomerReference = trim((string) ($stripeCustomerSync['customer_reference'] ?? ''));
                        $stripeCustomerSyncLastStatus = trim((string) ($stripeCustomerSync['last_status'] ?? 'never'));
                        $stripeCustomerSyncReasons = is_array($stripeCustomerSync['not_ready_reasons'] ?? null)
                            ? (array) $stripeCustomerSync['not_ready_reasons']
                            : [];
                        $stripeSubscriptionPrep = is_array($row['stripe_subscription_prep'] ?? null) ? (array) $row['stripe_subscription_prep'] : [];
                        $stripeSubscriptionPrepReady = (bool) ($stripeSubscriptionPrep['ready'] ?? false);
                        $stripeSubscriptionPrepLastStatus = trim((string) ($stripeSubscriptionPrep['last_status'] ?? 'never'));
                        $stripeSubscriptionPrepLastMode = trim((string) ($stripeSubscriptionPrep['last_mode'] ?? ''));
                        $stripeSubscriptionPrepHash = trim((string) ($stripeSubscriptionPrep['candidate_hash'] ?? ''));
                        $stripeSubscriptionPrepReasons = is_array($stripeSubscriptionPrep['not_ready_reasons'] ?? null)
                            ? (array) $stripeSubscriptionPrep['not_ready_reasons']
                            : [];
                        $stripeLiveSubscriptionSync = is_array($row['stripe_live_subscription_sync'] ?? null) ? (array) $row['stripe_live_subscription_sync'] : [];
                        $stripeLiveSubscriptionSyncReady = (bool) ($stripeLiveSubscriptionSync['ready'] ?? false);
                        $stripeLiveSubscriptionReference = trim((string) ($stripeLiveSubscriptionSync['subscription_reference'] ?? ''));
                        $stripeLiveSubscriptionStatus = trim((string) ($stripeLiveSubscriptionSync['subscription_status'] ?? ''));
                        $stripeLiveSubscriptionSyncLastStatus = trim((string) ($stripeLiveSubscriptionSync['last_status'] ?? 'never'));
                        $stripeLiveSubscriptionSyncLastMode = trim((string) ($stripeLiveSubscriptionSync['last_mode'] ?? ''));
                        $stripeLiveSubscriptionSyncReasons = is_array($stripeLiveSubscriptionSync['not_ready_reasons'] ?? null)
                            ? (array) $stripeLiveSubscriptionSync['not_ready_reasons']
                            : [];
                    @endphp
                    <article class="rounded-2xl border border-emerald-200/10 bg-white/5 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 class="text-base font-semibold text-white">{{ $tenant->name }}</h3>
                                <p class="text-xs text-emerald-50/70">
                                    {{ $tenant->slug }}
                                    · assigned plan: {{ $row['plan_key'] }}
                                    · effective plan: {{ $row['resolved_plan_key'] }}
                                    · template: {{ $row['template_key'] !== '' ? $row['template_key'] : 'none' }}
                                    · labels: {{ str_replace('_', ' ', (string) ($row['label_source'] ?? 'entitlements_default')) }}
                                </p>
                                <p class="mt-1 text-[11px] text-emerald-100/70">
                                    Billing readiness:
                                    {{ $tenantBillingReady ? 'ready for activation prep' : 'not ready for activation prep' }}
                                    · mode: {{ (bool) ($tenantBillingReadiness['config_only'] ?? true) ? 'config-only' : 'active' }}
                                    · lifecycle: {{ (bool) ($tenantBillingReadiness['lifecycle_disabled'] ?? true) ? 'disabled' : 'enabled' }}
                                </p>
                                @if((bool) ($row['template_missing'] ?? false))
                                    <p class="mt-1 text-[11px] text-amber-200/90">
                                        Assigned template key is missing from the catalog. Commercialization surfaces will fall back to entitlement defaults.
                                    </p>
                                @endif
                                @if ($tenantBillingMissing !== [])
                                    <p class="mt-1 text-[11px] text-amber-100/90">
                                        Missing billing requirements: {{ implode('; ', $tenantBillingMissing) }}.
                                    </p>
                                @endif
                                <p class="mt-1 text-[11px] text-emerald-100/70">
                                    Guarded Stripe customer sync:
                                    {{ $stripeCustomerReference !== '' ? $stripeCustomerReference : 'not synced' }}
                                    · last status: {{ $stripeCustomerSyncLastStatus !== '' ? $stripeCustomerSyncLastStatus : 'never' }}
                                    @if(filled($stripeCustomerSync['last_mode'] ?? null))
                                        · mode: {{ (string) $stripeCustomerSync['last_mode'] }}
                                    @endif
                                    @if(filled($stripeCustomerSync['last_synced_at'] ?? null))
                                        · synced at: {{ (string) $stripeCustomerSync['last_synced_at'] }}
                                    @endif
                                    @if(filled($stripeCustomerSync['last_attempted_at'] ?? null))
                                        · last attempt: {{ (string) $stripeCustomerSync['last_attempted_at'] }}
                                    @endif
                                </p>
                                @if (filled($stripeCustomerSync['last_message'] ?? null))
                                    <p class="mt-1 text-[11px] text-emerald-100/70">
                                        Last sync note: {{ (string) $stripeCustomerSync['last_message'] }}
                                    </p>
                                @endif
                                @if ($stripeCustomerSyncReasons !== [])
                                    <p class="mt-1 text-[11px] text-amber-100/90">
                                        Stripe sync blocked until: {{ implode('; ', $stripeCustomerSyncReasons) }}
                                    </p>
                                @endif
                                <p class="mt-1 text-[11px] text-emerald-100/70">
                                    Guarded Stripe subscription prep:
                                    {{ $stripeSubscriptionPrepLastStatus !== '' ? $stripeSubscriptionPrepLastStatus : 'never' }}
                                    @if($stripeSubscriptionPrepLastMode !== '')
                                        · mode: {{ $stripeSubscriptionPrepLastMode }}
                                    @endif
                                    @if($stripeSubscriptionPrepHash !== '')
                                        · candidate hash: {{ $stripeSubscriptionPrepHash }}
                                    @endif
                                </p>
                                @if (filled($stripeSubscriptionPrep['last_synced_at'] ?? null))
                                    <p class="mt-1 text-[11px] text-emerald-100/70">
                                        Subscription prep synced at: {{ (string) $stripeSubscriptionPrep['last_synced_at'] }}
                                    </p>
                                @endif
                                @if (filled($stripeSubscriptionPrep['last_attempted_at'] ?? null))
                                    <p class="mt-1 text-[11px] text-emerald-100/70">
                                        Subscription prep last attempt: {{ (string) $stripeSubscriptionPrep['last_attempted_at'] }}
                                    </p>
                                @endif
                                @if (filled($stripeSubscriptionPrep['last_message'] ?? null))
                                    <p class="mt-1 text-[11px] text-emerald-100/70">
                                        Subscription prep note: {{ (string) $stripeSubscriptionPrep['last_message'] }}
                                    </p>
                                @endif
                                @if ($stripeSubscriptionPrepReasons !== [])
                                    <p class="mt-1 text-[11px] text-amber-100/90">
                                        Subscription prep blocked until: {{ implode('; ', $stripeSubscriptionPrepReasons) }}
                                    </p>
                                @endif
                                <p class="mt-1 text-[11px] text-emerald-100/70">
                                    Guarded Stripe live subscription create/sync:
                                    {{ $stripeLiveSubscriptionReference !== '' ? $stripeLiveSubscriptionReference : 'not synced' }}
                                    · last status: {{ $stripeLiveSubscriptionSyncLastStatus !== '' ? $stripeLiveSubscriptionSyncLastStatus : 'never' }}
                                    @if($stripeLiveSubscriptionSyncLastMode !== '')
                                        · mode: {{ $stripeLiveSubscriptionSyncLastMode }}
                                    @endif
                                    @if($stripeLiveSubscriptionStatus !== '')
                                        · subscription status: {{ $stripeLiveSubscriptionStatus }}
                                    @endif
                                </p>
                                @if (filled($stripeLiveSubscriptionSync['last_synced_at'] ?? null))
                                    <p class="mt-1 text-[11px] text-emerald-100/70">
                                        Live subscription synced at: {{ (string) $stripeLiveSubscriptionSync['last_synced_at'] }}
                                    </p>
                                @endif
                                @if (filled($stripeLiveSubscriptionSync['last_attempted_at'] ?? null))
                                    <p class="mt-1 text-[11px] text-emerald-100/70">
                                        Live subscription last attempt: {{ (string) $stripeLiveSubscriptionSync['last_attempted_at'] }}
                                    </p>
                                @endif
                                @if (filled($stripeLiveSubscriptionSync['last_message'] ?? null))
                                    <p class="mt-1 text-[11px] text-emerald-100/70">
                                        Live subscription sync note: {{ (string) $stripeLiveSubscriptionSync['last_message'] }}
                                    </p>
                                @endif
                                @if ($stripeLiveSubscriptionSyncReasons !== [])
                                    <p class="mt-1 text-[11px] text-amber-100/90">
                                        Live subscription sync blocked until: {{ implode('; ', $stripeLiveSubscriptionSyncReasons) }}
                                    </p>
                                @endif
                                <div class="mt-2 rounded-xl border border-emerald-200/10 bg-white/5 p-2 text-[11px] text-emerald-100/75">
                                    <p class="font-semibold text-emerald-100/85">Guarded Stripe sequence status</p>
                                    <p class="mt-1">1) Customer sync: {{ $stripeCustomerSyncLastStatus !== '' ? $stripeCustomerSyncLastStatus : 'never' }}</p>
                                    <p>2) Subscription prep: {{ $stripeSubscriptionPrepLastStatus !== '' ? $stripeSubscriptionPrepLastStatus : 'never' }}</p>
                                    <p>3) Live subscription create/sync: {{ $stripeLiveSubscriptionSyncLastStatus !== '' ? $stripeLiveSubscriptionSyncLastStatus : 'never' }}</p>
                                    <p class="mt-1">
                                        Staging evidence after each step: capture this tenant row state and corresponding Stripe object/reference evidence.
                                    </p>
                                </div>
                                <p class="mt-1 text-[11px] text-emerald-100/70">
                                    Checkout and broader subscription lifecycle automation remain disabled. This guarded action only creates/syncs a single subscription reference.
                                </p>
                            </div>
                            <a href="{{ route('landlord.tenants.show', ['tenant' => $tenant->id]) }}" class="text-xs font-semibold text-emerald-200 hover:text-white">Open tenant detail</a>
                        </div>

                        <div class="mt-3 grid gap-4 xl:grid-cols-2">
                            <form method="POST" action="{{ route('landlord.tenants.commercial.plan', ['tenant' => $tenant->id]) }}" class="rounded-xl border border-emerald-200/10 p-3">
                                @csrf
                                <h4 class="text-xs font-semibold uppercase tracking-[0.12em] text-emerald-100/70">Plan Assignment</h4>
                                <label class="mt-2 block text-xs text-emerald-100/70">
                                    Plan
                                    <select name="plan_key" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-sm text-white">
                                        @foreach ($plans as $plan)
                                            <option value="{{ $plan['entry_key'] }}" @selected($plan['entry_key'] === $row['plan_key'])>{{ $plan['name'] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="mt-2 block text-xs text-emerald-100/70">
                                    Operating mode
                                    <select name="operating_mode" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-sm text-white">
                                        <option value="shopify" @selected($row['operating_mode'] === 'shopify')>shopify</option>
                                        <option value="direct" @selected($row['operating_mode'] === 'direct')>direct</option>
                                    </select>
                                </label>
                                <button type="submit" class="mt-3 rounded-md border border-emerald-300/30 bg-emerald-500/20 px-3 py-1 text-xs font-semibold text-white">Save Plan</button>
                            </form>

                            <form method="POST" action="{{ route('landlord.tenants.commercial.override', ['tenant' => $tenant->id]) }}" class="rounded-xl border border-emerald-200/10 p-3">
                                @csrf
                                <h4 class="text-xs font-semibold uppercase tracking-[0.12em] text-emerald-100/70">Template and Commercial Overrides</h4>
                                <label class="mt-2 block text-xs text-emerald-100/70">
                                    Template
                                    <select name="template_key" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-sm text-white">
                                        <option value="">No template assignment</option>
                                        @foreach ($templates as $template)
                                            <option value="{{ $template['entry_key'] }}" @selected($template['entry_key'] === $row['template_key'])>{{ $template['name'] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="mt-2 block text-xs text-emerald-100/70">
                                    Store/channel allowance
                                    <input name="store_channel_allowance" value="{{ old('store_channel_allowance', $row['store_channel_allowance'] ?? '') }}" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-sm text-white" placeholder="1">
                                </label>
                                <label class="mt-2 block text-xs text-emerald-100/70">
                                    Display labels JSON
                                    <textarea name="display_labels_json" rows="2" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-xs text-white" placeholder='{"rewards":"Rewards","birthdays":"Lifecycle"}'>{{ old('display_labels_json', (string) ($row['display_labels_json'] ?? '')) }}</textarea>
                                </label>
                                <p class="text-[11px] text-emerald-100/65">
                                    Use a JSON object keyed by module (for example: <code>rewards</code>, <code>birthdays</code>). Invalid keys/values are ignored and fallback labels are used.
                                </p>
                                <label class="mt-2 block text-xs text-emerald-100/70">
                                    Plan pricing overrides JSON
                                    <textarea name="plan_pricing_overrides_json" rows="2" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-xs text-white" placeholder='{"pro":{"recurring_price_cents":45000}}'>{{ old('plan_pricing_overrides_json', (string) ($row['plan_pricing_overrides_json'] ?? '')) }}</textarea>
                                </label>
                                <label class="mt-2 block text-xs text-emerald-100/70">
                                    Add-on pricing overrides JSON
                                    <textarea name="addon_pricing_overrides_json" rows="2" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-xs text-white" placeholder='{"sms":{"recurring_price_cents":8900}}'>{{ old('addon_pricing_overrides_json', (string) ($row['addon_pricing_overrides_json'] ?? '')) }}</textarea>
                                </label>
                                <label class="mt-2 block text-xs text-emerald-100/70">
                                    Included usage overrides JSON
                                    <textarea name="included_usage_overrides_json" rows="2" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-xs text-white" placeholder='{"contact_count":12000,"store_channels":2}'>{{ old('included_usage_overrides_json', (string) ($row['included_usage_overrides_json'] ?? '')) }}</textarea>
                                </label>
                                <label class="mt-2 block text-xs text-emerald-100/70">
                                    Billing mapping JSON (readiness only)
                                    <textarea name="billing_mapping_json" rows="2" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-xs text-white" placeholder='{"stripe":{"customer_reference":"cus_placeholder","subscription_reference":"sub_placeholder"}}'>{{ old('billing_mapping_json', (string) ($row['billing_mapping_json'] ?? '')) }}</textarea>
                                </label>
                                <p class="text-[11px] text-emerald-100/65">
                                    This field stores future provider mapping metadata only. It does not activate checkout or mutate subscriptions.
                                    Required readiness keys: <code>stripe.customer_reference</code>, <code>stripe.subscription_reference</code>.
                                </p>
                                <label class="mt-2 block text-xs text-emerald-100/70">
                                    Metadata JSON
                                    <textarea name="metadata_json" rows="2" class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-xs text-white" placeholder='{"notes":"staging assignment"}'>{{ old('metadata_json', (string) ($row['metadata_json'] ?? '')) }}</textarea>
                                </label>
                                <button type="submit" class="mt-3 rounded-md border border-emerald-300/30 bg-emerald-500/20 px-3 py-1 text-xs font-semibold text-white">Save Overrides</button>
                                <p class="text-[11px] text-emerald-100/65">
                                    If no template is assigned, module labels fall back to entitlement defaults.
                                </p>
                                @if (filled($row['template_default_labels_json'] ?? null))
                                    <label class="mt-2 block text-xs text-emerald-100/70">
                                        Template default labels (read-only)
                                        <textarea rows="2" readonly class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-xs text-emerald-50/75">{{ $row['template_default_labels_json'] }}</textarea>
                                    </label>
                                @endif
                                @if (filled($row['effective_labels_json'] ?? null))
                                    <label class="mt-2 block text-xs text-emerald-100/70">
                                        Effective labels (read-only)
                                        <textarea rows="2" readonly class="mt-1 w-full rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-xs text-emerald-50/75">{{ $row['effective_labels_json'] }}</textarea>
                                    </label>
                                @endif
                            </form>
                        </div>

                        <form method="POST" action="{{ route('landlord.tenants.commercial.billing.stripe.customer-sync', ['tenant' => $tenant->id]) }}" class="mt-4 rounded-xl border border-emerald-200/10 p-3">
                            @csrf
                            <h4 class="text-xs font-semibold uppercase tracking-[0.12em] text-emerald-100/70">Guarded Stripe Customer Sync (Landlord-Only)</h4>
                            <p class="mt-1 text-[11px] text-emerald-100/70">
                                This guarded action creates or syncs only the Stripe customer reference for this tenant. It does not create subscriptions, run checkout, or activate billing lifecycle mutations.
                            </p>
                            <div class="mt-2 text-[11px] text-emerald-100/70">
                                <div>Current reference: {{ $stripeCustomerReference !== '' ? $stripeCustomerReference : 'none' }}</div>
                                <div>Action readiness: {{ $stripeCustomerSyncReady ? 'ready' : 'not ready' }}</div>
                            </div>
                            <button
                                type="submit"
                                @disabled(! $stripeCustomerSyncReady)
                                class="mt-3 rounded-md border border-emerald-300/30 bg-emerald-500/20 px-3 py-1 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {{ $stripeCustomerReference !== '' ? 'Sync Stripe Customer Reference' : 'Create Stripe Customer Reference' }}
                            </button>
                        </form>

                        <form method="POST" action="{{ route('landlord.tenants.commercial.billing.stripe.subscription-prep', ['tenant' => $tenant->id]) }}" class="mt-4 rounded-xl border border-emerald-200/10 p-3">
                            @csrf
                            <h4 class="text-xs font-semibold uppercase tracking-[0.12em] text-emerald-100/70">Guarded Stripe Subscription Prep (Landlord-Only)</h4>
                            <p class="mt-1 text-[11px] text-emerald-100/70">
                                This guarded action syncs subscription-prep metadata only (plan/add-on mapping candidate state). It does not create a live subscription, run checkout, or collect payment methods.
                            </p>
                            <div class="mt-2 text-[11px] text-emerald-100/70">
                                <div>Customer reference prerequisite: {{ $stripeCustomerReference !== '' ? $stripeCustomerReference : 'missing' }}</div>
                                <div>Action readiness: {{ $stripeSubscriptionPrepReady ? 'ready' : 'not ready' }}</div>
                            </div>
                            <button
                                type="submit"
                                @disabled(! $stripeSubscriptionPrepReady)
                                class="mt-3 rounded-md border border-emerald-300/30 bg-emerald-500/20 px-3 py-1 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                Sync Stripe Subscription Prep State
                            </button>
                        </form>

                        <form method="POST" action="{{ route('landlord.tenants.commercial.billing.stripe.subscription-live-sync', ['tenant' => $tenant->id]) }}" class="mt-4 rounded-xl border border-emerald-200/10 p-3">
                            @csrf
                            <h4 class="text-xs font-semibold uppercase tracking-[0.12em] text-emerald-100/70">Guarded Stripe Live Subscription Create/Sync (Landlord-Only)</h4>
                            <p class="mt-1 text-[11px] text-emerald-100/70">
                                This guarded action creates a live Stripe subscription reference when missing, or syncs an existing reference. It requires customer sync + subscription prep to be ready first.
                            </p>
                            <div class="mt-2 text-[11px] text-emerald-100/70">
                                <div>Customer reference prerequisite: {{ $stripeCustomerReference !== '' ? $stripeCustomerReference : 'missing' }}</div>
                                <div>Subscription prep prerequisite: {{ $stripeSubscriptionPrepLastStatus !== '' ? $stripeSubscriptionPrepLastStatus : 'never' }}</div>
                                <div>Current subscription reference: {{ $stripeLiveSubscriptionReference !== '' ? $stripeLiveSubscriptionReference : 'none' }}</div>
                                <div>Action readiness: {{ $stripeLiveSubscriptionSyncReady ? 'ready' : 'not ready' }}</div>
                            </div>
                            <button
                                type="submit"
                                @disabled(! $stripeLiveSubscriptionSyncReady)
                                class="mt-3 rounded-md border border-emerald-300/30 bg-emerald-500/20 px-3 py-1 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {{ $stripeLiveSubscriptionReference !== '' ? 'Sync Stripe Live Subscription Reference' : 'Create Stripe Live Subscription Reference' }}
                            </button>
                            <p class="mt-2 text-[11px] text-emerald-100/65">
                                Checkout, payment-method collection UX, tenant self-serve billing, and broad update/cancel lifecycle flows remain disabled.
                            </p>
                        </form>

                        <div class="mt-4 grid gap-4 xl:grid-cols-2">
                            <div class="rounded-xl border border-emerald-200/10 p-3">
                                <h4 class="text-xs font-semibold uppercase tracking-[0.12em] text-emerald-100/70">Module Overrides</h4>
                                <div class="mt-2 grid gap-2">
                                    @foreach ($moduleCatalog as $moduleKey => $definition)
                                        @php
                                            $moduleOverride = (array) data_get($row, 'module_overrides.'.$moduleKey, []);
                                            $enabledOverride = array_key_exists('enabled_override', $moduleOverride) ? $moduleOverride['enabled_override'] : null;
                                            $setupStatus = (string) ($moduleOverride['setup_status'] ?? 'not_started');
                                            $effectiveModuleState = is_array(data_get($row, 'resolved_module_states.'.$moduleKey))
                                                ? (array) data_get($row, 'resolved_module_states.'.$moduleKey)
                                                : [];
                                        @endphp
                                        <form method="POST" action="{{ route('landlord.tenants.commercial.modules.update', ['tenant' => $tenant->id, 'moduleKey' => $moduleKey]) }}" class="grid gap-2 md:grid-cols-[minmax(0,1fr)_auto_auto_auto_auto]">
                                            @csrf
                                            <div class="text-xs text-emerald-50/80">{{ $effectiveModuleState['label'] ?? ($definition['label'] ?? $moduleKey) }}</div>
                                            <select name="enabled_override" class="rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-xs text-white">
                                                <option value="inherit" @selected($enabledOverride === null)>inherit</option>
                                                <option value="enabled" @selected($enabledOverride === true)>enabled</option>
                                                <option value="disabled" @selected($enabledOverride === false)>disabled</option>
                                            </select>
                                            <select name="setup_status" class="rounded-md border border-emerald-200/20 bg-[#0b1412] px-2 py-1 text-xs text-white">
                                                @foreach (['not_started', 'in_progress', 'configured', 'blocked'] as $status)
                                                    <option value="{{ $status }}" @selected($setupStatus === $status)>{{ $status }}</option>
                                                @endforeach
                                            </select>
                                            <x-tenancy.module-state-badge :module-state="$effectiveModuleState" size="sm" compact />
                                            <button type="submit" class="rounded-md border border-emerald-200/20 px-2 py-1 text-xs text-emerald-100">Save</button>
                                        </form>
                                    @endforeach
                                </div>
                            </div>

                            <div class="rounded-xl border border-emerald-200/10 p-3">
                                <h4 class="text-xs font-semibold uppercase tracking-[0.12em] text-emerald-100/70">Add-on Enablement</h4>
                                <div class="mt-2 grid gap-2">
                                    @foreach ($addonCatalog as $addonKey => $definition)
                                        @php
                                            $addonEnabled = (bool) data_get($row, 'addon_states.'.$addonKey, false);
                                        @endphp
                                        <form method="POST" action="{{ route('landlord.tenants.commercial.addons.update', ['tenant' => $tenant->id, 'addonKey' => $addonKey]) }}" class="grid gap-2 md:grid-cols-[1fr_auto_auto]">
                                            @csrf
                                            <input type="hidden" name="enabled" value="0">
                                            <div class="text-xs text-emerald-50/80">{{ $definition['label'] ?? $addonKey }}</div>
                                            <label class="inline-flex items-center gap-2 text-xs text-emerald-100">
                                                <input type="checkbox" name="enabled" value="1" @checked($addonEnabled)>
                                                enabled
                                            </label>
                                            <button type="submit" class="rounded-md border border-emerald-200/20 px-2 py-1 text-xs text-emerald-100">Save</button>
                                        </form>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 text-xs text-emerald-50/70">
                            Usage now:
                            contacts {{ (int) data_get($row, 'usage.metrics.contact_count', 0) }}/{{ data_get($row, 'usage.included_limits.contact_count', 'n/a') }},
                            email {{ (int) data_get($row, 'usage.metrics.email_usage', 0) }}/{{ data_get($row, 'usage.included_limits.email_usage', 'n/a') }},
                            sms {{ (int) data_get($row, 'usage.metrics.sms_usage', 0) }}/{{ data_get($row, 'usage.included_limits.sms_usage', 'n/a') }},
                            store channels {{ data_get($row, 'usage.included_limits.store_channels', 'n/a') }}.
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    </div>
</x-app-layout>
