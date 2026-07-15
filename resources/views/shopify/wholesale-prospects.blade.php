@php $embeddedUrl = static fn (string $url): string => app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class)->append($url, request()); @endphp
<x-shopify-embedded-shell :authorized="$authorized" :shopify-api-key="$shopifyApiKey" :shop-domain="$shopDomain" :host="$host" :store-label="$storeLabel" :headline="$headline" :subheadline="$subheadline" :app-navigation="$appNavigation" :page-subnav="$pageSubnav ?? []" :page-actions="$pageActions">
    @if ($authorized)
        <div class="space-y-6">
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                @foreach ([['Prospects', $analytics['total']], ['New', $analytics['new']], ['Qualified', $analytics['qualified']], ['Converted', $analytics['converted']], ['Conversion rate', $analytics['conversion_rate'].'%']] as [$label, $value])
                    <div class="fb-page-surface p-5"><div class="fb-kpi-label">{{ $label }}</div><div class="mt-2 text-3xl font-semibold text-zinc-950">{{ $value }}</div></div>
                @endforeach
            </section>
            <section class="fb-page-surface fb-page-surface--subtle p-6">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div><div class="fb-kpi-label">Review queue</div><h2 class="mt-2 text-2xl font-semibold text-zinc-950">Prospects are not customers</h2><p class="mt-2 text-sm text-zinc-600">Conversion requires a user decision or verified wholesale activity. No outreach is sent from this queue.</p></div>
                    <a href="{{ $embeddedUrl(route('shopify.app.wholesale.prospects.discover', [], false)) }}" class="rounded-full bg-zinc-950 px-5 py-2.5 text-sm font-semibold text-white">Discover prospects</a>
                </div>
            </section>
            @php
                $mapped = $prospects->filter(fn ($prospect) => $prospect->latitude !== null && $prospect->longitude !== null);
                $minLat = (float) ($mapped->min('latitude') ?? 0); $maxLat = (float) ($mapped->max('latitude') ?? 0);
                $minLng = (float) ($mapped->min('longitude') ?? 0); $maxLng = (float) ($mapped->max('longitude') ?? 0);
            @endphp
            <section class="fb-page-surface overflow-hidden">
                <div class="border-b border-zinc-200 px-6 py-4"><h2 class="font-semibold text-zinc-950">Geographic coverage</h2><p class="mt-1 text-sm text-zinc-600">Coordinate-backed prospects only. Select a point to review its evidence.</p></div>
                @if ($mapped->isNotEmpty())
                    <div class="relative h-80 overflow-hidden bg-[linear-gradient(135deg,#f4f4f5_25%,transparent_25%),linear-gradient(225deg,#f4f4f5_25%,transparent_25%),linear-gradient(45deg,#f4f4f5_25%,transparent_25%),linear-gradient(315deg,#f4f4f5_25%,#fafafa_25%)] bg-[length:28px_28px]">
                        @foreach ($mapped as $prospect)
                            @php
                                $left = $maxLng === $minLng ? 50 : 5 + ((((float) $prospect->longitude - $minLng) / ($maxLng - $minLng)) * 90);
                                $top = $maxLat === $minLat ? 50 : 5 + ((($maxLat - (float) $prospect->latitude) / ($maxLat - $minLat)) * 90);
                            @endphp
                            <a href="{{ $embeddedUrl(route('shopify.app.wholesale.prospects.show', ['prospectPublicId' => $prospect->public_id], false)) }}" title="{{ $prospect->business_name }} · fit {{ $prospect->fit_score }}" class="absolute -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-emerald-700 shadow ring-2 ring-emerald-200" style="left: {{ $left }}%; top: {{ $top }}%; width: {{ 12 + round($prospect->fit_score / 12) }}px; height: {{ 12 + round($prospect->fit_score / 12) }}px;"><span class="sr-only">{{ $prospect->business_name }}</span></a>
                        @endforeach
                    </div>
                @else
                    <div class="px-6 py-10 text-sm text-zinc-600">No mapped prospects yet.</div>
                @endif
            </section>
            <section class="fb-page-surface overflow-hidden">
                @forelse ($prospects as $prospect)
                    <a href="{{ $embeddedUrl(route('shopify.app.wholesale.prospects.show', ['prospectPublicId' => $prospect->public_id], false)) }}" class="grid gap-3 border-b border-zinc-100 px-6 py-4 last:border-0 hover:bg-zinc-50 md:grid-cols-[minmax(0,1fr)_130px_110px_150px] md:items-center">
                        <div><div class="font-semibold text-zinc-950">{{ $prospect->business_name }}</div><div class="mt-1 text-sm text-zinc-600">{{ $prospect->primary_category ? str_replace('_', ' ', $prospect->primary_category) : 'Category needs review' }} · {{ collect([$prospect->city, $prospect->state])->filter()->join(', ') ?: 'Location unavailable' }}</div></div>
                        <div><div class="text-xs uppercase text-zinc-500">Status</div><div class="font-semibold capitalize">{{ str_replace('_', ' ', $prospect->status) }}</div></div>
                        <div><div class="text-xs uppercase text-zinc-500">Fit</div><div class="font-semibold">{{ $prospect->fit_score }}/100</div></div>
                        <div><div class="text-xs uppercase text-zinc-500">Evidence</div><div class="font-semibold">{{ $prospect->fit_confidence }}% confidence</div></div>
                    </a>
                @empty
                    <div class="px-6 py-12 text-sm text-zinc-600">No prospects have been discovered. Start a controlled search to create the review queue.</div>
                @endforelse
            </section>
        </div>
    @endif
</x-shopify-embedded-shell>
