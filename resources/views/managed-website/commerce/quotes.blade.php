<x-layouts::app.sidebar title="Quotes">
    <flux:main>
        <div class="mx-auto max-w-6xl space-y-5 pb-10">
            @if(session('status'))<div class="border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-950" role="status">{{ session('status') }}</div>@endif
            <x-ui.operational-header title="Quotes" description="Website quote requests with their requested quantity and reply history." :back="['href' => route('managed-website.orders.index'), 'label' => 'Orders']">
                <a class="fb-btn fb-btn-secondary" href="{{ route('managed-website.orders.index') }}">Orders</a>
                <a class="fb-btn fb-btn-primary" href="{{ route('managed-website.quotes.index') }}">Quotes</a>
            </x-ui.operational-header>

            <section class="overflow-hidden border border-zinc-200 bg-white">
                <div class="divide-y divide-zinc-100">
                    @forelse($quotes as $quote)
                        @php($attribution = (array) data_get($quote->metadata, 'quote_attribution', []))
                        @php($response = (array) data_get($quote->metadata, 'quote_response', []))
                        <article class="p-5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div><p class="text-xs font-bold uppercase tracking-[.14em] text-emerald-800">{{ $quote->status === 'responded' ? 'Responded' : 'New quote request' }}</p><h2 class="mt-1 text-lg font-bold text-zinc-950">{{ $quote->submitter_name }}</h2><p class="mt-1 text-sm text-zinc-600"><a class="hover:underline" href="mailto:{{ $quote->submitter_email }}">{{ $quote->submitter_email }}</a> · <a class="hover:underline" href="tel:{{ $quote->submitter_phone }}">{{ $quote->submitter_phone }}</a></p></div>
                                <time class="text-xs text-zinc-500">{{ $quote->submitted_at?->format('M j, Y g:i A') }}</time>
                            </div>
                            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3"><div><dt class="font-semibold text-zinc-500">Product</dt><dd>{{ data_get($quote->payload, 'productSlug') }}</dd></div><div><dt class="font-semibold text-zinc-500">Quantity</dt><dd>{{ data_get($quote->payload, 'quantity') }}</dd></div><div><dt class="font-semibold text-zinc-500">Finish</dt><dd>{{ data_get($quote->payload, 'finish') }}</dd></div></dl>
                            <p class="mt-4 text-sm leading-6 text-zinc-700">{{ data_get($quote->payload, 'message') }}</p>
                            @if(data_get($attribution, 'website_customer_id') || data_get($attribution, 'website_order_id'))<p class="mt-4 border-l-2 border-emerald-700 pl-3 text-sm text-emerald-900">Matched by exact email and phone: @if(data_get($attribution, 'website_customer_id'))<a class="font-medium underline" href="{{ route('managed-website.customers.show', data_get($attribution, 'website_customer_id')) }}">customer</a>@endif @if(data_get($attribution, 'website_order_id')) · <a class="font-medium underline" href="{{ route('managed-website.orders.show', data_get($attribution, 'website_order_id')) }}">order</a>@endif</p>@endif
                            @if($response)<div class="mt-4 border-t border-zinc-200 pt-4 text-sm"><p class="font-semibold text-zinc-800">Last response</p><p class="mt-1 whitespace-pre-line text-zinc-700">{{ data_get($response, 'reply') }}</p><p class="mt-1 text-xs text-zinc-500">{{ data_get($response, 'responded_at') }}</p></div>@endif
                            <details class="mt-4 border-t border-zinc-200 pt-4"><summary class="cursor-pointer text-sm font-medium text-emerald-800">Reply to this quote</summary><form class="mt-3 grid gap-3" method="POST" action="{{ route('managed-website.quotes.reply', $quote) }}">@csrf<label class="text-sm font-medium text-zinc-800">Subject<input class="mt-1 block w-full rounded-md border-zinc-300 text-sm" required name="subject" value="Your Carolina Barrel Co. quote request"></label><label class="text-sm font-medium text-zinc-800">Response<textarea class="mt-1 block w-full rounded-md border-zinc-300 text-sm" required name="reply" rows="5" placeholder="Include the quoted price, timeline, delivery details, and next step."></textarea></label><button class="fb-btn fb-btn-primary w-fit" type="submit">Send email response</button></form></details>
                        </article>
                    @empty
                        <div class="p-12 text-center text-sm text-zinc-500">Website quote requests will appear here.</div>
                    @endforelse
                </div>
            </section>
            {{ $quotes->links() }}
        </div>
    </flux:main>
</x-layouts::app.sidebar>
