<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold">Administration</h1>
    </x-slot>

    <div class="space-y-6">
        <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6 shadow-[0_30px_80px_-50px_rgba(0,0,0,0.9)]">
            <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Administration</div>
            <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">System Controls</div>
            <div class="mt-2 text-sm text-emerald-50/70">Configuration and catalog tools for Production-OS.</div>
        </section>

        <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
            <div class="text-sm font-semibold text-white/90">Locked Dropdown Lists</div>
            <div class="text-sm text-emerald-50/70 mt-1">
                Manage the allowed Scent + Size options used by Shipping orders.
            </div>

            <div class="mt-4">
                <livewire:admin.catalog />
            </div>
        </section>

        <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
            <div class="text-sm font-semibold text-white/90">Scent Mapping</div>
            <div class="text-sm text-emerald-50/70 mt-1">
                Resolve unmapped Shopify lines and create new scents.
            </div>
            <div class="mt-4">
                <a href="{{ route('admin.mapping-exceptions') }}"
                   class="inline-flex items-center rounded-full border border-emerald-400/30 bg-emerald-500/15 px-4 py-2 text-xs font-semibold text-white/90">
                    Open Mapping Queue
                </a>
                <a href="{{ route('admin.import-runs') }}"
                   class="ml-3 inline-flex items-center rounded-full border border-emerald-400/30 bg-emerald-500/10 px-4 py-2 text-xs font-semibold text-white/80">
                    Import Runs
                </a>
            </div>
        </section>
    </div>
</x-app-layout>
