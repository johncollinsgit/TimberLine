@php
    use App\Models\MarketingWishlistOutreachQueue;
@endphp

<x-layouts::app :title="$section['label']">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
        />

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
            @foreach([
                ['label' => 'Active saves', 'value' => number_format((int) data_get($wishlistSummary, 'active_items', 0)), 'detail' => 'Current active wishlist rows in this tenant.'],
                ['label' => 'Customers', 'value' => number_format((int) data_get($wishlistSummary, 'unique_customers', 0)), 'detail' => 'Customers with at least one active saved item.'],
                ['label' => 'Products', 'value' => number_format((int) data_get($wishlistSummary, 'unique_products', 0)), 'detail' => 'Distinct products currently being saved.'],
                ['label' => 'SMS ready', 'value' => number_format((int) data_get($wishlistSummary, 'outreach_candidates', 0)), 'detail' => 'Saved items tied to customers with a phone number.'],
                ['label' => 'Prepared', 'value' => number_format((int) data_get($wishlistSummary, 'prepared_queue', 0)), 'detail' => 'Outreach offers prepared and ready to send.'],
                ['label' => 'Sent', 'value' => number_format((int) data_get($wishlistSummary, 'sent_queue', 0)), 'detail' => 'Wishlist offers already sent through this queue.'],
            ] as $card)
                <article class="rounded-[1.7rem] border border-zinc-200 bg-zinc-50 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-zinc-500">{{ $card['label'] }}</div>
                    <div class="mt-3 text-3xl font-semibold text-zinc-950">{{ $card['value'] }}</div>
                    <p class="mt-2 text-sm text-zinc-500">{{ $card['detail'] }}</p>
                </article>
            @endforeach
        </section>

        <section class="rounded-[1.8rem] border border-zinc-200 bg-zinc-50 p-5">
            <form method="GET" action="{{ route('marketing.wishlist') }}" class="grid gap-3 md:grid-cols-[minmax(0,1fr),180px,180px,180px,auto]">
                <input type="text" name="search" value="{{ data_get($wishlistFilters, 'search') }}" placeholder="Search product, customer, email, phone" class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-950 placeholder:text-zinc-500" />
                <select name="status" class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-950">
                    @foreach(['active' => 'Active only', 'removed' => 'Removed only', 'all' => 'All statuses'] as $value => $label)
                        <option value="{{ $value }}" @selected(data_get($wishlistFilters, 'status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="channel" class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-950">
                    @foreach(['all' => 'All items', 'sms_ready' => 'SMS ready'] as $value => $label)
                        <option value="{{ $value }}" @selected(data_get($wishlistFilters, 'channel') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="queue_status" class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-950">
                    @foreach(['all' => 'All queue rows', 'prepared' => 'Prepared', 'sent' => 'Sent', 'failed' => 'Failed', 'redeemed' => 'Redeemed'] as $value => $label)
                        <option value="{{ $value }}" @selected(data_get($wishlistFilters, 'queue_status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <button type="submit" class="inline-flex rounded-full border border-zinc-200 bg-zinc-50 px-4 py-2 text-sm font-semibold text-zinc-700">Apply</button>
            </form>
        </section>

        <section class="grid gap-4 xl:grid-cols-[minmax(0,1.1fr),minmax(360px,0.9fr)]">
            <article class="rounded-[1.8rem] border border-zinc-200 bg-zinc-50 p-5">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <div class="text-[11px] uppercase tracking-[0.24em] text-zinc-500">Wishlist intent</div>
                        <h2 class="mt-2 text-lg font-semibold text-zinc-950">Customer and product saves</h2>
                    </div>
                    <div class="text-sm text-zinc-500">{{ number_format($wishlistItems->total()) }} total rows</div>
                </div>

                <div class="mt-5 overflow-x-auto rounded-[1.4rem] border border-zinc-200">
                    <table class="min-w-full text-left text-sm text-zinc-700">
                        <thead class="bg-zinc-50 text-xs uppercase tracking-[0.18em] text-zinc-500">
                            <tr>
                                <th class="px-4 py-3">Product</th>
                                <th class="px-4 py-3">Customer</th>
                                <th class="px-4 py-3">List</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Saved</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($wishlistItems as $item)
                                <tr class="border-t border-zinc-200">
                                    <td class="px-4 py-3 align-top">
                                        <a href="{{ route('marketing.wishlist', array_merge($wishlistFilters, ['item' => $item->id])) }}" wire:navigate class="font-semibold text-zinc-950">
                                            {{ $item->product_title ?: ($item->product_handle ?: 'Product #' . $item->product_id) }}
                                        </a>
                                        <div class="mt-1 text-xs text-zinc-500">{{ $item->store_key }} · {{ $item->product_id }}</div>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <div class="font-medium text-zinc-950">{{ trim(($item->profile->first_name ?? '') . ' ' . ($item->profile->last_name ?? '')) ?: ($item->profile->email ?? 'Guest / unresolved') }}</div>
                                        <div class="mt-1 text-xs text-zinc-500">{{ $item->profile->email ?: ($item->profile->phone ?: 'No contact') }}</div>
                                    </td>
                                    <td class="px-4 py-3 align-top text-zinc-600">
                                        {{ $item->wishlistList->name ?? 'Saved Items' }}
                                        @if($item->wishlistList?->is_default)
                                            <div class="mt-1 text-xs text-zinc-500">Default list</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 align-top text-zinc-600">{{ strtoupper($item->status) }}</td>
                                    <td class="px-4 py-3 align-top text-zinc-500">{{ optional($item->last_added_at ?: $item->added_at)->format('Y-m-d H:i') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-6 text-center text-zinc-500">No wishlist rows match the current filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">{{ $wishlistItems->links() }}</div>
            </article>

            <article class="space-y-4">
                <div class="rounded-[1.8rem] border border-zinc-200 bg-zinc-50 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-zinc-500">Selected wishlist item</div>
                    @if($selectedWishlistItem)
                        <h2 class="mt-2 text-lg font-semibold text-zinc-950">{{ $selectedWishlistItem->product_title ?: ($selectedWishlistItem->product_handle ?: 'Saved product') }}</h2>
                        <div class="mt-2 text-sm text-zinc-500">{{ trim(($selectedWishlistItem->profile->first_name ?? '') . ' ' . ($selectedWishlistItem->profile->last_name ?? '')) ?: ($selectedWishlistItem->profile->email ?? 'Customer') }}</div>

                        <div class="mt-5 grid gap-3 md:grid-cols-2">
                            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                                <div class="text-xs uppercase tracking-[0.18em] text-zinc-500">Product</div>
                                <div class="mt-2 text-base font-semibold text-zinc-950">{{ $selectedWishlistItem->product_title ?: 'Saved product' }}</div>
                                <div class="mt-1 text-xs text-zinc-500">{{ $selectedWishlistItem->store_key }} · {{ $selectedWishlistItem->product_id }}</div>
                                @if($selectedWishlistItem->product_url)
                                    <a href="{{ $selectedWishlistItem->product_url }}" target="_blank" rel="noopener" class="mt-3 inline-flex text-xs font-semibold text-amber-800 underline decoration-zinc-300 underline-offset-4">Open product</a>
                                @endif
                            </div>
                            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                                <div class="text-xs uppercase tracking-[0.18em] text-zinc-500">Customer</div>
                                <div class="mt-2 text-base font-semibold text-zinc-950">{{ trim(($selectedWishlistItem->profile->first_name ?? '') . ' ' . ($selectedWishlistItem->profile->last_name ?? '')) ?: ($selectedWishlistItem->profile->email ?? 'Customer') }}</div>
                                <div class="mt-1 text-xs text-zinc-500">{{ $selectedWishlistItem->profile->email ?: 'No email' }}</div>
                                <div class="mt-1 text-xs text-zinc-500">{{ $selectedWishlistItem->profile->phone ?: 'No phone on file' }}</div>
                            </div>
                        </div>

                        <form method="POST" action="{{ route('marketing.wishlist.prepare-outreach', $selectedWishlistItem) }}" class="mt-5 grid gap-3">
                            @csrf
                            <div class="text-sm font-semibold text-zinc-950">Prepare targeted offer</div>
                            <div class="grid gap-3 md:grid-cols-3">
                                <label class="block text-sm text-zinc-700">
                                    Channel
                                    <select name="channel" class="mt-2 block w-full rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-zinc-950">
                                        <option value="sms">SMS</option>
                                        <option value="email">Email</option>
                                    </select>
                                </label>
                                <label class="block text-sm text-zinc-700">
                                    Offer type
                                    <select name="offer_type" class="mt-2 block w-full rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-zinc-950">
                                        <option value="amount_off">$ off</option>
                                        <option value="percent_off">% off</option>
                                    </select>
                                </label>
                                <label class="block text-sm text-zinc-700">
                                    Offer value
                                    <input type="number" step="0.01" min="0.01" max="9999" name="offer_value" value="10.00" class="mt-2 block w-full rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-zinc-950" />
                                </label>
                            </div>
                            <label class="block text-sm text-zinc-700">
                                Message body override
                                <textarea name="message_body" rows="4" class="mt-2 block w-full rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-zinc-950" placeholder="Leave blank to use the generated SMS copy."></textarea>
                            </label>
                            <div class="rounded-2xl border border-amber-300/25 bg-amber-100 p-4 text-sm text-amber-900">
                                Product-specific Shopify discounts are created on send where the product id maps cleanly to Shopify. Otherwise the queue will fail loudly instead of sending a broken code.
                            </div>
                            <div>
                                <button type="submit" class="inline-flex rounded-full border border-amber-300/35 bg-amber-100 px-4 py-2 text-sm font-semibold text-zinc-950">Prepare offer</button>
                            </div>
                        </form>
                    @else
                        <div class="mt-3 text-sm text-zinc-500">Pick a wishlist row from the left to prepare a targeted offer.</div>
                    @endif
                </div>

                <div class="rounded-[1.8rem] border border-zinc-200 bg-zinc-50 p-5">
                    <div class="flex items-end justify-between gap-3">
                        <div>
                            <div class="text-[11px] uppercase tracking-[0.24em] text-zinc-500">Outreach queue</div>
                            <h2 class="mt-2 text-lg font-semibold text-zinc-950">Prepared and sent offers</h2>
                        </div>
                        <div class="text-sm text-zinc-500">{{ number_format($wishlistQueueEntries->total()) }} rows</div>
                    </div>

                    <div class="mt-4 space-y-3">
                        @forelse($wishlistQueueEntries as $queue)
                            <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                    <div>
                                        <div class="text-sm font-semibold text-zinc-950">{{ $queue->product_title ?: ($queue->product_handle ?: 'Saved product') }}</div>
                                        <div class="mt-1 text-xs text-zinc-500">
                                            {{ trim(($queue->profile->first_name ?? '') . ' ' . ($queue->profile->last_name ?? '')) ?: ($queue->profile->email ?? 'Customer') }}
                                            · {{ strtoupper($queue->queue_status) }}
                                            · {{ strtoupper($queue->channel) }}
                                        </div>
                                        <div class="mt-2 text-xs text-zinc-500">
                                            {{ $queue->offer_type === 'percent_off' ? rtrim(rtrim(number_format((float) $queue->offer_value, 2, '.', ''), '0'), '.') . '% off' : '$' . number_format((float) $queue->offer_value, 2) . ' off' }}
                                            @if($queue->offer_code)
                                                · Code {{ $queue->offer_code }}
                                            @endif
                                        </div>
                                        @if($queue->delivery_error)
                                            <div class="mt-2 text-xs text-rose-200">{{ $queue->delivery_error }}</div>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        @if(in_array($queue->queue_status, [MarketingWishlistOutreachQueue::STATUS_PREPARED, MarketingWishlistOutreachQueue::STATUS_QUEUED, MarketingWishlistOutreachQueue::STATUS_FAILED], true))
                                            <form method="POST" action="{{ route('marketing.wishlist.send-outreach', $queue) }}">
                                                @csrf
                                                <button type="submit" class="inline-flex rounded-full border border-zinc-300 bg-emerald-100 px-4 py-2 text-sm font-semibold text-zinc-950">Send SMS</button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                                <div class="mt-3 text-xs text-zinc-500">
                                    Prepared {{ optional($queue->created_at)->format('Y-m-d H:i') ?: '—' }}
                                    @if($queue->sent_at)
                                        · Sent {{ optional($queue->sent_at)->format('Y-m-d H:i') }}
                                    @endif
                                </div>
                            </article>
                        @empty
                            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-500">No outreach has been prepared yet.</div>
                        @endforelse
                    </div>

                    <div class="mt-4">{{ $wishlistQueueEntries->links() }}</div>
                </div>
            </article>
        </section>

        <section class="grid gap-4 xl:grid-cols-2">
            <article class="rounded-[1.8rem] border border-zinc-200 bg-zinc-50 p-5">
                <div class="text-[11px] uppercase tracking-[0.24em] text-zinc-500">Customer view</div>
                <h2 class="mt-2 text-lg font-semibold text-zinc-950">High-intent customers</h2>
                <div class="mt-4 grid gap-3">
                    @forelse($customerIntentRollup as $customer)
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-zinc-950">{{ $customer['label'] }}</div>
                                    <div class="mt-1 text-xs text-zinc-500">{{ $customer['email'] ?: ($customer['phone'] ?: 'No contact info') }}</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-2xl font-semibold text-zinc-950">{{ number_format((int) $customer['active_count']) }}</div>
                                    <div class="text-xs text-zinc-500">active saves</div>
                                </div>
                            </div>
                            @if(!empty($customer['products']))
                                <div class="mt-3 flex flex-wrap gap-2 text-xs text-zinc-500">
                                    @foreach($customer['products'] as $productTitle)
                                        <span class="rounded-full border border-zinc-200 bg-zinc-50 px-2 py-1">{{ $productTitle }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-500">No active customer intent found yet.</div>
                    @endforelse
                </div>
            </article>

            <article class="rounded-[1.8rem] border border-zinc-200 bg-zinc-50 p-5">
                <div class="text-[11px] uppercase tracking-[0.24em] text-zinc-500">Product view</div>
                <h2 class="mt-2 text-lg font-semibold text-zinc-950">Most wishlisted products</h2>
                <div class="mt-4 grid gap-3">
                    @forelse($productIntentRollup as $product)
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-zinc-950">{{ $product['product_title'] }}</div>
                                    <div class="mt-1 text-xs text-zinc-500">{{ $product['store_key'] }} · {{ $product['product_id'] }}</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-2xl font-semibold text-zinc-950">{{ number_format((int) $product['customer_count']) }}</div>
                                    <div class="text-xs text-zinc-500">customers</div>
                                </div>
                            </div>
                            <div class="mt-3 text-xs text-zinc-500">{{ number_format((int) $product['list_count']) }} lists currently include this product.</div>
                            @if($product['product_url'])
                                <a href="{{ $product['product_url'] }}" target="_blank" rel="noopener" class="mt-3 inline-flex text-xs font-semibold text-amber-800 underline decoration-zinc-300 underline-offset-4">Open product</a>
                            @endif
                        </div>
                    @empty
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-500">No active product intent found yet.</div>
                    @endforelse
                </div>
            </article>
        </section>
    </div>
</x-layouts::app>
