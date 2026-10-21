@extends('pos.layout')
@section('title', $register->name)
@section('content')
    @php($money = fn ($cents) => '$'.number_format($cents / 100, 2))
    <div class="page-heading">
        <div><div class="eyebrow">Counter checkout</div><h1>{{ $register->name }}</h1><p class="muted">Choose items, review the server-confirmed total, then practice a reader outcome.</p></div>
        <span class="status {{ $blocked ? 'unknown' : 'succeeded' }}">{{ $blocked ? 'Sale needs attention' : 'Ready for a practice sale' }}</span>
    </div>
    <div class="register-intro"><div><strong>Practice the full counter flow.</strong><p>Choose merchandise, review the frozen total, then use the simulated reader. Receipts and manager refunds follow the verified outcome.</p></div><span class="step-pill">1 · Basket → 2 · Reader → 3 · Receipt</span></div>
    @if ($blocked)
        <div class="notice" role="status"><strong>Finish the open sale before starting another.</strong><p>Open its status below. An uncertain payment keeps its stock hold until the simulated reader confirms an outcome.</p></div>
    @endif
    <form id="sale-form" method="POST" action="{{ route('pos.start', ['register' => $register->id, 'tenant' => $tenant->slug]) }}">
        @csrf
        <input type="hidden" name="request_id" value="{{ $requestId }}" data-start-request data-replay="{{ old('request_id') ? 'true' : 'false' }}">
        <div class="grid">
            <section class="panel" aria-labelledby="catalog-heading">
                <h2 id="catalog-heading">Products</h2><p class="muted compact">Up to 100 eligible catalog variants are shown.</p>
                <label for="catalog-search" class="muted">Find by name or SKU</label>
                <input id="catalog-search" type="search" placeholder="Search this catalog" autocomplete="off">
                <table><thead><tr><th scope="col">Item</th><th scope="col" class="number">Price</th><th scope="col" class="number">Quantity</th></tr></thead><tbody>
                    @forelse ($catalog as $index => $item)
                        <tr data-product data-price="{{ $item['price_cents'] }}" data-search="{{ strtolower($item['title'].' '.($item['variant_title'] ?? '').' '.($item['sku'] ?? '')) }}">
                            <td><strong>{{ $item['title'] }}</strong><span class="sub">{{ $item['variant_title'] ?? '' }}{{ !empty($item['sku']) ? ' · '.$item['sku'] : '' }}</span><span class="sub">{{ $item['available_quantity'] === null ? 'Not stock tracked' : $item['available_quantity'].' available' }}</span></td>
                            <td class="number">{{ $money($item['price_cents']) }}</td>
                            <td class="number"><input type="hidden" name="lines[{{ $index }}][variant_id]" value="{{ $item['variant_id'] }}"><label class="sr-only" for="quantity-{{ $item['variant_id'] }}" style="position:absolute;width:1px;height:1px;overflow:hidden">Quantity for {{ $item['title'] }}</label><input class="quantity" id="quantity-{{ $item['variant_id'] }}" type="number" name="lines[{{ $index }}][quantity]" min="0" max="99" step="1" value="{{ old('lines.'.$index.'.quantity', 0) }}" {{ $blocked ? 'disabled' : '' }}></td>
                        </tr>
                    @empty
                        <tr><td colspan="3"><div class="empty-state"><h3>Your merchandise catalog needs a review.</h3><p class="muted">No eligible products are available in this register's catalog. Ask the workspace owner to review active physical merchandise and stock before practicing a sale.</p></div></td></tr>
                    @endforelse
                </tbody></table>
            </section>
            <aside class="panel" aria-labelledby="basket-heading">
                <h2 id="basket-heading">Practice basket</h2>
                <p class="muted compact" id="basket-count" aria-live="polite">Select quantities from the catalog.</p>
                <dl class="totals"><div><dt>Estimated subtotal</dt><dd id="basket-subtotal">$0.00</dd></div><div><dt>Simulation tax {{ rtrim(rtrim(number_format($register->tax_rate_bps / 100, 2), '0'), '.') }}%</dt><dd id="basket-tax">$0.00</dd></div><div class="total"><dt>Estimated total</dt><dd id="basket-total">$0.00</dd></div></dl>
                <p class="muted compact">The server confirms current prices and tax before the reader step. This estimate does not create a sale.</p>
                <button class="full" type="submit" disabled>Review simulated sale</button>
                <p class="muted compact">Tax basis: {{ $register->tax_basis }}</p>
            </aside>
        </div>
    </form>
    <section class="panel" style="margin-top:1.25rem" aria-labelledby="recent-heading">
        <h2 id="recent-heading">Recent sales and recovery</h2><p class="muted compact">Up to 20 most recent practice sales. Open a sale to check its reader result or receipt.</p>
        <table><thead><tr><th scope="col">Sale</th><th scope="col">Created</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead><tbody>
        @forelse ($attempts as $attempt)
            <tr><td>Practice sale #{{ $attempt->id }}</td><td>{{ $attempt->created_at->format('M j, H:i') }}</td><td><span class="status {{ $attempt->status }}">{{ str_replace('_', ' ', ucfirst($attempt->status)) }}</span></td><td><a href="{{ route('pos.show', ['attempt' => $attempt->id, 'tenant' => $tenant->slug]) }}">Open sale</a></td></tr>
        @empty
            <tr><td colspan="4"><div class="empty-state"><h3>Your first practice sale will appear here.</h3><p class="muted">Choose merchandise above. Once the sale is prepared, use this area to reopen its reader result or receipt.</p></div></td></tr>
        @endforelse
        </tbody></table>
    </section>
@endsection
@section('scripts')
@include('pos.request-key-script')
<script>
(() => {
    const form = document.getElementById('sale-form');
    const request = form.querySelector('[data-start-request]');
    const scope = @json('everbranch-pos-start:'.$tenant->id.':'.$register->id);
    PosRequestKeys.bind({ form, input: request, scope, freshKey: @json($freshRequestId), blocked: @json($blocked || count($catalog) === 0),
        terminalStatuses: ['succeeded', 'failed', 'cancelled'], label: 'practice sale', nextLabel: 'Prepare another practice sale',
        lookup: async key => {
            const url = @json($requestLookupUrl);
            const response = await fetch(url.replace('00000000-0000-0000-0000-000000000000', key), { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error('Request status unavailable.');
            return (await response.json()).record;
        }
    });
    const money = cents => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(cents / 100);
    const update = () => {
        let subtotal = 0, count = 0;
        form.querySelectorAll('[data-product]').forEach(row => {
            const quantity = Math.max(0, Math.min(99, Number(row.querySelector('.quantity').value) || 0));
            subtotal += Number(row.dataset.price) * quantity; count += quantity;
        });
        const tax = Math.floor((subtotal * @json($register->tax_rate_bps) + 5000) / 10000);
        document.getElementById('basket-count').textContent = count ? `${count} item${count === 1 ? '' : 's'} selected` : 'Select quantities from the catalog.';
        document.getElementById('basket-subtotal').textContent = money(subtotal);
        document.getElementById('basket-tax').textContent = money(tax);
        document.getElementById('basket-total').textContent = money(subtotal + tax);
    };
    form.addEventListener('input', update); update();
    document.getElementById('catalog-search').addEventListener('input', event => {
        const query = event.target.value.trim().toLowerCase();
        form.querySelectorAll('[data-product]').forEach(row => { row.hidden = !row.dataset.search.includes(query); });
    });
})();
</script>
@endsection
