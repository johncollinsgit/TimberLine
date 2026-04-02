<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Tenant Detail</h1>
    </x-slot>

    <div class="fb-page-canvas">
        @if (session('status'))
            <section class="rounded-2xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ session('status') }}
            </section>
        @endif

        <section class="rounded-3xl border border-zinc-200 bg-white shadow-sm p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="text-[11px] uppercase tracking-[0.35em] text-zinc-500">Tenant</div>
                    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">{{ $summary['name'] }}</div>
                    <p class="mt-2 text-sm text-zinc-600">
                        Slug: <span class="font-mono text-xs">{{ $summary['slug'] }}</span>
                        <span class="mx-2 text-zinc-400">•</span>
                        Subdomain: <span class="font-mono text-xs">{{ $summary['subdomain'] }}</span>
                    </p>
                </div>
                <div class="text-right">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Derived Status</div>
                    <div class="mt-1 text-lg font-semibold text-zinc-950">{{ $summary['status_label'] }}</div>
                    <div class="mt-1 text-xs text-zinc-500">{{ $summary['status'] }}</div>
                </div>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-3xl border border-zinc-200 bg-white shadow-sm p-5">
                <p class="text-xs uppercase tracking-[0.2em] text-zinc-500">Created</p>
                <p class="mt-2 text-sm font-semibold text-zinc-950">{{ $summary['created_at'] ?? 'n/a' }}</p>
            </article>
            <article class="rounded-3xl border border-zinc-200 bg-white shadow-sm p-5">
                <p class="text-xs uppercase tracking-[0.2em] text-zinc-500">Users</p>
                <p class="mt-2 text-3xl font-semibold text-zinc-950">{{ number_format((int) $summary['user_count']) }}</p>
            </article>
            <article class="rounded-3xl border border-zinc-200 bg-white shadow-sm p-5">
                <p class="text-xs uppercase tracking-[0.2em] text-zinc-500">Connected Shopify Stores</p>
                <p class="mt-2 text-3xl font-semibold text-zinc-950">{{ number_format((int) $summary['connected_shopify_stores_count']) }}</p>
                <p class="mt-1 text-xs text-zinc-500">Total rows: {{ number_format((int) $summary['shopify_stores_count']) }}</p>
            </article>
            <article class="rounded-3xl border border-zinc-200 bg-white shadow-sm p-5">
                <p class="text-xs uppercase tracking-[0.2em] text-zinc-500">Open Integration Issues</p>
                <p class="mt-2 text-3xl font-semibold text-zinc-950">{{ number_format((int) $summary['open_integration_health_events_count']) }}</p>
            </article>
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            <article class="rounded-3xl border border-zinc-200 bg-white shadow-sm p-6">
                <h2 class="text-lg font-semibold text-zinc-950">Access Profile</h2>
                <dl class="mt-4 space-y-3 text-sm text-zinc-700">
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-500">Plan key</dt>
                        <dd class="font-mono text-xs">{{ $summary['access_profile']['plan_key'] ?? 'n/a' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-500">Operating mode</dt>
                        <dd class="font-mono text-xs">{{ $summary['access_profile']['operating_mode'] ?? 'n/a' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-500">Profile source</dt>
                        <dd class="font-mono text-xs">{{ $summary['access_profile']['source'] ?? 'n/a' }}</dd>
                    </div>
                </dl>
            </article>

            <article class="rounded-3xl border border-zinc-200 bg-white shadow-sm p-6">
                <h2 class="text-lg font-semibold text-zinc-950">Module Setup Indicators</h2>
                <dl class="mt-4 space-y-3 text-sm text-zinc-700">
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-500">Configured</dt>
                        <dd>{{ (int) ($summary['module_setup']['configured'] ?? 0) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-500">In progress</dt>
                        <dd>{{ (int) ($summary['module_setup']['in_progress'] ?? 0) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-500">Not started</dt>
                        <dd>{{ (int) ($summary['module_setup']['not_started'] ?? 0) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-500">Other</dt>
                        <dd>{{ (int) ($summary['module_setup']['other'] ?? 0) }}</dd>
                    </div>
                </dl>
            </article>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-white shadow-sm p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-zinc-950">Guarded Tenant Operations</h2>
                    <p class="mt-1 text-sm text-zinc-600">
                        Every action requires explicit tenant confirmation and writes an immutable landlord operator audit record.
                    </p>
                </div>
                <a
                    href="{{ route('landlord.tenants.index') }}"
                    class="inline-flex items-center rounded-full border border-zinc-300 bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800"
                >
                    Switch Tenant
                </a>
            </div>

            <div class="mt-4 rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-xs text-zinc-700">
                <div>Tenant confirmation phrase: <span class="font-mono text-zinc-800">{{ $tenantConfirmationPhrase }}</span></div>
                <div class="mt-1">Restore apply phrase: <span class="font-mono text-zinc-800">{{ $tenantApplyRestorePhrase }}</span></div>
                <div class="mt-1">Restore overwrite phrase: <span class="font-mono text-zinc-800">{{ $tenantOverwritePhrase }}</span></div>
                <div class="mt-1">Selected tenant id: <span class="font-mono text-zinc-800">{{ (int) $tenant->id }}</span></div>
                <div class="mt-1">Selected tenant slug: <span class="font-mono text-zinc-800">{{ $tenant->slug }}</span></div>
                <div class="mt-1">Snapshot retention: <span class="font-mono text-zinc-800">{{ (int) $snapshotRetentionDays }} days</span></div>
                <div class="mt-1">Snapshot max upload size: <span class="font-mono text-zinc-800">{{ number_format((int) $snapshotMaxBytes) }} bytes</span></div>
            </div>

            <div class="mt-5 grid gap-6 xl:grid-cols-2">
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <h3 class="text-sm font-semibold text-zinc-950">Backup / Export Snapshot</h3>
                    <p class="mt-1 text-xs text-zinc-600">
                        Exports tenant-owned marketing/customer snapshot data only for this tenant context.
                    </p>

                    <details class="mt-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-[11px] text-zinc-600">
                        <summary class="cursor-pointer font-semibold text-zinc-800">Snapshot scope tables (MVP)</summary>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($snapshotTables as $tableName)
                                <li class="font-mono">{{ $tableName }}</li>
                            @endforeach
                        </ul>
                    </details>

                    <form method="POST" action="{{ route('landlord.tenants.operations.export', ['tenant' => $tenant->id]) }}" class="mt-3 space-y-3">
                        @csrf
                        <input type="hidden" name="tenant_id" value="{{ (int) $tenant->id }}">
                        <input type="hidden" name="tenant_slug" value="{{ $tenant->slug }}">

                        <label class="block text-xs text-zinc-800">
                            Confirmation phrase
                            <input
                                type="text"
                                name="confirm_phrase"
                                class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                                placeholder="{{ $tenantConfirmationPhrase }}"
                                required
                            >
                        </label>
                        <label class="block text-xs text-zinc-800">
                            Export reason
                            <input
                                type="text"
                                name="reason"
                                maxlength="500"
                                minlength="8"
                                class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                                placeholder="operator reason for export"
                                required
                            >
                        </label>
                        <label class="flex items-center gap-2 text-xs text-zinc-700">
                            <input type="checkbox" name="confirm_export" value="1" class="rounded border-zinc-300 bg-white">
                            I confirm this export should run for this tenant only.
                        </label>

                        @error('tenant_operations_export')
                            <p class="text-xs text-rose-700">{{ $message }}</p>
                        @enderror
                        @error('confirm_phrase')
                            <p class="text-xs text-rose-700">{{ $message }}</p>
                        @enderror
                        @error('reason')
                            <p class="text-xs text-rose-700">{{ $message }}</p>
                        @enderror

                        <button
                            type="submit"
                            class="inline-flex items-center rounded-lg border border-zinc-300 bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800"
                        >
                            Create Tenant Snapshot
                        </button>
                    </form>
                </article>

                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <h3 class="text-sm font-semibold text-zinc-950">Restore / Import Snapshot</h3>
                    <p class="mt-1 text-xs text-zinc-600">
                        Bounded MVP: restore from this snapshot format into the explicitly selected tenant only.
                    </p>

                    <form
                        method="POST"
                        action="{{ route('landlord.tenants.operations.restore', ['tenant' => $tenant->id]) }}"
                        enctype="multipart/form-data"
                        class="mt-3 space-y-3"
                    >
                        @csrf
                        <input type="hidden" name="tenant_id" value="{{ (int) $tenant->id }}">
                        <input type="hidden" name="tenant_slug" value="{{ $tenant->slug }}">

                        <label class="block text-xs text-zinc-800">
                            Snapshot file (JSON)
                            <input
                                type="file"
                                name="snapshot_file"
                                accept=".json,text/json,application/json,text/plain"
                                class="mt-1 block w-full text-xs text-zinc-700"
                                required
                            >
                        </label>
                        <label class="block text-xs text-zinc-800">
                            Confirmation phrase
                            <input
                                type="text"
                                name="confirm_phrase"
                                class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                                placeholder="{{ $tenantConfirmationPhrase }}"
                                required
                            >
                        </label>
                        <label class="block text-xs text-zinc-800">
                            Restore reason
                            <input
                                type="text"
                                name="reason"
                                maxlength="500"
                                minlength="8"
                                class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                                placeholder="operator reason for restore or dry-run"
                                required
                            >
                        </label>
                        <label class="flex items-center gap-2 text-xs text-zinc-700">
                            <input type="checkbox" name="dry_run" value="1" class="rounded border-zinc-300 bg-white">
                            Run as dry-run only (preview counts, no mutations).
                        </label>
                        <label class="flex items-center gap-2 text-xs text-zinc-700">
                            <input type="checkbox" name="confirm_restore" value="1" class="rounded border-zinc-300 bg-white">
                            I confirm this restore targets only tenant {{ $tenant->slug }}.
                        </label>
                        <label class="block text-xs text-zinc-800">
                            Apply phrase (required when dry-run is off)
                            <input
                                type="text"
                                name="apply_phrase"
                                class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                                placeholder="{{ $tenantApplyRestorePhrase }}"
                            >
                        </label>
                        <label class="flex items-center gap-2 text-xs text-zinc-700">
                            <input type="checkbox" name="allow_overwrite" value="1" class="rounded border-zinc-300 bg-white">
                            Allow overwrite of existing row ids.
                        </label>
                        <label class="flex items-center gap-2 text-xs text-zinc-700">
                            <input type="checkbox" name="confirm_overwrite" value="1" class="rounded border-zinc-300 bg-white">
                            I understand overwrite mode mutates existing rows in the selected tenant.
                        </label>
                        <label class="block text-xs text-zinc-800">
                            Overwrite phrase (required when overwrite is enabled)
                            <input
                                type="text"
                                name="overwrite_phrase"
                                class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                                placeholder="{{ $tenantOverwritePhrase }}"
                            >
                        </label>

                        @error('tenant_operations_restore')
                            <p class="text-xs text-rose-700">{{ $message }}</p>
                        @enderror
                        @error('reason')
                            <p class="text-xs text-rose-700">{{ $message }}</p>
                        @enderror
                        @error('apply_phrase')
                            <p class="text-xs text-rose-700">{{ $message }}</p>
                        @enderror
                        @error('confirm_overwrite')
                            <p class="text-xs text-rose-700">{{ $message }}</p>
                        @enderror
                        @error('overwrite_phrase')
                            <p class="text-xs text-rose-700">{{ $message }}</p>
                        @enderror
                        @error('snapshot_file')
                            <p class="text-xs text-rose-700">{{ $message }}</p>
                        @enderror

                        <button
                            type="submit"
                            class="inline-flex items-center rounded-lg border border-zinc-300 bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800"
                        >
                            Restore Tenant Snapshot
                        </button>
                    </form>
                </article>

                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <h3 class="text-sm font-semibold text-zinc-950">Modify Customer (Bounded Fields)</h3>
                    <p class="mt-1 text-xs text-zinc-600">
                        Updates only selected profile fields inside this tenant. No global profile mutation path is allowed.
                    </p>

                    <form method="POST" action="{{ route('landlord.tenants.operations.customers.modify', ['tenant' => $tenant->id]) }}" class="mt-3 space-y-3">
                        @csrf
                        <input type="hidden" name="tenant_id" value="{{ (int) $tenant->id }}">
                        <input type="hidden" name="tenant_slug" value="{{ $tenant->slug }}">

                        <label class="block text-xs text-zinc-800">
                            Customer profile id
                            <input type="number" name="profile_id" min="1" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900" required>
                        </label>
                        <label class="block text-xs text-zinc-800">
                            Confirm profile id
                            <input type="text" name="confirm_profile_id" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900" placeholder="type the same profile id" required>
                        </label>
                        <label class="block text-xs text-zinc-800">
                            Reason
                            <input type="text" name="reason" maxlength="500" minlength="8" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900" required>
                        </label>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label class="text-xs text-zinc-800">
                                First name
                                <input type="text" name="first_name" maxlength="120" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900">
                            </label>
                            <label class="text-xs text-zinc-800">
                                Last name
                                <input type="text" name="last_name" maxlength="120" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900">
                            </label>
                            <label class="text-xs text-zinc-800">
                                Email
                                <input type="email" name="email" maxlength="255" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900">
                            </label>
                            <label class="text-xs text-zinc-800">
                                Phone
                                <input type="text" name="phone" maxlength="40" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900">
                            </label>
                        </div>
                        <label class="block text-xs text-zinc-800">
                            Notes
                            <textarea name="notes" rows="3" maxlength="4000" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"></textarea>
                        </label>
                        <div class="flex flex-wrap gap-4 text-xs text-zinc-700">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="accepts_email_marketing" value="1" class="rounded border-zinc-300 bg-white">
                                Set accepts email marketing = true
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="accepts_sms_marketing" value="1" class="rounded border-zinc-300 bg-white">
                                Set accepts SMS marketing = true
                            </label>
                        </div>
                        <label class="block text-xs text-zinc-800">
                            Confirmation phrase
                            <input type="text" name="confirm_phrase" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900" placeholder="{{ $tenantConfirmationPhrase }}" required>
                        </label>
                        <label class="flex items-center gap-2 text-xs text-zinc-700">
                            <input type="checkbox" name="confirm_modify" value="1" class="rounded border-zinc-300 bg-white">
                            I confirm this customer modify action is scoped to this tenant.
                        </label>

                        @error('tenant_operations_customer_modify')
                            <p class="text-xs text-rose-700">{{ $message }}</p>
                        @enderror
                        @error('confirm_profile_id')
                            <p class="text-xs text-rose-700">{{ $message }}</p>
                        @enderror

                        <button
                            type="submit"
                            class="inline-flex items-center rounded-lg border border-zinc-300 bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800"
                        >
                            Apply Customer Modify Action
                        </button>
                    </form>
                </article>

                <article class="rounded-2xl border border-rose-300 bg-rose-50 p-4">
                    <h3 class="text-sm font-semibold text-zinc-950">Delete Customer (Safe Archive)</h3>
                    <p class="mt-1 text-xs text-rose-700">
                        This workflow uses archive/redaction safeguards and audit traceability instead of hard-delete.
                    </p>

                    <form method="POST" action="{{ route('landlord.tenants.operations.customers.archive', ['tenant' => $tenant->id]) }}" class="mt-3 space-y-3">
                        @csrf
                        <input type="hidden" name="tenant_id" value="{{ (int) $tenant->id }}">
                        <input type="hidden" name="tenant_slug" value="{{ $tenant->slug }}">

                        <label class="block text-xs text-rose-700">
                            Customer profile id
                            <input type="number" name="profile_id" min="1" class="mt-1 w-full rounded-lg border border-rose-300 bg-white px-3 py-2 text-sm text-rose-700" required>
                        </label>
                        <label class="block text-xs text-rose-700">
                            Confirm profile id
                            <input type="text" name="confirm_profile_id" class="mt-1 w-full rounded-lg border border-rose-300 bg-white px-3 py-2 text-sm text-rose-700" placeholder="type the same profile id" required>
                        </label>
                        <label class="block text-xs text-rose-700">
                            Deletion reason
                            <input type="text" name="reason" maxlength="500" minlength="8" class="mt-1 w-full rounded-lg border border-rose-300 bg-white px-3 py-2 text-sm text-rose-700" required>
                        </label>
                        <label class="block text-xs text-rose-700">
                            Confirmation phrase
                            <input type="text" name="confirm_phrase" class="mt-1 w-full rounded-lg border border-rose-300 bg-white px-3 py-2 text-sm text-rose-700" placeholder="{{ $tenantConfirmationPhrase }}" required>
                        </label>
                        <label class="flex items-center gap-2 text-xs text-rose-700">
                            <input type="checkbox" name="confirm_delete" value="1" class="rounded border-rose-300 bg-white">
                            I understand this action archives/redacts customer identity fields for this tenant.
                        </label>

                        @error('tenant_operations_customer_archive')
                            <p class="text-xs text-rose-700">{{ $message }}</p>
                        @enderror
                        @error('confirm_profile_id')
                            <p class="text-xs text-rose-700">{{ $message }}</p>
                        @enderror

                        <button
                            type="submit"
                            class="inline-flex items-center rounded-lg border border-rose-300 bg-rose-100 px-4 py-2 text-xs font-semibold text-rose-700"
                        >
                            Run Safe Delete (Archive)
                        </button>
                    </form>
                </article>
            </div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-white shadow-sm p-6">
            <h2 class="text-lg font-semibold text-zinc-950">Recent Tenant Customers (Quick Lookup)</h2>
            <p class="mt-1 text-sm text-zinc-600">
                Use profile ids from this list when running landlord customer modify/delete actions.
            </p>
            <div class="mt-4 overflow-hidden rounded-2xl border border-zinc-200">
                <table class="w-full divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50 text-left text-xs uppercase tracking-[0.12em] text-zinc-500">
                        <tr>
                            <th class="px-4 py-3">Profile id</th>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Email</th>
                            <th class="px-4 py-3">Phone</th>
                            <th class="px-4 py-3">Updated</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 text-zinc-700">
                        @forelse ($recentTenantCustomers as $profile)
                            <tr>
                                <td class="px-4 py-3 font-mono text-xs">{{ (int) $profile->id }}</td>
                                <td class="px-4 py-3">{{ trim((string) ($profile->first_name . ' ' . $profile->last_name)) ?: 'n/a' }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $profile->email ?: 'n/a' }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $profile->phone ?: 'n/a' }}</td>
                                <td class="px-4 py-3">{{ optional($profile->updated_at)->toDateTimeString() ?? 'n/a' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-5 text-sm text-zinc-500">No tenant customers found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-white shadow-sm p-6">
            <h2 class="text-lg font-semibold text-zinc-950">Operator Action Trace</h2>
            <p class="mt-1 text-sm text-zinc-600">
                Append-only operator records for export/restore/customer actions in this tenant context.
            </p>
            <div class="mt-4 grid gap-3 sm:grid-cols-4">
                <article class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
                    <p class="text-[11px] uppercase tracking-[0.12em] text-zinc-500">Recent total</p>
                    <p class="mt-1 text-lg font-semibold text-zinc-950">{{ (int) ($operatorActionSummary['total'] ?? 0) }}</p>
                </article>
                <article class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
                    <p class="text-[11px] uppercase tracking-[0.12em] text-zinc-500">Success</p>
                    <p class="mt-1 text-lg font-semibold text-zinc-800">{{ (int) ($operatorActionSummary['success'] ?? 0) }}</p>
                </article>
                <article class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
                    <p class="text-[11px] uppercase tracking-[0.12em] text-zinc-500">Blocked</p>
                    <p class="mt-1 text-lg font-semibold text-amber-700">{{ (int) ($operatorActionSummary['blocked'] ?? 0) }}</p>
                </article>
                <article class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
                    <p class="text-[11px] uppercase tracking-[0.12em] text-zinc-500">Failed</p>
                    <p class="mt-1 text-lg font-semibold text-rose-700">{{ (int) ($operatorActionSummary['failed'] ?? 0) }}</p>
                </article>
            </div>
            <div class="mt-4 overflow-hidden rounded-2xl border border-zinc-200">
                <table class="w-full divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50 text-left text-xs uppercase tracking-[0.12em] text-zinc-500">
                        <tr>
                            <th class="px-4 py-3">When</th>
                            <th class="px-4 py-3">Action</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Actor</th>
                            <th class="px-4 py-3">Target</th>
                            <th class="px-4 py-3">Artifact</th>
                            <th class="px-4 py-3">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 text-zinc-700">
                        @forelse ($recentOperatorActions as $action)
                            @php
                                $result = (array) ($action->result ?? []);
                                $confirmation = (array) ($action->confirmation ?? []);
                                $artifactFile = trim((string) ($result['artifact_file_name'] ?? ''));
                                $errorMessage = trim((string) ($result['error'] ?? ''));
                                $modeLabel = trim((string) ($result['mode'] ?? ''));
                                $expiresAt = trim((string) ($result['expires_at'] ?? ''));
                            @endphp
                            <tr>
                                <td class="px-4 py-3 text-xs">{{ optional($action->created_at)->toDateTimeString() ?? 'n/a' }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $action->action_type }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full border border-zinc-300 px-2 py-0.5 text-[11px]">
                                        {{ $action->status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs">{{ $action->actor_user_id ?: 'n/a' }}</td>
                                <td class="px-4 py-3 text-xs">
                                    {{ $action->target_type ?: 'n/a' }}@if($action->target_id) ({{ $action->target_id }})@endif
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    @if ($artifactFile !== '' && $action->action_type === \App\Http\Controllers\Landlord\LandlordTenantOperationsController::ACTION_EXPORT)
                                        <a
                                            href="{{ route('landlord.tenants.operations.exports.download', ['tenant' => $tenant->id, 'action' => $action->id]) }}"
                                            class="underline decoration-dotted underline-offset-2"
                                        >
                                            {{ $artifactFile }}
                                        </a>
                                    @else
                                        n/a
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    @if ($modeLabel !== '')
                                        <div>mode: <span class="font-mono">{{ $modeLabel }}</span></div>
                                    @endif
                                    @if ($expiresAt !== '')
                                        <div>expires: <span class="font-mono">{{ $expiresAt }}</span></div>
                                    @endif
                                    @if (array_key_exists('reason', $confirmation))
                                        <div>reason: {{ (string) $confirmation['reason'] }}</div>
                                    @endif
                                    @if ($errorMessage !== '')
                                        <div class="text-rose-700">error: {{ $errorMessage }}</div>
                                    @elseif ($modeLabel === '' && $expiresAt === '' && ! array_key_exists('reason', $confirmation))
                                        n/a
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-5 text-sm text-zinc-500">No operator actions recorded for this tenant yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-white shadow-sm p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-zinc-950">Shopify Stores</h2>
                    <p class="mt-1 text-sm text-zinc-600">Read-only store mappings linked to this tenant.</p>
                </div>
                <a
                    href="{{ route('landlord.tenants.index') }}"
                    class="inline-flex items-center rounded-full border border-zinc-300 bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800"
                >
                    Back to Directory
                </a>
            </div>

            <div class="mt-4 overflow-hidden rounded-2xl border border-zinc-200">
                <table class="w-full divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50 text-left text-xs uppercase tracking-[0.12em] text-zinc-500">
                        <tr>
                            <th class="px-4 py-3">Store key</th>
                            <th class="px-4 py-3">Shop domain</th>
                            <th class="px-4 py-3">Installed at</th>
                            <th class="px-4 py-3">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 text-zinc-700">
                        @forelse ($shopifyStores as $store)
                            <tr>
                                <td class="px-4 py-3 font-mono text-xs">{{ $store->store_key }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $store->shop_domain }}</td>
                                <td class="px-4 py-3">{{ optional($store->installed_at)->toDateTimeString() ?? 'n/a' }}</td>
                                <td class="px-4 py-3">{{ optional($store->created_at)->toDateTimeString() ?? 'n/a' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-5 text-sm text-zinc-500">No Shopify stores linked.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
