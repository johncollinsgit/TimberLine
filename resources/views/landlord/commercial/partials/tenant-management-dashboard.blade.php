
<section
    x-data="landlordTenantManagement(@js($tenantManagement))"
    x-init="init()"
    class="rounded-[2rem] border border-zinc-200 bg-white shadow-[0_35px_120px_-75px_rgba(15,23,42,0.45)]"
>
    <div class="border-b border-zinc-200 px-6 py-6 sm:px-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-3xl">
                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-zinc-500">Landlord analytics</p>
                <h2 class="mt-2 font-['Fraunces'] text-4xl leading-tight text-zinc-950">Tenant Management</h2>
                <p class="mt-3 text-sm leading-6 text-zinc-600">
                    Track tenant performance, recurring revenue posture, customer growth, and reward activity from one landlord-ready workspace.
                </p>
                <p class="mt-2 text-xs text-zinc-500" x-text="currencyNote"></p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    @click="exportCsv()"
                    class="inline-flex items-center rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 transition hover:border-zinc-400 hover:bg-zinc-100"
                >
                    Export CSV
                </button>
                <a
                    :href="selectedRow ? selectedRow.detail_url : routes.tenant_index"
                    class="inline-flex items-center rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold transition"
                    :class="selectedRow ? 'text-zinc-700 hover:border-zinc-400 hover:bg-zinc-100' : 'pointer-events-none text-zinc-400'"
                >
                    View tenant detail
                </a>
                <a
                    :href="routes.tenant_index"
                    class="inline-flex items-center rounded-full bg-zinc-950 px-4 py-2 text-xs font-semibold text-white transition hover:bg-zinc-800"
                >
                    Add tenant
                </a>
            </div>
        </div>
    </div>

    <div class="space-y-8 px-6 py-6 sm:px-8">
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
            <template x-for="card in kpiCards" :key="card.key">
                <article class="rounded-3xl border border-zinc-200 bg-zinc-50/80 px-4 py-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-zinc-500" x-text="card.label"></p>
                            <p class="mt-3 text-3xl font-semibold tracking-tight text-zinc-950" x-text="formatMetricValue(card.unit, card.value)"></p>
                        </div>
                        <span
                            class="rounded-full px-2.5 py-1 text-[11px] font-semibold"
                            :class="deltaToneClass(card.delta_tone)"
                            x-text="card.delta_label"
                        ></span>
                    </div>
                    <p class="mt-3 text-xs text-zinc-500" x-text="card.helper"></p>
                </article>
            </template>
        </div>

        <article class="rounded-[1.75rem] border border-zinc-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold text-zinc-950">Activity analytics</h3>
                    <p class="mt-1 text-sm text-zinc-600">
                        Shopify-admin-style trend view for revenue, onboarding, reward redemption, and tenant activity.
                    </p>
                </div>
                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <label class="text-[11px] font-semibold uppercase tracking-[0.2em] text-zinc-500">
                        Dataset
                        <select x-model="filters.dataset" @change="syncMetricForDataset(); refreshAll()" class="mt-1 w-full rounded-2xl border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900">
                            <template x-for="option in datasetOptions" :key="option.value">
                                <option :value="option.value" x-text="option.label"></option>
                            </template>
                        </select>
                    </label>
                    <label class="text-[11px] font-semibold uppercase tracking-[0.2em] text-zinc-500">
                        Metric
                        <select x-model="filters.metric" @change="refreshAll()" class="mt-1 w-full rounded-2xl border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900">
                            <template x-for="option in metricOptionsForDataset()" :key="option.value">
                                <option :value="option.value" x-text="option.label"></option>
                            </template>
                        </select>
                    </label>
                    <label class="text-[11px] font-semibold uppercase tracking-[0.2em] text-zinc-500">
                        Time range
                        <select x-model="filters.range" @change="syncGroupForRange(); refreshAll()" class="mt-1 w-full rounded-2xl border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900">
                            <template x-for="option in rangeOptions" :key="option.value">
                                <option :value="option.value" x-text="option.label"></option>
                            </template>
                        </select>
                    </label>
                    <label class="text-[11px] font-semibold uppercase tracking-[0.2em] text-zinc-500">
                        Group by
                        <select x-model="filters.groupBy" @change="refreshAll()" class="mt-1 w-full rounded-2xl border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900">
                            <template x-for="option in groupOptionsForRange()" :key="option.value">
                                <option :value="option.value" x-text="option.label"></option>
                            </template>
                        </select>
                    </label>
                </div>
            </div>

            <div x-show="filters.range === 'custom'" class="mt-4 grid gap-3 sm:grid-cols-2">
                <label class="text-[11px] font-semibold uppercase tracking-[0.2em] text-zinc-500">
                    From
                    <input type="date" x-model="filters.customFrom" @change="refreshAll()" class="mt-1 w-full rounded-2xl border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900">
                </label>
                <label class="text-[11px] font-semibold uppercase tracking-[0.2em] text-zinc-500">
                    To
                    <input type="date" x-model="filters.customTo" @change="refreshAll()" class="mt-1 w-full rounded-2xl border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900">
                </label>
            </div>

            <div class="mt-5 grid gap-4 xl:grid-cols-[minmax(0,1.6fr)_320px]">
                <div class="rounded-[1.5rem] border border-zinc-200 bg-[radial-gradient(circle_at_top_left,_rgba(18,60,67,0.08),_transparent_44%),linear-gradient(180deg,_#ffffff,_#f7f8f8)] p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2 pb-3">
                        <div>
                            <p class="text-sm font-semibold text-zinc-900" x-text="chartState.metric_label || activeMetricLabel()"></p>
                            <p class="text-xs text-zinc-500" x-text="chartState.window_label || selectedRangeLabel()"></p>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-semibold text-zinc-900" x-text="formatMetricValue(chartState.unit || currentMetricUnit(), chartState.total || 0)"></div>
                            <div class="text-xs text-zinc-500" x-text="chartState.delta_label ? `${chartState.delta_label} vs prior period` : 'No comparison yet'"></div>
                        </div>
                    </div>

                    <div x-show="activityLoading" class="flex min-h-[360px] items-center justify-center rounded-2xl border border-dashed border-zinc-300 bg-white/70 px-5 py-10 text-center text-sm text-zinc-500">
                        Loading activity analytics...
                    </div>

                    <div x-show="!activityLoading && chartState.empty_state" class="flex min-h-[360px] items-center justify-center rounded-2xl border border-dashed border-zinc-300 bg-white/70 px-5 py-10 text-center text-sm text-zinc-500" x-text="chartState.empty_state"></div>

                    <div x-show="!activityLoading && !chartState.empty_state">
                        <div x-ref="activityChart" class="min-h-[360px]"></div>
                    </div>
                </div>

                <div class="space-y-4">
                    <article class="rounded-[1.5rem] border border-zinc-200 bg-zinc-50/80 p-4 shadow-sm">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.24em] text-zinc-500">Selected window</p>
                        <p class="mt-3 text-2xl font-semibold text-zinc-950" x-text="chartState.window_label || selectedRangeLabel()"></p>
                        <p class="mt-2 text-sm leading-6 text-zinc-600">
                            One shared filter state drives the KPI strip, the activity graph, and the tenant table so we do not drift between surfaces.
                        </p>
                        <div class="mt-4 space-y-2 text-xs text-zinc-600">
                            <div><span class="font-semibold text-zinc-900">Dataset:</span> <span x-text="activeDatasetLabel()"></span></div>
                            <div><span class="font-semibold text-zinc-900">Metric:</span> <span x-text="activeMetricLabel()"></span></div>
                            <div><span class="font-semibold text-zinc-900">Tenant:</span> <span x-text="selectedTenantLabel()"></span></div>
                            <div><span class="font-semibold text-zinc-900">Plan:</span> <span x-text="selectedPlanLabel()"></span></div>
                            <div><span class="font-semibold text-zinc-900">Status:</span> <span x-text="selectedStatusLabel()"></span></div>
                        </div>
                    </article>

                    <article class="rounded-[1.5rem] border border-zinc-200 bg-white p-4 shadow-sm">
                        <h4 class="text-sm font-semibold text-zinc-900">Data contract notes</h4>
                        <ul class="mt-3 space-y-2 text-sm leading-6 text-zinc-600">
                            <li>Subscription income stays honest by using configured recurring run rate until captured billing history exists.</li>
                            <li>Module revenue charts use current recurring commercial mix until module-attributed revenue is modeled canonically.</li>
                            <li>Tenant table and graph both read from dedicated landlord analytics endpoints.</li>
                        </ul>
                    </article>
                </div>
            </div>

            <p x-show="chartState.note" class="mt-4 text-xs text-zinc-500" x-text="chartState.note"></p>
        </article>

        <article class="rounded-[1.75rem] border border-zinc-200 bg-white shadow-sm">
            <div class="border-b border-zinc-200 px-5 py-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-zinc-950">Tenant table</h3>
                        <p class="mt-1 text-sm text-zinc-600">Server-backed tenant revenue and activity view with shared filters, pagination, and a tenant detail drawer.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" @click="showMoreFilters = true" class="inline-flex items-center rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 transition hover:border-zinc-400 hover:bg-zinc-100">
                            More filters
                        </button>
                        <div class="relative" @click.outside="showColumnPicker = false">
                            <button type="button" @click="showColumnPicker = !showColumnPicker" class="inline-flex items-center rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 transition hover:border-zinc-400 hover:bg-zinc-100">
                                Columns
                            </button>
                            <div x-show="showColumnPicker" x-cloak class="absolute right-0 top-full z-20 mt-2 w-64 rounded-3xl border border-zinc-200 bg-white p-4 shadow-xl">
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Optional columns</p>
                                <div class="mt-3 space-y-2 text-sm text-zinc-700">
                                    <label class="flex items-center gap-2"><input type="checkbox" x-model="columns.orders"> Orders count</label>
                                    <label class="flex items-center gap-2"><input type="checkbox" x-model="columns.teamUsers"> Active users</label>
                                    <label class="flex items-center gap-2"><input type="checkbox" x-model="columns.moduleMix"> Module revenue breakdown</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                    <label class="text-[11px] font-semibold uppercase tracking-[0.2em] text-zinc-500">
                        Date range
                        <select x-model="filters.range" @change="syncGroupForRange(); refreshAll()" class="mt-1 w-full rounded-2xl border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900">
                            <template x-for="option in rangeOptions" :key="option.value">
                                <option :value="option.value" x-text="option.label"></option>
                            </template>
                        </select>
                    </label>
                    <label class="text-[11px] font-semibold uppercase tracking-[0.2em] text-zinc-500">
                        Tenant
                        <select x-model="filters.tenant" @change="refreshAll()" class="mt-1 w-full rounded-2xl border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900">
                            <option value="all">All tenants</option>
                            <template x-for="option in filterOptions.tenants" :key="option.value">
                                <option :value="option.value" x-text="option.label"></option>
                            </template>
                        </select>
                    </label>
                    <label class="text-[11px] font-semibold uppercase tracking-[0.2em] text-zinc-500">
                        Status
                        <select x-model="filters.status" @change="refreshAll()" class="mt-1 w-full rounded-2xl border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900">
                            <option value="all">All statuses</option>
                            <template x-for="option in filterOptions.statuses" :key="option.value">
                                <option :value="option.value" x-text="option.label"></option>
                            </template>
                        </select>
                    </label>
                    <label class="text-[11px] font-semibold uppercase tracking-[0.2em] text-zinc-500">
                        Plan
                        <select x-model="filters.plan" @change="refreshAll()" class="mt-1 w-full rounded-2xl border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900">
                            <option value="all">All plans</option>
                            <template x-for="option in filterOptions.plans" :key="option.value">
                                <option :value="option.value" x-text="option.label"></option>
                            </template>
                        </select>
                    </label>
                    <label class="text-[11px] font-semibold uppercase tracking-[0.2em] text-zinc-500">
                        Activity type
                        <select x-model="filters.dataset" @change="syncMetricForDataset(); refreshAll()" class="mt-1 w-full rounded-2xl border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900">
                            <template x-for="option in datasetOptions" :key="option.value">
                                <option :value="option.value" x-text="option.label"></option>
                            </template>
                        </select>
                    </label>
                    <label class="text-[11px] font-semibold uppercase tracking-[0.2em] text-zinc-500">
                        Metric
                        <select x-model="filters.metric" @change="refreshAll()" class="mt-1 w-full rounded-2xl border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900">
                            <template x-for="option in metricOptionsForDataset()" :key="option.value">
                                <option :value="option.value" x-text="option.label"></option>
                            </template>
                        </select>
                    </label>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-[minmax(0,1fr)_180px]">
                    <label class="text-[11px] font-semibold uppercase tracking-[0.2em] text-zinc-500">
                        Search
                        <input type="search" x-model="filters.search" @input="queueSearchRefresh()" placeholder="Search tenant, slug, or plan" class="mt-1 w-full rounded-2xl border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900">
                    </label>
                    <label class="text-[11px] font-semibold uppercase tracking-[0.2em] text-zinc-500">
                        Group by
                        <select x-model="filters.groupBy" @change="refreshAll()" class="mt-1 w-full rounded-2xl border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900">
                            <template x-for="option in groupOptionsForRange()" :key="option.value">
                                <option :value="option.value" x-text="option.label"></option>
                            </template>
                        </select>
                    </label>
                </div>
            </div>

            <div class="relative overflow-x-auto">
                <div x-show="tableLoading" class="absolute inset-x-0 top-0 z-10 flex items-center justify-center border-b border-zinc-200 bg-white/85 px-5 py-3 text-sm text-zinc-500 backdrop-blur">
                    Refreshing tenant table...
                </div>

                <table class="min-w-[1360px] divide-y divide-zinc-200 text-sm">
                    <thead class="sticky top-0 z-10 bg-zinc-50 text-left text-[11px] uppercase tracking-[0.2em] text-zinc-500">
                        <tr>
                            <th class="px-5 py-3"><button type="button" @click="setSort('name')">Tenant</button></th>
                            <th class="px-5 py-3"><button type="button" @click="setSort('status')">Status</button></th>
                            <th class="px-5 py-3"><button type="button" @click="setSort('plan_label')">Plan</button></th>
                            <th class="px-5 py-3 text-right"><button type="button" @click="setSort('monthly_subscription_cents')">Monthly subscription</button></th>
                            <th class="px-5 py-3 text-right"><button type="button" @click="setSort('subscription_income_to_date_cents')">Subscription income to date</button></th>
                            <th class="px-5 py-3 text-right"><button type="button" @click="setSort('sales_generated_cents')">Sales generated to date</button></th>
                            <th class="px-5 py-3 text-right"><button type="button" @click="setSort('rewards_redeemed_cents')">Rewards redeemed to date</button></th>
                            <th class="px-5 py-3 text-right"><button type="button" @click="setSort('customers_onboarded')">Customers onboarded</button></th>
                            <th class="px-5 py-3"><button type="button" @click="setSort('last_active_at')">Last active</button></th>
                            <th class="px-5 py-3 text-right"><button type="button" @click="setSort('mrr_contribution_cents')">MRR contribution</button></th>
                            <th x-show="columns.orders" class="px-5 py-3 text-right"><button type="button" @click="setSort('orders_count')">Orders</button></th>
                            <th x-show="columns.teamUsers" class="px-5 py-3 text-right"><button type="button" @click="setSort('team_user_count')">Active users</button></th>
                            <th x-show="columns.moduleMix" class="px-5 py-3">Module revenue</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 bg-white">
                        <template x-if="!tableLoading && tableRows.length === 0">
                            <tr>
                                <td colspan="14" class="px-5 py-10 text-center text-sm text-zinc-500">No tenants match the current filter combination yet.</td>
                            </tr>
                        </template>

                        <template x-for="row in tableRows" :key="row.id">
                            <tr class="cursor-pointer transition hover:bg-zinc-50/80" @click="openRow(row)">
                                <td class="px-5 py-4 align-top">
                                    <div class="font-semibold text-zinc-900" x-text="row.name"></div>
                                    <div class="mt-1 text-xs text-zinc-500" x-text="row.slug"></div>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold" :class="statusBadgeClass(row.status_tone)" x-text="row.status_label"></span>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <div class="font-medium text-zinc-900" x-text="row.plan_label"></div>
                                    <div class="mt-1 text-xs text-zinc-500" x-text="row.billing_health_label"></div>
                                </td>
                                <td class="px-5 py-4 text-right align-top font-medium text-zinc-900" x-text="formatCurrency(row.monthly_subscription_cents)"></td>
                                <td class="px-5 py-4 text-right align-top">
                                    <div class="font-medium text-zinc-900" x-text="formatCurrency(row.subscription_income_to_date_cents)"></div>
                                    <div class="mt-1 text-[11px] text-zinc-500">Configured run rate proxy</div>
                                </td>
                                <td class="px-5 py-4 text-right align-top font-medium text-zinc-900" x-text="formatCurrency(row.sales_generated_cents)"></td>
                                <td class="px-5 py-4 text-right align-top font-medium text-zinc-900" x-text="formatCurrency(row.rewards_redeemed_cents)"></td>
                                <td class="px-5 py-4 text-right align-top font-medium text-zinc-900" x-text="formatInteger(row.customers_onboarded)"></td>
                                <td class="px-5 py-4 align-top">
                                    <div class="font-medium text-zinc-900" x-text="row.last_active_label"></div>
                                </td>
                                <td class="px-5 py-4 text-right align-top font-medium text-zinc-900" x-text="formatCurrency(row.mrr_contribution_cents)"></td>
                                <td x-show="columns.orders" class="px-5 py-4 text-right align-top" x-text="formatInteger(row.orders_count)"></td>
                                <td x-show="columns.teamUsers" class="px-5 py-4 text-right align-top" x-text="formatInteger(row.team_user_count)"></td>
                                <td x-show="columns.moduleMix" class="px-5 py-4 align-top">
                                    <div class="flex flex-wrap gap-1">
                                        <template x-for="item in (row.module_revenue_breakdown || []).slice(0, 2)" :key="item.label + item.kind">
                                            <span class="rounded-full border border-zinc-300 bg-zinc-50 px-2 py-1 text-[11px]" x-text="item.label"></span>
                                        </template>
                                        <span x-show="(row.module_revenue_breakdown || []).length > 2" class="rounded-full border border-zinc-300 bg-zinc-50 px-2 py-1 text-[11px]" x-text="`+${(row.module_revenue_breakdown || []).length - 2}`"></span>
                                    </div>
                                </td>
                                <td class="px-5 py-4 align-top text-right">
                                    <div class="flex justify-end gap-2">
                                        <button type="button" @click.stop="openRow(row)" class="inline-flex items-center rounded-full border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 transition hover:border-zinc-400 hover:bg-zinc-100">
                                            Open
                                        </button>
                                        <a :href="row.detail_url" @click.stop class="inline-flex items-center rounded-full bg-zinc-950 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-zinc-800">
                                            Detail
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 px-5 py-4 text-sm text-zinc-600">
                <div>
                    Showing <span class="font-semibold text-zinc-900" x-text="tableMeta.from || 0"></span>
                    to <span class="font-semibold text-zinc-900" x-text="tableMeta.to || 0"></span>
                    of <span class="font-semibold text-zinc-900" x-text="tableMeta.total || 0"></span> tenants
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" @click="previousPage()" class="inline-flex items-center rounded-full border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 transition hover:border-zinc-400 hover:bg-zinc-100">Previous</button>
                    <span class="rounded-full border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700" x-text="`Page ${tableMeta.page || 1}`"></span>
                    <button type="button" @click="nextPage()" class="inline-flex items-center rounded-full border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 transition hover:border-zinc-400 hover:bg-zinc-100">Next</button>
                </div>
            </div>
        </article>
    </div>

    <div x-show="showMoreFilters" x-cloak class="fixed inset-0 z-40" aria-modal="true" role="dialog">
        <div class="absolute inset-0 bg-zinc-950/35" @click="showMoreFilters = false"></div>
        <div class="absolute right-0 top-0 h-full w-full max-w-md overflow-y-auto border-l border-zinc-200 bg-white p-6 shadow-2xl">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-zinc-500">More filters</p>
                    <h4 class="mt-2 text-xl font-semibold text-zinc-950">Refine tenant analytics</h4>
                </div>
                <button type="button" @click="showMoreFilters = false" class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-zinc-300 text-zinc-700 hover:bg-zinc-100">×</button>
            </div>

            <div class="mt-6 grid gap-4">
                <label class="text-[11px] font-semibold uppercase tracking-[0.2em] text-zinc-500">
                    Module
                    <select x-model="filters.module" @change="refreshAll()" class="mt-1 w-full rounded-2xl border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900">
                        <option value="all">All modules</option>
                        <template x-for="option in filterOptions.modules" :key="option.value">
                            <option :value="option.value" x-text="option.label"></option>
                        </template>
                    </select>
                </label>
                <label class="text-[11px] font-semibold uppercase tracking-[0.2em] text-zinc-500">
                    Billing state
                    <select x-model="filters.billingHealth" @change="refreshAll()" class="mt-1 w-full rounded-2xl border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900">
                        <option value="all">All billing states</option>
                        <option value="ready">Billing prep ready</option>
                        <option value="needs_attention">Needs billing follow-up</option>
                    </select>
                </label>
                <label class="text-[11px] font-semibold uppercase tracking-[0.2em] text-zinc-500">
                    Min revenue (USD)
                    <input type="number" min="0" step="1" x-model="filters.minRevenue" @input="queueFilterRefresh()" class="mt-1 w-full rounded-2xl border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900" placeholder="0">
                </label>
                <label class="text-[11px] font-semibold uppercase tracking-[0.2em] text-zinc-500">
                    Min active users
                    <input type="number" min="0" step="1" x-model="filters.minUsers" @input="queueFilterRefresh()" class="mt-1 w-full rounded-2xl border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900" placeholder="0">
                </label>
                <label class="text-[11px] font-semibold uppercase tracking-[0.2em] text-zinc-500">
                    Reward redemption state
                    <select x-model="filters.rewardsState" @change="refreshAll()" class="mt-1 w-full rounded-2xl border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-900">
                        <option value="all">All reward states</option>
                        <option value="redeeming">Redeeming</option>
                        <option value="none">No redemption yet</option>
                    </select>
                </label>
            </div>
        </div>
    </div>

    <div x-show="showDrawer && selectedRow" x-cloak class="fixed inset-0 z-50" aria-modal="true" role="dialog">
        <div class="absolute inset-0 bg-zinc-950/45" @click="closeDrawer()"></div>
        <div class="absolute right-0 top-0 h-full w-full max-w-lg overflow-y-auto border-l border-zinc-200 bg-white p-6 shadow-2xl">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-zinc-500">Tenant detail</p>
                    <h4 class="mt-2 text-2xl font-semibold text-zinc-950" x-text="selectedRow?.name || 'Tenant'"></h4>
                    <p class="mt-1 text-sm text-zinc-500" x-text="selectedRow?.slug || ''"></p>
                </div>
                <button type="button" @click="closeDrawer()" class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-zinc-300 text-zinc-700 hover:bg-zinc-100">×</button>
            </div>

            <template x-if="selectedRow">
                <div class="mt-6 space-y-4">
                    <div class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-zinc-900" x-text="selectedRow.plan_label"></p>
                                <p class="mt-1 text-xs text-zinc-500" x-text="selectedRow.billing_health_label"></p>
                            </div>
                            <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold" :class="statusBadgeClass(selectedRow.status_tone)" x-text="selectedRow.status_label"></span>
                        </div>
                        <dl class="mt-4 grid gap-3 text-sm text-zinc-600 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs uppercase tracking-[0.2em] text-zinc-500">Monthly subscription</dt>
                                <dd class="mt-1 font-medium text-zinc-900" x-text="formatCurrency(selectedRow.monthly_subscription_cents)"></dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-[0.2em] text-zinc-500">Subscription income to date</dt>
                                <dd class="mt-1 font-medium text-zinc-900" x-text="formatCurrency(selectedRow.subscription_income_to_date_cents)"></dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-[0.2em] text-zinc-500">Sales generated</dt>
                                <dd class="mt-1 font-medium text-zinc-900" x-text="formatCurrency(selectedRow.sales_generated_cents)"></dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-[0.2em] text-zinc-500">Rewards redeemed</dt>
                                <dd class="mt-1 font-medium text-zinc-900" x-text="formatCurrency(selectedRow.rewards_redeemed_cents)"></dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-[0.2em] text-zinc-500">Customers onboarded</dt>
                                <dd class="mt-1 font-medium text-zinc-900" x-text="formatInteger(selectedRow.customers_onboarded)"></dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-[0.2em] text-zinc-500">Last active</dt>
                                <dd class="mt-1 font-medium text-zinc-900" x-text="selectedRow.last_active_label"></dd>
                            </div>
                        </dl>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                            <div class="flex items-center justify-between gap-2">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.2em] text-zinc-500">Sales trend</p>
                                    <p class="mt-1 text-sm font-semibold text-zinc-900" x-text="formatCurrency(selectedRow.sales_generated_cents)"></p>
                                </div>
                                <svg viewBox="0 0 120 40" class="h-10 w-28 text-[#123C43]">
                                    <polyline fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" :points="sparklinePoints(selectedRow.sales_sparkline)"></polyline>
                                </svg>
                            </div>
                        </div>
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                            <div class="flex items-center justify-between gap-2">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.2em] text-zinc-500">Customer growth</p>
                                    <p class="mt-1 text-sm font-semibold text-zinc-900" x-text="formatInteger(selectedRow.customers_onboarded)"></p>
                                </div>
                                <svg viewBox="0 0 120 40" class="h-10 w-28 text-[#2F7D6B]">
                                    <polyline fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" :points="sparklinePoints(selectedRow.customers_sparkline)"></polyline>
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-zinc-200 bg-white px-4 py-4">
                        <div class="flex items-center justify-between gap-2">
                            <h5 class="text-sm font-semibold text-zinc-900">Module revenue breakdown</h5>
                            <a :href="selectedRow.workspace_url" class="text-xs font-semibold text-zinc-700 hover:text-zinc-950">Open configuration</a>
                        </div>
                        <div class="mt-3 space-y-2" x-show="(selectedRow.module_revenue_breakdown || []).length > 0">
                            <template x-for="item in (selectedRow.module_revenue_breakdown || [])" :key="item.kind + item.label">
                                <div class="flex items-center justify-between rounded-2xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-700">
                                    <span><span class="font-medium text-zinc-900" x-text="item.label"></span> <span class="text-zinc-500" x-text="item.kind"></span></span>
                                    <span class="font-semibold text-zinc-900" x-text="formatCurrency(item.amount_cents)"></span>
                                </div>
                            </template>
                        </div>
                        <p x-show="(selectedRow.module_revenue_breakdown || []).length === 0" class="mt-3 text-sm text-zinc-500">No recurring commercial mix is configured for this tenant yet.</p>
                    </div>

                    <div class="rounded-2xl border border-zinc-200 bg-white px-4 py-4">
                        <h5 class="text-sm font-semibold text-zinc-900">Recent activity feed</h5>
                        <div class="mt-3 space-y-2" x-show="(selectedRow.recent_activity || []).length > 0">
                            <template x-for="item in (selectedRow.recent_activity || [])" :key="item.label + item.at">
                                <div class="flex items-center justify-between rounded-2xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-700">
                                    <span x-text="item.label"></span>
                                    <span class="text-xs text-zinc-500" x-text="item.at"></span>
                                </div>
                            </template>
                        </div>
                        <p x-show="(selectedRow.recent_activity || []).length === 0" class="mt-3 text-sm text-zinc-500">No recent activity has been recorded for this tenant yet.</p>
                    </div>
                </div>
            </template>
        </div>
    </div>
