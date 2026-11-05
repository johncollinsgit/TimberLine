@php $embeddedUrl = static fn (string $url): string => app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class)->append($url, request()); $showHistory = (bool) ($showHistory ?? false); @endphp
<x-shopify-embedded-shell :authorized="$authorized" :shopify-api-key="$shopifyApiKey" :shop-domain="$shopDomain" :host="$host" :store-label="$storeLabel" :headline="$headline" :subheadline="$subheadline" :app-navigation="$appNavigation" :page-subnav="$pageSubnav ?? []" :page-actions="$pageActions">
    @if ($authorized)
        @include('shopify.partials.wholesale-reporting-status')
        <nav aria-label="Recommendation views" class="mb-5 flex gap-5 text-sm font-semibold">
            <a href="{{ $embeddedUrl(route('shopify.app.wholesale.suggestions', ['history' => 0], false)) }}" class="underline" @if(! $showHistory) aria-current="page" @endif>Current recommendations</a>
            <a href="{{ $embeddedUrl(route('shopify.app.wholesale.suggestions', ['history' => 1], false)) }}" class="underline" data-wholesale-suggestion-history @if($showHistory) aria-current="page" @endif>Decision history</a>
        </nav>
        <div class="space-y-5">
            @forelse ($suggestions as $suggestion)
                <article class="fb-page-surface overflow-hidden">
                    <div class="grid gap-6 p-6 xl:grid-cols-[minmax(0,1fr)_360px]">
                        <div>
                            <div class="flex flex-wrap items-center gap-2"><span class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-xs font-semibold uppercase text-zinc-600">{{ str_replace('_', ' ', $suggestion->suggestion_type) }}</span><span class="rounded-full border border-zinc-200 px-3 py-1 text-xs font-semibold uppercase text-zinc-600">{{ $suggestion->priority }}</span><span class="rounded-full border border-zinc-200 px-3 py-1 text-xs font-semibold uppercase text-zinc-600">History evidence score: {{ $suggestion->confidence }}/100</span><span class="rounded-full border border-zinc-200 px-3 py-1 text-xs font-semibold uppercase text-zinc-600">{{ str_replace('_', ' ', $suggestion->status) }}</span></div>
                            <h2 class="mt-4 text-xl font-semibold text-zinc-950">{{ $suggestion->title }}</h2>
                            <p class="mt-2 text-sm text-zinc-700">{{ $suggestion->reason }}</p>
                            <div class="mt-5 rounded-2xl border border-sky-200 bg-sky-50 p-4"><div class="text-xs font-semibold uppercase text-sky-700">{{ $showHistory ? 'Original recommended action' : 'Recommended action' }}</div><p class="mt-2 text-sm text-sky-950">{{ $suggestion->recommended_action }}</p></div>
                            <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                @foreach ([
                                    ['Last order', data_get($suggestion->supporting_evidence, 'last_wholesale_order_at') ? \Carbon\Carbon::parse(data_get($suggestion->supporting_evidence, 'last_wholesale_order_at'))->timezone('America/New_York')->format('M j, Y') : '—'],
                                    ['Days since', data_get($suggestion->supporting_evidence, 'days_since_last_wholesale_order', '—')],
                                    ['Median reorder', data_get($suggestion->supporting_evidence, 'median_reorder_days') ? data_get($suggestion->supporting_evidence, 'median_reorder_days').' days' : '—'],
                                    ['Opportunity', $suggestion->estimated_opportunity !== null ? '$'.number_format((float) $suggestion->estimated_opportunity, 2) : '—'],
                                ] as [$label, $value])
                                    <div><div class="text-xs font-semibold uppercase text-zinc-500">{{ $label }}</div><div class="mt-1 font-semibold text-zinc-950">{{ $value }}</div></div>
                                @endforeach
                            </div>
                            @if ($suggestion->target_type === 'customer')
                                <a href="{{ $embeddedUrl(route('shopify.app.wholesale.customers.show', ['accountKey' => $suggestion->target_key], false)) }}" class="mt-5 inline-flex text-sm font-semibold text-zinc-800 underline">Open wholesale customer</a>
                            @endif
                        </div>

                        <div>
                            @if (filled($contextToken) && in_array($suggestion->status, ['pending', 'account_review', 'data_review', 'snoozed'], true))
                                <form method="POST" action="{{ route('shopify.app.wholesale.suggestions.decide', ['suggestionPublicId' => $suggestion->public_id, 'store_key' => 'wholesale'], false) }}" class="space-y-4 rounded-2xl border border-zinc-200 bg-zinc-50 p-5">
                                    @csrf
                                    <input type="hidden" name="context_token" value="{{ $contextToken }}">
                                    <input type="hidden" name="shopify_session_token" value="" data-wholesale-session-token>
                                    <label class="block"><span class="text-xs font-semibold uppercase text-zinc-500">Decision</span><select name="action" class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5"><option value="create_follow_up">Accept and create follow-up</option><option value="accept">Accept</option><option value="snooze">Snooze</option><option value="already_handled">Already handled</option><option value="dismiss">Dismiss</option><option value="mark_incorrect">Data is incorrect</option><option value="request_review">Request account review</option></select></label>
                                    <label class="block"><span class="text-xs font-semibold uppercase text-zinc-500">Due / snooze date (Eastern)</span><input type="datetime-local" name="decision_local_at" class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5"></label>
                                    <p class="text-xs text-zinc-500">Choose a future date to snooze. Leave blank to use the suggested date for a new follow-up.</p>
                                    <label class="block"><span class="text-xs font-semibold uppercase text-zinc-500">Dismissal reason</span><input name="dismissal_reason" maxlength="255" class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5"></label>
                                    <label class="block"><span class="text-xs font-semibold uppercase text-zinc-500">Note</span><textarea name="note" rows="3" maxlength="2000" class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5"></textarea></label>
                                    <button class="rounded-full bg-zinc-950 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60" data-wholesale-mutation-button @disabled(! $canDecide)>Record decision</button>
                                    <p class="text-xs text-zinc-500" data-wholesale-verification-help>{{ $canDecide ? 'Shopify admin identity verified.' : 'Finishing Shopify admin verification…' }}</p>
                                </form>
                            @elseif(! in_array($suggestion->status, ['pending', 'account_review', 'data_review', 'snoozed'], true))
                                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">Historical entry retained with its decisions. Use current recommendations for new follow-ups.</div>
                            @else
                                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">Open this page from the installed wholesale Shopify app to record a decision.</div>
                            @endif
                        </div>
                    </div>

                    @if ($suggestion->decisions->isNotEmpty())
                        <div class="border-t border-zinc-200 bg-zinc-50 px-6 py-4"><div class="text-xs font-semibold uppercase text-zinc-500">Decision history</div>@foreach($suggestion->decisions as $decision)<div class="mt-2 text-sm text-zinc-700">{{ str_replace('_', ' ', $decision->action) }} · {{ $decision->decided_at->copy()->timezone('America/New_York')->format('M j, Y g:i A') }}{{ $decision->note ? ' · '.$decision->note : '' }}</div>@endforeach</div>
                    @endif
                </article>
            @empty
                <section class="fb-page-surface px-6 py-12 text-sm text-zinc-600">{{ $showHistory ? 'No historical recommendations have been recorded.' : 'No wholesale suggestions are waiting for review. Recommendations use verified wholesale history only.' }}</section>
            @endforelse
        </div>
    @endif


    @include('shopify.partials.wholesale-embedded-mutation-bootstrap')
</x-shopify-embedded-shell>
