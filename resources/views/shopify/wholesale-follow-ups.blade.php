<x-shopify-embedded-shell :authorized="$authorized" :shopify-api-key="$shopifyApiKey" :shop-domain="$shopDomain" :host="$host" :store-label="$storeLabel" :headline="$headline" :subheadline="$subheadline" :app-navigation="$appNavigation" :page-subnav="$pageSubnav ?? []" :page-actions="$pageActions">
    @if ($authorized)
        <section class="fb-page-surface overflow-hidden">
            @forelse ($followUps as $followUp)
                @php $overdue = in_array($followUp->status, ['open', 'in_progress'], true) && $followUp->due_at?->isPast(); @endphp
                <div class="grid gap-4 border-b border-zinc-100 px-6 py-4 last:border-0 md:grid-cols-[minmax(0,1fr)_140px_160px] md:items-center">
                    <div><div class="flex items-center gap-2"><span class="font-semibold text-zinc-950">{{ $followUp->title }}</span>@if($overdue)<span class="rounded-full bg-rose-100 px-2 py-1 text-xs font-semibold text-rose-700">Overdue</span>@endif</div><div class="mt-1 text-sm text-zinc-600">{{ $followUp->notes ?: 'No note added.' }}</div>@if($followUp->suggestion)<div class="mt-2 text-xs text-zinc-500">From suggestion: {{ $followUp->suggestion->title }}</div>@endif</div>
                    <div><div class="text-xs uppercase text-zinc-500">Due (Eastern)</div><div class="font-semibold">{{ $followUp->due_at?->copy()->timezone('America/New_York')->format('M j, Y g:i A') ?: 'Not scheduled' }}</div></div>
                    <div><div class="text-xs uppercase text-zinc-500">Status</div><div class="font-semibold capitalize">{{ str_replace('_', ' ', $followUp->status) }}</div></div>
                    @if (in_array($followUp->status, ['open', 'in_progress'], true) && filled($contextToken))
                        <form method="POST" action="{{ route('shopify.app.wholesale.follow-ups.update', ['followUpPublicId' => $followUp->public_id, 'store_key' => 'wholesale'], false) }}" class="flex flex-wrap items-end gap-3 md:col-span-3">
                            @csrf
                            <input type="hidden" name="context_token" value="{{ $contextToken }}">
                            <input type="hidden" name="shopify_session_token" value="" data-wholesale-session-token>
                            <label><span class="block text-xs uppercase text-zinc-500">Action</span><select name="action" class="mt-1 rounded-xl border border-zinc-300 px-3 py-2"><option value="complete">Mark completed</option><option value="reschedule">Reschedule</option></select></label>
                            <label><span class="block text-xs uppercase text-zinc-500">New date for rescheduling (Eastern)</span><input type="datetime-local" name="due_local_at" class="mt-1 rounded-xl border border-zinc-300 px-3 py-2"></label>
                            <button class="rounded-full bg-zinc-950 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60" data-wholesale-mutation-button @disabled(! $canDecide)>Update follow-up</button>
                            <p class="w-full text-xs text-zinc-500" data-wholesale-verification-help>{{ $canDecide ? 'Shopify admin identity verified.' : 'Finishing Shopify admin verification…' }}</p>
                        </form>
                    @elseif ($followUp->completed_at)
                        <p class="text-xs text-zinc-500 md:col-span-3">Completed {{ $followUp->completed_at->copy()->timezone('America/New_York')->format('M j, Y g:i A T') }}. Recent completions pause outreach recommendations for 14 days.</p>
                    @endif
                </div>
            @empty
                <div class="px-6 py-12 text-sm text-zinc-600">No wholesale follow-ups have been created.</div>
            @endforelse
        </section>
    @endif
    @include('shopify.partials.wholesale-embedded-mutation-bootstrap')
</x-shopify-embedded-shell>
