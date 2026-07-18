@php
    $formatMoney = fn ($value) => $value === null || $value === '' ? '—' : '$'.number_format((float) $value, 2);
@endphp

<x-layouts::app.sidebar title="Plant Inventory">
    <div class="min-h-screen bg-[#fbfaf7]">
        <div class="mx-auto w-full max-w-[1500px] space-y-6 px-4 py-6 sm:px-6">
            @if(session('status'))
                <div class="rounded-3xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-900 shadow-sm">{{ session('status') }}</div>
            @endif
            @if($errors->any())
                <div class="rounded-3xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-900 shadow-sm">
                    <ul class="list-disc pl-5">
                        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            <header class="overflow-hidden rounded-[2rem] border border-emerald-100 bg-gradient-to-br from-[#f8f3df] via-white to-[#d9efe3] p-6 shadow-sm sm:p-8">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.28em] text-emerald-800">Front Yard Foods · inventory branch</p>
                        <h1 class="mt-3 text-3xl font-semibold tracking-tight text-zinc-950 sm:text-4xl">Plant Inventory</h1>
                        <p class="mt-3 max-w-3xl text-sm leading-6 text-zinc-700">Track plants and resale products here first. Square and Shopify IDs are ready to map once Laura grants access, but publishing and sync stay pending until those connections are approved and tested.</p>
                    </div>
                    <div class="rounded-2xl border border-white/70 bg-white/80 px-4 py-3 text-sm text-zinc-700 shadow-sm">
                        <span class="font-semibold text-zinc-950">Next client need:</span> current inventory/product files and Square access.
                    </div>
                </div>
            </header>

            <section class="grid gap-4 lg:grid-cols-[0.9fr_1.1fr]">
                <div class="rounded-[1.75rem] border border-zinc-200 bg-white p-5 shadow-sm">
                    <h2 class="text-xl font-semibold text-zinc-950">Add a plant or resale product</h2>
                    <p class="mt-2 text-sm text-zinc-600">Use this for strawberries, starts, nursery resale plants, class materials, or other items Laura wants tracked centrally.</p>
                    <form method="POST" action="{{ route('plant-inventory.store') }}" class="mt-5 grid gap-4 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="status" value="active">
                        <label class="sm:col-span-2 text-sm font-medium text-zinc-700">Name<input name="name" required value="{{ old('name') }}" class="mt-1.5 w-full rounded-2xl border-zinc-200 bg-white" placeholder="Strawberry starts"></label>
                        <label class="text-sm font-medium text-zinc-700">Category<input name="category" value="{{ old('category') }}" class="mt-1.5 w-full rounded-2xl border-zinc-200" placeholder="Edible plants"></label>
                        <label class="text-sm font-medium text-zinc-700">SKU<input name="sku" value="{{ old('sku') }}" class="mt-1.5 w-full rounded-2xl border-zinc-200" placeholder="FYF-STRAW-START"></label>
                        <label class="text-sm font-medium text-zinc-700">Vendor / source<input name="vendor_source" value="{{ old('vendor_source') }}" class="mt-1.5 w-full rounded-2xl border-zinc-200" placeholder="Purchased resale plants"></label>
                        <label class="text-sm font-medium text-zinc-700">Purchased cost<input type="number" min="0" step="0.01" name="purchased_cost" value="{{ old('purchased_cost') }}" class="mt-1.5 w-full rounded-2xl border-zinc-200"></label>
                        <label class="text-sm font-medium text-zinc-700">Sell price<input type="number" min="0" step="0.01" name="sell_price" value="{{ old('sell_price') }}" class="mt-1.5 w-full rounded-2xl border-zinc-200"></label>
                        <label class="text-sm font-medium text-zinc-700">On hand<input type="number" min="0" name="quantity_on_hand" required value="{{ old('quantity_on_hand', 0) }}" class="mt-1.5 w-full rounded-2xl border-zinc-200"></label>
                        <label class="text-sm font-medium text-zinc-700">Held / reserved<input type="number" min="0" name="reserved_quantity" value="{{ old('reserved_quantity', 0) }}" class="mt-1.5 w-full rounded-2xl border-zinc-200"></label>
                        <label class="text-sm font-medium text-zinc-700">Square ID<input name="square_id" value="{{ old('square_id') }}" class="mt-1.5 w-full rounded-2xl border-zinc-200" placeholder="Pending"></label>
                        <label class="text-sm font-medium text-zinc-700">Shopify product ID<input name="shopify_product_id" value="{{ old('shopify_product_id') }}" class="mt-1.5 w-full rounded-2xl border-zinc-200" placeholder="Pending"></label>
                        <label class="sm:col-span-2 text-sm font-medium text-zinc-700">Shopify variant ID<input name="shopify_variant_id" value="{{ old('shopify_variant_id') }}" class="mt-1.5 w-full rounded-2xl border-zinc-200" placeholder="Pending"></label>
                        <label class="sm:col-span-2 text-sm font-medium text-zinc-700">Notes<textarea name="notes" rows="3" class="mt-1.5 w-full rounded-2xl border-zinc-200">{{ old('notes') }}</textarea></label>
                        <div class="sm:col-span-2"><button class="rounded-2xl bg-emerald-800 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-emerald-900">Add to inventory</button></div>
                    </form>
                </div>

                <div class="rounded-[1.75rem] border border-zinc-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-xl font-semibold text-zinc-950">Inventory list</h2>
                            <p class="mt-1 text-sm text-zinc-600">Available = on hand minus held/reserved.</p>
                        </div>
                        <form method="GET" action="{{ route('plant-inventory.index') }}" class="flex gap-2">
                            <input name="search" value="{{ $search }}" class="w-40 rounded-2xl border-zinc-200 text-sm" placeholder="Search">
                            <select name="status" class="rounded-2xl border-zinc-200 text-sm">
                                <option value="active" @selected($status === 'active')>Active</option>
                                <option value="archived" @selected($status === 'archived')>Archived</option>
                                <option value="all" @selected($status === 'all')>All</option>
                            </select>
                            <button class="rounded-2xl border border-zinc-200 px-3 text-sm font-semibold">Filter</button>
                        </form>
                    </div>

                    @if($items->isEmpty())
                        <div class="mt-6 rounded-[1.5rem] border border-dashed border-emerald-200 bg-emerald-50/70 p-6 text-sm leading-6 text-emerald-950">
                            <p class="font-semibold">No plant inventory yet.</p>
                            <p class="mt-1">Ask Laura for her current inventory/product files and Square access. Once those arrive, Evergrove can enter the starting count and map Square → Shopify identifiers after the store is connected.</p>
                        </div>
                    @else
                        <div class="mt-5 space-y-4">
                            @foreach($items as $item)
                                <article class="rounded-[1.5rem] border border-zinc-200 bg-[#fffdf8] p-4">
                                    <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                                        <div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h3 class="text-lg font-semibold text-zinc-950">{{ $item->name }}</h3>
                                                <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-900">{{ $item->category ?: 'Uncategorized' }}</span>
                                                @if($item->status !== 'active')<span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-700">{{ $item->status }}</span>@endif
                                            </div>
                                            <p class="mt-1 text-xs text-zinc-500">SKU {{ $item->sku ?: '—' }} · Source {{ $item->vendor_source ?: '—' }} · Square {{ $item->square_id ?: 'pending' }} · Shopify {{ $item->shopify_variant_id ?: ($item->shopify_product_id ?: 'pending') }}</p>
                                        </div>
                                        <div class="grid grid-cols-3 gap-2 text-center text-sm">
                                            <div class="rounded-2xl bg-white px-3 py-2 shadow-sm"><p class="text-xs text-zinc-500">On hand</p><p class="font-semibold">{{ $item->quantity_on_hand }}</p></div>
                                            <div class="rounded-2xl bg-white px-3 py-2 shadow-sm"><p class="text-xs text-zinc-500">Held</p><p class="font-semibold">{{ $item->reserved_quantity }}</p></div>
                                            <div class="rounded-2xl bg-white px-3 py-2 shadow-sm"><p class="text-xs text-zinc-500">Available</p><p class="font-semibold text-emerald-800">{{ $item->available_quantity }}</p></div>
                                        </div>
                                    </div>

                                    <div class="mt-4 grid gap-4 xl:grid-cols-[1fr_0.8fr]">
                                        <form method="POST" action="{{ route('plant-inventory.update', $item) }}" class="grid gap-3 sm:grid-cols-3">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="status" value="{{ $item->status }}">
                                            <label class="text-xs font-medium text-zinc-600">Name<input name="name" required value="{{ $item->name }}" class="mt-1 w-full rounded-xl border-zinc-200 text-sm"></label>
                                            <label class="text-xs font-medium text-zinc-600">Category<input name="category" value="{{ $item->category }}" class="mt-1 w-full rounded-xl border-zinc-200 text-sm"></label>
                                            <label class="text-xs font-medium text-zinc-600">SKU<input name="sku" value="{{ $item->sku }}" class="mt-1 w-full rounded-xl border-zinc-200 text-sm"></label>
                                            <label class="text-xs font-medium text-zinc-600">Cost<input type="number" min="0" step="0.01" name="purchased_cost" value="{{ $item->purchased_cost }}" class="mt-1 w-full rounded-xl border-zinc-200 text-sm"></label>
                                            <label class="text-xs font-medium text-zinc-600">Sell<input type="number" min="0" step="0.01" name="sell_price" value="{{ $item->sell_price }}" class="mt-1 w-full rounded-xl border-zinc-200 text-sm"></label>
                                            <label class="text-xs font-medium text-zinc-600">Vendor/source<input name="vendor_source" value="{{ $item->vendor_source }}" class="mt-1 w-full rounded-xl border-zinc-200 text-sm"></label>
                                            <label class="text-xs font-medium text-zinc-600">On hand<input type="number" min="0" name="quantity_on_hand" required value="{{ $item->quantity_on_hand }}" class="mt-1 w-full rounded-xl border-zinc-200 text-sm"></label>
                                            <label class="text-xs font-medium text-zinc-600">Held<input type="number" min="0" name="reserved_quantity" value="{{ $item->reserved_quantity }}" class="mt-1 w-full rounded-xl border-zinc-200 text-sm"></label>
                                            <label class="text-xs font-medium text-zinc-600">Square ID<input name="square_id" value="{{ $item->square_id }}" class="mt-1 w-full rounded-xl border-zinc-200 text-sm"></label>
                                            <label class="text-xs font-medium text-zinc-600">Shopify product<input name="shopify_product_id" value="{{ $item->shopify_product_id }}" class="mt-1 w-full rounded-xl border-zinc-200 text-sm"></label>
                                            <label class="text-xs font-medium text-zinc-600">Shopify variant<input name="shopify_variant_id" value="{{ $item->shopify_variant_id }}" class="mt-1 w-full rounded-xl border-zinc-200 text-sm"></label>
                                            <label class="text-xs font-medium text-zinc-600">Notes<input name="notes" value="{{ $item->notes }}" class="mt-1 w-full rounded-xl border-zinc-200 text-sm"></label>
                                            <div class="sm:col-span-3 flex flex-wrap items-center justify-between gap-2">
                                                <p class="text-xs text-zinc-500">Cost {{ $formatMoney($item->purchased_cost) }} · Sell {{ $formatMoney($item->sell_price) }}</p>
                                                <button class="rounded-xl bg-zinc-950 px-3 py-2 text-xs font-semibold text-white">Save item</button>
                                            </div>
                                        </form>
                                        <div class="space-y-3">
                                            <form method="POST" action="{{ route('plant-inventory.adjustments.store', $item) }}" class="rounded-2xl border border-zinc-200 bg-white p-3">
                                                @csrf
                                                <div class="grid gap-2 sm:grid-cols-[1fr_0.6fr]">
                                                    <select name="adjustment_type" class="rounded-xl border-zinc-200 text-sm">
                                                        @foreach($adjustmentTypes as $type)<option value="{{ $type }}">{{ str_replace('_', ' ', ucfirst($type)) }}</option>@endforeach
                                                    </select>
                                                    <input type="number" min="1" name="quantity" value="1" class="rounded-xl border-zinc-200 text-sm">
                                                </div>
                                                <input name="notes" class="mt-2 w-full rounded-xl border-zinc-200 text-sm" placeholder="Optional note">
                                                <button class="mt-2 rounded-xl bg-emerald-700 px-3 py-2 text-xs font-semibold text-white">Record adjustment</button>
                                            </form>
                                            @if($item->status === 'active')
                                                <form method="POST" action="{{ route('plant-inventory.archive', $item) }}">@csrf<button class="rounded-xl border border-zinc-200 px-3 py-2 text-xs font-semibold text-zinc-700">Archive</button></form>
                                            @endif
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                        <div class="mt-5">{{ $items->links() }}</div>
                    @endif
                </div>
            </section>

            <section class="rounded-[1.75rem] border border-zinc-200 bg-white p-5 shadow-sm">
                <h2 class="text-xl font-semibold text-zinc-950">Recent adjustments</h2>
                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    @forelse($recentAdjustments as $adjustment)
                        <div class="rounded-2xl border border-zinc-100 bg-zinc-50 p-4 text-sm">
                            <p class="font-semibold text-zinc-950">{{ $adjustment->item?->name ?? 'Inventory item' }}</p>
                            <p class="mt-1 text-xs uppercase tracking-wide text-zinc-500">{{ str_replace('_', ' ', $adjustment->adjustment_type) }}</p>
                            <p class="mt-2 text-zinc-700">Qty {{ $adjustment->quantity_delta >= 0 ? '+' : '' }}{{ $adjustment->quantity_delta }} · Held {{ $adjustment->reserved_delta >= 0 ? '+' : '' }}{{ $adjustment->reserved_delta }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $adjustment->created_at->diffForHumans() }}</p>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600">Adjustments will appear here after inventory is received, sold, held, released, damaged, or corrected.</div>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</x-layouts::app.sidebar>
