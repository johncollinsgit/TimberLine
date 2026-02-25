<x-layouts::app :title="'Analytics'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0 mf-responsive-shell">
        <section class="rounded-3xl border border-white/10 bg-white/5 p-5 sm:p-6 shadow-[0_24px_60px_-42px_rgba(0,0,0,0.6)]">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <div class="text-[11px] uppercase tracking-[0.32em] text-white/55">Analytics</div>
                    <h1 class="mt-2 text-2xl sm:text-3xl font-semibold text-white">Metrics + Operational Widgets</h1>
                    <p class="mt-2 text-sm text-white/65">The data brain for daily operations, queue health, and trend tracking.</p>
                </div>
                <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80 hover:bg-white/10">
                    Back to Dashboard
                </a>
            </div>
        </section>

        <section class="space-y-6 min-w-0">
            <livewire:dashboard.dashboard-widgets />
        </section>

        <section class="space-y-6 min-w-0">
            <livewire:analytics.analytics-widgets />
        </section>
    </div>
</x-layouts::app>
