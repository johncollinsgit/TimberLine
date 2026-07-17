@php
    $content = (array) ($document['content'] ?? []);
    $pricing = (array) ($document['pricing'] ?? []);
    $money = static fn (array $card): string => isset($card['amount_cents']) ? '$'.number_format($card['amount_cents'] / 100, 2) : (string) ($card['display_amount'] ?? 'To be agreed');
@endphp
<article class="space-y-8 text-zinc-800" data-agreement-document>
    <header class="border-b border-zinc-200 pb-6">
        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-700">Evergrove Software · Everbranch</p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight text-zinc-950">{{ $document['title'] ?? $content['title'] ?? 'Agreement' }}</h1>
        <p class="mt-3 max-w-3xl text-sm leading-6 text-zinc-600">{{ $content['purpose'] ?? '' }}</p>
    </header>

    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach((array) ($content['parties'] ?? []) as $label => $value)
            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ str_replace('_', ' ', $label) }}</p>
                <p class="mt-1 font-semibold text-zinc-950">{{ $value }}</p>
            </div>
        @endforeach
    </section>

    <section>
        <h2 class="text-xl font-semibold text-zinc-950">Who does what</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-3">
            @foreach((array) ($content['responsibilities'] ?? []) as $owner => $items)
                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                    <h3 class="font-semibold text-zinc-950">{{ $owner }}</h3>
                    <ul class="mt-3 list-disc space-y-2 pl-5 text-sm leading-6 text-zinc-600">
                        @foreach((array) $items as $item)<li>{{ $item }}</li>@endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </section>

    <section>
        <div class="flex items-end justify-between gap-4">
            <div><h2 class="text-xl font-semibold text-zinc-950">Pricing and authorization</h2><p class="mt-1 text-sm text-zinc-600">Each card is a separate cost. Shopify and Everbranch are never combined into one price.</p></div>
        </div>
        <div class="mt-6 space-y-7">
            @foreach((array) ($pricing['cost_categories'] ?? []) as $categoryKey => $category)
                @php $categoryCards = collect((array) ($pricing['cards'] ?? []))->where('cost_category', $categoryKey); @endphp
                <section id="cost-{{ str_replace('_', '-', $categoryKey) }}">
                    <h3 class="text-lg font-semibold text-zinc-950">{{ $category['label'] }}</h3>
                    <p class="mt-1 text-sm text-zinc-600">{{ $category['description'] }}</p>
                    <div class="mt-3 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach($categoryCards as $card)
                            @php
                                $isShopify = ($card['owner'] ?? '') === 'Shopify';
                                $isThirdParty = $categoryKey === 'third_party';
                                $isSeparateWorkOrder = ($card['payment_timing'] ?? '') === 'separate_work_order';
                            @endphp
                            <a href="{{ $isShopify ? 'https://www.shopify.com/pricing' : ($isThirdParty ? '#third-party-costs' : '#acceptance') }}" @if($isShopify) target="_blank" rel="noopener noreferrer" @endif class="group block rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition hover:border-emerald-500 hover:shadow-md">
                                <div class="flex items-start justify-between gap-4"><h4 class="font-semibold text-zinc-950">{{ $card['label'] }}</h4><span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-600">{{ $card['owner'] }}</span></div>
                                <p class="mt-5 text-3xl font-semibold tracking-tight text-zinc-950">{{ $money($card) }}</p>
                                <p class="mt-1 text-sm font-medium text-emerald-700">{{ str_replace('_', ' ', $card['frequency']) }}</p>
                                <p class="mt-3 text-sm leading-6 text-zinc-600">{{ $card['detail'] }}</p>
                                <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $isShopify ? 'Review Shopify pricing ↗' : ($isThirdParty ? 'Review separate provider costs ↓' : ($isSeparateWorkOrder ? 'Separate written work order required' : 'Included in this agreement →')) }}</p>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
        <div class="mt-4 space-y-3 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-950">
            <p><strong>Shopify plan:</strong> {{ $pricing['shopify_plan_disclosure'] ?? '' }}</p>
            <p><strong>Taxes and receipts:</strong> {{ $pricing['tax_disclosure'] ?? '' }}</p>
        </div>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-zinc-950">Scope matrix</h2>
        <div class="mt-4 overflow-x-auto rounded-2xl border border-zinc-200">
            <table class="min-w-full divide-y divide-zinc-200 text-left text-sm">
                <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500"><tr><th class="px-4 py-3">Need</th><th class="px-4 py-3">System</th><th class="px-4 py-3">Approach</th></tr></thead>
                <tbody class="divide-y divide-zinc-100 bg-white">
                    @foreach((array) data_get($document, 'scope.matrix', []) as $row)
                        <tr><td class="px-4 py-4 font-medium text-zinc-950">{{ $row['thing'] }}</td><td class="px-4 py-4 text-zinc-600">{{ $row['surface'] }}</td><td class="px-4 py-4 leading-6 text-zinc-600">{{ $row['approach'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="grid gap-4 md:grid-cols-2">
        @foreach((array) data_get($document, 'scope.sections', []) as $section)
            <div class="rounded-2xl border border-zinc-200 bg-white p-5"><h3 class="font-semibold text-zinc-950">{{ $section['title'] }}</h3><p class="mt-2 text-sm leading-6 text-zinc-600">{{ $section['body'] }}</p></div>
        @endforeach
    </section>

    <section>
        <h2 class="text-xl font-semibold text-zinc-950">Implementation phases</h2>
        <p class="mt-1 text-sm text-zinc-600">The project proceeds through reviewable phases. Provider charges and ongoing Everbranch service remain separate from Evergrove implementation work.</p>
        <div class="mt-4 space-y-4">
            @foreach((array) data_get($document, 'scope.implementation_phases', []) as $phase)
                <div class="rounded-2xl border border-zinc-200 bg-white p-5">
                    <div class="flex items-start gap-4"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-800 text-sm font-semibold text-white">{{ $phase['phase'] }}</span><div><h3 class="font-semibold text-zinc-950">{{ $phase['title'] }}</h3><ul class="mt-2 list-disc space-y-2 pl-5 text-sm leading-6 text-zinc-600">@foreach((array) $phase['deliverables'] as $deliverable)<li>{{ $deliverable }}</li>@endforeach</ul></div></div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="grid gap-6 lg:grid-cols-2">
        <div id="third-party-costs" class="rounded-2xl border border-zinc-200 p-5"><h2 class="font-semibold text-zinc-950">Third-party costs</h2><ul class="mt-3 list-disc space-y-2 pl-5 text-sm leading-6 text-zinc-600">@foreach((array) ($content['third_party_costs'] ?? []) as $item)<li>{{ $item }}</li>@endforeach</ul></div>
        <div class="rounded-2xl border border-zinc-200 p-5"><h2 class="font-semibold text-zinc-950">Ownership</h2>@foreach((array) ($content['ownership'] ?? []) as $owner => $term)<p class="mt-3 text-sm leading-6 text-zinc-600"><strong class="capitalize text-zinc-950">{{ $owner }}:</strong> {{ $term }}</p>@endforeach</div>
        <div class="rounded-2xl border border-zinc-200 p-5"><h2 class="font-semibold text-zinc-950">Client responsibilities</h2><p class="mt-3 text-sm leading-6 text-zinc-600">{{ $content['client_responsibilities'] ?? '' }}</p></div>
        <div class="rounded-2xl border border-zinc-200 p-5"><h2 class="font-semibold text-zinc-950">Platform and provider limits</h2><p class="mt-3 text-sm leading-6 text-zinc-600">{{ $content['platform_availability'] ?? '' }}</p></div>
    </section>

    <section class="rounded-2xl border border-zinc-900 bg-zinc-950 p-6 text-white">
        <h2 class="text-xl font-semibold">Termination and data export</h2>
        <p class="mt-2 text-sm leading-6 text-zinc-300">30 days’ notice. The operational export window remains open for 30 days after the effective termination date.</p>
        <ul class="mt-4 list-disc space-y-2 pl-5 text-sm leading-6 text-zinc-300">@foreach((array) data_get($document, 'termination.terms', []) as $term)<li>{{ $term }}</li>@endforeach</ul>
    </section>

    <section class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5"><h2 class="font-semibold text-emerald-950">Electronic acceptance</h2><p class="mt-2 text-sm leading-6 text-emerald-900">{{ $content['electronic_acceptance'] ?? '' }}</p></section>
</article>
