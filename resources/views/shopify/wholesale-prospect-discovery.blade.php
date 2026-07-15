<x-shopify-embedded-shell :authorized="$authorized" :shopify-api-key="$shopifyApiKey" :shop-domain="$shopDomain" :host="$host" :store-label="$storeLabel" :headline="$headline" :subheadline="$subheadline" :app-navigation="$appNavigation" :page-subnav="$pageSubnav ?? []" :page-actions="$pageActions">
    @if ($authorized)
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
            <section class="fb-page-surface p-6">
                <div class="fb-kpi-label">Controlled discovery</div>
                <h2 class="mt-2 text-2xl font-semibold text-zinc-950">Google Places search</h2>
                <p class="mt-2 text-sm text-zinc-600">Results become reviewable prospects. Ratings and review counts are retained as source context, not treated as proof of fit.</p>

                @if (filled($contextToken))
                    <form method="POST" action="{{ route('shopify.app.wholesale.prospects.run', ['store_key' => 'wholesale'], false) }}" class="mt-6 space-y-5">
                        @csrf
                        <input type="hidden" name="context_token" value="{{ $contextToken }}">
                        <input type="hidden" name="shopify_session_token" value="" data-wholesale-session-token>
                        <label class="block"><span class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-500">Search region</span><input name="search_region" required maxlength="190" placeholder="Greenville, South Carolina" class="mt-2 w-full rounded-2xl border border-zinc-300 px-4 py-3"></label>
                        <label class="block"><span class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-500">Search phrases</span><textarea name="search_phrases" required rows="5" placeholder="gift shop&#10;home goods store&#10;outdoor store" class="mt-2 w-full rounded-2xl border border-zinc-300 px-4 py-3"></textarea><span class="mt-2 block text-xs text-zinc-500">One phrase per line or comma-separated; up to 20 phrases.</span></label>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label><span class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-500">Maximum total results</span><input type="number" name="maximum_results" min="1" max="200" value="{{ $defaultSampleSize }}" class="mt-2 w-full rounded-2xl border border-zinc-300 px-4 py-3"><span class="mt-2 block text-xs text-zinc-500">Defaulted to a small sample. Run one category at a time for {{ $defaultSampleSize }} examples from each category.</span></label>
                            <label><span class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-500">Campaign</span><input name="campaign_name" maxlength="190" placeholder="Fall regional expansion" class="mt-2 w-full rounded-2xl border border-zinc-300 px-4 py-3"></label>
                        </div>
                        <label class="flex gap-3 rounded-2xl border border-zinc-200 p-4"><input type="checkbox" name="website_enrichment" value="1" checked class="mt-1"><span><span class="block text-sm font-semibold text-zinc-950">Enrich public websites</span><span class="mt-1 block text-xs text-zinc-600">Respectfully inspect allowed public pages for concise merchandise and business-contact evidence.</span></span></label>
                        <label class="flex gap-3 rounded-2xl border border-zinc-200 p-4"><input type="checkbox" name="large_search_confirmed" value="1" class="mt-1"><span><span class="block text-sm font-semibold text-zinc-950">Confirm a large search</span><span class="mt-1 block text-xs text-zinc-600">Required above {{ $largeSearchThreshold }} results after reviewing the estimate. Configured estimate: ${{ number_format($estimatedCostPerRequest, 4) }} per API request.</span></span></label>
                        <button class="rounded-full bg-zinc-950 px-5 py-2.5 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60" data-wholesale-mutation-button @disabled(! $canRunDiscovery)>Queue discovery</button>
                        <p class="text-xs text-zinc-500" data-wholesale-verification-help>{{ $canRunDiscovery ? 'Shopify admin identity verified.' : 'Finishing Shopify admin verification…' }}</p>
                    </form>
                @else
                    <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">Open this page from the installed wholesale Shopify app before running discovery.</div>
                @endif
            </section>

            <section class="fb-page-surface overflow-hidden self-start">
                <div class="border-b border-zinc-200 px-5 py-4"><h2 class="font-semibold text-zinc-950">Recent runs</h2></div>
                @forelse ($runs as $run)
                    <div class="border-b border-zinc-100 px-5 py-4 last:border-0"><div class="flex justify-between gap-3"><div class="font-semibold text-zinc-950">{{ $run->campaign_name ?: $run->search_region }}</div><span class="text-xs font-semibold uppercase text-zinc-500">{{ $run->status }}</span></div><div class="mt-2 text-sm text-zinc-600">{{ $run->results_created }} created · {{ $run->duplicates_suppressed }} exact duplicates suppressed</div><div class="mt-1 text-xs text-zinc-500">Estimated ${{ number_format((float) $run->estimated_api_cost, 4) }} · actual ${{ number_format((float) $run->actual_api_cost, 4) }}</div></div>
                @empty
                    <div class="px-5 py-10 text-sm text-zinc-600">No discovery runs yet.</div>
                @endforelse
            </section>
        </div>
    @endif

    @include('shopify.partials.wholesale-embedded-mutation-bootstrap')
</x-shopify-embedded-shell>