</section>

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script>
        function landlordTenantManagement(payload) {
            return {
                routes: payload.routes || {},
                chartConfig: payload.chart || {},
                filterOptions: payload.filters || { tenants: [], plans: [], statuses: [], modules: [] },
                datasetOptions: Object.entries(payload.chart?.datasets || {}).map(([value, definition]) => ({ value, label: definition.label })),
                rangeOptions: Object.entries(payload.chart?.ranges || {}).map(([value, definition]) => ({ value, label: definition.label })),
                currencyNote: payload.chart?.currency_note || '',
                filters: {
                    dataset: payload.chart?.default_dataset || 'all_activity',
                    metric: payload.chart?.default_metric || 'sales_generated',
                    range: payload.chart?.default_range || '30d',
                    groupBy: payload.chart?.default_group || 'day',
                    tenant: 'all',
                    status: 'all',
                    plan: 'all',
                    search: '',
                    module: 'all',
                    billingHealth: 'all',
                    minRevenue: '',
                    minUsers: '',
                    rewardsState: 'all',
                    customFrom: '',
                    customTo: '',
                },
                columns: { orders: false, teamUsers: false, moduleMix: false },
                sort: { key: 'sales_generated_cents', direction: 'desc' },
                tableMeta: payload.initial?.table?.meta || { page: 1, per_page: 10, total: 0, from: 0, to: 0 },
                tableRows: payload.initial?.table?.data || [],
                kpiCards: payload.initial?.activity?.summary?.cards || [],
                chartState: payload.initial?.activity?.chart || {
                    metric_label: '',
                    unit: 'currency',
                    total: 0,
                    previous_total: 0,
                    delta_label: '',
                    delta_tone: 'neutral',
                    categories: [],
                    series: [],
                    note: '',
                    empty_state: null,
                    window_label: '',
                    chart_type: 'area',
                    stacked: false,
                    xaxis_type: 'datetime',
                    buckets: [],
                },
                tableLoading: false,
                activityLoading: false,
                chartInstance: null,
                selectedRow: null,
                showDrawer: false,
                showMoreFilters: false,
                showColumnPicker: false,
                searchRefreshTimer: null,
                filterRefreshTimer: null,
                tableRequestToken: 0,
                activityRequestToken: 0,

                init() {
                    const hasUrlOverrides = this.readFiltersFromUrl();
                    this.syncMetricForDataset();
                    this.syncGroupForRange();
                    this.renderChart();
                    if (hasUrlOverrides || this.tableRows.length === 0) {
                        this.refreshAll(false);
                    }
                },

                refreshAll(resetPage = true) {
                    if (resetPage) {
                        this.tableMeta.page = 1;
                    }
                    this.syncFiltersToUrl();
                    this.fetchTable();
                    this.fetchActivity();
                },

                queueSearchRefresh() {
                    clearTimeout(this.searchRefreshTimer);
                    this.searchRefreshTimer = setTimeout(() => this.refreshAll(), 250);
                },

                queueFilterRefresh() {
                    clearTimeout(this.filterRefreshTimer);
                    this.filterRefreshTimer = setTimeout(() => this.refreshAll(), 250);
                },

                metricOptionsForDataset() {
                    const dataset = this.chartConfig.datasets?.[this.filters.dataset] || { metrics: [] };
                    return (dataset.metrics || []).map((metricKey) => ({
                        value: metricKey,
                        label: this.chartConfig.metrics?.[metricKey]?.label || metricKey,
                    }));
                },

                groupOptionsForRange() {
                    const range = this.chartConfig.ranges?.[this.filters.range] || { groups: ['day'] };
                    return (range.groups || ['day']).map((group) => ({ value: group, label: this.groupLabel(group) }));
                },

                groupLabel(group) {
                    return { hour: 'Hour', day: 'Day', week: 'Week', month: 'Month' }[group] || group;
                },

                syncMetricForDataset() {
                    const options = this.metricOptionsForDataset();
                    if (! options.find((option) => option.value === this.filters.metric)) {
                        this.filters.metric = options[0]?.value || 'sales_generated';
                    }
                },

                syncGroupForRange() {
                    const options = this.groupOptionsForRange();
                    if (! options.find((option) => option.value === this.filters.groupBy)) {
                        this.filters.groupBy = options[0]?.value || 'day';
                    }
                },

                currentMetricUnit() {
                    return this.chartConfig.metrics?.[this.filters.metric]?.unit || 'count';
                },

                activeDatasetLabel() {
                    return this.datasetOptions.find((option) => option.value === this.filters.dataset)?.label || 'Dataset';
                },

                activeMetricLabel() {
                    return this.metricOptionsForDataset().find((option) => option.value === this.filters.metric)?.label || 'Metric';
                },

                selectedRangeLabel() {
                    if (this.filters.range === 'custom' && this.filters.customFrom && this.filters.customTo) {
                        return `${this.filters.customFrom} to ${this.filters.customTo}`;
                    }

                    return this.chartConfig.ranges?.[this.filters.range]?.label || '30D';
                },

                selectedTenantLabel() {
                    if (this.filters.tenant === 'all') {
                        return 'All tenants';
                    }

                    const option = (this.filterOptions.tenants || []).find((item) => item.value === String(this.filters.tenant));
                    return option ? option.label : 'Selected tenant';
                },

                selectedPlanLabel() {
                    if (this.filters.plan === 'all') {
                        return 'All plans';
                    }

                    const option = (this.filterOptions.plans || []).find((item) => item.value === this.filters.plan);
                    return option ? option.label : 'Plan filter';
                },

                selectedStatusLabel() {
                    if (this.filters.status === 'all') {
                        return 'All statuses';
                    }

                    const option = (this.filterOptions.statuses || []).find((item) => item.value === this.filters.status);
                    return option ? option.label : 'Status filter';
                },

                sharedQueryParams() {
                    const params = new URLSearchParams();
                    params.set('dataset', this.filters.dataset);
                    params.set('metric', this.filters.metric);
                    params.set('range', this.filters.range);
                    params.set('group_by', this.filters.groupBy);
                    params.set('tenant', this.filters.tenant);
                    params.set('status', this.filters.status);
                    params.set('plan', this.filters.plan);
                    params.set('search', this.filters.search || '');
                    params.set('module', this.filters.module);
                    params.set('billing_health', this.filters.billingHealth);
                    params.set('rewards_state', this.filters.rewardsState);
                    if (this.filters.minRevenue !== '') params.set('min_revenue', this.filters.minRevenue);
                    if (this.filters.minUsers !== '') params.set('min_users', this.filters.minUsers);
                    if (this.filters.range === 'custom') {
                        if (this.filters.customFrom) params.set('from', this.filters.customFrom);
                        if (this.filters.customTo) params.set('to', this.filters.customTo);
                    }
                    return params;
                },

                tableQuery() {
                    const params = this.sharedQueryParams();
                    params.set('sort', this.sort.key);
                    params.set('direction', this.sort.direction);
                    params.set('page', this.tableMeta.page || 1);
                    params.set('per_page', this.tableMeta.per_page || 10);
                    return params;
                },

                async fetchTable() {
                    if (! this.routes.table_endpoint) return;
                    const token = ++this.tableRequestToken;
                    this.tableLoading = true;

                    try {
                        const response = await fetch(`${this.routes.table_endpoint}?${this.tableQuery().toString()}`, {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                        });
                        if (! response.ok) throw new Error(`Table request failed (${response.status})`);
                        const data = await response.json();
                        if (token !== this.tableRequestToken) return;

                        this.tableRows = Array.isArray(data.data) ? data.data : [];
                        this.tableMeta = data.meta || this.tableMeta;

                        if (this.selectedRow) {
                            const updatedRow = this.tableRows.find((row) => String(row.id) === String(this.selectedRow.id));
                            if (updatedRow) {
                                this.selectedRow = updatedRow;
                            }
                        }
                    } catch (error) {
                        console.error(error);
                    } finally {
                        if (token === this.tableRequestToken) {
                            this.tableLoading = false;
                        }
                    }
                },

                async fetchActivity() {
                    if (! this.routes.activity_endpoint) return;
                    const token = ++this.activityRequestToken;
                    this.activityLoading = true;

                    try {
                        const response = await fetch(`${this.routes.activity_endpoint}?${this.sharedQueryParams().toString()}`, {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                        });
                        if (! response.ok) throw new Error(`Activity request failed (${response.status})`);
                        const data = await response.json();
                        if (token !== this.activityRequestToken) return;

                        this.kpiCards = data.summary?.cards || [];
                        this.chartState = data.chart || this.chartState;
                        this.activityLoading = false;
                        this.$nextTick(() => this.renderChart());
                    } catch (error) {
                        console.error(error);
                        if (token === this.activityRequestToken) {
                            this.activityLoading = false;
                        }
                    } finally {
                        // renderChart is intentionally driven after loading flips false so
                        // non-empty series mount correctly instead of bailing while loading.
                    }
                },

                renderChart() {
                    if (this.activityLoading || this.chartState.empty_state || ! Array.isArray(this.chartState.series) || this.chartState.series.length === 0) {
                        if (this.chartInstance) {
                            this.chartInstance.destroy();
                            this.chartInstance = null;
                        }
                        return;
                    }

                    const useDatetimeAxis = this.chartState.xaxis_type === 'datetime';
                    const options = {
                        chart: {
                            type: this.chartState.chart_type || 'area',
                            height: 360,
                            toolbar: { show: true },
                            zoom: { enabled: false },
                            stacked: !! this.chartState.stacked,
                            animations: { easing: 'easeinout', speed: 350 },
                            fontFamily: 'Inter, sans-serif',
                        },
                        series: this.chartState.series,
                        colors: ['#123C43', '#2F7D6B', '#A97C50', '#D6A15E', '#6D8494', '#A7B5B7'],
                        stroke: {
                            curve: this.chartState.chart_type === 'area' ? 'smooth' : 'straight',
                            width: this.chartState.chart_type === 'area' ? 3 : 0,
                        },
                        fill: this.chartState.chart_type === 'area'
                            ? { type: 'gradient', gradient: { opacityFrom: 0.28, opacityTo: 0.03 } }
                            : { opacity: 0.9 },
                        dataLabels: { enabled: false },
                        legend: { position: 'top', horizontalAlign: 'left' },
                        plotOptions: { bar: { borderRadius: 6, columnWidth: '55%' } },
                        grid: { borderColor: '#E7ECEB', strokeDashArray: 4 },
                        xaxis: useDatetimeAxis
                            ? {
                                type: 'datetime',
                                labels: { datetimeUTC: false, style: { colors: '#5D6B6A', fontSize: '11px' } },
                                axisBorder: { show: false },
                                axisTicks: { show: false },
                              }
                            : {
                                categories: this.chartState.categories || [],
                                labels: { style: { colors: '#5D6B6A', fontSize: '11px' } },
                                axisBorder: { show: false },
                                axisTicks: { show: false },
                              },
                        yaxis: {
                            labels: {
                                style: { colors: '#5D6B6A', fontSize: '11px' },
                                formatter: (value) => this.formatAxisValue(this.chartState.unit || 'count', value),
                            },
                        },
                        tooltip: {
                            shared: true,
                            intersect: false,
                            y: {
                                formatter: (value) => this.formatMetricValue(this.chartState.unit || 'count', value),
                            },
                        },
                        noData: { text: 'No activity data for this selection yet.' },
                    };

                    if (this.chartInstance) {
                        this.chartInstance.updateOptions(options, true, true);
                        return;
                    }

                    this.chartInstance = new ApexCharts(this.$refs.activityChart, options);
                    this.chartInstance.render();
                },

                setSort(key) {
                    if (this.sort.key === key) {
                        this.sort.direction = this.sort.direction === 'asc' ? 'desc' : 'asc';
                    } else {
                        this.sort.key = key;
                        this.sort.direction = key === 'name' ? 'asc' : 'desc';
                    }
                    this.tableMeta.page = 1;
                    this.fetchTable();
                },

                nextPage() {
                    if ((this.tableMeta.page || 1) * (this.tableMeta.per_page || 10) >= (this.tableMeta.total || 0)) return;
                    this.tableMeta.page += 1;
                    this.fetchTable();
                },

                previousPage() {
                    if ((this.tableMeta.page || 1) <= 1) return;
                    this.tableMeta.page -= 1;
                    this.fetchTable();
                },

                openRow(row) {
                    this.selectedRow = row;
                    this.showDrawer = true;
                },

                closeDrawer() {
                    this.showDrawer = false;
                },

                async exportCsv() {
                    if (! this.routes.table_endpoint) return;
                    const params = this.tableQuery();
                    params.set('page', '1');
                    params.set('per_page', '500');
                    const response = await fetch(`${this.routes.table_endpoint}?${params.toString()}`, {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    });
                    if (! response.ok) {
                        console.error('Export failed');
                        return;
                    }
                    const payload = await response.json();
                    const rows = Array.isArray(payload.data) ? payload.data : [];
                    const header = ['Tenant', 'Status', 'Plan', 'Monthly subscription', 'Subscription income to date', 'Sales generated to date', 'Rewards redeemed to date', 'Customers onboarded', 'Last active', 'MRR contribution'];
                    const lines = [header.join(',')];
                    rows.forEach((row) => {
                        lines.push([
                            row.name,
                            row.status_label,
                            row.plan_label,
                            this.formatCurrency(row.monthly_subscription_cents),
                            this.formatCurrency(row.subscription_income_to_date_cents),
                            this.formatCurrency(row.sales_generated_cents),
                            this.formatCurrency(row.rewards_redeemed_cents),
                            this.formatInteger(row.customers_onboarded),
                            row.last_active_label,
                            this.formatCurrency(row.mrr_contribution_cents),
                        ].map((value) => `"${String(value).replaceAll('"', '""')}"`).join(','));
                    });

                    const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
                    const url = URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.href = url;
                    link.download = 'tenant-management-export.csv';
                    link.click();
                    URL.revokeObjectURL(url);
                },

                readFiltersFromUrl() {
                    const params = new URLSearchParams(window.location.search);
                    const hasUrlOverrides = Array.from(params.keys()).length > 0;
                    this.filters.dataset = params.get('dataset') || this.filters.dataset;
                    this.filters.metric = params.get('metric') || this.filters.metric;
                    this.filters.range = params.get('range') || this.filters.range;
                    this.filters.groupBy = params.get('group_by') || this.filters.groupBy;
                    this.filters.tenant = params.get('tenant') || this.filters.tenant;
                    this.filters.status = params.get('status') || this.filters.status;
                    this.filters.plan = params.get('plan') || this.filters.plan;
                    this.filters.search = params.get('search') || this.filters.search;
                    this.filters.module = params.get('module') || this.filters.module;
                    this.filters.billingHealth = params.get('billing_health') || this.filters.billingHealth;
                    this.filters.rewardsState = params.get('rewards_state') || this.filters.rewardsState;
                    this.filters.customFrom = params.get('from') || this.filters.customFrom;
                    this.filters.customTo = params.get('to') || this.filters.customTo;
                    this.filters.minRevenue = params.get('min_revenue') || this.filters.minRevenue;
                    this.filters.minUsers = params.get('min_users') || this.filters.minUsers;
                    return hasUrlOverrides;
                },

                syncFiltersToUrl() {
                    const params = this.sharedQueryParams();
                    const nextUrl = `${window.location.pathname}?${params.toString()}${window.location.hash}`;
                    history.replaceState({}, '', nextUrl);
                },

                deltaToneClass(tone) {
                    return {
                        up: 'bg-emerald-100 text-emerald-700',
                        down: 'bg-rose-100 text-rose-700',
                        neutral: 'bg-zinc-200 text-zinc-600',
                    }[tone] || 'bg-zinc-200 text-zinc-600';
                },

                statusBadgeClass(tone) {
                    return {
                        emerald: 'bg-emerald-100 text-emerald-700',
                        amber: 'bg-amber-100 text-amber-700',
                        sky: 'bg-sky-100 text-sky-700',
                        zinc: 'bg-zinc-200 text-zinc-600',
                    }[tone] || 'bg-zinc-200 text-zinc-600';
                },

                sparklinePoints(values) {
                    if (! Array.isArray(values) || values.length === 0) return '';
                    const width = 120;
                    const height = 40;
                    const max = Math.max(...values);
                    const min = Math.min(...values);
                    const span = Math.max(max - min, 1);
                    return values.map((value, index) => {
                        const x = (index / Math.max(values.length - 1, 1)) * width;
                        const y = height - (((value - min) / span) * (height - 4)) - 2;
                        return `${x},${y}`;
                    }).join(' ');
                },

                formatAxisValue(unit, value) {
                    if (unit === 'currency') {
                        if (Math.abs(value) >= 100000) {
                            return `$${(value / 100000).toFixed(0)}k`;
                        }
                        return `$${(value / 100).toFixed(0)}`;
                    }

                    return Number(value || 0).toFixed(0);
                },

                formatMetricValue(unit, value) {
                    return unit === 'currency' ? this.formatCurrency(value) : this.formatInteger(value);
                },

                formatCurrency(cents) {
                    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 }).format((Number(cents || 0)) / 100);
                },

                formatInteger(value) {
                    return new Intl.NumberFormat('en-US').format(Number(value || 0));
                },
            };
        }
    </script>
@endpush
