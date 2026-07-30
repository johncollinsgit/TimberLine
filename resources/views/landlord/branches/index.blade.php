<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-zinc-900">Branches</h1>
    </x-slot>

    @php
        $payload = is_array($moduleStorePayload ?? null) ? $moduleStorePayload : [];
        $currentPlan = is_array($payload['current_plan'] ?? null) ? $payload['current_plan'] : ['label' => 'Unknown', 'operating_mode' => 'direct'];
        $sectionsPayload = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
        $blueprintRecommendations = is_array($payload['blueprint_recommendations'] ?? null) ? $payload['blueprint_recommendations'] : [];
        $blueprintContext = is_array($blueprintRecommendations['context'] ?? null) ? $blueprintRecommendations['context'] : [];
        $blueprintRows = array_values((array) ($blueprintRecommendations['rows'] ?? []));
        $blueprintSummary = is_array($blueprintRecommendations['summary'] ?? null) ? $blueprintRecommendations['summary'] : [];
        $storeSections = [
            'active' => 'Active now',
            'available' => 'Available to add',
            'upgrade' => 'Upgrade path',
            'request' => 'Request or sales assist',
        ];
        $tenantOptions = collect($tenants ?? []);
        $selectedTenantId = $selectedTenant instanceof \App\Models\Tenant ? (int) $selectedTenant->id : null;
        $focusModule = strtolower(trim((string) request('module', '')));
        $moduleCount = collect((array) ($payload['modules'] ?? []))->count();
    @endphp

    <div class="space-y-6">
        <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <div class="border-b border-zinc-200 bg-gradient-to-br from-emerald-50 via-white to-sky-50 px-6 py-6">
                <div class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-800">Customer preview</p>
                        <h2 class="mt-2 text-3xl font-semibold tracking-tight text-zinc-950">Branches</h2>
                        <p class="mt-2 max-w-4xl text-sm leading-6 text-zinc-600">
                            Read-only landlord view of the same Branch catalog a workspace sees in its Module Store.
                            Use it to inspect copy, setup steps, pricing labels, visibility, and customer-facing next steps before enabling access.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('landlord.commercial.index') }}" class="rounded-xl border border-zinc-300 bg-white px-4 py-2 text-sm font-semibold text-zinc-800 hover:bg-zinc-50">
                            Manage access
                        </a>
                        @if(filled($customerModuleStoreUrl ?? null))
                            <a href="{{ $customerModuleStoreUrl }}" class="rounded-xl bg-zinc-950 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-zinc-800">
                                Open customer Module Store
                            </a>
                        @endif
                    </div>
                </div>
            </div>

            <div class="grid gap-4 border-b border-zinc-200 bg-zinc-50 px-6 py-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                <form method="GET" action="{{ route('landlord.branches.index') }}" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">Preview workspace</span>
                        <select name="tenant" class="mt-2 w-full rounded-xl border-zinc-300 bg-white text-sm text-zinc-900">
                            @forelse($tenantOptions as $tenant)
                                <option value="{{ $tenant->id }}" @selected($selectedTenantId === (int) $tenant->id)>
                                    {{ $tenant->name }} · {{ $tenant->slug }}
                                </option>
                            @empty
                                <option value="">No workspaces yet</option>
                            @endforelse
                        </select>
                    </label>
                    <button class="rounded-xl border border-zinc-300 bg-white px-4 py-2 text-sm font-semibold text-zinc-800 hover:bg-zinc-100">
                        Preview
                    </button>
                </form>

                <div class="flex flex-wrap gap-2 text-xs">
                    <span class="rounded-full border border-zinc-200 bg-white px-3 py-1.5 font-semibold text-zinc-700">
                        {{ $moduleCount }} visible Branch{{ $moduleCount === 1 ? '' : 'es' }}
                    </span>
                    <span class="rounded-full border border-zinc-200 bg-white px-3 py-1.5 font-semibold text-zinc-700">
                        Plan {{ $currentPlan['label'] ?? 'Unknown' }}
                    </span>
                    <span class="rounded-full border border-zinc-200 bg-white px-3 py-1.5 font-semibold text-zinc-700">
                        {{ strtoupper((string) ($currentPlan['operating_mode'] ?? 'direct')) }} setup
                    </span>
                </div>
            </div>
        </section>

        @if(! $selectedTenant instanceof \App\Models\Tenant)
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-950">
                Create or select a workspace before previewing the customer Branch catalog.
                <a href="{{ route('landlord.tenants.create') }}" class="font-semibold underline">Create workspace</a>
            </section>
        @else
            <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-800">Base user lens</p>
                        <h3 class="mt-1 text-lg font-semibold text-zinc-950">{{ $selectedTenant->name }} Branch catalog</h3>
                        <p class="mt-1 max-w-3xl text-sm leading-6 text-zinc-600">
                            This preview uses the selected workspace’s plan, operating mode, module states, and blueprint context.
                            Actions are intentionally disabled here; use Commercial Config for landlord changes or open the customer store for the real customer surface.
                        </p>
                    </div>
                    <a href="{{ route('landlord.commercial.index') }}#tenant-overrides" class="rounded-xl border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-800 hover:bg-zinc-50">
                        Enable or price Branches
                    </a>
                </div>
            </section>

            @if($blueprintRows !== [])
                <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm" data-landlord-branch-blueprint-preview="true">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-700">Setup guidance</p>
                            <h3 class="mt-1 text-lg font-semibold text-zinc-950">Recommended for this customer setup</h3>
                            <p class="mt-1 max-w-3xl text-sm leading-6 text-zinc-600">
                                {{ $blueprintContext['business_template_label'] ?? 'Workspace' }} profile · {{ $blueprintContext['operating_mode_label'] ?? 'Not sure yet' }} setup.
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <span class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700">{{ (int) ($blueprintSummary['recommended'] ?? 0) }} recommended</span>
                            <span class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700">{{ (int) ($blueprintSummary['requested'] ?? 0) }} requested</span>
                            <span class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700">{{ (int) ($blueprintSummary['planned_or_future'] ?? 0) }} planned/future</span>
                        </div>
                    </div>
                    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach(array_slice($blueprintRows, 0, 12) as $row)
                            <article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <h4 class="text-sm font-semibold text-zinc-950">{{ $row['label'] ?? Str::headline((string) ($row['key'] ?? 'module')) }}</h4>
                                        <p class="mt-1 text-xs leading-5 text-zinc-600">{{ $row['reason'] ?? 'Setup recommendation only.' }}</p>
                                    </div>
                                    <span class="rounded-full border border-zinc-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-zinc-700">{{ $row['display_state_label'] ?? 'Planned' }}</span>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif

            @foreach($storeSections as $sectionKey => $sectionLabel)
                @php
                    $modules = is_array($sectionsPayload[$sectionKey] ?? null) ? $sectionsPayload[$sectionKey] : [];
                @endphp

                @if($modules === [])
                    @continue
                @endif

                <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500">Customer catalog section</p>
                            <h3 class="mt-1 text-lg font-semibold text-zinc-950">{{ $sectionLabel }}</h3>
                        </div>
                        <span class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700">{{ count($modules) }} Branch{{ count($modules) === 1 ? '' : 'es' }}</span>
                    </div>

                    <div class="mt-5 grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
                        @foreach($modules as $module)
                            @php
                                $moduleState = is_array($module['module_state'] ?? null) ? $module['module_state'] : [];
                                $moduleKey = (string) ($module['module_key'] ?? '');
                                $isFocused = $focusModule !== '' && $focusModule === $moduleKey;
                                $customerModuleUrl = filled($customerModuleStoreUrl ?? null)
                                    ? $customerModuleStoreUrl.'?module='.urlencode($moduleKey)
                                    : null;
                            @endphp
                            <x-tenancy.module-next-step-card
                                :module="$module"
                                :module-state="$moduleState"
                                :focused="$isFocused"
                            >
                                <span class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700">
                                    Read-only preview
                                </span>
                                @if($customerModuleUrl !== null)
                                    <a href="{{ $customerModuleUrl }}" class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-900 hover:bg-emerald-100">
                                        Open this Branch as customer
                                    </a>
                                @endif
                            </x-tenancy.module-next-step-card>
                        @endforeach
                    </div>
                </section>
            @endforeach
        @endif
    </div>
</x-app-layout>
