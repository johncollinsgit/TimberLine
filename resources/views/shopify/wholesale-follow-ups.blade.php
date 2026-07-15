<x-shopify-embedded-shell :authorized="$authorized" :shopify-api-key="$shopifyApiKey" :shop-domain="$shopDomain" :host="$host" :store-label="$storeLabel" :headline="$headline" :subheadline="$subheadline" :app-navigation="$appNavigation" :page-subnav="$pageSubnav ?? []" :page-actions="$pageActions">
    @if ($authorized)
        <section class="fb-page-surface overflow-hidden">
            @forelse ($followUps as $followUp)
                @php $overdue = in_array($followUp->status, ['open', 'in_progress'], true) && $followUp->due_at?->isPast(); @endphp
                <div class="grid gap-4 border-b border-zinc-100 px-6 py-4 last:border-0 md:grid-cols-[minmax(0,1fr)_140px_160px] md:items-center">
                    <div><div class="flex items-center gap-2"><span class="font-semibold text-zinc-950">{{ $followUp->title }}</span>@if($overdue)<span class="rounded-full bg-rose-100 px-2 py-1 text-xs font-semibold text-rose-700">Overdue</span>@endif</div><div class="mt-1 text-sm text-zinc-600">{{ $followUp->notes ?: 'No note added.' }}</div>@if($followUp->suggestion)<div class="mt-2 text-xs text-zinc-500">From suggestion: {{ $followUp->suggestion->title }}</div>@endif</div>
                    <div><div class="text-xs uppercase text-zinc-500">Due</div><div class="font-semibold">{{ optional($followUp->due_at)->format('M j, Y') ?: 'Not scheduled' }}</div></div>
                    <div><div class="text-xs uppercase text-zinc-500">Status</div><div class="font-semibold capitalize">{{ str_replace('_', ' ', $followUp->status) }}</div></div>
                </div>
            @empty
                <div class="px-6 py-12 text-sm text-zinc-600">No wholesale follow-ups have been created.</div>
            @endforelse
        </section>
    @endif
</x-shopify-embedded-shell>
