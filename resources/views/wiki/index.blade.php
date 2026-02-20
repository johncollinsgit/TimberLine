<x-layouts::app :title="'Backstage Wiki'">
    <div class="mx-auto w-full max-w-[1200px] px-4 md:px-6 py-8 mf-container space-y-6">
        <section class="rounded-3xl border border-emerald-200/10 bg-[#101513]/80 p-6">
            <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-100/60">Backstage Wiki</div>
            <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-white">Reference Library</div>
            <div class="mt-2 text-sm text-emerald-50/70">Read-only documentation sourced from live data.</div>
        </section>

        <div class="grid gap-4 md:grid-cols-2">
            <a href="{{ route('wiki.article', ['slug' => 'market-room']) }}" class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 p-5 hover:bg-emerald-500/10 transition">
                <div class="text-sm font-semibold text-white">Market Room Process</div>
                <div class="mt-1 text-xs text-emerald-100/60">Operations -> Events / Market Room process guide.</div>
            </a>
            <a href="{{ route('wiki.wholesale-processes') }}" class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 p-5 hover:bg-emerald-500/10 transition">
                <div class="text-sm font-semibold text-white">Wholesale Processes</div>
                <div class="mt-1 text-xs text-emerald-100/60">Wholesale workflow playbooks and checklists.</div>
            </a>
            <a href="/wiki/oil-blends" class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 p-5 hover:bg-emerald-500/10 transition">
                <div class="text-sm font-semibold text-white">Oil Blend Recipes</div>
                <div class="mt-1 text-xs text-emerald-100/60">Global blends and component ratios.</div>
            </a>
            <a href="/wiki/wholesale-custom-scents" class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 p-5 hover:bg-emerald-500/10 transition">
                <div class="text-sm font-semibold text-white">Wholesale Custom Scents</div>
                <div class="mt-1 text-xs text-emerald-100/60">Account-specific names mapped to canonical scents.</div>
            </a>
            <a href="/wiki/candle-club" class="rounded-2xl border border-emerald-200/10 bg-emerald-500/5 p-5 hover:bg-emerald-500/10 transition">
                <div class="text-sm font-semibold text-white">Candle Club</div>
                <div class="mt-1 text-xs text-emerald-100/60">Monthly Candle Club scent archive.</div>
            </a>
        </div>
    </div>
</x-layouts::app>
