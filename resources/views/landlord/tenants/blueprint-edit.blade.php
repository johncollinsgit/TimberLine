<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-zinc-900">Edit Tenant Setup Plan</h1>
    </x-slot>

    @php
        $blueprintOptions = is_array($blueprintOptions ?? null) ? $blueprintOptions : [];
        $tenantBlueprint = is_array($tenantBlueprint ?? null) ? $tenantBlueprint : [];
        $testAccess = is_array($testAccess ?? null) ? $testAccess : [];
        $accountModes = is_array($blueprintOptions['account_modes'] ?? null) ? $blueprintOptions['account_modes'] : [];
        $businessTemplates = is_array($blueprintOptions['business_templates'] ?? null) ? $blueprintOptions['business_templates'] : [];
        $operatingModes = is_array($blueprintOptions['operating_modes'] ?? null) ? $blueprintOptions['operating_modes'] : [];
        $dataSourcePreferences = is_array($blueprintOptions['data_source_preferences'] ?? null) ? $blueprintOptions['data_source_preferences'] : [];
        $starterModules = is_array($blueprintOptions['starter_modules'] ?? null) ? $blueprintOptions['starter_modules'] : [];
        $workManagementIntents = is_array($blueprintOptions['work_management_intents'] ?? null) ? $blueprintOptions['work_management_intents'] : [];
        $reviewStatuses = is_array($blueprintOptions['blueprint_review_statuses'] ?? null) ? $blueprintOptions['blueprint_review_statuses'] : [];
        $selectedStarterModules = array_values((array) old('starter_modules', $tenantBlueprint['starter_modules'] ?? []));
        $accountMode = (string) ($tenantBlueprint['account_mode'] ?? 'production');
    @endphp

    <div class="space-y-6">
        @if ($errors->any())
            <section class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                <p class="font-semibold">We could not save the tenant setup plan.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-xs">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-zinc-500">Landlord setup review</p>
                    <h2 class="mt-2 text-3xl font-semibold text-zinc-950">Edit Tenant Setup Plan: {{ $tenant->name }}</h2>
                    <p class="mt-2 max-w-3xl text-sm text-zinc-600">
                        Update presentation labels, setup path, starter recommendations, and work-management intent for this tenant.
                        This remains setup guidance only; it does not activate billing, modules, imports, OAuth, uploads, messaging, or mobile APIs.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-700">
                        {{ $testAccess['lane_label'] ?? ($tenantBlueprint['account_mode_label'] ?? 'Tenant') }}
                    </span>
                    <a href="{{ route('landlord.tenants.show', ['tenant' => $tenant->id]) }}" class="rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                        Back to tenant
                    </a>
                </div>
            </div>

            @if (! empty($testAccess['warning']))
                <p class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    {{ $testAccess['warning'] }}
                </p>
            @endif
        </section>

        <form method="POST" action="{{ route('landlord.tenants.blueprint.update', ['tenant' => $tenant->id]) }}" class="space-y-6">
            @csrf
            @method('PATCH')
            <input type="hidden" name="account_mode" value="{{ $accountMode }}">

            <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold text-zinc-950">Blueprint profile</h3>
                        <p class="mt-1 text-sm text-zinc-600">Templates change labels and recommendations only. They do not create separate industry route systems.</p>
                    </div>
                    <span class="rounded-full border border-zinc-300 bg-zinc-50 px-3 py-1 text-xs font-semibold text-zinc-700">
                        Account mode: {{ $accountModes[$accountMode] ?? str_replace('_', ' ', $accountMode) }}
                    </span>
                </div>

                <div class="mt-4 grid gap-4 lg:grid-cols-3">
                    <label class="block text-sm text-zinc-700">
                        Business template
                        <select name="business_template" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                            @foreach ($businessTemplates as $value => $label)
                                <option value="{{ $value }}" @selected(old('business_template', $tenantBlueprint['business_template'] ?? 'generic') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm text-zinc-700">
                        Operating mode
                        <select name="operating_mode" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                            @foreach ($operatingModes as $value => $label)
                                <option value="{{ $value }}" @selected(old('operating_mode', $tenantBlueprint['operating_mode'] ?? 'custom_or_unknown') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm text-zinc-700">
                        Data source preference
                        <select name="data_source_preference" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                            @foreach ($dataSourcePreferences as $value => $label)
                                <option value="{{ $value }}" @selected(old('data_source_preference', $tenantBlueprint['data_source_preference'] ?? 'undecided') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <label class="mt-4 block text-sm text-zinc-700">
                    Primary outcome
                    <input type="text" name="primary_outcome" value="{{ old('primary_outcome', $tenantBlueprint['primary_outcome'] ?? '') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                </label>

                <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                    @foreach ([
                        'customer_label' => 'Customer label',
                        'work_label' => 'Work label',
                        'money_label' => 'Money label',
                        'material_label' => 'Material/resource label',
                        'stage_label' => 'Stage label',
                    ] as $field => $label)
                        <label class="block text-sm text-zinc-700">
                            {{ $label }}
                            <input type="text" name="{{ $field }}" value="{{ old($field, $tenantBlueprint[$field] ?? '') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                        </label>
                    @endforeach
                </div>
            </section>

            <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h3 class="text-base font-semibold text-zinc-950">Work management needs</h3>
                <p class="mt-1 max-w-3xl text-sm text-zinc-600">
                    These are requested/planned setup signals only. Saving this form does not create projects, tasks, assignments, messages, uploads, storage, notifications, mobile capture, modules, or feature access.
                </p>

                <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                    @foreach ([
                        'project_label' => 'Project label',
                        'task_label' => 'Task label',
                        'assignee_label' => 'Assignee label',
                        'communication_label' => 'Communication label',
                        'upload_label' => 'Upload label',
                    ] as $field => $label)
                        <label class="block text-sm text-zinc-700">
                            {{ $label }}
                            <input type="text" name="{{ $field }}" value="{{ old($field, $tenantBlueprint[$field] ?? '') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                        </label>
                    @endforeach
                </div>

                <div class="mt-4 grid gap-2 md:grid-cols-2 xl:grid-cols-4">
                    @foreach ($workManagementIntents as $value => $label)
                        <label class="flex items-start gap-2 rounded-xl border border-zinc-200 px-3 py-2 text-sm text-zinc-700">
                            <input type="hidden" name="{{ $value }}" value="0">
                            <input type="checkbox" name="{{ $value }}" value="1" class="mt-1" @checked((bool) old($value, data_get($tenantBlueprint, 'work_management_intent.'.$value, false)))>
                            <span>
                                <span class="block font-semibold text-zinc-900">{{ $label }}</span>
                                <span class="mt-0.5 block text-xs text-zinc-500">Requested/planned only; no live module is activated here.</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <label class="mt-4 block text-sm text-zinc-700">
                    Work management notes
                    <textarea name="work_management_notes" rows="3" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">{{ old('work_management_notes', $tenantBlueprint['work_management_notes'] ?? '') }}</textarea>
                </label>
            </section>

            <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h3 class="text-base font-semibold text-zinc-950">Starter recommendations</h3>
                <p class="mt-1 text-sm text-zinc-600">Recommendations only. Saving this setup plan does not install modules, grant paid access, or activate checkout.</p>
                <div class="mt-4 grid gap-2 md:grid-cols-2 xl:grid-cols-4">
                    @foreach ($starterModules as $value => $label)
                        <label class="flex items-center gap-2 rounded-xl border border-zinc-200 px-3 py-2 text-sm text-zinc-700">
                            <input type="checkbox" name="starter_modules[]" value="{{ $value }}" @checked(in_array($value, $selectedStarterModules, true))>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <label class="block text-sm text-zinc-700">
                        Setup notes
                        <textarea name="setup_notes" rows="4" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">{{ old('setup_notes', $tenantBlueprint['setup_notes'] ?? '') }}</textarea>
                    </label>
                    <label class="block text-sm text-zinc-700">
                        Tenant-facing onboarding next action
                        <textarea name="onboarding_next_action" rows="4" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">{{ old('onboarding_next_action', $tenantBlueprint['onboarding_next_action'] ?? '') }}</textarea>
                        <span class="mt-1 block text-xs text-zinc-500">This may appear as setup guidance on tenant Start Here.</span>
                    </label>
                </div>
            </section>

            <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h3 class="text-base font-semibold text-zinc-950">Landlord review</h3>
                <p class="mt-1 text-sm text-zinc-600">Internal review context stays landlord-only. It is not shown on tenant Start Here.</p>
                <div class="mt-4 grid gap-4 lg:grid-cols-3">
                    <label class="block text-sm text-zinc-700">
                        Review status
                        <select name="blueprint_review_status" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                            @foreach ($reviewStatuses as $value => $label)
                                <option value="{{ $value }}" @selected(old('blueprint_review_status', $tenantBlueprint['blueprint_review_status'] ?? 'unreviewed') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm text-zinc-700 lg:col-span-2">
                        Internal next action
                        <input type="text" name="blueprint_next_action" value="{{ old('blueprint_next_action', $tenantBlueprint['blueprint_next_action'] ?? '') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                    </label>
                </div>
                <label class="mt-4 block text-sm text-zinc-700">
                    Internal notes
                    <textarea name="blueprint_internal_notes" rows="4" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">{{ old('blueprint_internal_notes', $tenantBlueprint['blueprint_internal_notes'] ?? '') }}</textarea>
                </label>
            </section>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-zinc-500">No billing, checkout, module, import, OAuth, upload, messaging, mobile API, or privacy deletion behavior is activated by this form.</p>
                <div class="flex flex-wrap justify-end gap-3">
                    <a href="{{ route('landlord.tenants.show', ['tenant' => $tenant->id]) }}" class="rounded-xl border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Cancel</a>
                    <button type="submit" class="rounded-xl bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800">Save setup review</button>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
