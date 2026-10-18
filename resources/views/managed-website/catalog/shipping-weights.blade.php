<x-layouts::app.sidebar title="Shipping weights">
    <flux:main>
        <div class="mx-auto max-w-6xl space-y-5 pb-12">
            @include('managed-website.catalog.nav')
            <header>
                <a href="{{ route('managed-website.products.index') }}" class="text-sm text-zinc-500">← Products</a>
                <h1 class="mt-2 text-3xl font-bold">Shipping weights</h1>
                <p class="mt-2 text-sm text-zinc-600">Add the weight of one item, including its retail packaging. Box and packing material weights belong in your package setup.</p>
            </header>
            @if(session('status'))<p role="status" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</p>@endif
            @if($errors->any())<div role="alert" class="rounded-xl bg-rose-50 p-4 text-sm text-rose-900">{{ $errors->first() }}</div>@endif
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                <strong>{{ $missingWeightCount }} {{ str('variant')->plural($missingWeightCount) }} {{ $missingWeightCount === 1 ? 'needs a shipping weight' : 'need shipping weights' }}.</strong>
                <p class="mt-1">Missing weights prevent shipping for those items. Your products stay in the catalog, and other delivery options remain available when offered.</p>
            </div>
            <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm">
                <form method="GET" class="flex flex-wrap items-end gap-3 border-b bg-zinc-50 p-4">
                    <label class="min-w-48 flex-1 text-xs font-semibold text-zinc-600">Search products or SKUs<input name="q" type="search" value="{{ $filters['q'] ?? '' }}" class="mt-1 block w-full rounded-lg border-zinc-300 text-sm"></label>
                    <label class="text-xs font-semibold text-zinc-600">Show<select name="show" class="mt-1 block rounded-lg border-zinc-300 text-sm"><option value="missing" @selected(($filters['show'] ?? 'missing') === 'missing')>Missing weights</option><option value="all" @selected(($filters['show'] ?? '') === 'all')>All available physical variants</option></select></label>
                    <button class="fb-btn fb-btn-secondary">Filter</button>
                </form>
                <form method="POST" action="{{ route('managed-website.products.shipping-weights.save') }}">
                    @csrf
                    <input type="hidden" name="show" value="{{ $filters['show'] ?? 'missing' }}">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="border-b bg-zinc-50 text-xs text-zinc-500"><tr><th class="px-5 py-3">Product and variant</th><th class="px-5 py-3">Current weight</th><th class="px-5 py-3">Weight per item</th><th class="px-5 py-3">Unit</th></tr></thead>
                            <tbody class="divide-y divide-zinc-100">
                                @forelse($variants as $variant)
                                    <tr>
                                        <td class="px-5 py-4">
                                            <a href="{{ route('managed-website.products.edit', $variant->product) }}" class="font-semibold text-emerald-800">{{ $variant->product->title }}</a>
                                            <p class="mt-1 text-xs text-zinc-500">{{ $variant->title }} @if($variant->sku) · {{ $variant->sku }}@endif</p>
                                            <input type="hidden" name="variants[{{ $loop->index }}][id]" value="{{ $variant->id }}">
                                            <input type="hidden" name="variants[{{ $loop->index }}][revision]" value="{{ data_get($variant->product->service_details, 'catalog_revision', 0) }}">
                                        </td>
                                        <td class="px-5 py-4">
                                            @if($variant->shipping_weight_ounces > 0){{ $variant->shipping_weight_ounces }} oz
                                            @else<span class="font-medium text-amber-800">Weight missing</span>@endif
                                        </td>
                                        <td class="px-5 py-4">
                                            <label class="sr-only" for="weight-{{ $variant->id }}">Weight for {{ $variant->product->title }} {{ $variant->title }}</label>
                                            <input id="weight-{{ $variant->id }}" name="variants[{{ $loop->index }}][shipping_weight]" type="number" min="0.0001" step="any" inputmode="decimal" value="{{ old('variants.'.$loop->index.'.shipping_weight') }}" placeholder="Add weight" class="w-32 rounded-lg border-zinc-300 text-sm">
                                        </td>
                                        <td class="px-5 py-4">
                                            <label class="sr-only" for="unit-{{ $variant->id }}">Weight unit for {{ $variant->product->title }} {{ $variant->title }}</label>
                                            <select id="unit-{{ $variant->id }}" name="variants[{{ $loop->index }}][shipping_weight_unit]" class="rounded-lg border-zinc-300 text-sm">
                                                @foreach(['oz' => 'Ounces', 'lb' => 'Pounds', 'g' => 'Grams'] as $unit => $label)<option value="{{ $unit }}" @selected(old('variants.'.$loop->parent->index.'.shipping_weight_unit', 'oz') === $unit)>{{ $label }}</option>@endforeach
                                            </select>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="p-10 text-center"><h2 class="font-semibold">{{ ($filters['show'] ?? 'missing') === 'missing' ? 'No missing weights in this view' : 'No physical variants in this view' }}</h2><p class="mt-2 text-sm text-zinc-500">Shipping also needs your carrier account, ship-from address, and package setup.</p></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if($variants->isNotEmpty())
                        <footer class="flex flex-wrap items-center justify-between gap-4 border-t p-4"><p class="text-xs text-zinc-500">Blank fields keep existing weights. Weights are saved in whole ounces, rounded up.</p><button class="fb-btn fb-btn-primary">Save weights on this page</button></footer>
                    @endif
                </form>
                <div class="border-t p-4">{{ $variants->links() }}</div>
            </section>
        </div>
    </flux:main>
</x-layouts::app.sidebar>
