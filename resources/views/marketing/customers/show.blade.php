<x-layouts::app :title="'Marketing Customer Detail'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Customer Detail"
            description="Detailed marketing identity view with linked source records and related operational order context."
            hint-title="How to use this detail page"
            hint-text="This profile is a marketing-layer identity record. Linked source rows show how operational data is connected without replacing operational tables."
        />

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    @php
                        $name = trim((string) ($profile->first_name . ' ' . $profile->last_name));
                    @endphp
                    <h2 class="text-xl font-semibold text-white">{{ $name !== '' ? $name : 'Unnamed profile' }}</h2>
                    <div class="mt-1 text-xs text-white/50">Marketing Profile #{{ $profile->id }}</div>
                </div>
                <a href="{{ route('marketing.customers') }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/75 hover:bg-white/10">
                    Back to Customers
                </a>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Email</div>
                    <div class="mt-2 text-sm text-white">{{ $profile->email ?: '—' }}</div>
                    <div class="mt-1 text-xs text-white/45">Normalized: {{ $profile->normalized_email ?: '—' }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Phone</div>
                    <div class="mt-2 text-sm text-white">{{ $profile->phone ?: '—' }}</div>
                    <div class="mt-1 text-xs text-white/45">Normalized: {{ $profile->normalized_phone ?: '—' }}</div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Consent Status</div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] {{ $profile->accepts_email_marketing ? 'bg-emerald-500/20 text-emerald-100' : 'bg-white/10 text-white/60' }}">
                            Email {{ $profile->accepts_email_marketing ? 'Opt-In' : 'Opt-Out' }}
                        </span>
                        <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] {{ $profile->accepts_sms_marketing ? 'bg-emerald-500/20 text-emerald-100' : 'bg-white/10 text-white/60' }}">
                            SMS {{ $profile->accepts_sms_marketing ? 'Opt-In' : 'Opt-Out' }}
                        </span>
                    </div>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Profile Meta</div>
                    <div class="mt-2 text-xs text-white/70">Created: {{ optional($profile->created_at)->format('Y-m-d H:i') }}</div>
                    <div class="mt-1 text-xs text-white/70">Updated: {{ optional($profile->updated_at)->format('Y-m-d H:i') }}</div>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach((array) $profile->source_channels as $channel)
                            <span class="inline-flex rounded-full border border-white/15 bg-white/5 px-2 py-0.5 text-[11px] text-white/70">{{ $channel }}</span>
                        @endforeach
                    </div>
                </article>
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
            <h2 class="text-lg font-semibold text-white">Linked Source Records</h2>
            <div class="mt-4 overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Source Type</th>
                            <th class="px-4 py-3 text-left">Source ID</th>
                            <th class="px-4 py-3 text-left">Match Method</th>
                            <th class="px-4 py-3 text-left">Confidence</th>
                            <th class="px-4 py-3 text-left">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($profile->links as $link)
                            <tr>
                                <td class="px-4 py-3 text-white/80">{{ $link->source_type }}</td>
                                <td class="px-4 py-3 text-white/80">{{ $link->source_id }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $link->match_method ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/70">{{ $link->confidence !== null ? number_format((float) $link->confidence, 2) : '—' }}</td>
                                <td class="px-4 py-3 text-white/60">{{ optional($link->created_at)->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-white/50">No linked source records yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
            <h2 class="text-lg font-semibold text-white">Orders</h2>
            <p class="mt-1 text-sm text-white/65">Operational orders linked through marketing profile source links.</p>
            <div class="mt-4 overflow-x-auto rounded-2xl border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-white/65">
                        <tr>
                            <th class="px-4 py-3 text-left">Order</th>
                            <th class="px-4 py-3 text-left">Source/Channel</th>
                            <th class="px-4 py-3 text-left">Order Date</th>
                            <th class="px-4 py-3 text-left">Customer Snapshot</th>
                            <th class="px-4 py-3 text-right">Operational Detail</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse($orders as $order)
                            <tr>
                                <td class="px-4 py-3 text-white/80">
                                    {{ $order->order_number ?: ('Order #' . $order->id) }}
                                    <div class="text-xs text-white/45">ID #{{ $order->id }}</div>
                                </td>
                                <td class="px-4 py-3 text-white/70">
                                    {{ $order->source ?: '—' }} / {{ $order->order_type ?: $order->channel }}
                                </td>
                                <td class="px-4 py-3 text-white/70">{{ optional($order->ordered_at)->toDateString() ?: '—' }}</td>
                                <td class="px-4 py-3 text-white/65">
                                    {{ $order->customer_name ?: ($order->shipping_name ?: $order->billing_name ?: '—') }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if((auth()->user()?->isAdmin() ?? false) || (auth()->user()?->isManager() ?? false))
                                        <a href="{{ route('pouring.order', $order) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-semibold text-white/75 hover:bg-white/10">
                                            Open
                                        </a>
                                    @else
                                        <span class="text-xs text-white/40">Restricted</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-white/50">No linked operational orders available yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
            <h2 class="text-lg font-semibold text-white">Events Purchased At</h2>
            @if($eventOrders->isNotEmpty())
                <div class="mt-3 space-y-2">
                    @foreach($eventOrders as $order)
                        <div class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/75">
                            {{ $order->order_number ?: ('Order #' . $order->id) }}
                            @if($order->event)
                                · Event: {{ $order->event->display_name ?: $order->event->name }}
                            @elseif($order->order_type === 'event')
                                · Event attribution pending explicit event mapping
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-2 text-sm text-white/65">No event-attributed orders are safely derivable for this profile yet.</p>
            @endif
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-3xl border border-white/10 bg-black/15 p-5">
                <h3 class="text-sm font-semibold text-white">Campaign / Message History</h3>
                <p class="mt-2 text-xs text-white/65">Coming in later stages once campaign sending and tracking are enabled.</p>
            </article>
            <article class="rounded-3xl border border-white/10 bg-black/15 p-5">
                <h3 class="text-sm font-semibold text-white">Candle Cash</h3>
                <p class="mt-2 text-xs text-white/65">Rewards balances and activity will appear here in a later stage.</p>
            </article>
            <article class="rounded-3xl border border-white/10 bg-black/15 p-5">
                <h3 class="text-sm font-semibold text-white">Marketing Likelihood</h3>
                <p class="mt-2 text-xs text-white/65">Scoring is intentionally deferred. No synthetic score is shown in Stage 2.</p>
            </article>
            <article class="rounded-3xl border border-white/10 bg-black/15 p-5">
                <h3 class="text-sm font-semibold text-white">Reviews</h3>
                <p class="mt-2 text-xs text-white/65">Review provider history and outreach state will be added in a future stage.</p>
            </article>
        </section>
    </div>
</x-layouts::app>
