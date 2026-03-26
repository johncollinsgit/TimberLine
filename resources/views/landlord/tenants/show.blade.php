<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Tenant Detail</h1>
    </x-slot>

    <div class="space-y-6">
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
