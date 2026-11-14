<x-layouts::app :title="$detail['customer']['name'] ?? 'Customers'">
    <main class="mx-auto max-w-6xl space-y-6 px-4 py-6 sm:px-6">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div><p class="text-sm text-zinc-500">{{ $tenant->name }}</p><h1 class="text-3xl font-semibold">{{ $detail['customer']['name'] ?? 'Customers' }}</h1><p class="mt-2 text-zinc-600">Purchases, events and rewards connected to one customer.</p></div>
            <nav class="flex flex-wrap gap-3" aria-label="Customer workspace">
                <a class="inline-flex min-h-11 items-center rounded-xl border px-4" href="{{ route('customers.directory', [...($detail ? array_diff_key($query, ['purchase_page'=>true]) : []), 'tenant' => $tenant->slug]) }}">{{ $detail ? 'Back to customers' : 'All customers' }}</a>
                @if($canManage)<a class="inline-flex min-h-11 items-center rounded-xl border px-4" href="{{ route('customers.reconciliation', ['tenant' => $tenant->slug]) }}">Sources &amp; reconciliation</a><a class="inline-flex min-h-11 items-center rounded-xl border px-4" href="{{ route('marketing.event-audiences', ['tenant' => $tenant->slug, 'event_id' => $query['event_id'] ?? null]) }}">Marketing audiences</a>@endif
            </nav>
        </header>
        @if($detail)
            <section class="rounded-2xl border bg-white p-5 dark:bg-zinc-900">
                <h2 class="text-lg font-semibold">Contact</h2><div class="mt-3 flex flex-wrap gap-4">
                    @if($detail['customer']['email'])<a class="min-h-11 break-all py-2 underline" href="mailto:{{ $detail['customer']['email'] }}">{{ $detail['customer']['email'] }}</a>@endif
                    @if($detail['customer']['phone'])<a class="min-h-11 py-2 underline" href="tel:{{ $detail['customer']['phone'] }}">{{ $detail['customer']['phone'] }}</a>@endif
                </div>
            </section>
            <x-customer-commerce :snapshot="$detail['commerce']" :rewards-label="data_get($detail, 'commerce.rewards.label', 'Rewards')" />
            <x-customer-birthday :birthday="$detail['birthday'] ?? null" />
            @if($purchasePage > 1 || $purchaseHasMore)<nav class="flex flex-wrap items-center gap-3" aria-label="Customer purchase history"><span class="text-sm text-zinc-500">Linked purchases · page {{ $purchasePage }}</span>@if($purchasePage > 1)<a class="inline-flex min-h-11 items-center rounded-xl border px-4" href="{{ route('customers.profile', [...$query, 'profile'=>$profile->id, 'tenant'=>$tenant->slug, 'purchase_page'=>$purchasePage-1]) }}">Newer purchases</a>@endif@if($purchaseHasMore && $purchasePage < 100)<a class="inline-flex min-h-11 items-center rounded-xl border px-4" href="{{ route('customers.profile', [...$query, 'profile'=>$profile->id, 'tenant'=>$tenant->slug, 'purchase_page'=>$purchasePage+1]) }}">Older purchases</a>@endif</nav>@endif
            @if($squareSource)@include('customers.square-source')@endif
        @else
            <form method="GET" class="grid gap-4 rounded-2xl border bg-white p-5 sm:grid-cols-3 dark:bg-zinc-900">
                <input type="hidden" name="tenant" value="{{ $tenant->slug }}">
                <label class="space-y-2"><span class="block font-medium">Find a customer</span><input class="min-h-11 w-full rounded-lg border px-3 text-base" type="search" name="query" value="{{ $query['query'] ?? '' }}" placeholder="Name, phone or email"></label>
                @if($canManage)<label class="space-y-2"><span class="block font-medium">Event</span><select name="event_id" class="min-h-11 w-full rounded-lg border px-3 text-base"><option value="">All events</option>@foreach($events as $event)<option value="{{ $event['id'] }}" @selected(($query['event_id'] ?? null) == $event['id'])>{{ $event['name'] }}</option>@endforeach</select></label>@endif
                <button class="eb-primary-button min-h-11 self-end rounded-lg px-5 py-3">Find customers</button>
            </form>
            @if($context && $context['review_items'])<p class="rounded-xl bg-amber-50 p-4">{{ implode(' ', $context['review_items']) }}</p>@endif
            @if($results)
                <div class="grid gap-3 sm:grid-cols-2">
                    @forelse($results['customers'] as $customer)
                        <a class="block min-h-24 rounded-2xl border bg-white p-5 dark:bg-zinc-900" href="{{ route('customers.profile', [...$query, 'profile' => $customer['id'], 'tenant' => $tenant->slug]) }}"><h2 class="text-lg font-semibold">{{ $customer['name'] }}</h2><p class="mt-2 break-all text-sm text-zinc-500">{{ implode(' · ', array_filter([$customer['phone'], $customer['email']])) }}</p></a>
                    @empty<p class="rounded-2xl border p-6">No matching customers. Try another name, phone or email, or review your source imports.</p>@endforelse
                </div>
                @if($results['has_more'])<a class="inline-flex min-h-11 items-center rounded-xl border px-5" href="{{ route('customers.directory', [...$query, 'tenant' => $tenant->slug, 'page' => $results['page'] + 1]) }}">More customers</a>@endif
            @else<p class="rounded-2xl border p-6">Search your existing customers or choose an event. Pay and Marketing use these same customer records.</p>@endif
        @endif
    </main>
</x-layouts::app>
