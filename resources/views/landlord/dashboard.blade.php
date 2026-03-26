<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Landlord Console</h1>
    </x-slot>

    <div class="space-y-6">
        <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 shadow-[0_30px_80px_-50px_rgba(0,0,0,0.9)]">
            <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Landlord</div>
            <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Landlord Operator Console</div>
            <div class="mt-2 text-sm text-emerald-50/70">
                Read-only tenant visibility for host-based operator access.
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5">
                <p class="text-xs uppercase tracking-[0.2em] text-emerald-100/50">Total Tenants</p>
                <p class="mt-2 text-3xl font-semibold text-white">{{ number_format((int) ($metrics['total_tenants'] ?? 0)) }}</p>
            </article>
            <article class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5">
                <p class="text-xs uppercase tracking-[0.2em] text-emerald-100/50">Healthy</p>
                <p class="mt-2 text-3xl font-semibold text-white">{{ number_format((int) ($metrics['healthy_tenants'] ?? 0)) }}</p>
            </article>
            <article class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5">
                <p class="text-xs uppercase tracking-[0.2em] text-emerald-100/50">Shopify Connected</p>
                <p class="mt-2 text-3xl font-semibold text-white">{{ number_format((int) ($metrics['tenants_with_connected_shopify'] ?? 0)) }}</p>
            </article>
            <article class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-5">
                <p class="text-xs uppercase tracking-[0.2em] text-emerald-100/50">Needs Attention</p>
                <p class="mt-2 text-3xl font-semibold text-white">{{ number_format((int) ($metrics['tenants_needing_attention'] ?? 0)) }}</p>
            </article>
        </section>

        <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-white">Recent Tenants</h2>
                    <p class="mt-1 text-sm text-emerald-50/70">Most recently created tenants.</p>
                </div>
                <a
                    href="{{ route('landlord.tenants.index') }}"
                    class="inline-flex items-center rounded-full border border-emerald-400/30 bg-emerald-500/15 px-4 py-2 text-xs font-semibold text-white/90"
                >
                    Open Tenant Directory
                </a>
            </div>

            <div class="mt-4 overflow-hidden rounded-2xl border border-emerald-200/10">
                <table class="w-full divide-y divide-emerald-200/10 text-sm">
                    <thead class="bg-white/5 text-left text-xs uppercase tracking-[0.12em] text-emerald-100/60">
                        <tr>
                            <th class="px-4 py-3">Tenant</th>
                            <th class="px-4 py-3">Slug</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-emerald-200/10">
                        @forelse ($recent_tenants as $row)
                            <tr class="text-emerald-50/85">
                                <td class="px-4 py-3">
                                    <a
                                        href="{{ route('landlord.tenants.show', ['tenant' => $row['id']]) }}"
                                        class="font-semibold text-white hover:text-emerald-200"
                                    >
                                        {{ $row['name'] }}
                                    </a>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $row['slug'] }}</td>
                                <td class="px-4 py-3">{{ $row['status_label'] }}</td>
                                <td class="px-4 py-3">{{ $row['created_at'] ?? 'n/a' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-5 text-sm text-emerald-50/65">
                                    No tenants found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
