<x-layouts::app :title="'Backstage Wiki'">
    <div class="mx-auto w-full max-w-[1200px] px-4 md:px-6 py-8 mf-container space-y-6">
        <section class="rounded-3xl border border-zinc-200 bg-white p-6">
            <div class="text-[11px] uppercase tracking-[0.35em] text-emerald-800">Backstage Wiki</div>
            <div class="mt-2 text-3xl font-['Fraunces'] font-semibold text-zinc-950">Reference Library</div>
            <div class="mt-2 text-sm text-zinc-600">Read-only documentation sourced from live data.</div>
        </section>

        <div class="grid gap-4 md:grid-cols-2">
            <a href="{{ route('wiki.article', ['slug' => 'market-room']) }}" class="rounded-2xl border border-zinc-200 bg-emerald-50 p-5 hover:bg-emerald-100 transition">
                <div class="text-sm font-semibold text-zinc-950">Market Room Process</div>
                <div class="mt-1 text-xs text-emerald-800">Operations -> Events / Market Room process guide.</div>
            </a>
            <a href="{{ route('wiki.wholesale-processes') }}" class="rounded-2xl border border-zinc-200 bg-emerald-50 p-5 hover:bg-emerald-100 transition">
                <div class="text-sm font-semibold text-zinc-950">Wholesale Processes</div>
                <div class="mt-1 text-xs text-emerald-800">Wholesale workflow playbooks and checklists.</div>
            </a>
            <a href="/wiki/oil-blends" class="rounded-2xl border border-zinc-200 bg-emerald-50 p-5 hover:bg-emerald-100 transition">
                <div class="text-sm font-semibold text-zinc-950">Oil Blend Recipes</div>
                <div class="mt-1 text-xs text-emerald-800">Global blends and component ratios.</div>
            </a>
            <a href="/wiki/wholesale-custom-scents" class="rounded-2xl border border-zinc-200 bg-emerald-50 p-5 hover:bg-emerald-100 transition">
                <div class="text-sm font-semibold text-zinc-950">Wholesale Custom Scents</div>
                <div class="mt-1 text-xs text-emerald-800">Account-specific names mapped to canonical scents.</div>
            </a>
            <a href="/wiki/candle-club" class="rounded-2xl border border-zinc-200 bg-emerald-50 p-5 hover:bg-emerald-100 transition">
                <div class="text-sm font-semibold text-zinc-950">Candle Club</div>
                <div class="mt-1 text-xs text-emerald-800">Monthly Candle Club scent archive.</div>
            </a>
        </div>
    </div>
</x-layouts::app>
