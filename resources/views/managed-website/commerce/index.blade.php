<x-layouts::app.sidebar :title="str($screen)->headline()">
    <flux:main>
        <div class="mx-auto max-w-[1440px] space-y-6 pb-10">
            @if(session('status'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-950">{{ session('status') }}</div>
            @endif
            @if($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-950">{{ $errors->first() }}</div>
            @endif

            <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <a class="text-sm font-semibold text-emerald-800 hover:underline" href="{{ route('managed-website.index') }}">← Website</a>
                    <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-950">{{ str($screen)->headline() }}</h1>
                    <p class="mt-1 text-sm text-zinc-600">
                        @if($screen === 'products') One website catalog for retail, wholesale, inventory, and storefront publishing.
                        @elseif($screen === 'customers') Website shoppers are separate from existing customer and marketing records.
                        @else Native Website orders only — never Shopify or legacy orders.
                        @endif
                    </p>
                </div>
                @if($screen === 'products')
                    <div class="flex flex-wrap gap-2">
                        <a class="fb-btn fb-btn-secondary" href="{{ route('managed-website.products.export') }}">Export CSV</a>
                        @if($isEditorEnabled)<a href="#import-products" class="fb-btn fb-btn-secondary">Import CSV</a><a href="#new-product" class="fb-btn fb-btn-primary">Add product</a>@endif
                    </div>
                @endif
            </header>

            @if($screen === 'products')
                @if($isEditorEnabled)
                    <section id="import-products" class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5">
                        <div class="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-end">
                            <div><h2 class="font-bold text-zinc-950">Import products</h2><p class="mt-1 text-sm text-zinc-600">Upload an exported Everbranch CSV. Matching handles update; new handles create. The whole file rolls back if a row is invalid.</p></div>
                            <form method="POST" action="{{ route('managed-website.products.import') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2">@csrf
                                <label class="text-xs font-bold uppercase tracking-wide text-zinc-600">Catalog CSV<input required name="catalog" type="file" accept=".csv,text/csv" class="mt-1 block max-w-72 text-sm"></label>
                                <button class="fb-btn fb-btn-secondary" type="submit">Import catalog</button>
                            </form>
                        </div>
                    </section>
                @endif

                <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-zinc-200 p-4"><strong class="text-sm">All products</strong><span class="text-xs text-zinc-500">{{ $products->total() }} total</span></div>
                    <div class="overflow-x-auto"><table class="min-w-full text-left text-sm">
                        <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500"><tr><th class="px-5 py-3">Product</th><th class="px-5 py-3">Type</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Inventory</th><th class="px-5 py-3 text-right">Retail</th><th class="px-5 py-3 text-right">Wholesale</th><th class="px-5 py-3"></th></tr></thead>
                        <tbody class="divide-y divide-zinc-100">
                        @forelse($products as $product)
                            @php($variant = $product->variants->first())
                            <tr>
                                <td class="px-5 py-4"><strong class="text-zinc-950">{{ $product->title }}</strong><span class="mt-1 block text-xs text-zinc-500">{{ $product->handle }}</span></td>
                                <td class="px-5 py-4">{{ str($product->product_type)->headline() }}</td>
                                <td class="px-5 py-4"><span class="rounded-full px-2 py-1 text-xs font-bold {{ $product->status === 'active' ? 'bg-emerald-100 text-emerald-900' : 'bg-zinc-100 text-zinc-700' }}">{{ str($product->status)->headline() }}</span></td>
                                <td class="px-5 py-4">{{ $product->track_inventory ? ($variant?->inventory_quantity ?? 0) : 'Not tracked' }}</td>
                                <td class="px-5 py-4 text-right font-semibold">${{ number_format(($variant?->price_cents ?? 0) / 100, 2) }}</td>
                                <td class="px-5 py-4 text-right font-semibold">{{ $variant?->wholesale_price_cents === null ? '—' : '$'.number_format($variant->wholesale_price_cents / 100, 2) }}</td>
                                <td class="px-5 py-4"><details><summary class="cursor-pointer text-xs font-bold text-emerald-800">Edit</summary>
                                    <form method="POST" action="{{ route('managed-website.products.update', ['product' => $product]) }}" class="mt-3 grid min-w-80 gap-2">@csrf @method('PUT')
                                        <input required name="title" value="{{ $product->title }}" class="rounded border-zinc-300 text-xs" aria-label="Product title">
                                        <select name="product_type" class="rounded border-zinc-300 text-xs" aria-label="Product type"><option value="physical" @selected($product->product_type === 'physical')>Physical</option><option value="service" @selected($product->product_type === 'service')>Service</option><option value="quote" @selected($product->product_type === 'quote')>Quote</option></select>
                                        <div class="grid grid-cols-2 gap-2"><input required name="price" type="number" step=".01" min="0" value="{{ number_format(($variant?->price_cents ?? 0)/100, 2, '.', '') }}" class="rounded border-zinc-300 text-xs" aria-label="Retail price"><input name="wholesale_price" type="number" step=".01" min="0" value="{{ $variant?->wholesale_price_cents === null ? '' : number_format($variant->wholesale_price_cents/100, 2, '.', '') }}" class="rounded border-zinc-300 text-xs" placeholder="Wholesale price" aria-label="Wholesale price"></div>
                                        <input name="sku" value="{{ $variant?->sku }}" class="rounded border-zinc-300 text-xs" placeholder="SKU">
                                        <input name="image_url" value="{{ data_get($product->media, '0') }}" class="rounded border-zinc-300 text-xs" placeholder="https://… product image">
                                        <select name="status" class="rounded border-zinc-300 text-xs" aria-label="Product status"><option value="draft" @selected($product->status === 'draft')>Draft</option><option value="active" @selected($product->status === 'active')>Active</option><option value="archived" @selected($product->status === 'archived')>Archived</option></select>
                                        <textarea name="description" class="rounded border-zinc-300 text-xs" aria-label="Description">{{ $product->description }}</textarea>
                                        <input type="hidden" name="track_inventory" value="{{ $product->track_inventory ? 1 : 0 }}"><input type="hidden" name="inventory_quantity" value="{{ $variant?->inventory_quantity ?? 0 }}"><input type="hidden" name="is_available" value="{{ $variant?->is_available ? 1 : 0 }}">
                                        <button class="fb-btn fb-btn-secondary justify-center text-xs" type="submit">Save changes</button>
                                    </form>
                                    @if($product->status !== 'archived')<form method="POST" action="{{ route('managed-website.products.destroy', ['product' => $product]) }}" class="mt-2" onsubmit="return confirm('Archive {{ addslashes($product->title) }}? It will leave the storefront but order history will stay intact.')">@csrf @method('DELETE')<button class="text-xs font-bold text-rose-700" type="submit">Archive product</button></form>@endif
                                </details></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-5 py-14 text-center text-sm text-zinc-500">Add the first product or import a catalog CSV.</td></tr>
                        @endforelse
                        </tbody>
                    </table></div>
                </section>

                @if($isEditorEnabled)
                    <section id="new-product" class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-bold text-zinc-950">Add a product or service</h2>
                        <form method="POST" action="{{ route('managed-website.products.store') }}" class="mt-5 grid gap-4 md:grid-cols-2">@csrf
                            <label class="text-sm font-semibold">Title<input required name="title" class="mt-1 block w-full rounded-lg border-zinc-300" placeholder="Five-stave chair"></label>
                            <label class="text-sm font-semibold">Sell as<select name="product_type" class="mt-1 block w-full rounded-lg border-zinc-300"><option value="physical">Physical product</option><option value="service">Fixed-price service</option><option value="quote">Quote-only service</option></select></label>
                            <label class="text-sm font-semibold">Retail price<input required name="price" type="number" min="0" step=".01" class="mt-1 block w-full rounded-lg border-zinc-300" value="0.00"></label>
                            <label class="text-sm font-semibold">Wholesale price<input name="wholesale_price" type="number" min="0" step=".01" class="mt-1 block w-full rounded-lg border-zinc-300" placeholder="Optional"></label>
                            <label class="text-sm font-semibold">SKU<input name="sku" class="mt-1 block w-full rounded-lg border-zinc-300"></label>
                            <label class="text-sm font-semibold">Status<select name="status" class="mt-1 block w-full rounded-lg border-zinc-300"><option value="draft">Draft</option><option value="active">Active</option></select></label>
                            <label class="text-sm font-semibold md:col-span-2">Product image URL<input name="image_url" type="url" class="mt-1 block w-full rounded-lg border-zinc-300" placeholder="https://…"></label>
                            <label class="text-sm font-semibold md:col-span-2">Description<textarea name="description" rows="3" class="mt-1 block w-full rounded-lg border-zinc-300"></textarea></label>
                            <label class="flex items-center gap-2 text-sm font-semibold"><input type="hidden" name="track_inventory" value="0"><input type="checkbox" name="track_inventory" value="1"> Track inventory</label>
                            <label class="text-sm font-semibold">Starting inventory<input name="inventory_quantity" type="number" min="0" class="mt-1 block w-full rounded-lg border-zinc-300" value="0"></label>
                            <input type="hidden" name="is_available" value="1"><button class="fb-btn fb-btn-primary md:col-span-2 justify-center" type="submit">Save product</button>
                        </form>
                    </section>
                @endif
            @elseif($screen === 'customers')
                <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm"><div class="border-b border-zinc-200 p-4"><strong class="text-sm">Website customers</strong></div><div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500"><tr><th class="px-5 py-3">Customer</th><th class="px-5 py-3">Email</th><th class="px-5 py-3">Phone</th><th class="px-5 py-3 text-right">Created</th><th class="px-5 py-3"></th></tr></thead><tbody class="divide-y divide-zinc-100">@forelse($customers as $customer)<tr><td class="px-5 py-4 font-semibold text-zinc-950">{{ trim($customer->first_name.' '.$customer->last_name) ?: 'Website shopper' }}</td><td class="px-5 py-4">{{ $customer->email ?: '—' }}</td><td class="px-5 py-4">{{ $customer->phone ?: '—' }}</td><td class="px-5 py-4 text-right text-zinc-500">{{ $customer->created_at->format('M j, Y') }}</td><td class="px-5 py-4"><details><summary class="cursor-pointer text-xs font-bold text-emerald-800">Edit</summary><form method="POST" action="{{ route('managed-website.customers.update', ['customer' => $customer]) }}" class="mt-3 grid min-w-72 gap-2">@csrf @method('PUT')<input name="first_name" value="{{ $customer->first_name }}" class="rounded border-zinc-300 text-xs" placeholder="First name"><input name="last_name" value="{{ $customer->last_name }}" class="rounded border-zinc-300 text-xs" placeholder="Last name"><input name="email" value="{{ $customer->email }}" class="rounded border-zinc-300 text-xs" placeholder="Email"><input name="phone" value="{{ $customer->phone }}" class="rounded border-zinc-300 text-xs" placeholder="Phone"><button class="fb-btn fb-btn-secondary justify-center text-xs" type="submit">Save</button></form></details></td></tr>@empty<tr><td colspan="5" class="px-5 py-14 text-center text-sm text-zinc-500">Customers appear here after a native Website checkout.</td></tr>@endforelse</tbody></table></div></section>
                @if($isEditorEnabled)<section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-bold text-zinc-950">Add a Website customer</h2><form method="POST" action="{{ route('managed-website.customers.store') }}" class="mt-4 grid gap-3 md:grid-cols-2">@csrf<label class="text-sm font-semibold">First name<input name="first_name" class="mt-1 block w-full rounded-lg border-zinc-300"></label><label class="text-sm font-semibold">Last name<input name="last_name" class="mt-1 block w-full rounded-lg border-zinc-300"></label><label class="text-sm font-semibold">Email<input name="email" type="email" class="mt-1 block w-full rounded-lg border-zinc-300"></label><label class="text-sm font-semibold">Phone<input name="phone" class="mt-1 block w-full rounded-lg border-zinc-300"></label><button class="fb-btn fb-btn-primary md:col-span-2 justify-center" type="submit">Save customer</button></form></section>@endif
            @else
                <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm"><div class="border-b border-zinc-200 p-4"><strong class="text-sm">Website orders</strong></div><div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500"><tr><th class="px-5 py-3">Order</th><th class="px-5 py-3">Customer</th><th class="px-5 py-3">Payment</th><th class="px-5 py-3">Fulfillment</th><th class="px-5 py-3 text-right">Total</th><th class="px-5 py-3"></th></tr></thead><tbody class="divide-y divide-zinc-100">@forelse($orders as $order)<tr><td class="px-5 py-4 font-semibold text-zinc-950">{{ $order->number }}<span class="mt-1 block text-xs font-normal text-zinc-500">{{ $order->created_at->format('M j, Y g:i A') }}</span></td><td class="px-5 py-4">{{ data_get($order->customer_snapshot, 'name', '—') }}<span class="mt-1 block text-xs text-zinc-500">{{ data_get($order->customer_snapshot, 'email') }}</span></td><td class="px-5 py-4"><span class="rounded-full px-2 py-1 text-xs font-bold {{ $order->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-900' : 'bg-zinc-100 text-zinc-700' }}">{{ str($order->payment_status)->headline() }}</span></td><td class="px-5 py-4">{{ str($order->fulfillment_status)->headline() }}<span class="mt-1 block text-xs text-zinc-500">{{ str($order->fulfillment_method)->headline() }}</span></td><td class="px-5 py-4 text-right font-semibold">${{ number_format($order->total_cents / 100, 2) }}</td><td class="px-5 py-4">@if($order->payment_status === 'paid' && $order->fulfillment_status !== 'fulfilled')<form method="POST" action="{{ route('managed-website.orders.fulfill', ['order' => $order]) }}">@csrf<button class="fb-btn fb-btn-secondary whitespace-nowrap" type="submit">Mark fulfilled</button></form>@endif</td></tr>@empty<tr><td colspan="6" class="px-5 py-14 text-center text-sm text-zinc-500">Paid Website orders appear here after Stripe confirms payment.</td></tr>@endforelse</tbody></table></div></section>
            @endif
        </div>
    </flux:main>
</x-layouts::app.sidebar>
