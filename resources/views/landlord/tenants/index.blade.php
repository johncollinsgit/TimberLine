<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-zinc-900">Tenant Directory</h1>
    </x-slot>

    @php
        $totalTenants = $tenants->count();
        $activeTenants = $tenants->filter(fn (array $row): bool => (string) ($row['tenant_status'] ?? '') === 'active')->count();
        $attentionTenants = $tenants->filter(fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['attention_needed', 'shopify_connection_pending', 'users_pending', 'access_profile_missing'], true))->count();
        $connectedShopifyTenants = $tenants->filter(fn (array $row): bool => (int) ($row['connected_shopify_stores_count'] ?? 0) > 0)->count();
    @endphp

    <div class="space-y-6">
        @if (session('status'))
            <section class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                {{ session('status') }}
            </section>
        @endif

        @if ($errors->any())
            <section class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                <p class="font-semibold">We could not save one or more changes.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-xs">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="grid gap-6 xl:grid-cols-[1.2fr_1fr]">
            <article class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-zinc-500">Landlord</p>
                        <h2 class="mt-2 text-3xl font-semibold text-zinc-950">Tenant Workspace Directory</h2>
                        <p class="mt-2 max-w-2xl text-sm text-zinc-600">
                            Open any tenant to manage role, module access, applications, customers, activity, and performance from one clean workspace.
                        </p>
                    </div>
                    <a href="{{ route('landlord.dashboard') }}" class="rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                        Back to dashboard
                    </a>
                </div>

                <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <article class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                        <p class="text-[11px] uppercase tracking-[0.2em] text-zinc-500">Total tenants</p>
                        <p class="mt-1 text-2xl font-semibold text-zinc-900">{{ number_format($totalTenants) }}</p>
                    </article>
                    <article class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                        <p class="text-[11px] uppercase tracking-[0.2em] text-zinc-500">Active</p>
                        <p class="mt-1 text-2xl font-semibold text-zinc-900">{{ number_format($activeTenants) }}</p>
                    </article>
                    <article class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                        <p class="text-[11px] uppercase tracking-[0.2em] text-zinc-500">Connected Shopify</p>
                        <p class="mt-1 text-2xl font-semibold text-zinc-900">{{ number_format($connectedShopifyTenants) }}</p>
                    </article>
                    <article class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                        <p class="text-[11px] uppercase tracking-[0.2em] text-zinc-500">Needs attention</p>
                        <p class="mt-1 text-2xl font-semibold text-zinc-900">{{ number_format($attentionTenants) }}</p>
                    </article>
                </div>
            </article>

            <article class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h3 class="text-base font-semibold text-zinc-900">Create tenant</h3>
                <p class="mt-1 text-sm text-zinc-600">Short flow with only essential fields. Advanced options stay collapsed.</p>

                <form method="POST" action="{{ route('landlord.tenants.store') }}" class="mt-4 space-y-3">
                    @csrf
                    <label class="block text-sm text-zinc-700">
                        Name
                        <input type="text" name="name" value="{{ old('name') }}" required class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                    </label>
                    <label class="block text-sm text-zinc-700">
                        Primary contact email
                        <input type="email" name="primary_contact_email" value="{{ old('primary_contact_email') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                    </label>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="block text-sm text-zinc-700">
                            Tenant type
                            <select name="tenant_type" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                                @foreach ($tenantTypeOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('tenant_type', $defaultTenantType) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm text-zinc-700">
                            Role
                            <select name="role" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                                @foreach ($tenantRoleOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('role', $defaultTenantRole) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm text-zinc-700">
                            Status
                            <select name="status" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                                @foreach ($tenantStatusOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('status', $defaultTenantStatus) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <details class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
                        <summary class="cursor-pointer text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">Advanced settings</summary>
                        <label class="mt-3 block text-sm text-zinc-700">
                            Slug (optional)
                            <input type="text" name="slug" value="{{ old('slug') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900" placeholder="example-tenant">
                        </label>
                    </details>

                    <div class="flex justify-end">
                        <button type="submit" class="rounded-xl bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800">Create tenant</button>
                    </div>
                </form>
            </article>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-zinc-900">Tenants</h3>
                    <p class="mt-1 text-sm text-zinc-600">Open a tenant to manage modules, role, type, applications, customers, activity, and performance.</p>
                </div>
            </div>

            <form method="GET" action="{{ route('landlord.tenants.index') }}" class="mt-4 flex flex-wrap items-center gap-2 text-sm">
                @foreach (request()->except(['onboarding_filter', 'page']) as $key => $value)
                    @if (is_scalar($value) && $value !== '')
                        <input type="hidden" name="{{ $key }}" value="{{ (string) $value }}" />
                    @endif
                @endforeach
                <label class="flex items-center gap-2 text-sm text-zinc-700">
                    <span class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500">Onboarding</span>
                    <select name="onboarding_filter" class="rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900" onchange="this.form.submit()">
                        @foreach (($onboardingFilterOptions ?? []) as $value => $label)
                            <option value="{{ $value }}" @selected(($activeOnboardingFilter ?? 'all') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <noscript>
                    <button type="submit" class="rounded-xl border border-zinc-300 px-3 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Apply</button>
                </noscript>
            </form>

            <div class="mt-4 overflow-hidden rounded-2xl border border-zinc-200">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1120px] divide-y divide-zinc-200 text-sm">
                        <thead class="bg-zinc-50 text-left text-xs uppercase tracking-[0.12em] text-zinc-500">
                            <tr>
                                <th class="px-4 py-3">Tenant</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Role</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3 text-right">Users</th>
                                <th class="px-4 py-3 text-right">Connected stores</th>
                                <th class="px-4 py-3">Health</th>
                                <th class="px-4 py-3">Onboarding</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 text-zinc-700">
                            @forelse ($tenants as $row)
                                @php
                                    $tenantStatus = (string) ($row['tenant_status'] ?? 'inactive');
                                    $tenantStatusClasses = match ($tenantStatus) {
                                        'active' => 'border-emerald-300 bg-emerald-50 text-emerald-800',
                                        'suspended' => 'border-amber-300 bg-amber-50 text-amber-800',
                                        default => 'border-zinc-300 bg-zinc-50 text-zinc-700',
                                    };

                                    $healthStatus = (string) ($row['status'] ?? 'healthy');
                                    $healthLabel = (string) ($row['status_label'] ?? Str::headline($healthStatus));

                                    $onboarding = is_array($row['onboarding'] ?? null) ? (array) $row['onboarding'] : null;
                                    $onboardingHasTelemetry = (bool) data_get($onboarding, 'has_telemetry', false);
                                    $onboardingBlueprintId = data_get($onboarding, 'selected_blueprint_id');
                                    $onboardingBlueprintId = is_numeric($onboardingBlueprintId) ? (int) $onboardingBlueprintId : null;
                                    $onboardingPhase = (string) data_get($onboarding, 'latest_phase', '');
                                    $onboardingStuck = (string) data_get($onboarding, 'stuck_point', '');
                                    $onboardingSentence = (string) data_get($onboarding, 'status_sentence', $onboardingHasTelemetry ? 'Onboarding telemetry present' : 'No onboarding telemetry yet');
                                @endphp
                                <tr>
                                    <td class="px-4 py-3 align-top">
                                        <a href="{{ route('landlord.tenants.show', ['tenant' => $row['id']]) }}" class="font-semibold text-zinc-900 underline decoration-dotted underline-offset-2">
                                            {{ $row['name'] }}
                                        </a>
                                        <div class="mt-1 text-xs text-zinc-500">{{ $row['subdomain'] }}</div>
                                        @if (! empty($row['primary_contact_email']))
                                            <div class="mt-1 font-mono text-xs text-zinc-500">{{ $row['primary_contact_email'] }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 align-top">{{ $row['tenant_type_label'] }}</td>
                                    <td class="px-4 py-3 align-top">{{ $row['tenant_role_label'] }}</td>
                                    <td class="px-4 py-3 align-top">
                                        <span class="rounded-full border px-2 py-1 text-[11px] font-semibold {{ $tenantStatusClasses }}">{{ $row['tenant_status_label'] }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right align-top font-semibold text-zinc-900">{{ number_format((int) $row['user_count']) }}</td>
                                    <td class="px-4 py-3 text-right align-top font-semibold text-zinc-900">{{ number_format((int) $row['connected_shopify_stores_count']) }}</td>
                                    <td class="px-4 py-3 align-top text-xs">
                                        <div class="font-semibold text-zinc-900">{{ $healthLabel }}</div>
                                        <div class="mt-1 text-zinc-500">Open issues: {{ number_format((int) $row['open_integration_health_events_count']) }}</div>
                                    </td>
                                    <td class="px-4 py-3 align-top text-xs">
                                        <div class="{{ $onboardingHasTelemetry ? 'font-semibold text-zinc-900' : 'text-zinc-500' }}">
                                            {{ $onboardingSentence }}
                                        </div>
                                        <div class="mt-1 flex flex-wrap gap-1.5 text-[11px] font-semibold">
                                            @if ($onboardingPhase !== '')
                                                <span class="rounded-full border border-zinc-200 bg-zinc-50 px-2 py-0.5 text-zinc-700">Phase: {{ str_replace('_', ' ', $onboardingPhase) }}</span>
                                            @endif
                                            @if ($onboardingStuck !== '')
                                                <span class="rounded-full border border-zinc-200 bg-zinc-50 px-2 py-0.5 text-zinc-700">Stuck: {{ str_replace('_', ' ', $onboardingStuck) }}</span>
                                            @endif
                                            @if ($onboardingBlueprintId)
                                                <span class="rounded-full border border-zinc-200 bg-white px-2 py-0.5 font-mono text-zinc-700">{{ '#'.$onboardingBlueprintId }}</span>
                                            @endif
                                        </div>
                                        @if ($onboardingHasTelemetry)
                                            <a
                                                href="{{ route('landlord.tenants.show', array_filter([
                                                    'tenant' => $row['id'],
                                                    'tab' => 'onboarding_journey',
                                                    'final_blueprint_id' => $onboardingBlueprintId,
                                                ], fn ($value): bool => $value !== null)) }}"
                                                class="mt-2 inline-block text-[11px] font-semibold text-zinc-900 underline decoration-dotted underline-offset-2"
                                            >
                                                Open onboarding
                                            </a>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <div class="flex flex-wrap gap-1.5">
                                            <a href="{{ route('landlord.tenants.show', ['tenant' => $row['id'], 'tab' => 'overview']) }}" class="rounded-full border border-zinc-300 px-2.5 py-1 text-[11px] font-semibold text-zinc-700 hover:bg-zinc-100">Workspace</a>
                                            <a href="{{ route('landlord.tenants.show', ['tenant' => $row['id'], 'tab' => 'applications']) }}" class="rounded-full border border-zinc-300 px-2.5 py-1 text-[11px] font-semibold text-zinc-700 hover:bg-zinc-100">Applications</a>
                                            <a href="{{ route('landlord.tenants.show', ['tenant' => $row['id'], 'tab' => 'activity']) }}" class="rounded-full border border-zinc-300 px-2.5 py-1 text-[11px] font-semibold text-zinc-700 hover:bg-zinc-100">Activity</a>
                                            <a href="{{ route('landlord.tenants.show', ['tenant' => $row['id'], 'tab' => 'performance']) }}" class="rounded-full border border-zinc-300 px-2.5 py-1 text-[11px] font-semibold text-zinc-700 hover:bg-zinc-100">Performance</a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-4 py-8 text-center text-sm text-zinc-500">No tenants yet. Create the first tenant from the panel above.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
            <h3 class="text-base font-semibold text-zinc-900">Advanced</h3>
            <p class="mt-1 text-sm text-zinc-600">Legacy operational tooling remains available, but is intentionally secondary.</p>

            <details class="mt-4 rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                <summary class="cursor-pointer text-sm font-semibold text-zinc-900">Open legacy tenant operations context</summary>
                <form method="POST" action="{{ route('landlord.tenants.select') }}" class="mt-4 flex flex-wrap items-center gap-2">
                    @csrf
                    <select name="tenant" class="min-w-[18rem] rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900" @disabled($tenants->isEmpty())>
                        @foreach ($tenants as $row)
                            <option value="{{ $row['id'] }}">{{ $row['name'] }} ({{ $row['slug'] }})</option>
                        @endforeach
                    </select>
                    <button type="submit" class="rounded-xl border border-zinc-300 px-3 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100" @disabled($tenants->isEmpty())>
                        Open legacy operations
                    </button>
                </form>
            </details>
        </section>
    </div>
</x-app-layout>
