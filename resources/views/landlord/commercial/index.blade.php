<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-zinc-900">Landlord Commercial Config</h1>
    </x-slot>

    @php
        $tenantCount = count($tenants);
        $tenantReadyForActivationCount = collect($tenants)->filter(
            fn (array $row): bool => (bool) data_get($row, 'billing_readiness.ready_for_activation_prep', false)
        )->count();
        $tenantWithStripeCustomerCount = collect($tenants)->filter(
            fn (array $row): bool => trim((string) data_get($row, 'stripe_customer_sync.customer_reference', '')) !== ''
        )->count();
        $tenantWithStripeSubscriptionCount = collect($tenants)->filter(
            fn (array $row): bool => trim((string) data_get($row, 'stripe_live_subscription_sync.subscription_reference', '')) !== ''
        )->count();
        $moduleCategoryMeta = [
            'shared-core' => [
                'label' => 'Shared Core Modules',
                'description' => 'Core tenant capabilities used across supported operating tracks.',
            ],
            'shopify-only' => [
                'label' => 'Shopify Track Modules',
                'description' => 'Shopify-specific module surfaces and tenant operations.',
            ],
            'integration-layer' => [
                'label' => 'Integration Layer Modules',
                'description' => 'Connectors and provider-linked module experiences.',
            ],
            'add-on' => [
                'label' => 'Add-on Linked Modules',
                'description' => 'Modules commonly enabled through add-on configuration.',
            ],
            'internal-admin' => [
                'label' => 'Internal/Admin Modules',
                'description' => 'Operational modules intended for internal workflows.',
            ],
            'uncategorized' => [
                'label' => 'Uncategorized Modules',
                'description' => 'Modules that do not currently have a classification.',
            ],
        ];
        $categorizedModules = collect($moduleCatalog)
            ->map(function (array $definition, string $moduleKey) use ($moduleCategoryMeta): array {
                $classification = strtolower(trim((string) ($definition['classification'] ?? '')));
                if (! array_key_exists($classification, $moduleCategoryMeta)) {
                    $classification = 'uncategorized';
                }

                return [
                    'module_key' => $moduleKey,
                    'label' => (string) ($definition['label'] ?? $moduleKey),
                    'classification' => $classification,
                ];
            })
            ->sortBy(fn (array $module): string => strtolower((string) ($module['label'] ?? $module['module_key'])))
            ->values();
        $moduleCategories = [];
        foreach (array_keys($moduleCategoryMeta) as $categoryKey) {
            $items = $categorizedModules
                ->where('classification', $categoryKey)
                ->values()
                ->all();
            if ($items === []) {
                continue;
            }

            $moduleCategories[] = [
                'key' => $categoryKey,
                'label' => (string) data_get($moduleCategoryMeta, $categoryKey.'.label', $categoryKey),
                'description' => (string) data_get($moduleCategoryMeta, $categoryKey.'.description', ''),
                'items' => $items,
            ];
        }
        $defaultModuleCategoryTab = (string) data_get($moduleCategories, '0.key', '');
    @endphp

    <div class="space-y-6">
        @if (session('status'))
            <section class="rounded-xl border border-zinc-300 bg-zinc-50 px-4 py-3 text-sm text-zinc-900">
                {{ session('status') }}
            </section>
        @endif

        @if (session('status_error'))
            <section class="rounded-xl border border-zinc-900 bg-zinc-100 px-4 py-3 text-sm text-zinc-900">
                {{ session('status_error') }}
            </section>
        @endif

        @if ($errors->any())
            <section class="rounded-xl border border-zinc-900 bg-zinc-100 px-4 py-3 text-sm text-zinc-900">
                <p class="font-semibold">Validation blocked one or more changes.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-xs">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <header class="sticky top-0 z-20 border-b border-zinc-200 bg-white/95 backdrop-blur">
                <div class="flex flex-wrap items-start justify-between gap-4 px-6 py-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Landlord</p>
                        <h2 class="mt-1 text-2xl font-semibold text-zinc-950">Commercial Revenue Configuration</h2>
                        <p class="mt-1 max-w-3xl text-sm text-zinc-600">
                            Commercial Control Center for plan pricing, add-on pricing, tenant overrides, and guarded billing-readiness actions.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <a
                            href="{{ route('landlord.dashboard') }}"
                            class="inline-flex items-center rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100"
                        >
                            Back to Dashboard
                        </a>
                        <a
                            href="#plans-pricing"
                            class="inline-flex items-center rounded-lg bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800"
                        >
                            Update Pricing
                        </a>
                    </div>
                </div>
                <nav class="overflow-x-auto border-t border-zinc-200 px-6 py-3">
                    <ul class="flex min-w-max items-center gap-2 text-xs font-medium text-zinc-600">
                        <li><a href="#overview" class="rounded-md border border-zinc-300 px-3 py-1.5 hover:bg-zinc-100">Overview</a></li>
                        <li><a href="#billing-readiness" class="rounded-md border border-zinc-300 px-3 py-1.5 hover:bg-zinc-100">Billing readiness</a></li>
                        <li><a href="#plans-pricing" class="rounded-md border border-zinc-300 px-3 py-1.5 hover:bg-zinc-100">Plans &amp; pricing</a></li>
                        <li><a href="#templates" class="rounded-md border border-zinc-300 px-3 py-1.5 hover:bg-zinc-100">Templates</a></li>
                        <li><a href="#modules-addons" class="rounded-md border border-zinc-300 px-3 py-1.5 hover:bg-zinc-100">Modules &amp; add-ons</a></li>
                        <li><a href="#usage-limits" class="rounded-md border border-zinc-300 px-3 py-1.5 hover:bg-zinc-100">Usage / included limits</a></li>
                        <li><a href="#tenant-overrides" class="rounded-md border border-zinc-300 px-3 py-1.5 hover:bg-zinc-100">Tenant overrides</a></li>
                    </ul>
                </nav>
            </header>

            <div class="space-y-8 p-6">
                <section id="overview" class="space-y-4 scroll-mt-40">
                    <div>
                        <h3 class="text-lg font-semibold text-zinc-950">Overview</h3>
                        <p class="text-sm text-zinc-600">
                            Snapshot of current commercial pricing coverage and guarded Stripe prep status.
                        </p>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Tenants</p>
                            <p class="mt-2 text-2xl font-semibold text-zinc-950">{{ $tenantCount }}</p>
                            <p class="mt-1 text-xs text-zinc-600">Configured tenant rows</p>
                        </article>
                        <article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Activation prep ready</p>
                            <p class="mt-2 text-2xl font-semibold text-zinc-950">{{ $tenantReadyForActivationCount }}</p>
                            <p class="mt-1 text-xs text-zinc-600">Billing readiness gate satisfied</p>
                        </article>
                        <article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Stripe customers</p>
                            <p class="mt-2 text-2xl font-semibold text-zinc-950">{{ $tenantWithStripeCustomerCount }}</p>
                            <p class="mt-1 text-xs text-zinc-600">Customer references present</p>
                        </article>
                        <article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Live subscriptions</p>
                            <p class="mt-2 text-2xl font-semibold text-zinc-950">{{ $tenantWithStripeSubscriptionCount }}</p>
                            <p class="mt-1 text-xs text-zinc-600">Guarded live sync references present</p>
                        </article>
                    </div>
                </section>

                <section id="billing-readiness" class="space-y-4 scroll-mt-40">
                    <div>
                        <h3 class="text-lg font-semibold text-zinc-950">Billing Readiness / Status</h3>
                        <p class="text-sm text-zinc-600">
                            Revenue configuration is ready for mapping validation while broad lifecycle automation stays intentionally disabled.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2 text-xs">
                        <span class="rounded-full border border-zinc-300 bg-zinc-50 px-2.5 py-1 text-zinc-700">
                            Mapping status: {{ (string) ($billingReadiness['mapping_status'] ?? 'missing') }}
                        </span>
                        <span class="rounded-full border border-zinc-300 bg-zinc-50 px-2.5 py-1 text-zinc-700">
                            Lifecycle: {{ (bool) ($billingReadiness['lifecycle_disabled'] ?? true) ? 'disabled' : 'enabled' }}
                        </span>
                        <span class="rounded-full border border-zinc-300 bg-zinc-50 px-2.5 py-1 text-zinc-700">
                            Provider priority: {{ implode(' > ', array_map('strtoupper', (array) ($billingReadiness['provider_priority'] ?? []))) ?: 'n/a' }}
                        </span>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ((array) ($billingReadiness['providers'] ?? []) as $providerKey => $provider)
                            <article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-700">
                                <div class="font-semibold text-zinc-900">{{ strtoupper((string) $providerKey) }}</div>
                                <div class="mt-1">Role: {{ (string) ($provider['role'] ?? 'n/a') }}</div>
                                <div>Status: {{ (string) ($provider['status'] ?? 'n/a') }}</div>
                            </article>
                        @endforeach
                    </div>

                    <div class="grid gap-3 lg:grid-cols-2">
                        <article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 text-xs text-zinc-700">
                            <div class="font-semibold text-zinc-900">Stripe tier mappings</div>
                            @foreach ((array) data_get($billingReadiness, 'stripe_mapping.tiers', []) as $tierKey => $tierMapping)
                                <div class="mt-1">
                                    {{ $tierKey }}:
                                    {{ (string) data_get($tierMapping, 'recurring_price_lookup_key', 'missing') }}
                                    / {{ (string) data_get($tierMapping, 'setup_price_lookup_key', 'missing') }}
                                </div>
                            @endforeach
                        </article>
                        <article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 text-xs text-zinc-700">
                            <div class="font-semibold text-zinc-900">Stripe add-on + support mappings</div>
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

                    <ul class="list-disc space-y-1 pl-5 text-xs text-zinc-600">
                        <li>Checkout remains disabled.</li>
                        <li>Only one guarded live subscription create/sync action is available (landlord-triggered, prerequisite-gated).</li>
                        <li>Broad subscription update/cancel automation is still disabled.</li>
                        <li>Billing mapping fields are metadata placeholders only.</li>
                    </ul>

                    <p class="text-xs text-zinc-600">
                        Guarded Stripe actions in this phase are landlord-triggered only:
                        customer reference sync, subscription-prep metadata sync, and narrow live subscription create/sync.
                    </p>

                    @if ((array) ($billingReadiness['missing_global_requirements'] ?? []) !== [])
                        <div class="rounded-xl border border-zinc-900 bg-zinc-100 p-3 text-xs text-zinc-900">
                            <p class="font-semibold">Billing readiness still has unresolved global mapping requirements:</p>
                            <ul class="mt-2 list-disc space-y-1 pl-4">
                                @foreach ((array) ($billingReadiness['missing_global_requirements'] ?? []) as $missingRequirement)
                                    <li>{{ $missingRequirement }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ((array) data_get($billingReadiness, 'stripe_mapping.tenant_override_fields', []) !== [])
                        <p class="text-xs text-zinc-600">
                            Expected tenant override keys for future billing mapping:
                            {{ implode(', ', (array) data_get($billingReadiness, 'stripe_mapping.tenant_override_fields', [])) }}.
                        </p>
                    @endif

                    <p class="text-xs text-zinc-600">
                        Checkout remains disabled and broad lifecycle writes remain intentionally inactive in this phase.
                    </p>
                    <p class="text-xs text-zinc-600">
                        Pre-billing activation requires completed staging evidence artifacts documented in:
                        <code>docs/operations/staging-commercial-uat-runbook.md</code>,
                        <code>docs/operations/staging-commercial-uat-evidence-template.md</code>, and
                        <code>docs/operations/pre-billing-readiness-gate.md</code>.
                    </p>
                    <p class="text-xs text-zinc-600">
                        Activation gate checklist:
                        <code>docs/operations/billing-activation-checklist.md</code>.
                    </p>
                    @if ((array) ($billingReadiness['required_tenant_billing_fields'] ?? []) !== [])
                        <p class="text-xs text-zinc-600">
                            Tenant billing mapping JSON must include:
                            {{ implode(', ', (array) ($billingReadiness['required_tenant_billing_fields'] ?? [])) }}.
                        </p>
                    @endif
                </section>

                <section id="plans-pricing" class="space-y-4 scroll-mt-40">
                    <div>
                        <h3 class="text-lg font-semibold text-zinc-950">Plans &amp; Pricing</h3>
                        <p class="text-sm text-zinc-600">
                            Edit monthly prices, setup fees, and revenue configuration values in USD cents.
                        </p>
                    </div>

                    <div class="grid gap-6 lg:grid-cols-2">
                        <article class="rounded-2xl border border-zinc-200 bg-white p-5">
                            <h4 class="text-base font-semibold text-zinc-950">Plan pricing</h4>
                            <p class="mt-1 text-xs text-zinc-600">Starter, Growth, and Pro recurring economics.</p>
                            <div class="mt-4 space-y-4">
                                @foreach ($plans as $entry)
                                    @php
                                        $monthlyCents = is_numeric($entry['recurring_price_cents'] ?? null) ? (int) $entry['recurring_price_cents'] : null;
                                        $setupCents = is_numeric($entry['setup_price_cents'] ?? null) ? (int) $entry['setup_price_cents'] : null;
                                    @endphp
                                    <form method="POST" action="{{ route('landlord.commercial.catalog.upsert', ['type' => 'plan']) }}" class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                                        @csrf
                                        <input type="hidden" name="entry_key" value="{{ $entry['entry_key'] }}">
                                        <div class="grid gap-3 md:grid-cols-2">
                                            <label class="text-xs text-zinc-700">
                                                Plan name
                                                <input name="name" value="{{ $entry['name'] }}" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-900">
                                            </label>
                                            <label class="text-xs text-zinc-700">
                                                Display position
                                                <input name="position" value="{{ (int) ($entry['position'] ?? 100) }}" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-900">
                                            </label>
                                            <label class="text-xs text-zinc-700">
                                                Monthly price (USD cents)
                                                <input name="recurring_price_cents" value="{{ $entry['recurring_price_cents'] ?? '' }}" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-900">
                                            </label>
                                            <label class="text-xs text-zinc-700">
                                                One-time setup fee (USD cents)
                                                <input name="setup_price_cents" value="{{ $entry['setup_price_cents'] ?? '' }}" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-900">
                                            </label>
                                        </div>
                                        <p class="mt-2 text-[11px] text-zinc-600">
                                            Monthly preview: {{ $monthlyCents !== null ? '$'.number_format($monthlyCents / 100, 2) : 'n/a' }}
                                            · Setup preview: {{ $setupCents !== null ? '$'.number_format($setupCents / 100, 2) : 'n/a' }}
                                        </p>
                                        <label class="mt-3 block text-xs text-zinc-700">
                                            Commercial configuration JSON
                                            <textarea name="payload_json" rows="4" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 font-mono text-xs text-zinc-900">{{ json_encode((array) ($entry['payload'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
                                        </label>
                                        <button type="submit" class="mt-3 rounded-md bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-zinc-800">Save plan pricing</button>
                                    </form>
                                @endforeach
                            </div>
                        </article>

                        <article class="rounded-2xl border border-zinc-200 bg-white p-5">
                            <h4 class="text-base font-semibold text-zinc-950">Add-on pricing</h4>
                            <p class="mt-1 text-xs text-zinc-600">Recurring and setup pricing for purchasable add-ons.</p>
                            <div class="mt-4 space-y-4">
                                @foreach ($addons as $entry)
                                    @php
                                        $monthlyCents = is_numeric($entry['recurring_price_cents'] ?? null) ? (int) $entry['recurring_price_cents'] : null;
                                        $setupCents = is_numeric($entry['setup_price_cents'] ?? null) ? (int) $entry['setup_price_cents'] : null;
                                    @endphp
                                    <form method="POST" action="{{ route('landlord.commercial.catalog.upsert', ['type' => 'addon']) }}" class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                                        @csrf
                                        <input type="hidden" name="entry_key" value="{{ $entry['entry_key'] }}">
                                        <div class="grid gap-3 md:grid-cols-2">
                                            <label class="text-xs text-zinc-700">
                                                Add-on name
                                                <input name="name" value="{{ $entry['name'] }}" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-900">
                                            </label>
                                            <label class="text-xs text-zinc-700">
                                                Display position
                                                <input name="position" value="{{ (int) ($entry['position'] ?? 100) }}" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-900">
                                            </label>
                                            <label class="text-xs text-zinc-700">
                                                Monthly price (USD cents)
                                                <input name="recurring_price_cents" value="{{ $entry['recurring_price_cents'] ?? '' }}" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-900">
                                            </label>
                                            <label class="text-xs text-zinc-700">
                                                One-time setup fee (USD cents)
                                                <input name="setup_price_cents" value="{{ $entry['setup_price_cents'] ?? '' }}" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-900">
                                            </label>
                                        </div>
                                        <p class="mt-2 text-[11px] text-zinc-600">
                                            Monthly preview: {{ $monthlyCents !== null ? '$'.number_format($monthlyCents / 100, 2) : 'n/a' }}
                                            · Setup preview: {{ $setupCents !== null ? '$'.number_format($setupCents / 100, 2) : 'n/a' }}
                                        </p>
                                        <label class="mt-3 block text-xs text-zinc-700">
                                            Commercial configuration JSON
                                            <textarea name="payload_json" rows="3" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 font-mono text-xs text-zinc-900">{{ json_encode((array) ($entry['payload'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
                                        </label>
                                        <button type="submit" class="mt-3 rounded-md bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-zinc-800">Save add-on pricing</button>
                                    </form>
                                @endforeach
                            </div>
                        </article>
                    </div>

                    <article class="rounded-2xl border border-zinc-200 bg-white p-5">
                        <h4 class="text-base font-semibold text-zinc-950">Setup packages</h4>
                        <p class="mt-1 text-xs text-zinc-600">One-time onboarding and migration commercial values.</p>
                        <div class="mt-4 grid gap-4 lg:grid-cols-2">
                            @foreach ($setupPackages as $entry)
                                @php
                                    $setupCents = is_numeric($entry['setup_price_cents'] ?? null) ? (int) $entry['setup_price_cents'] : null;
                                @endphp
                                <form method="POST" action="{{ route('landlord.commercial.catalog.upsert', ['type' => 'setup_package']) }}" class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                                    @csrf
                                    <input type="hidden" name="entry_key" value="{{ $entry['entry_key'] }}">
                                    <div class="grid gap-3 md:grid-cols-2">
                                        <label class="text-xs text-zinc-700">
                                            Package name
                                            <input name="name" value="{{ $entry['name'] }}" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-900">
                                        </label>
                                        <label class="text-xs text-zinc-700">
                                            Display position
                                            <input name="position" value="{{ (int) ($entry['position'] ?? 100) }}" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-900">
                                        </label>
                                        <label class="text-xs text-zinc-700 md:col-span-2">
                                            One-time setup fee (USD cents)
                                            <input name="setup_price_cents" value="{{ $entry['setup_price_cents'] ?? '' }}" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-900">
                                        </label>
                                    </div>
                                    <p class="mt-2 text-[11px] text-zinc-600">Setup preview: {{ $setupCents !== null ? '$'.number_format($setupCents / 100, 2) : 'n/a' }}</p>
                                    <button type="submit" class="mt-3 rounded-md bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-zinc-800">Save package pricing</button>
                                </form>
                            @endforeach
                        </div>
                    </article>
                </section>

                <section id="templates" class="space-y-4 scroll-mt-40">
                    <div>
                        <h3 class="text-lg font-semibold text-zinc-950">Templates</h3>
                        <p class="text-sm text-zinc-600">
                            Template defaults shape labels and recommended module emphasis for tenant assignments.
                        </p>
                    </div>

                    <div class="space-y-4">
                        @foreach ($templates as $entry)
                            <section class="rounded-2xl border border-zinc-200 bg-white p-5">
                                <form method="POST" action="{{ route('landlord.commercial.catalog.upsert', ['type' => 'template']) }}">
                                    @csrf
                                    <input type="hidden" name="entry_key" value="{{ $entry['entry_key'] }}">
                                    <div class="grid gap-3 md:grid-cols-2">
                                        <label class="text-xs text-zinc-700">
                                            Template name
                                            <input name="name" value="{{ $entry['name'] }}" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-900">
                                        </label>
                                        <label class="text-xs text-zinc-700">
                                            Display position
                                            <input name="position" value="{{ (int) ($entry['position'] ?? 100) }}" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-900">
                                        </label>
                                    </div>
                                    <label class="mt-3 block text-xs text-zinc-700">
                                        Template payload JSON
                                        <textarea name="payload_json" rows="5" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 font-mono text-xs text-zinc-900">{{ json_encode((array) ($entry['payload'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
                                    </label>
                                    <button type="submit" class="mt-3 rounded-md bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-zinc-800">Save template</button>
                                </form>

                                <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-zinc-200 pt-4">
                                    <form method="POST" action="{{ route('landlord.commercial.templates.duplicate', ['entryKey' => $entry['entry_key']]) }}" class="flex flex-wrap items-center gap-2">
                                        @csrf
                                        <input name="new_key" placeholder="{{ $entry['entry_key'] }}_copy" class="rounded-md border border-zinc-300 bg-white px-2 py-1 text-xs text-zinc-900">
                                        <button type="submit" class="rounded-md border border-zinc-300 px-2 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-100">Duplicate</button>
                                    </form>

                                    <div class="ml-auto flex flex-wrap items-center gap-2">
                                        @foreach (['active' => 'Activate', 'inactive' => 'Deactivate', 'archived' => 'Archive'] as $state => $label)
                                            <form method="POST" action="{{ route('landlord.commercial.templates.state', ['entryKey' => $entry['entry_key']]) }}">
                                                @csrf
                                                <input type="hidden" name="state" value="{{ $state }}">
                                                <button type="submit" class="rounded-md border border-zinc-300 px-2 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-100">{{ $label }}</button>
                                            </form>
                                        @endforeach
                                    </div>
                                </div>
                            </section>
                        @endforeach
                    </div>
                </section>

                <section id="modules-addons" class="space-y-4 scroll-mt-40">
                    <div>
                        <h3 class="text-lg font-semibold text-zinc-950">Modules and Add-ons</h3>
                        <p class="text-sm text-zinc-600">
                            Canonical catalog references used by plan entitlements and tenant override controls.
                        </p>
                    </div>

                    <div class="grid gap-4 xl:grid-cols-2">
                        <article
                            class="rounded-2xl border border-zinc-200 bg-white p-5"
                            x-data="{ moduleCategoryTab: @js($defaultModuleCategoryTab) }"
                        >
                            <h4 class="text-base font-semibold text-zinc-950">Module catalog</h4>
                            <p class="mt-1 text-xs text-zinc-600">
                                Modules are grouped by top-level classification to separate core, integration, and add-on linked information.
                            </p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach ($moduleCategories as $category)
                                    @php
                                        $categoryKey = (string) ($category['key'] ?? '');
                                    @endphp
                                    <button
                                        type="button"
                                        @click="moduleCategoryTab = @js($categoryKey)"
                                        :class="moduleCategoryTab === @js($categoryKey)
                                            ? 'border-zinc-900 bg-zinc-900 text-white'
                                            : 'border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-100'"
                                        class="rounded-md border px-3 py-1.5 text-xs font-medium transition"
                                    >
                                        {{ $category['label'] }}
                                    </button>
                                @endforeach
                            </div>
                            <div class="mt-3 space-y-3">
                                @foreach ($moduleCategories as $category)
                                    @php
                                        $categoryKey = (string) ($category['key'] ?? '');
                                    @endphp
                                    <section
                                        x-show="moduleCategoryTab === @js($categoryKey)"
                                        class="rounded-xl border border-zinc-200 bg-zinc-50 p-3"
                                    >
                                        <h5 class="text-xs font-semibold uppercase tracking-[0.1em] text-zinc-700">{{ $category['label'] }}</h5>
                                        <p class="mt-1 text-[11px] text-zinc-600">{{ $category['description'] }}</p>
                                        <div class="mt-2 overflow-x-auto">
                                            <table class="min-w-full divide-y divide-zinc-200 text-xs text-zinc-700">
                                                <thead class="bg-white text-zinc-600">
                                                    <tr>
                                                        <th class="px-3 py-2 text-left font-semibold">Module key</th>
                                                        <th class="px-3 py-2 text-left font-semibold">Label</th>
                                                        <th class="px-3 py-2 text-left font-semibold">Classification</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-zinc-200 bg-white">
                                                    @foreach ((array) ($category['items'] ?? []) as $module)
                                                        <tr>
                                                            <td class="px-3 py-2 font-mono text-zinc-900">{{ $module['module_key'] }}</td>
                                                            <td class="px-3 py-2">{{ $module['label'] }}</td>
                                                            <td class="px-3 py-2">{{ str_replace('-', ' ', (string) ($module['classification'] ?? 'uncategorized')) }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>
                                @endforeach
                            </div>
                        </article>

                        <article class="rounded-2xl border border-zinc-200 bg-white p-5">
                            <h4 class="text-base font-semibold text-zinc-950">Add-on catalog</h4>
                            <div class="mt-3 overflow-x-auto">
                                <table class="min-w-full divide-y divide-zinc-200 text-xs text-zinc-700">
                                    <thead class="bg-zinc-50 text-zinc-600">
                                        <tr>
                                            <th class="px-3 py-2 text-left font-semibold">Add-on key</th>
                                            <th class="px-3 py-2 text-left font-semibold">Label</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-200">
                                        @foreach ($addonCatalog as $addonKey => $definition)
                                            <tr>
                                                <td class="px-3 py-2 font-mono text-zinc-900">{{ $addonKey }}</td>
                                                <td class="px-3 py-2">{{ $definition['label'] ?? $addonKey }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </article>
                    </div>
                </section>

                <section id="usage-limits" class="space-y-4 scroll-mt-40">
                    <div>
                        <h3 class="text-lg font-semibold text-zinc-950">Usage / Included Limits</h3>
                        <p class="text-sm text-zinc-600">
                            Included usage value shown here reflects plan payload configuration and current tracked usage metrics.
                        </p>
                    </div>

                    <article class="rounded-2xl border border-zinc-200 bg-white p-5">
                        <h4 class="text-base font-semibold text-zinc-950">Included usage value by plan</h4>
                        <div class="mt-3 overflow-x-auto">
                            <table class="min-w-full divide-y divide-zinc-200 text-xs text-zinc-700">
                                <thead class="bg-zinc-50 text-zinc-600">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-semibold">Plan</th>
                                        <th class="px-3 py-2 text-left font-semibold">Store channels included</th>
                                        <th class="px-3 py-2 text-left font-semibold">Contacts included</th>
                                        <th class="px-3 py-2 text-left font-semibold">Monthly price</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200">
                                    @foreach ($plans as $entry)
                                        @php
                                            $includedUsage = is_array(data_get($entry, 'payload.included_usage'))
                                                ? (array) data_get($entry, 'payload.included_usage')
                                                : [];
                                            $monthlyCents = is_numeric($entry['recurring_price_cents'] ?? null)
                                                ? (int) $entry['recurring_price_cents']
                                                : null;
                                        @endphp
                                        <tr>
                                            <td class="px-3 py-2 font-semibold text-zinc-900">{{ $entry['name'] }}</td>
                                            <td class="px-3 py-2">{{ $includedUsage['store_channels'] ?? 'n/a' }}</td>
                                            <td class="px-3 py-2">{{ $includedUsage['contact_count'] ?? 'n/a' }}</td>
                                            <td class="px-3 py-2">{{ $monthlyCents !== null ? '$'.number_format($monthlyCents / 100, 2).'/month' : 'n/a' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </article>

                    <article class="rounded-2xl border border-zinc-200 bg-white p-5">
                        <h4 class="text-base font-semibold text-zinc-950">Tracked usage metrics</h4>
                        <div class="mt-3 grid gap-3 md:grid-cols-3">
                            @foreach ($usageMetrics as $usageMetricKey => $usageMetric)
                                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-700">
                                    <p class="font-semibold text-zinc-900">{{ (string) ($usageMetric['label'] ?? $usageMetricKey) }}</p>
                                    <p class="mt-1 font-mono text-[11px] text-zinc-600">{{ $usageMetricKey }}</p>
                                    <p class="mt-1 text-[11px] text-zinc-600">Track only: {{ (bool) ($usageMetric['track_only'] ?? false) ? 'yes' : 'no' }}</p>
                                </div>
                            @endforeach
                        </div>
                    </article>
                </section>

                <section id="tenant-overrides" class="space-y-4 scroll-mt-40">
                    <div>
                        <h3 class="text-lg font-semibold text-zinc-950">Tenant Overrides</h3>
                        <p class="text-sm text-zinc-600">
                            Landlord-managed assignment source for plans, templates, pricing overrides, and guarded billing actions.
                        </p>
                    </div>

                    <div class="space-y-6">
                        @if ($tenants === [])
                            <article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-700">
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

                            <article class="rounded-2xl border border-zinc-200 bg-white p-5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h4 class="text-base font-semibold text-zinc-950">{{ $tenant->name }}</h4>
                                        <p class="text-xs text-zinc-600">
                                            {{ $tenant->slug }}
                                            · assigned plan: {{ $row['plan_key'] }}
                                            · effective plan: {{ $row['resolved_plan_key'] }}
                                            · template: {{ $row['template_key'] !== '' ? $row['template_key'] : 'none' }}
                                            · labels: {{ str_replace('_', ' ', (string) ($row['label_source'] ?? 'entitlements_default')) }}
                                        </p>
                                        <p class="mt-1 text-[11px] text-zinc-600">
                                            Billing readiness:
                                            {{ $tenantBillingReady ? 'ready for activation prep' : 'not ready for activation prep' }}
                                            · mode: {{ (bool) ($tenantBillingReadiness['config_only'] ?? true) ? 'config-only' : 'active' }}
                                            · lifecycle: {{ (bool) ($tenantBillingReadiness['lifecycle_disabled'] ?? true) ? 'disabled' : 'enabled' }}
                                        </p>

                                        @if ((bool) ($row['template_missing'] ?? false))
                                            <p class="mt-1 text-[11px] text-zinc-800">
                                                Assigned template key is missing from the catalog. Commercialization surfaces will fall back to entitlement defaults.
                                            </p>
                                        @endif

                                        @if ($tenantBillingMissing !== [])
                                            <p class="mt-1 text-[11px] text-zinc-700">
                                                Missing billing requirements: {{ implode('; ', $tenantBillingMissing) }}.
                                            </p>
                                        @endif

                                        <p class="mt-1 text-[11px] text-zinc-600">
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
                                            <p class="mt-1 text-[11px] text-zinc-600">Last sync note: {{ (string) $stripeCustomerSync['last_message'] }}</p>
                                        @endif

                                        @if ($stripeCustomerSyncReasons !== [])
                                            <p class="mt-1 text-[11px] text-zinc-700">
                                                Stripe sync blocked until: {{ implode('; ', $stripeCustomerSyncReasons) }}
                                            </p>
                                        @endif

                                        <p class="mt-1 text-[11px] text-zinc-600">
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
                                            <p class="mt-1 text-[11px] text-zinc-600">Subscription prep synced at: {{ (string) $stripeSubscriptionPrep['last_synced_at'] }}</p>
                                        @endif

                                        @if (filled($stripeSubscriptionPrep['last_attempted_at'] ?? null))
                                            <p class="mt-1 text-[11px] text-zinc-600">Subscription prep last attempt: {{ (string) $stripeSubscriptionPrep['last_attempted_at'] }}</p>
                                        @endif

                                        @if (filled($stripeSubscriptionPrep['last_message'] ?? null))
                                            <p class="mt-1 text-[11px] text-zinc-600">Subscription prep note: {{ (string) $stripeSubscriptionPrep['last_message'] }}</p>
                                        @endif

                                        @if ($stripeSubscriptionPrepReasons !== [])
                                            <p class="mt-1 text-[11px] text-zinc-700">
                                                Subscription prep blocked until: {{ implode('; ', $stripeSubscriptionPrepReasons) }}
                                            </p>
                                        @endif

                                        <p class="mt-1 text-[11px] text-zinc-600">
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
                                            <p class="mt-1 text-[11px] text-zinc-600">Live subscription synced at: {{ (string) $stripeLiveSubscriptionSync['last_synced_at'] }}</p>
                                        @endif

                                        @if (filled($stripeLiveSubscriptionSync['last_attempted_at'] ?? null))
                                            <p class="mt-1 text-[11px] text-zinc-600">Live subscription last attempt: {{ (string) $stripeLiveSubscriptionSync['last_attempted_at'] }}</p>
                                        @endif

                                        @if (filled($stripeLiveSubscriptionSync['last_message'] ?? null))
                                            <p class="mt-1 text-[11px] text-zinc-600">Live subscription sync note: {{ (string) $stripeLiveSubscriptionSync['last_message'] }}</p>
                                        @endif

                                        @if ($stripeLiveSubscriptionSyncReasons !== [])
                                            <p class="mt-1 text-[11px] text-zinc-700">
                                                Live subscription sync blocked until: {{ implode('; ', $stripeLiveSubscriptionSyncReasons) }}
                                            </p>
                                        @endif

                                        <div class="mt-2 rounded-lg border border-zinc-200 bg-zinc-50 p-2 text-[11px] text-zinc-700">
                                            <p class="font-semibold text-zinc-900">Guarded Stripe sequence status</p>
                                            <p class="mt-1">1) Customer sync: {{ $stripeCustomerSyncLastStatus !== '' ? $stripeCustomerSyncLastStatus : 'never' }}</p>
                                            <p>2) Subscription prep: {{ $stripeSubscriptionPrepLastStatus !== '' ? $stripeSubscriptionPrepLastStatus : 'never' }}</p>
                                            <p>3) Live subscription create/sync: {{ $stripeLiveSubscriptionSyncLastStatus !== '' ? $stripeLiveSubscriptionSyncLastStatus : 'never' }}</p>
                                            <p class="mt-1">
                                                Staging evidence after each step: capture this tenant row state and corresponding Stripe object/reference evidence.
                                            </p>
                                        </div>

                                        <p class="mt-1 text-[11px] text-zinc-600">
                                            Checkout and broader subscription lifecycle automation remain disabled. This guarded action only creates/syncs a single subscription reference.
                                        </p>
                                    </div>

                                    <a href="{{ route('landlord.tenants.show', ['tenant' => $tenant->id]) }}" class="text-xs font-semibold text-zinc-700 hover:text-zinc-900">
                                        Open tenant detail
                                    </a>
                                </div>

                                <div class="mt-4 grid gap-4 xl:grid-cols-2">
                                    <form method="POST" action="{{ route('landlord.tenants.commercial.plan', ['tenant' => $tenant->id]) }}" class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                                        @csrf
                                        <h5 class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-600">Plan assignment</h5>
                                        <label class="mt-2 block text-xs text-zinc-700">
                                            Plan
                                            <select name="plan_key" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-900">
                                                @foreach ($plans as $plan)
                                                    <option value="{{ $plan['entry_key'] }}" @selected($plan['entry_key'] === $row['plan_key'])>{{ $plan['name'] }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="mt-2 block text-xs text-zinc-700">
                                            Operating mode
                                            <select name="operating_mode" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-900">
                                                <option value="shopify" @selected($row['operating_mode'] === 'shopify')>shopify</option>
                                                <option value="direct" @selected($row['operating_mode'] === 'direct')>direct</option>
                                            </select>
                                        </label>
                                        <button type="submit" class="mt-3 rounded-md bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-zinc-800">Save plan assignment</button>
                                    </form>

                                    <form method="POST" action="{{ route('landlord.tenants.commercial.override', ['tenant' => $tenant->id]) }}" class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                                        @csrf
                                        <h5 class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-600">Tenant commercial overrides</h5>
                                        <label class="mt-2 block text-xs text-zinc-700">
                                            Template
                                            <select name="template_key" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-900">
                                                <option value="">No template assignment</option>
                                                @foreach ($templates as $template)
                                                    <option value="{{ $template['entry_key'] }}" @selected($template['entry_key'] === $row['template_key'])>{{ $template['name'] }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="mt-2 block text-xs text-zinc-700">
                                            Store/channel allowance
                                            <input name="store_channel_allowance" value="{{ old('store_channel_allowance', $row['store_channel_allowance'] ?? '') }}" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-zinc-900" placeholder="1">
                                        </label>
                                        <label class="mt-2 block text-xs text-zinc-700">
                                            Display labels JSON
                                            <textarea name="display_labels_json" rows="2" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 font-mono text-xs text-zinc-900" placeholder='{"rewards":"Rewards","birthdays":"Lifecycle"}'>{{ old('display_labels_json', (string) ($row['display_labels_json'] ?? '')) }}</textarea>
                                        </label>
                                        <p class="text-[11px] text-zinc-600">
                                            Use a JSON object keyed by module (for example: <code>rewards</code>, <code>birthdays</code>). Invalid keys/values are ignored and fallback labels are used.
                                        </p>
                                        <label class="mt-2 block text-xs text-zinc-700">
                                            Plan pricing overrides JSON
                                            <textarea name="plan_pricing_overrides_json" rows="2" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 font-mono text-xs text-zinc-900" placeholder='{"pro":{"recurring_price_cents":45000}}'>{{ old('plan_pricing_overrides_json', (string) ($row['plan_pricing_overrides_json'] ?? '')) }}</textarea>
                                        </label>
                                        <label class="mt-2 block text-xs text-zinc-700">
                                            Add-on pricing overrides JSON
                                            <textarea name="addon_pricing_overrides_json" rows="2" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 font-mono text-xs text-zinc-900" placeholder='{"sms":{"recurring_price_cents":8900}}'>{{ old('addon_pricing_overrides_json', (string) ($row['addon_pricing_overrides_json'] ?? '')) }}</textarea>
                                        </label>
                                        <label class="mt-2 block text-xs text-zinc-700">
                                            Included usage overrides JSON
                                            <textarea name="included_usage_overrides_json" rows="2" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 font-mono text-xs text-zinc-900" placeholder='{"contact_count":12000,"store_channels":2}'>{{ old('included_usage_overrides_json', (string) ($row['included_usage_overrides_json'] ?? '')) }}</textarea>
                                        </label>
                                        <label class="mt-2 block text-xs text-zinc-700">
                                            Billing mapping JSON (readiness only)
                                            <textarea name="billing_mapping_json" rows="2" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 font-mono text-xs text-zinc-900" placeholder='{"stripe":{"customer_reference":"cus_placeholder","subscription_reference":"sub_placeholder"}}'>{{ old('billing_mapping_json', (string) ($row['billing_mapping_json'] ?? '')) }}</textarea>
                                        </label>
                                        <p class="text-[11px] text-zinc-600">
                                            This field stores future provider mapping metadata only. It does not activate checkout or mutate subscriptions.
                                            Required readiness keys: <code>stripe.customer_reference</code>, <code>stripe.subscription_reference</code>.
                                        </p>
                                        <label class="mt-2 block text-xs text-zinc-700">
                                            Metadata JSON
                                            <textarea name="metadata_json" rows="2" class="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 font-mono text-xs text-zinc-900" placeholder='{"notes":"staging assignment"}'>{{ old('metadata_json', (string) ($row['metadata_json'] ?? '')) }}</textarea>
                                        </label>
                                        <button type="submit" class="mt-3 rounded-md bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-zinc-800">Save overrides</button>
                                        <p class="mt-2 text-[11px] text-zinc-600">If no template is assigned, module labels fall back to entitlement defaults.</p>

                                        @if (filled($row['template_default_labels_json'] ?? null))
                                            <label class="mt-2 block text-xs text-zinc-700">
                                                Template default labels (read-only)
                                                <textarea rows="2" readonly class="mt-1 w-full rounded-md border border-zinc-300 bg-zinc-100 px-2 py-1 font-mono text-xs text-zinc-700">{{ $row['template_default_labels_json'] }}</textarea>
                                            </label>
                                        @endif
                                        @if (filled($row['effective_labels_json'] ?? null))
                                            <label class="mt-2 block text-xs text-zinc-700">
                                                Effective labels (read-only)
                                                <textarea rows="2" readonly class="mt-1 w-full rounded-md border border-zinc-300 bg-zinc-100 px-2 py-1 font-mono text-xs text-zinc-700">{{ $row['effective_labels_json'] }}</textarea>
                                            </label>
                                        @endif
                                    </form>
                                </div>

                                <form method="POST" action="{{ route('landlord.tenants.commercial.billing.stripe.customer-sync', ['tenant' => $tenant->id]) }}" class="mt-4 rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                                    @csrf
                                    <h5 class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-600">Guarded Stripe Customer Sync (Landlord-Only)</h5>
                                    <p class="mt-1 text-[11px] text-zinc-600">
                                        This guarded action creates or syncs only the Stripe customer reference for this tenant. It does not create subscriptions, run checkout, or activate billing lifecycle mutations.
                                    </p>
                                    <div class="mt-2 text-[11px] text-zinc-600">
                                        <div>Current reference: {{ $stripeCustomerReference !== '' ? $stripeCustomerReference : 'none' }}</div>
                                        <div>Action readiness: {{ $stripeCustomerSyncReady ? 'ready' : 'not ready' }}</div>
                                    </div>
                                    <button
                                        type="submit"
                                        @disabled(! $stripeCustomerSyncReady)
                                        class="mt-3 rounded-md bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {{ $stripeCustomerReference !== '' ? 'Sync Stripe Customer Reference' : 'Create Stripe Customer Reference' }}
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('landlord.tenants.commercial.billing.stripe.subscription-prep', ['tenant' => $tenant->id]) }}" class="mt-4 rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                                    @csrf
                                    <h5 class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-600">Guarded Stripe Subscription Prep (Landlord-Only)</h5>
                                    <p class="mt-1 text-[11px] text-zinc-600">
                                        This guarded action syncs subscription-prep metadata only (plan/add-on mapping candidate state). It does not create a live subscription, run checkout, or collect payment methods.
                                    </p>
                                    <div class="mt-2 text-[11px] text-zinc-600">
                                        <div>Customer reference prerequisite: {{ $stripeCustomerReference !== '' ? $stripeCustomerReference : 'missing' }}</div>
                                        <div>Action readiness: {{ $stripeSubscriptionPrepReady ? 'ready' : 'not ready' }}</div>
                                    </div>
                                    <button
                                        type="submit"
                                        @disabled(! $stripeSubscriptionPrepReady)
                                        class="mt-3 rounded-md bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Sync Stripe Subscription Prep State
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('landlord.tenants.commercial.billing.stripe.subscription-live-sync', ['tenant' => $tenant->id]) }}" class="mt-4 rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                                    @csrf
                                    <h5 class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-600">Guarded Stripe Live Subscription Create/Sync (Landlord-Only)</h5>
                                    <p class="mt-1 text-[11px] text-zinc-600">
                                        This guarded action creates a live Stripe subscription reference when missing, or syncs an existing reference. It requires customer sync + subscription prep to be ready first.
                                    </p>
                                    <div class="mt-2 text-[11px] text-zinc-600">
                                        <div>Customer reference prerequisite: {{ $stripeCustomerReference !== '' ? $stripeCustomerReference : 'missing' }}</div>
                                        <div>Subscription prep prerequisite: {{ $stripeSubscriptionPrepLastStatus !== '' ? $stripeSubscriptionPrepLastStatus : 'never' }}</div>
                                        <div>Current subscription reference: {{ $stripeLiveSubscriptionReference !== '' ? $stripeLiveSubscriptionReference : 'none' }}</div>
                                        <div>Action readiness: {{ $stripeLiveSubscriptionSyncReady ? 'ready' : 'not ready' }}</div>
                                    </div>
                                    <button
                                        type="submit"
                                        @disabled(! $stripeLiveSubscriptionSyncReady)
                                        class="mt-3 rounded-md bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {{ $stripeLiveSubscriptionReference !== '' ? 'Sync Stripe Live Subscription Reference' : 'Create Stripe Live Subscription Reference' }}
                                    </button>
                                    <p class="mt-2 text-[11px] text-zinc-600">
                                        Checkout, payment-method collection UX, tenant self-serve billing, and broad update/cancel lifecycle flows remain disabled.
                                    </p>
                                </form>

                                <div class="mt-4 grid gap-4 xl:grid-cols-2">
                                    <div
                                        class="rounded-xl border border-zinc-200 bg-zinc-50 p-4"
                                        x-data="{ moduleOverrideTab: @js($defaultModuleCategoryTab) }"
                                    >
                                        <h5 class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-600">Module overrides</h5>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach ($moduleCategories as $category)
                                                @php
                                                    $categoryKey = (string) ($category['key'] ?? '');
                                                @endphp
                                                <button
                                                    type="button"
                                                    @click="moduleOverrideTab = @js($categoryKey)"
                                                    :class="moduleOverrideTab === @js($categoryKey)
                                                        ? 'border-zinc-900 bg-zinc-900 text-white'
                                                        : 'border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-100'"
                                                    class="rounded-md border px-2.5 py-1 text-[11px] font-medium transition"
                                                >
                                                    {{ $category['label'] }}
                                                </button>
                                            @endforeach
                                        </div>
                                        <div class="mt-2 space-y-3">
                                            @foreach ($moduleCategories as $category)
                                                @php
                                                    $categoryKey = (string) ($category['key'] ?? '');
                                                @endphp
                                                <section
                                                    x-show="moduleOverrideTab === @js($categoryKey)"
                                                    class="rounded-lg border border-zinc-200 bg-white p-3"
                                                >
                                                    <h6 class="text-[11px] font-semibold uppercase tracking-[0.1em] text-zinc-700">{{ $category['label'] }}</h6>
                                                    <p class="mt-1 text-[11px] text-zinc-600">{{ $category['description'] }}</p>
                                                    <div class="mt-2 grid gap-2">
                                                        @foreach ((array) ($category['items'] ?? []) as $module)
                                                            @php
                                                                $moduleKey = (string) ($module['module_key'] ?? '');
                                                                $definition = is_array(data_get($moduleCatalog, $moduleKey))
                                                                    ? (array) data_get($moduleCatalog, $moduleKey)
                                                                    : [];
                                                                $moduleOverride = (array) data_get($row, 'module_overrides.'.$moduleKey, []);
                                                                $enabledOverride = array_key_exists('enabled_override', $moduleOverride) ? $moduleOverride['enabled_override'] : null;
                                                                $setupStatus = (string) ($moduleOverride['setup_status'] ?? 'not_started');
                                                                $effectiveModuleState = is_array(data_get($row, 'resolved_module_states.'.$moduleKey))
                                                                    ? (array) data_get($row, 'resolved_module_states.'.$moduleKey)
                                                                    : [];
                                                            @endphp
                                                            <form method="POST" action="{{ route('landlord.tenants.commercial.modules.update', ['tenant' => $tenant->id, 'moduleKey' => $moduleKey]) }}" class="grid gap-2 md:grid-cols-[minmax(0,1fr)_auto_auto_auto_auto]">
                                                                @csrf
                                                                <div class="text-xs text-zinc-700">{{ $effectiveModuleState['label'] ?? ($definition['label'] ?? $moduleKey) }}</div>
                                                                <select name="enabled_override" class="rounded-md border border-zinc-300 bg-white px-2 py-1 text-xs text-zinc-900">
                                                                    <option value="inherit" @selected($enabledOverride === null)>inherit</option>
                                                                    <option value="enabled" @selected($enabledOverride === true)>enabled</option>
                                                                    <option value="disabled" @selected($enabledOverride === false)>disabled</option>
                                                                </select>
                                                                <select name="setup_status" class="rounded-md border border-zinc-300 bg-white px-2 py-1 text-xs text-zinc-900">
                                                                    @foreach (['not_started', 'in_progress', 'configured', 'blocked'] as $status)
                                                                        <option value="{{ $status }}" @selected($setupStatus === $status)>{{ $status }}</option>
                                                                    @endforeach
                                                                </select>
                                                                <x-tenancy.module-state-badge :module-state="$effectiveModuleState" size="sm" compact />
                                                                <button type="submit" class="rounded-md border border-zinc-300 px-2 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-100">Save</button>
                                                            </form>
                                                        @endforeach
                                                    </div>
                                                </section>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                                        <h5 class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-600">Add-on enablement</h5>
                                        <div class="mt-2 grid gap-2">
                                            @foreach ($addonCatalog as $addonKey => $definition)
                                                @php
                                                    $addonEnabled = (bool) data_get($row, 'addon_states.'.$addonKey, false);
                                                @endphp
                                                <form method="POST" action="{{ route('landlord.tenants.commercial.addons.update', ['tenant' => $tenant->id, 'addonKey' => $addonKey]) }}" class="grid gap-2 md:grid-cols-[1fr_auto_auto]">
                                                    @csrf
                                                    <input type="hidden" name="enabled" value="0">
                                                    <div class="text-xs text-zinc-700">{{ $definition['label'] ?? $addonKey }}</div>
                                                    <label class="inline-flex items-center gap-2 text-xs text-zinc-700">
                                                        <input type="checkbox" name="enabled" value="1" @checked($addonEnabled)>
                                                        enabled
                                                    </label>
                                                    <button type="submit" class="rounded-md border border-zinc-300 px-2 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-100">Save</button>
                                                </form>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-600">
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
        </section>
    </div>
</x-app-layout>
