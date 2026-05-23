<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-zinc-900">Create Tenant Setup Plan</h1>
    </x-slot>

    @php
        $blueprintOptions = is_array($blueprintOptions ?? null) ? $blueprintOptions : [];
        $accountModes = is_array($blueprintOptions['account_modes'] ?? null) ? $blueprintOptions['account_modes'] : [];
        $businessTemplates = is_array($blueprintOptions['business_templates'] ?? null) ? $blueprintOptions['business_templates'] : [];
        $operatingModes = is_array($blueprintOptions['operating_modes'] ?? null) ? $blueprintOptions['operating_modes'] : [];
        $dataSourcePreferences = is_array($blueprintOptions['data_source_preferences'] ?? null) ? $blueprintOptions['data_source_preferences'] : [];
        $starterModules = is_array($blueprintOptions['starter_modules'] ?? null) ? $blueprintOptions['starter_modules'] : [];
        $workManagementIntents = is_array($blueprintOptions['work_management_intents'] ?? null) ? $blueprintOptions['work_management_intents'] : [];
        $selectedStarterModules = array_values((array) old('starter_modules', []));
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
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-zinc-500">Landlord setup</p>
                    <h2 class="mt-2 text-3xl font-semibold text-zinc-950">Create tenant setup plan</h2>
                    <p class="mt-2 max-w-3xl text-sm text-zinc-600">
                        Pick the business shape and setup path before a tenant starts work. This creates setup/profile details only.
                        It does not install modules, start billing, run imports, or trigger Shopify/Square OAuth.
                    </p>
                </div>
                <a href="{{ route('landlord.tenants.index') }}" class="rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                    Back to tenants
                </a>
            </div>
        </section>

        <form method="POST" action="{{ route('landlord.tenants.store') }}" class="space-y-6">
            @csrf

            <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h3 class="text-base font-semibold text-zinc-950">Workspace identity</h3>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <label class="block text-sm text-zinc-700">
                        Workspace name
                        <input type="text" name="name" value="{{ old('name') }}" required class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                    </label>
                    <label class="block text-sm text-zinc-700">
                        Workspace address
                        <input type="text" name="slug" value="{{ old('slug') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900" placeholder="example-tenant">
                        <span class="mt-1 block text-xs text-zinc-500">Used for <span class="font-mono">workspace.theeverbranch.com</span>. Leave blank to derive from the name.</span>
                    </label>
                    <label class="block text-sm text-zinc-700">
                        Primary contact / owner email
                        <input type="email" name="primary_contact_email" value="{{ old('primary_contact_email') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                        <span class="mt-1 block text-xs text-zinc-500">Captured for operator follow-up. This page does not send invites yet.</span>
                    </label>
                    <label class="block text-sm text-zinc-700">
                        Account mode
                        <select name="account_mode" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                            @foreach ($accountModes as $value => $label)
                                <option value="{{ $value }}" @selected(old('account_mode', $defaultAccountMode ?? 'production') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </section>

            <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h3 class="text-base font-semibold text-zinc-950">Setup profile</h3>
                <div class="mt-4 grid gap-4 lg:grid-cols-3">
                    <label class="block text-sm text-zinc-700">
                        Business template
                        <select name="business_template" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                            @foreach ($businessTemplates as $value => $label)
                                <option value="{{ $value }}" @selected(old('business_template', $defaultBusinessTemplate ?? 'generic') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm text-zinc-700">
                        Operating mode
                        <select name="operating_mode" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                            @foreach ($operatingModes as $value => $label)
                                <option value="{{ $value }}" @selected(old('operating_mode', $defaultOperatingMode ?? 'custom_or_unknown') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm text-zinc-700">
                        Data source preference
                        <select name="data_source_preference" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                            @foreach ($dataSourcePreferences as $value => $label)
                                <option value="{{ $value }}" @selected(old('data_source_preference', $defaultDataSourcePreference ?? 'undecided') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <label class="mt-4 block text-sm text-zinc-700">
                    Primary outcome
                    <input type="text" name="primary_outcome" value="{{ old('primary_outcome') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900" placeholder="What should Everbranch help this business understand or improve?">
                </label>

                <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                    <label class="block text-sm text-zinc-700">
                        Customer label
                        <input type="text" name="customer_label" value="{{ old('customer_label') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900" placeholder="Customer">
                    </label>
                    <label class="block text-sm text-zinc-700">
                        Work label
                        <input type="text" name="work_label" value="{{ old('work_label') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900" placeholder="Job, Order, Matter">
                    </label>
                    <label class="block text-sm text-zinc-700">
                        Money label
                        <input type="text" name="money_label" value="{{ old('money_label') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900" placeholder="Revenue">
                    </label>
                    <label class="block text-sm text-zinc-700">
                        Material/resource label
                        <input type="text" name="material_label" value="{{ old('material_label') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900" placeholder="Materials">
                    </label>
                    <label class="block text-sm text-zinc-700">
                        Stage label
                        <input type="text" name="stage_label" value="{{ old('stage_label') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900" placeholder="Stage">
                    </label>
                </div>
            </section>

            <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h3 class="text-base font-semibold text-zinc-950">Work management needs</h3>
                <p class="mt-1 max-w-3xl text-sm text-zinc-600">
                    Capture whether this tenant will eventually need project/job tracking, tasks, assignments, communication, uploads, or mobile field capture.
                    These are setup signals only. They do not create projects, tasks, messages, uploads, notifications, mobile APIs, modules, or feature access.
                </p>

                <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                    <label class="block text-sm text-zinc-700">
                        Project label
                        <input type="text" name="project_label" value="{{ old('project_label') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900" placeholder="Project, Job, Matter">
                    </label>
                    <label class="block text-sm text-zinc-700">
                        Task label
                        <input type="text" name="task_label" value="{{ old('task_label') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900" placeholder="Task">
                    </label>
                    <label class="block text-sm text-zinc-700">
                        Assignee label
                        <input type="text" name="assignee_label" value="{{ old('assignee_label') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900" placeholder="Assignee">
                    </label>
                    <label class="block text-sm text-zinc-700">
                        Communication label
                        <input type="text" name="communication_label" value="{{ old('communication_label') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900" placeholder="Updates">
                    </label>
                    <label class="block text-sm text-zinc-700">
                        Upload label
                        <input type="text" name="upload_label" value="{{ old('upload_label') }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900" placeholder="Files / Photos">
                    </label>
                </div>

                <div class="mt-4 grid gap-2 md:grid-cols-2 xl:grid-cols-4">
                    @foreach ($workManagementIntents as $value => $label)
                        <label class="flex items-start gap-2 rounded-xl border border-zinc-200 px-3 py-2 text-sm text-zinc-700">
                            <input type="checkbox" name="{{ $value }}" value="1" class="mt-1" @checked((bool) old($value, false))>
                            <span>
                                <span class="block font-semibold text-zinc-900">{{ $label }}</span>
                                <span class="mt-0.5 block text-xs text-zinc-500">Requested/planned only; no live module is activated here.</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <label class="mt-4 block text-sm text-zinc-700">
                    Work management notes
                    <textarea name="work_management_notes" rows="3" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900" placeholder="What project/task/communication/upload needs should Everbranch review?">{{ old('work_management_notes') }}</textarea>
                </label>
            </section>

            <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h3 class="text-base font-semibold text-zinc-950">Starter recommendations</h3>
                <p class="mt-1 text-sm text-zinc-600">
                    These are recommendations only. Saving this setup plan does not install modules, grant paid access, or activate checkout.
                </p>
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
                        <textarea name="setup_notes" rows="4" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">{{ old('setup_notes') }}</textarea>
                    </label>
                    <label class="block text-sm text-zinc-700">
                        Onboarding next action
                        <textarea name="onboarding_next_action" rows="4" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">{{ old('onboarding_next_action') }}</textarea>
                    </label>
                </div>
            </section>

            <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h3 class="text-base font-semibold text-zinc-950">Landlord defaults</h3>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <label class="block text-sm text-zinc-700">
                        Default tenant role
                        <select name="role" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                            @foreach ($tenantRoleOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('role', $defaultTenantRole ?? 'manager') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm text-zinc-700">
                        Tenant status
                        <select name="status" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                            @foreach ($tenantStatusOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('status', $defaultTenantStatus ?? 'active') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </section>

            <div class="flex flex-wrap items-center justify-end gap-3">
                <a href="{{ route('landlord.tenants.index') }}" class="rounded-xl border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Cancel</a>
                <button type="submit" class="rounded-xl bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800">Create tenant setup plan</button>
            </div>
        </form>
    </div>
</x-app-layout>
