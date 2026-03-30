<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Tenant Detail</h1>
    </x-slot>

    <div class="space-y-6">
        @if (session('status'))
            <section class="rounded-2xl border border-emerald-300/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-50">
                {{ session('status') }}
            </section>
        @endif

        <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 shadow-[0_30px_80px_-50px_rgba(0,0,0,0.9)]">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Tenant</div>
                    <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">{{ $summary['name'] }}</div>
                    <p class="mt-2 text-sm text-emerald-50/70">
                        Slug: <span class="font-mono text-xs">{{ $summary['slug'] }}</span>
                        <span class="mx-2 text-emerald-50/40">•</span>
                        Subdomain: <span class="font-mono text-xs">{{ $summary['subdomain'] }}</span>
                    </p>
                </div>
                <div class="text-right">
                    <div class="text-xs uppercase tracking-[0.2em] text-emerald-100/50">Derived Status</div>
                    <div class="mt-1 text-lg font-semibold text-white">{{ $summary['status_label'] }}</div>
                    <div class="mt-1 text-xs text-emerald-50/60">{{ $summary['status'] }}</div>
                </div>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5">
                <p class="text-xs uppercase tracking-[0.2em] text-emerald-100/50">Created</p>
                <p class="mt-2 text-sm font-semibold text-white">{{ $summary['created_at'] ?? 'n/a' }}</p>
            </article>
            <article class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5">
                <p class="text-xs uppercase tracking-[0.2em] text-emerald-100/50">Users</p>
                <p class="mt-2 text-3xl font-semibold text-white">{{ number_format((int) $summary['user_count']) }}</p>
            </article>
            <article class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5">
                <p class="text-xs uppercase tracking-[0.2em] text-emerald-100/50">Connected Shopify Stores</p>
                <p class="mt-2 text-3xl font-semibold text-white">{{ number_format((int) $summary['connected_shopify_stores_count']) }}</p>
                <p class="mt-1 text-xs text-emerald-50/60">Total rows: {{ number_format((int) $summary['shopify_stores_count']) }}</p>
            </article>
            <article class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5">
                <p class="text-xs uppercase tracking-[0.2em] text-emerald-100/50">Open Integration Issues</p>
                <p class="mt-2 text-3xl font-semibold text-white">{{ number_format((int) $summary['open_integration_health_events_count']) }}</p>
            </article>
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            <article class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
                <h2 class="text-lg font-semibold text-white">Access Profile</h2>
                <dl class="mt-4 space-y-3 text-sm text-emerald-50/80">
                    <div class="flex justify-between gap-4">
                        <dt class="text-emerald-100/60">Plan key</dt>
                        <dd class="font-mono text-xs">{{ $summary['access_profile']['plan_key'] ?? 'n/a' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-emerald-100/60">Operating mode</dt>
                        <dd class="font-mono text-xs">{{ $summary['access_profile']['operating_mode'] ?? 'n/a' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-emerald-100/60">Profile source</dt>
                        <dd class="font-mono text-xs">{{ $summary['access_profile']['source'] ?? 'n/a' }}</dd>
                    </div>
                </dl>
            </article>

            <article class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
                <h2 class="text-lg font-semibold text-white">Module Setup Indicators</h2>
                <dl class="mt-4 space-y-3 text-sm text-emerald-50/80">
                    <div class="flex justify-between gap-4">
                        <dt class="text-emerald-100/60">Configured</dt>
                        <dd>{{ (int) ($summary['module_setup']['configured'] ?? 0) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-emerald-100/60">In progress</dt>
                        <dd>{{ (int) ($summary['module_setup']['in_progress'] ?? 0) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-emerald-100/60">Not started</dt>
                        <dd>{{ (int) ($summary['module_setup']['not_started'] ?? 0) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-emerald-100/60">Other</dt>
                        <dd>{{ (int) ($summary['module_setup']['other'] ?? 0) }}</dd>
                    </div>
                </dl>
            </article>
        </section>

        <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-white">Guarded Tenant Operations</h2>
                    <p class="mt-1 text-sm text-emerald-50/70">
                        Every action requires explicit tenant confirmation and writes an immutable landlord operator audit record.
                    </p>
                </div>
                <a
                    href="{{ route('landlord.tenants.index') }}"
                    class="inline-flex items-center rounded-full border border-emerald-400/30 bg-emerald-500/15 px-4 py-2 text-xs font-semibold text-white/90"
                >
                    Switch Tenant
                </a>
            </div>

            <div class="mt-4 rounded-2xl border border-emerald-200/10 bg-black/20 p-4 text-xs text-emerald-50/80">
                <div>Tenant confirmation phrase: <span class="font-mono text-emerald-100">{{ $tenantConfirmationPhrase }}</span></div>
                <div class="mt-1">Restore apply phrase: <span class="font-mono text-emerald-100">{{ $tenantApplyRestorePhrase }}</span></div>
                <div class="mt-1">Restore overwrite phrase: <span class="font-mono text-emerald-100">{{ $tenantOverwritePhrase }}</span></div>
                <div class="mt-1">Selected tenant id: <span class="font-mono text-emerald-100">{{ (int) $tenant->id }}</span></div>
                <div class="mt-1">Selected tenant slug: <span class="font-mono text-emerald-100">{{ $tenant->slug }}</span></div>
                <div class="mt-1">Snapshot retention: <span class="font-mono text-emerald-100">{{ (int) $snapshotRetentionDays }} days</span></div>
                <div class="mt-1">Snapshot max upload size: <span class="font-mono text-emerald-100">{{ number_format((int) $snapshotMaxBytes) }} bytes</span></div>
            </div>

            <div class="mt-5 grid gap-6 xl:grid-cols-2">
                <article class="rounded-2xl border border-emerald-200/10 bg-black/20 p-4">
                    <h3 class="text-sm font-semibold text-white">Backup / Export Snapshot</h3>
                    <p class="mt-1 text-xs text-emerald-50/70">
                        Exports tenant-owned marketing/customer snapshot data only for this tenant context.
                    </p>

                    <details class="mt-2 rounded-lg border border-emerald-200/10 bg-black/20 px-3 py-2 text-[11px] text-emerald-50/70">
                        <summary class="cursor-pointer font-semibold text-emerald-100">Snapshot scope tables (MVP)</summary>
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

                        <label class="block text-xs text-emerald-100">
                            Confirmation phrase
                            <input
                                type="text"
                                name="confirm_phrase"
                                class="mt-1 w-full rounded-lg border border-emerald-300/30 bg-[#0b1411] px-3 py-2 text-sm text-emerald-50"
                                placeholder="{{ $tenantConfirmationPhrase }}"
                                required
                            >
                        </label>
                        <label class="block text-xs text-emerald-100">
                            Export reason
                            <input
                                type="text"
                                name="reason"
                                maxlength="500"
                                minlength="8"
                                class="mt-1 w-full rounded-lg border border-emerald-300/30 bg-[#0b1411] px-3 py-2 text-sm text-emerald-50"
                                placeholder="operator reason for export"
                                required
                            >
                        </label>
                        <label class="flex items-center gap-2 text-xs text-emerald-50/80">
                            <input type="checkbox" name="confirm_export" value="1" class="rounded border-emerald-300/40 bg-[#0b1411]">
                            I confirm this export should run for this tenant only.
                        </label>

                        @error('tenant_operations_export')
                            <p class="text-xs text-rose-300">{{ $message }}</p>
                        @enderror
                        @error('confirm_phrase')
                            <p class="text-xs text-rose-300">{{ $message }}</p>
                        @enderror
                        @error('reason')
                            <p class="text-xs text-rose-300">{{ $message }}</p>
                        @enderror

                        <button
                            type="submit"
                            class="inline-flex items-center rounded-lg border border-emerald-300/40 bg-emerald-500/15 px-4 py-2 text-xs font-semibold text-emerald-50"
                        >
                            Create Tenant Snapshot
                        </button>
                    </form>
                </article>

                <article class="rounded-2xl border border-emerald-200/10 bg-black/20 p-4">
                    <h3 class="text-sm font-semibold text-white">Restore / Import Snapshot</h3>
                    <p class="mt-1 text-xs text-emerald-50/70">
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

                        <label class="block text-xs text-emerald-100">
                            Snapshot file (JSON)
                            <input
                                type="file"
                                name="snapshot_file"
                                accept=".json,text/json,application/json,text/plain"
                                class="mt-1 block w-full text-xs text-emerald-50"
                                required
                            >
                        </label>
                        <label class="block text-xs text-emerald-100">
                            Confirmation phrase
                            <input
                                type="text"
                                name="confirm_phrase"
                                class="mt-1 w-full rounded-lg border border-emerald-300/30 bg-[#0b1411] px-3 py-2 text-sm text-emerald-50"
                                placeholder="{{ $tenantConfirmationPhrase }}"
                                required
                            >
                        </label>
                        <label class="block text-xs text-emerald-100">
                            Restore reason
                            <input
                                type="text"
                                name="reason"
                                maxlength="500"
                                minlength="8"
                                class="mt-1 w-full rounded-lg border border-emerald-300/30 bg-[#0b1411] px-3 py-2 text-sm text-emerald-50"
                                placeholder="operator reason for restore or dry-run"
                                required
                            >
                        </label>
                        <label class="flex items-center gap-2 text-xs text-emerald-50/80">
                            <input type="checkbox" name="dry_run" value="1" class="rounded border-emerald-300/40 bg-[#0b1411]">
                            Run as dry-run only (preview counts, no mutations).
                        </label>
                        <label class="flex items-center gap-2 text-xs text-emerald-50/80">
                            <input type="checkbox" name="confirm_restore" value="1" class="rounded border-emerald-300/40 bg-[#0b1411]">
                            I confirm this restore targets only tenant {{ $tenant->slug }}.
                        </label>
                        <label class="block text-xs text-emerald-100">
                            Apply phrase (required when dry-run is off)
                            <input
                                type="text"
                                name="apply_phrase"
                                class="mt-1 w-full rounded-lg border border-emerald-300/30 bg-[#0b1411] px-3 py-2 text-sm text-emerald-50"
                                placeholder="{{ $tenantApplyRestorePhrase }}"
                            >
                        </label>
                        <label class="flex items-center gap-2 text-xs text-emerald-50/80">
                            <input type="checkbox" name="allow_overwrite" value="1" class="rounded border-emerald-300/40 bg-[#0b1411]">
                            Allow overwrite of existing row ids.
                        </label>
                        <label class="flex items-center gap-2 text-xs text-emerald-50/80">
                            <input type="checkbox" name="confirm_overwrite" value="1" class="rounded border-emerald-300/40 bg-[#0b1411]">
                            I understand overwrite mode mutates existing rows in the selected tenant.
                        </label>
                        <label class="block text-xs text-emerald-100">
                            Overwrite phrase (required when overwrite is enabled)
                            <input
                                type="text"
                                name="overwrite_phrase"
                                class="mt-1 w-full rounded-lg border border-emerald-300/30 bg-[#0b1411] px-3 py-2 text-sm text-emerald-50"
                                placeholder="{{ $tenantOverwritePhrase }}"
                            >
                        </label>

                        @error('tenant_operations_restore')
                            <p class="text-xs text-rose-300">{{ $message }}</p>
                        @enderror
                        @error('reason')
                            <p class="text-xs text-rose-300">{{ $message }}</p>
                        @enderror
                        @error('apply_phrase')
                            <p class="text-xs text-rose-300">{{ $message }}</p>
                        @enderror
                        @error('confirm_overwrite')
                            <p class="text-xs text-rose-300">{{ $message }}</p>
                        @enderror
                        @error('overwrite_phrase')
                            <p class="text-xs text-rose-300">{{ $message }}</p>
                        @enderror
                        @error('snapshot_file')
                            <p class="text-xs text-rose-300">{{ $message }}</p>
                        @enderror

                        <button
                            type="submit"
                            class="inline-flex items-center rounded-lg border border-emerald-300/40 bg-emerald-500/15 px-4 py-2 text-xs font-semibold text-emerald-50"
                        >
                            Restore Tenant Snapshot
                        </button>
                    </form>
                </article>

                <article class="rounded-2xl border border-emerald-200/10 bg-black/20 p-4">
                    <h3 class="text-sm font-semibold text-white">Modify Customer (Bounded Fields)</h3>
                    <p class="mt-1 text-xs text-emerald-50/70">
                        Updates only selected profile fields inside this tenant. No global profile mutation path is allowed.
                    </p>

                    <form method="POST" action="{{ route('landlord.tenants.operations.customers.modify', ['tenant' => $tenant->id]) }}" class="mt-3 space-y-3">
                        @csrf
                        <input type="hidden" name="tenant_id" value="{{ (int) $tenant->id }}">
                        <input type="hidden" name="tenant_slug" value="{{ $tenant->slug }}">

                        <label class="block text-xs text-emerald-100">
                            Customer profile id
                            <input type="number" name="profile_id" min="1" class="mt-1 w-full rounded-lg border border-emerald-300/30 bg-[#0b1411] px-3 py-2 text-sm text-emerald-50" required>
                        </label>
                        <label class="block text-xs text-emerald-100">
                            Confirm profile id
                            <input type="text" name="confirm_profile_id" class="mt-1 w-full rounded-lg border border-emerald-300/30 bg-[#0b1411] px-3 py-2 text-sm text-emerald-50" placeholder="type the same profile id" required>
                        </label>
                        <label class="block text-xs text-emerald-100">
                            Reason
                            <input type="text" name="reason" maxlength="500" minlength="8" class="mt-1 w-full rounded-lg border border-emerald-300/30 bg-[#0b1411] px-3 py-2 text-sm text-emerald-50" required>
                        </label>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label class="text-xs text-emerald-100">
                                First name
                                <input type="text" name="first_name" maxlength="120" class="mt-1 w-full rounded-lg border border-emerald-300/30 bg-[#0b1411] px-3 py-2 text-sm text-emerald-50">
                            </label>
                            <label class="text-xs text-emerald-100">
                                Last name
                                <input type="text" name="last_name" maxlength="120" class="mt-1 w-full rounded-lg border border-emerald-300/30 bg-[#0b1411] px-3 py-2 text-sm text-emerald-50">
                            </label>
                            <label class="text-xs text-emerald-100">
                                Email
                                <input type="email" name="email" maxlength="255" class="mt-1 w-full rounded-lg border border-emerald-300/30 bg-[#0b1411] px-3 py-2 text-sm text-emerald-50">
                            </label>
                            <label class="text-xs text-emerald-100">
                                Phone
                                <input type="text" name="phone" maxlength="40" class="mt-1 w-full rounded-lg border border-emerald-300/30 bg-[#0b1411] px-3 py-2 text-sm text-emerald-50">
                            </label>
                        </div>
                        <label class="block text-xs text-emerald-100">
                            Notes
                            <textarea name="notes" rows="3" maxlength="4000" class="mt-1 w-full rounded-lg border border-emerald-300/30 bg-[#0b1411] px-3 py-2 text-sm text-emerald-50"></textarea>
                        </label>
                        <div class="flex flex-wrap gap-4 text-xs text-emerald-50/80">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="accepts_email_marketing" value="1" class="rounded border-emerald-300/40 bg-[#0b1411]">
                                Set accepts email marketing = true
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="accepts_sms_marketing" value="1" class="rounded border-emerald-300/40 bg-[#0b1411]">
                                Set accepts SMS marketing = true
                            </label>
                        </div>
                        <label class="block text-xs text-emerald-100">
                            Confirmation phrase
                            <input type="text" name="confirm_phrase" class="mt-1 w-full rounded-lg border border-emerald-300/30 bg-[#0b1411] px-3 py-2 text-sm text-emerald-50" placeholder="{{ $tenantConfirmationPhrase }}" required>
                        </label>
                        <label class="flex items-center gap-2 text-xs text-emerald-50/80">
                            <input type="checkbox" name="confirm_modify" value="1" class="rounded border-emerald-300/40 bg-[#0b1411]">
                            I confirm this customer modify action is scoped to this tenant.
                        </label>

                        @error('tenant_operations_customer_modify')
                            <p class="text-xs text-rose-300">{{ $message }}</p>
                        @enderror
                        @error('confirm_profile_id')
                            <p class="text-xs text-rose-300">{{ $message }}</p>
                        @enderror

                        <button
                            type="submit"
                            class="inline-flex items-center rounded-lg border border-emerald-300/40 bg-emerald-500/15 px-4 py-2 text-xs font-semibold text-emerald-50"
                        >
                            Apply Customer Modify Action
                        </button>
                    </form>
                </article>

                <article class="rounded-2xl border border-rose-300/30 bg-rose-500/10 p-4">
                    <h3 class="text-sm font-semibold text-white">Delete Customer (Safe Archive)</h3>
                    <p class="mt-1 text-xs text-rose-100/80">
                        This workflow uses archive/redaction safeguards and audit traceability instead of hard-delete.
                    </p>

                    <form method="POST" action="{{ route('landlord.tenants.operations.customers.archive', ['tenant' => $tenant->id]) }}" class="mt-3 space-y-3">
                        @csrf
                        <input type="hidden" name="tenant_id" value="{{ (int) $tenant->id }}">
                        <input type="hidden" name="tenant_slug" value="{{ $tenant->slug }}">

                        <label class="block text-xs text-rose-100">
                            Customer profile id
                            <input type="number" name="profile_id" min="1" class="mt-1 w-full rounded-lg border border-rose-300/40 bg-[#1a1010] px-3 py-2 text-sm text-rose-50" required>
                        </label>
                        <label class="block text-xs text-rose-100">
                            Confirm profile id
                            <input type="text" name="confirm_profile_id" class="mt-1 w-full rounded-lg border border-rose-300/40 bg-[#1a1010] px-3 py-2 text-sm text-rose-50" placeholder="type the same profile id" required>
                        </label>
                        <label class="block text-xs text-rose-100">
                            Deletion reason
                            <input type="text" name="reason" maxlength="500" minlength="8" class="mt-1 w-full rounded-lg border border-rose-300/40 bg-[#1a1010] px-3 py-2 text-sm text-rose-50" required>
                        </label>
                        <label class="block text-xs text-rose-100">
                            Confirmation phrase
                            <input type="text" name="confirm_phrase" class="mt-1 w-full rounded-lg border border-rose-300/40 bg-[#1a1010] px-3 py-2 text-sm text-rose-50" placeholder="{{ $tenantConfirmationPhrase }}" required>
                        </label>
                        <label class="flex items-center gap-2 text-xs text-rose-100/80">
                            <input type="checkbox" name="confirm_delete" value="1" class="rounded border-rose-300/40 bg-[#1a1010]">
                            I understand this action archives/redacts customer identity fields for this tenant.
                        </label>

                        @error('tenant_operations_customer_archive')
                            <p class="text-xs text-rose-200">{{ $message }}</p>
                        @enderror
                        @error('confirm_profile_id')
                            <p class="text-xs text-rose-200">{{ $message }}</p>
                        @enderror

                        <button
                            type="submit"
                            class="inline-flex items-center rounded-lg border border-rose-300/40 bg-rose-500/20 px-4 py-2 text-xs font-semibold text-rose-50"
                        >
                            Run Safe Delete (Archive)
                        </button>
                    </form>
                </article>
            </div>
        </section>

        <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
            <h2 class="text-lg font-semibold text-white">Recent Tenant Customers (Quick Lookup)</h2>
            <p class="mt-1 text-sm text-emerald-50/70">
                Use profile ids from this list when running landlord customer modify/delete actions.
            </p>
            <div class="mt-4 overflow-hidden rounded-2xl border border-emerald-200/10">
                <table class="w-full divide-y divide-emerald-200/10 text-sm">
                    <thead class="bg-white/5 text-left text-xs uppercase tracking-[0.12em] text-emerald-100/60">
                        <tr>
                            <th class="px-4 py-3">Profile id</th>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Email</th>
                            <th class="px-4 py-3">Phone</th>
                            <th class="px-4 py-3">Updated</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-emerald-200/10 text-emerald-50/85">
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
                                <td colspan="5" class="px-4 py-5 text-sm text-emerald-50/65">No tenant customers found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
            <h2 class="text-lg font-semibold text-white">Operator Action Trace</h2>
            <p class="mt-1 text-sm text-emerald-50/70">
                Append-only operator records for export/restore/customer actions in this tenant context.
            </p>
            <div class="mt-4 grid gap-3 sm:grid-cols-4">
                <article class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2">
                    <p class="text-[11px] uppercase tracking-[0.12em] text-emerald-100/60">Recent total</p>
                    <p class="mt-1 text-lg font-semibold text-white">{{ (int) ($operatorActionSummary['total'] ?? 0) }}</p>
                </article>
                <article class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2">
                    <p class="text-[11px] uppercase tracking-[0.12em] text-emerald-100/60">Success</p>
                    <p class="mt-1 text-lg font-semibold text-emerald-100">{{ (int) ($operatorActionSummary['success'] ?? 0) }}</p>
                </article>
                <article class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2">
                    <p class="text-[11px] uppercase tracking-[0.12em] text-emerald-100/60">Blocked</p>
                    <p class="mt-1 text-lg font-semibold text-amber-200">{{ (int) ($operatorActionSummary['blocked'] ?? 0) }}</p>
                </article>
                <article class="rounded-xl border border-emerald-200/10 bg-black/20 px-3 py-2">
                    <p class="text-[11px] uppercase tracking-[0.12em] text-emerald-100/60">Failed</p>
                    <p class="mt-1 text-lg font-semibold text-rose-200">{{ (int) ($operatorActionSummary['failed'] ?? 0) }}</p>
                </article>
            </div>
            <div class="mt-4 overflow-hidden rounded-2xl border border-emerald-200/10">
                <table class="w-full divide-y divide-emerald-200/10 text-sm">
                    <thead class="bg-white/5 text-left text-xs uppercase tracking-[0.12em] text-emerald-100/60">
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
                    <tbody class="divide-y divide-emerald-200/10 text-emerald-50/85">
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
                                    <span class="rounded-full border border-emerald-200/20 px-2 py-0.5 text-[11px]">
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
                                        <div class="text-rose-200">error: {{ $errorMessage }}</div>
                                    @elseif ($modeLabel === '' && $expiresAt === '' && ! array_key_exists('reason', $confirmation))
                                        n/a
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-5 text-sm text-emerald-50/65">No operator actions recorded for this tenant yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-white">Shopify Stores</h2>
                    <p class="mt-1 text-sm text-emerald-50/70">Read-only store mappings linked to this tenant.</p>
                </div>
                <a
                    href="{{ route('landlord.tenants.index') }}"
                    class="inline-flex items-center rounded-full border border-emerald-400/30 bg-emerald-500/15 px-4 py-2 text-xs font-semibold text-white/90"
                >
                    Back to Directory
                </a>
            </div>

            <div class="mt-4 overflow-hidden rounded-2xl border border-emerald-200/10">
                <table class="w-full divide-y divide-emerald-200/10 text-sm">
                    <thead class="bg-white/5 text-left text-xs uppercase tracking-[0.12em] text-emerald-100/60">
                        <tr>
                            <th class="px-4 py-3">Store key</th>
                            <th class="px-4 py-3">Shop domain</th>
                            <th class="px-4 py-3">Installed at</th>
                            <th class="px-4 py-3">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-emerald-200/10 text-emerald-50/85">
                        @forelse ($shopifyStores as $store)
                            <tr>
                                <td class="px-4 py-3 font-mono text-xs">{{ $store->store_key }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $store->shop_domain }}</td>
                                <td class="px-4 py-3">{{ optional($store->installed_at)->toDateTimeString() ?? 'n/a' }}</td>
                                <td class="px-4 py-3">{{ optional($store->created_at)->toDateTimeString() ?? 'n/a' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-5 text-sm text-emerald-50/65">No Shopify stores linked.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
