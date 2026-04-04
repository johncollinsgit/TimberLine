<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-zinc-900">Tenant Workspace</h1>
    </x-slot>

    @php
        $typeLabel = (string) data_get($tenantTypeOptions, $tenantType, Str::headline($tenantType));
        $roleLabel = (string) data_get($tenantRoleOptions, $tenantRole, Str::headline($tenantRole));
        $statusLabel = (string) data_get($tenantStatusOptions, $tenantStatus, Str::headline($tenantStatus));
        $activeTab = in_array($activeTab, $tabs, true) ? $activeTab : 'overview';
        $money = static fn ($cents): string => '$' . number_format(((int) $cents) / 100, 2);
        $rangeOptions = is_array($performanceRanges ?? null) ? $performanceRanges : [];
    @endphp

    <div
        class="space-y-6"
        x-data="{
            showEdit: false,
            showRole: false,
            showModules: false,
            showType: false,
            showDeleteTenant: false,
        }"
    >
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

        <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-zinc-500">Tenant</p>
                    <h2 class="mt-2 text-3xl font-semibold text-zinc-950">{{ $summary['name'] }}</h2>
                    <p class="mt-2 text-sm text-zinc-600">
                        <span class="font-mono text-xs">{{ $summary['slug'] }}</span>
                        <span class="mx-2 text-zinc-400">•</span>
                        <span class="font-mono text-xs">{{ $summary['subdomain'] }}</span>
                    </p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <article class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                        <p class="text-[11px] uppercase tracking-[0.2em] text-zinc-500">Tenant type</p>
                        <p class="mt-1 text-sm font-semibold text-zinc-900">{{ $typeLabel }}</p>
                    </article>
                    <article class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                        <p class="text-[11px] uppercase tracking-[0.2em] text-zinc-500">Current role</p>
                        <p class="mt-1 text-sm font-semibold text-zinc-900">{{ $roleLabel }}</p>
                    </article>
                    <article class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                        <p class="text-[11px] uppercase tracking-[0.2em] text-zinc-500">Status</p>
                        <p class="mt-1 text-sm font-semibold text-zinc-900">{{ $statusLabel }}</p>
                    </article>
                    <article class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                        <p class="text-[11px] uppercase tracking-[0.2em] text-zinc-500">Enabled modules</p>
                        <p class="mt-1 text-sm font-semibold text-zinc-900">{{ number_format((int) $enabledModuleCount) }}</p>
                    </article>
                </div>
            </div>

            <div class="mt-5 flex flex-wrap gap-2">
                <button type="button" @click="showEdit = true" class="rounded-full border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Edit tenant</button>
                <button type="button" @click="showRole = true" class="rounded-full border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Change role</button>
                <button type="button" @click="showModules = true" class="rounded-full border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Manage modules</button>
                <button type="button" @click="showType = true" class="rounded-full border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Change tenant type</button>
                <a href="{{ route('landlord.tenants.show', ['tenant' => $tenant->id, 'tab' => 'applications']) }}" class="rounded-full border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">View applications</a>
                <a href="{{ route('landlord.tenants.show', ['tenant' => $tenant->id, 'tab' => 'customers']) }}" class="rounded-full border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">View customers</a>
                <a href="{{ route('landlord.tenants.show', ['tenant' => $tenant->id, 'tab' => 'activity']) }}" class="rounded-full border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">View activity</a>
                <a href="{{ route('landlord.tenants.show', ['tenant' => $tenant->id, 'tab' => 'performance']) }}" class="rounded-full border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">View performance</a>
                <button type="button" @click="showDeleteTenant = true" class="rounded-full border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">Delete tenant</button>
            </div>
        </section>

        @if ($activeTab === 'overview')
            <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl border border-zinc-200 bg-white p-4">
                    <p class="text-xs uppercase tracking-[0.2em] text-zinc-500">Created</p>
                    <p class="mt-2 text-sm font-semibold text-zinc-900">{{ $summary['created_at'] ?? 'n/a' }}</p>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-white p-4">
                    <p class="text-xs uppercase tracking-[0.2em] text-zinc-500">Users</p>
                    <p class="mt-2 text-2xl font-semibold text-zinc-900">{{ number_format((int) $summary['user_count']) }}</p>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-white p-4">
                    <p class="text-xs uppercase tracking-[0.2em] text-zinc-500">Connected stores</p>
                    <p class="mt-2 text-2xl font-semibold text-zinc-900">{{ number_format((int) $summary['connected_shopify_stores_count']) }}</p>
                    <p class="mt-1 text-xs text-zinc-500">Total store rows: {{ number_format((int) $summary['shopify_stores_count']) }}</p>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-white p-4">
                    <p class="text-xs uppercase tracking-[0.2em] text-zinc-500">Open integration issues</p>
                    <p class="mt-2 text-2xl font-semibold text-zinc-900">{{ number_format((int) $summary['open_integration_health_events_count']) }}</p>
                </article>
            </section>

            <section class="grid gap-6 lg:grid-cols-2">
                <article class="rounded-2xl border border-zinc-200 bg-white p-5">
                    <h3 class="text-base font-semibold text-zinc-900">Module status</h3>
                    <p class="mt-1 text-sm text-zinc-600">Simple setup signal by module state.</p>
                    <dl class="mt-4 space-y-2 text-sm">
                        <div class="flex items-center justify-between"><dt class="text-zinc-600">Configured</dt><dd class="font-semibold text-zinc-900">{{ (int) data_get($summary, 'module_setup.configured', 0) }}</dd></div>
                        <div class="flex items-center justify-between"><dt class="text-zinc-600">In progress</dt><dd class="font-semibold text-zinc-900">{{ (int) data_get($summary, 'module_setup.in_progress', 0) }}</dd></div>
                        <div class="flex items-center justify-between"><dt class="text-zinc-600">Not started</dt><dd class="font-semibold text-zinc-900">{{ (int) data_get($summary, 'module_setup.not_started', 0) }}</dd></div>
                        <div class="flex items-center justify-between"><dt class="text-zinc-600">Other</dt><dd class="font-semibold text-zinc-900">{{ (int) data_get($summary, 'module_setup.other', 0) }}</dd></div>
                    </dl>
                </article>

                <article class="rounded-2xl border border-zinc-200 bg-white p-5">
                    <h3 class="text-base font-semibold text-zinc-900">Linked Shopify stores</h3>
                    <p class="mt-1 text-sm text-zinc-600">Read-only store mappings for this tenant.</p>
                    <div class="mt-4 overflow-hidden rounded-xl border border-zinc-200">
                        <table class="w-full divide-y divide-zinc-200 text-sm">
                            <thead class="bg-zinc-50 text-xs uppercase tracking-[0.12em] text-zinc-500">
                                <tr>
                                    <th class="px-3 py-2 text-left">Store key</th>
                                    <th class="px-3 py-2 text-left">Domain</th>
                                    <th class="px-3 py-2 text-left">Installed</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100">
                                @forelse ($shopifyStores as $store)
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-xs text-zinc-700">{{ $store->store_key }}</td>
                                        <td class="px-3 py-2 font-mono text-xs text-zinc-700">{{ $store->shop_domain }}</td>
                                        <td class="px-3 py-2 text-zinc-700">{{ optional($store->installed_at)->toDateTimeString() ?? 'n/a' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="px-3 py-4 text-sm text-zinc-500">No Shopify stores linked.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>
        @endif

        @if ($activeTab === 'applications')
            <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h3 class="text-lg font-semibold text-zinc-900">Tenant Applications</h3>
                <p class="mt-1 text-sm text-zinc-600">Module access applications tied to this tenant.</p>
                <div class="mt-4 overflow-hidden rounded-2xl border border-zinc-200">
                    <table class="w-full divide-y divide-zinc-200 text-sm">
                        <thead class="bg-zinc-50 text-left text-xs uppercase tracking-[0.12em] text-zinc-500">
                            <tr>
                                <th class="px-4 py-3">Application</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Created</th>
                                <th class="px-4 py-3">Updated</th>
                                <th class="px-4 py-3">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100">
                            @forelse ($applications as $application)
                                <tr class="text-zinc-700">
                                    <td class="px-4 py-3">
                                        <div class="font-semibold text-zinc-900">{{ $application['title'] }}</div>
                                        <div class="mt-1 font-mono text-xs text-zinc-500">{{ $application['module_key'] }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full border border-zinc-300 px-2 py-1 text-[11px] font-semibold text-zinc-700">{{ $application['status_label'] }}</span>
                                    </td>
                                    <td class="px-4 py-3">{{ $application['created_at'] ?? 'n/a' }}</td>
                                    <td class="px-4 py-3">{{ $application['updated_at'] ?? 'n/a' }}</td>
                                    <td class="px-4 py-3">
                                        <a href="{{ $application['action_url'] }}" class="text-xs font-semibold text-zinc-900 underline decoration-dotted underline-offset-2">Open</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-sm text-zinc-500">No applications yet. Module access requests will appear here.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if ($activeTab === 'customers')
            <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-zinc-900">Tenant Customers</h3>
                        <p class="mt-1 text-sm text-zinc-600">Quick customer lookup with only the useful fields.</p>
                    </div>
                    <form method="GET" action="{{ route('landlord.tenants.show', ['tenant' => $tenant->id]) }}" class="flex items-center gap-2">
                        <input type="hidden" name="tab" value="customers">
                        <input type="search" name="customer_search" value="{{ $customerSearch }}" placeholder="Search name, email, or phone" class="w-72 rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                        <button type="submit" class="rounded-xl bg-zinc-900 px-3 py-2 text-xs font-semibold text-white hover:bg-zinc-800">Search</button>
                    </form>
                </div>

                <div class="mt-4 overflow-hidden rounded-2xl border border-zinc-200">
                    <table class="w-full divide-y divide-zinc-200 text-sm">
                        <thead class="bg-zinc-50 text-left text-xs uppercase tracking-[0.12em] text-zinc-500">
                            <tr>
                                <th class="px-4 py-3">Customer</th>
                                <th class="px-4 py-3">Email</th>
                                <th class="px-4 py-3">Phone</th>
                                <th class="px-4 py-3">Updated</th>
                                <th class="px-4 py-3">Open</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 text-zinc-700">
                            @forelse ($tenantCustomers as $profile)
                                @php
                                    $name = trim((string) ($profile->first_name . ' ' . $profile->last_name));
                                    $customerRoute = route('marketing.customers.show', ['marketingProfile' => $profile->id, 'tenant' => $tenant->id]);
                                @endphp
                                <tr>
                                    <td class="px-4 py-3">
                                        <div class="font-semibold text-zinc-900">{{ $name !== '' ? $name : 'n/a' }}</div>
                                        <div class="mt-1 text-xs text-zinc-500">#{{ (int) $profile->id }}</div>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs">{{ $profile->email ?: 'n/a' }}</td>
                                    <td class="px-4 py-3 font-mono text-xs">{{ $profile->phone ?: 'n/a' }}</td>
                                    <td class="px-4 py-3">{{ optional($profile->updated_at)->toDateTimeString() ?? 'n/a' }}</td>
                                    <td class="px-4 py-3">
                                        <a href="{{ $customerRoute }}" class="text-xs font-semibold text-zinc-900 underline decoration-dotted underline-offset-2">Open</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-sm text-zinc-500">No customers found for this tenant.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if ($activeTab === 'activity')
            <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h3 class="text-lg font-semibold text-zinc-900">Tenant Activity</h3>
                <p class="mt-1 text-sm text-zinc-600">Chronological event feed with clear actor, time, and related entity context.</p>

                <div class="mt-4 overflow-hidden rounded-2xl border border-zinc-200">
                    <table class="w-full divide-y divide-zinc-200 text-sm">
                        <thead class="bg-zinc-50 text-left text-xs uppercase tracking-[0.12em] text-zinc-500">
                            <tr>
                                <th class="px-4 py-3">Event</th>
                                <th class="px-4 py-3">Actor</th>
                                <th class="px-4 py-3">Time</th>
                                <th class="px-4 py-3">Related</th>
                                <th class="px-4 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 text-zinc-700">
                            @forelse ($activityRows as $row)
                                <tr>
                                    <td class="px-4 py-3 font-semibold text-zinc-900">{{ $row['event'] }}</td>
                                    <td class="px-4 py-3">{{ $row['actor'] }}</td>
                                    <td class="px-4 py-3">
                                        <div>{{ $row['time_label'] }}</div>
                                        <div class="mt-1 text-xs text-zinc-500">{{ $row['time'] }}</div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div>{{ $row['related_entity'] }}</div>
                                        @if (! empty($row['related_tenant_url']))
                                            <a href="{{ $row['related_tenant_url'] }}" class="mt-1 inline-block text-xs font-semibold text-zinc-900 underline decoration-dotted underline-offset-2">Open related tenant</a>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full border border-zinc-300 px-2 py-1 text-[11px] font-semibold text-zinc-700">{{ Str::headline((string) $row['status']) }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-sm text-zinc-500">No activity has been recorded for this tenant yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if ($activeTab === 'performance')
            <section class="space-y-5 rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-zinc-900">Tenant Performance</h3>
                        <p class="mt-1 text-sm text-zinc-600">XY trend chart modeled on the Shopify Backstage performance graph concept.</p>
                    </div>
                    <form method="GET" action="{{ route('landlord.tenants.show', ['tenant' => $tenant->id]) }}" class="flex items-end gap-2">
                        <input type="hidden" name="tab" value="performance">
                        <label class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">
                            Date range
                            <select name="range" class="mt-1 rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                                @foreach ($rangeOptions as $value => $label)
                                    <option value="{{ $value }}" @selected($performanceRange === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button type="submit" class="rounded-xl bg-zinc-900 px-3 py-2 text-xs font-semibold text-white hover:bg-zinc-800">Apply</button>
                    </form>
                </div>

                <div class="grid gap-3 md:grid-cols-3">
                    <article class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                        <p class="text-xs uppercase tracking-[0.2em] text-zinc-500">Earned rewards cash</p>
                        <p class="mt-2 text-2xl font-semibold text-zinc-900">{{ $money(data_get($performance, 'summary.earned_rewards_cash_cents', 0)) }}</p>
                    </article>
                    <article class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                        <p class="text-xs uppercase tracking-[0.2em] text-zinc-500">Attributable sales</p>
                        <p class="mt-2 text-2xl font-semibold text-zinc-900">{{ $money(data_get($performance, 'summary.attributable_sales_cents', 0)) }}</p>
                    </article>
                    <article class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                        <p class="text-xs uppercase tracking-[0.2em] text-zinc-500">Unused rewards</p>
                        <p class="mt-2 text-2xl font-semibold text-zinc-900">{{ $money(data_get($performance, 'summary.unused_rewards_cents', 0)) }}</p>
                    </article>
                </div>

                @if (data_get($performance, 'chart.empty', true))
                    <div class="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 px-5 py-12 text-center text-sm text-zinc-500">
                        {{ data_get($performance, 'chart.empty_state', 'No performance data is available yet.') }}
                    </div>
                @else
                    <div class="rounded-2xl border border-zinc-200 p-4">
                        <div id="tenant-performance-chart" class="min-h-[340px]"></div>
                    </div>
                @endif
            </section>
        @endif

        @if ($activeTab === 'settings')
            <section class="space-y-6">
                <article class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-semibold text-zinc-900">Users</h3>
                    <p class="mt-1 text-sm text-zinc-600">Remove users from this tenant, or permanently delete a user account when appropriate.</p>

                    <div class="mt-4 overflow-hidden rounded-2xl border border-zinc-200">
                        <table class="w-full divide-y divide-zinc-200 text-sm">
                            <thead class="bg-zinc-50 text-left text-xs uppercase tracking-[0.12em] text-zinc-500">
                                <tr>
                                    <th class="px-4 py-3">User</th>
                                    <th class="px-4 py-3">Email</th>
                                    <th class="px-4 py-3">Role</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 text-zinc-700">
                                @forelse ($tenant->users as $user)
                                    <tr>
                                        <td class="px-4 py-3 font-semibold text-zinc-900">{{ $user->name }}</td>
                                        <td class="px-4 py-3 font-mono text-xs">{{ $user->email }}</td>
                                        <td class="px-4 py-3">{{ Str::headline((string) ($user->pivot->role ?? 'member')) }}</td>
                                        <td class="px-4 py-3">{{ $user->is_active ? 'Active' : 'Inactive' }}</td>
                                        <td class="px-4 py-3">
                                            <details>
                                                <summary class="cursor-pointer text-xs font-semibold text-zinc-900 underline decoration-dotted underline-offset-2">Manage</summary>
                                                <div class="mt-3 space-y-3 rounded-xl border border-zinc-200 bg-zinc-50 p-3">
                                                    <form method="POST" action="{{ route('landlord.tenants.users.remove', ['tenant' => $tenant->id]) }}" class="space-y-2">
                                                        @csrf
                                                        <input type="hidden" name="user_id" value="{{ (int) $user->id }}">
                                                        <input type="hidden" name="action" value="detach">
                                                        <label class="block text-xs font-medium text-zinc-700">
                                                            Type user email to remove from tenant
                                                            <input type="text" name="confirmation" class="mt-1 w-full rounded-lg border border-zinc-300 px-2 py-1 text-xs" placeholder="{{ $user->email }}" required>
                                                        </label>
                                                        <button type="submit" class="rounded-lg border border-zinc-300 px-2 py-1 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Remove from tenant</button>
                                                    </form>

                                                    <form method="POST" action="{{ route('landlord.tenants.users.remove', ['tenant' => $tenant->id]) }}" class="space-y-2">
                                                        @csrf
                                                        <input type="hidden" name="user_id" value="{{ (int) $user->id }}">
                                                        <input type="hidden" name="action" value="delete_account">
                                                        <label class="block text-xs font-medium text-zinc-700">
                                                            Type user email to permanently delete account
                                                            <input type="text" name="confirmation" class="mt-1 w-full rounded-lg border border-zinc-300 px-2 py-1 text-xs" placeholder="{{ $user->email }}" required>
                                                        </label>
                                                        <button type="submit" class="rounded-lg border border-rose-300 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">Delete user account</button>
                                                    </form>
                                                </div>
                                            </details>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-8 text-center text-sm text-zinc-500">No users are assigned to this tenant.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-semibold text-zinc-900">Advanced</h3>
                    <p class="mt-1 text-sm text-zinc-600">Dangerous or niche landlord operations are intentionally secondary.</p>

                    <details class="mt-4 rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                        <summary class="cursor-pointer text-sm font-semibold text-zinc-900">Show advanced operation context</summary>
                        <div class="mt-3 space-y-2 text-xs text-zinc-600">
                            <div>Tenant confirmation phrase: <span class="font-mono text-zinc-900">{{ $tenantConfirmationPhrase }}</span></div>
                            <div>Restore apply phrase: <span class="font-mono text-zinc-900">{{ $tenantApplyRestorePhrase }}</span></div>
                            <div>Restore overwrite phrase: <span class="font-mono text-zinc-900">{{ $tenantOverwritePhrase }}</span></div>
                            <div>Snapshot retention: <span class="font-mono text-zinc-900">{{ (int) $snapshotRetentionDays }} days</span></div>
                            <div>Snapshot max upload size: <span class="font-mono text-zinc-900">{{ number_format((int) $snapshotMaxBytes) }} bytes</span></div>
                        </div>
                    </details>
                </article>
            </section>
        @endif

        <div x-show="showEdit" x-cloak class="fixed inset-0 z-50">
            <div class="absolute inset-0 bg-zinc-950/50" @click="showEdit = false"></div>
            <div class="absolute left-1/2 top-1/2 w-full max-w-lg -translate-x-1/2 -translate-y-1/2 rounded-2xl border border-zinc-200 bg-white p-6 shadow-2xl">
                <h3 class="text-lg font-semibold text-zinc-900">Edit tenant</h3>
                <p class="mt-1 text-sm text-zinc-600">Keep this simple: name, contact, type, role, and status.</p>
                <form method="POST" action="{{ route('landlord.tenants.update', ['tenant' => $tenant->id]) }}" class="mt-4 space-y-3">
                    @csrf
                    @method('PATCH')
                    <label class="block text-sm text-zinc-700">Name
                        <input type="text" name="name" value="{{ old('name', $tenant->name) }}" required class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                    </label>
                    <label class="block text-sm text-zinc-700">Primary contact email
                        <input type="email" name="primary_contact_email" value="{{ old('primary_contact_email', $primaryContactEmail) }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                    </label>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="block text-sm text-zinc-700">Tenant type
                            <select name="tenant_type" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                                @foreach ($tenantTypeOptions as $value => $label)
                                    <option value="{{ $value }}" @selected($tenantType === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm text-zinc-700">Role
                            <select name="role" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                                @foreach ($tenantRoleOptions as $value => $label)
                                    <option value="{{ $value }}" @selected($tenantRole === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm text-zinc-700">Status
                            <select name="status" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                                @foreach ($tenantStatusOptions as $value => $label)
                                    <option value="{{ $value }}" @selected($tenantStatus === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <details class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
                        <summary class="cursor-pointer text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">Advanced settings</summary>
                        <label class="mt-3 block text-sm text-zinc-700">Slug
                            <input type="text" name="slug" value="{{ old('slug', $tenant->slug) }}" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                        </label>
                    </details>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="showEdit = false" class="rounded-xl border border-zinc-300 px-3 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Cancel</button>
                        <button type="submit" class="rounded-xl bg-zinc-900 px-3 py-2 text-xs font-semibold text-white hover:bg-zinc-800">Save tenant</button>
                    </div>
                </form>
            </div>
        </div>

        <div x-show="showRole" x-cloak class="fixed inset-0 z-50">
            <div class="absolute inset-0 bg-zinc-950/50" @click="showRole = false"></div>
            <div class="absolute left-1/2 top-1/2 w-full max-w-md -translate-x-1/2 -translate-y-1/2 rounded-2xl border border-zinc-200 bg-white p-6 shadow-2xl">
                <h3 class="text-lg font-semibold text-zinc-900">Change role</h3>
                <p class="mt-1 text-sm text-zinc-600">This updates the tenant membership role used for permissions.</p>
                <form method="POST" action="{{ route('landlord.tenants.role.update', ['tenant' => $tenant->id]) }}" class="mt-4 space-y-3">
                    @csrf
                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-600">Current role: <span class="font-semibold text-zinc-900">{{ $roleLabel }}</span></div>
                    <label class="block text-sm text-zinc-700">New role
                        <select name="role" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                            @foreach ($tenantRoleOptions as $value => $label)
                                <option value="{{ $value }}" @selected($tenantRole === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <p class="text-xs text-zinc-500">Role controls who can manage tenant-level settings and operations.</p>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="showRole = false" class="rounded-xl border border-zinc-300 px-3 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Cancel</button>
                        <button type="submit" class="rounded-xl bg-zinc-900 px-3 py-2 text-xs font-semibold text-white hover:bg-zinc-800">Save role</button>
                    </div>
                </form>
            </div>
        </div>

        <div x-show="showType" x-cloak class="fixed inset-0 z-50">
            <div class="absolute inset-0 bg-zinc-950/50" @click="showType = false"></div>
            <div class="absolute left-1/2 top-1/2 w-full max-w-md -translate-x-1/2 -translate-y-1/2 rounded-2xl border border-zinc-200 bg-white p-6 shadow-2xl">
                <h3 class="text-lg font-semibold text-zinc-900">Change tenant type</h3>
                <p class="mt-1 text-sm text-zinc-600">Tenant type controls operating track and future module template defaults.</p>
                <form method="POST" action="{{ route('landlord.tenants.type.update', ['tenant' => $tenant->id]) }}" class="mt-4 space-y-3">
                    @csrf
                    <label class="block text-sm text-zinc-700">Tenant type
                        <select name="tenant_type" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900">
                            @foreach ($tenantTypeOptions as $value => $label)
                                <option value="{{ $value }}" @selected($tenantType === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="showType = false" class="rounded-xl border border-zinc-300 px-3 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Cancel</button>
                        <button type="submit" class="rounded-xl bg-zinc-900 px-3 py-2 text-xs font-semibold text-white hover:bg-zinc-800">Save type</button>
                    </div>
                </form>
            </div>
        </div>

        <div x-show="showModules" x-cloak class="fixed inset-0 z-50">
            <div class="absolute inset-0 bg-zinc-950/50" @click="showModules = false"></div>
            <div class="absolute inset-x-8 top-10 bottom-10 overflow-y-auto rounded-2xl border border-zinc-200 bg-white p-6 shadow-2xl">
                <h3 class="text-lg font-semibold text-zinc-900">Manage modules</h3>
                <p class="mt-1 text-sm text-zinc-600">Enable or disable module access quickly. This is built to support future tenant-type presets.</p>

                <form method="POST" action="{{ route('landlord.tenants.modules.update', ['tenant' => $tenant->id]) }}" class="mt-4 space-y-5">
                    @csrf
                    @foreach ($moduleGroups as $group)
                        <section class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                            <h4 class="text-sm font-semibold text-zinc-900">{{ $group['label'] }}</h4>
                            <div class="mt-3 grid gap-2 md:grid-cols-2 lg:grid-cols-3">
                                @foreach ($group['items'] as $module)
                                    <label class="flex items-start gap-2 rounded-xl border border-zinc-200 bg-white p-3 text-sm">
                                        <input type="checkbox" name="modules[{{ $module['key'] }}]" value="1" @checked($module['enabled']) class="mt-0.5 rounded border-zinc-300">
                                        <span>
                                            <span class="font-semibold text-zinc-900">{{ $module['label'] }}</span>
                                            <span class="mt-1 block text-xs text-zinc-500">{{ $module['description'] }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="showModules = false" class="rounded-xl border border-zinc-300 px-3 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Cancel</button>
                        <button type="submit" class="rounded-xl bg-zinc-900 px-3 py-2 text-xs font-semibold text-white hover:bg-zinc-800">Save modules</button>
                    </div>
                </form>
            </div>
        </div>

        <div x-show="showDeleteTenant" x-cloak class="fixed inset-0 z-50">
            <div class="absolute inset-0 bg-zinc-950/50" @click="showDeleteTenant = false"></div>
            <div class="absolute left-1/2 top-1/2 w-full max-w-md -translate-x-1/2 -translate-y-1/2 rounded-2xl border border-rose-200 bg-white p-6 shadow-2xl">
                <h3 class="text-lg font-semibold text-rose-800">Delete tenant</h3>
                <p class="mt-1 text-sm text-zinc-600">This permanently deletes the tenant and associated tenant-owned records.</p>
                <form method="POST" action="{{ route('landlord.tenants.destroy', ['tenant' => $tenant->id]) }}" class="mt-4 space-y-3">
                    @csrf
                    @method('DELETE')
                    <label class="block text-sm text-zinc-700">
                        Type <span class="font-semibold text-zinc-900">{{ $tenant->name }}</span> to confirm
                        <input type="text" name="confirmation" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm text-zinc-900" required>
                    </label>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="showDeleteTenant = false" class="rounded-xl border border-zinc-300 px-3 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Cancel</button>
                        <button type="submit" class="rounded-xl border border-rose-300 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50">Delete tenant</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @if ($activeTab === 'performance' && ! data_get($performance, 'chart.empty', true))
        <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const chartElement = document.getElementById('tenant-performance-chart');
                if (!chartElement || typeof ApexCharts === 'undefined') {
                    return;
                }

                const chartPayload = @json(data_get($performance, 'chart', []));
                const options = {
                    chart: {
                        type: 'area',
                        height: 340,
                        toolbar: { show: false },
                        zoom: { enabled: false },
                    },
                    series: chartPayload.series || [],
                    dataLabels: { enabled: false },
                    stroke: { curve: 'smooth', width: 3 },
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shadeIntensity: 0.5,
                            opacityFrom: 0.28,
                            opacityTo: 0.03,
                        },
                    },
                    colors: ['#0f766e', '#1d4ed8', '#7c3aed'],
                    grid: {
                        borderColor: '#e4e4e7',
                        strokeDashArray: 4,
                    },
                    xaxis: {
                        categories: chartPayload.categories || [],
                        labels: {
                            style: { colors: '#71717a', fontSize: '11px' },
                        },
                    },
                    yaxis: {
                        labels: {
                            style: { colors: '#71717a', fontSize: '11px' },
                            formatter: function (value) {
                                const number = Number(value || 0) / 100;
                                return '$' + number.toLocaleString(undefined, {
                                    minimumFractionDigits: 0,
                                    maximumFractionDigits: 0,
                                });
                            },
                        },
                    },
                    tooltip: {
                        y: {
                            formatter: function (value) {
                                const number = Number(value || 0) / 100;
                                return '$' + number.toLocaleString(undefined, {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2,
                                });
                            },
                        },
                    },
                    legend: {
                        position: 'top',
                        horizontalAlign: 'left',
                    },
                    noData: { text: 'No performance data available.' },
                };

                const chart = new ApexCharts(chartElement, options);
                chart.render();
            });
        </script>
    @endif
</x-app-layout>
