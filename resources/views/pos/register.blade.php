@extends('pos.layout')
@section('title', $register->name)
@section('content')
    @php($money = fn ($cents) => '$'.number_format($cents / 100, 2))
    <div class="page-heading">
        <div><div class="eyebrow">Counter checkout</div><h1>{{ $register->name }}</h1><p class="muted">Choose items, review the server-confirmed total, then practice a reader outcome.</p></div>
        <span class="status {{ $blocked ? 'unknown' : 'succeeded' }}">{{ $blocked ? 'Sale needs attention' : 'Ready for a practice sale' }}</span>
    </div>
    <div class="register-intro"><div><strong>Practice card or cash checkout.</strong><p>Choose merchandise, rehearse the payment, then print its verified simulation receipt. No money moves.</p></div><span class="step-pill">1 · Basket → 2 · Card or cash → 3 · Receipt</span></div>
    @if ($blocked)
        <div class="notice" role="status"><strong>Finish the open sale before starting another.</strong><p>Open its status below. An uncertain payment keeps its stock hold until the simulated reader confirms an outcome.</p></div>
    @endif
    <form id="sale-form" method="POST" action="{{ route('pos.start', ['register' => $register->id, 'tenant' => $tenant->slug]) }}">
        @csrf
        <input type="hidden" name="website_customer_id" id="selected-customer-id" value="{{ $selectedCustomer['id'] ?? '' }}">
        <input type="hidden" name="request_id" value="{{ $requestId }}" data-start-request data-replay="{{ old('request_id') ? 'true' : 'false' }}">
        <div class="grid">
            <section class="panel" aria-labelledby="catalog-heading">
                <h2 id="catalog-heading">Products</h2>
                <div class="scan-box"><label for="sku-scan">Scan a code or enter a SKU</label><div class="scan-input"><input id="sku-scan" type="text" maxlength="120" autocomplete="off" spellcheck="false" placeholder="Exact catalog SKU" {{ $blocked ? 'disabled' : '' }}><button type="button" id="sku-lookup" class="secondary" {{ $blocked ? 'disabled' : '' }}>Find & add</button></div><p id="sku-feedback" class="helper-copy" role="status" aria-live="polite">Codes must match a catalog SKU. Keyboard-style scanners can enter a code and press Enter.</p><p class="helper-copy">UPC mapping and physical scanner compatibility are unverified. This does not use USB access or a camera.</p></div>
                <p class="muted compact">Browse up to 100 eligible variants below. Exact SKU lookup searches the register's full catalog.</p>
                <label for="catalog-search" class="muted">Find by name or SKU</label>
                <input id="catalog-search" type="search" placeholder="Search this catalog" autocomplete="off">
                <table><thead><tr><th scope="col">Item</th><th scope="col" class="number">Price</th><th scope="col" class="number">Quantity</th></tr></thead><tbody id="catalog-rows" data-next-index="{{ count($catalog) }}">
                    @forelse ($catalog as $index => $item)
                        <tr data-product data-variant-id="{{ $item['variant_id'] }}" data-price="{{ $item['price_cents'] }}" data-search="{{ strtolower($item['title'].' '.($item['variant_title'] ?? '').' '.($item['sku'] ?? '')) }}">
                            <td><strong data-product-title>{{ $item['title'] }}</strong><span class="sub">{{ $item['variant_title'] ?? '' }}{{ !empty($item['sku']) ? ' · '.$item['sku'] : '' }}</span><span class="sub">{{ $item['available_quantity'] === null ? 'Not stock tracked' : $item['available_quantity'].' available' }}</span></td>
                            <td class="number" data-unit-price>{{ $money($item['price_cents']) }}</td>
                            <td class="number"><input type="hidden" name="lines[{{ $index }}][variant_id]" value="{{ $item['variant_id'] }}"><label class="sr-only" for="quantity-{{ $item['variant_id'] }}" style="position:absolute;width:1px;height:1px;overflow:hidden">Quantity for {{ $item['title'] }}</label><input class="quantity" id="quantity-{{ $item['variant_id'] }}" type="number" name="lines[{{ $index }}][quantity]" min="0" max="99" step="1" value="{{ old('lines.'.$index.'.quantity', 0) }}" {{ $blocked ? 'disabled' : '' }}></td>
                        </tr>
                    @empty
                        <tr data-empty-catalog><td colspan="3"><div class="empty-state"><h3>Your merchandise catalog needs a review.</h3><p class="muted">No eligible products are available in this register's catalog. Ask the workspace owner to review active physical merchandise and stock before practicing a sale.</p></div></td></tr>
                    @endforelse
                </tbody></table>
            </section>
            <aside class="panel" aria-labelledby="basket-heading">
                <h2 id="basket-heading">Practice basket</h2>
                <div class="customer-selection"><span class="eyebrow">Customer</span><strong id="selected-customer-name">{{ $selectedCustomer['name'] ?? 'Guest checkout' }}</strong><p id="selected-customer-contact" class="helper-copy">{{ $selectedCustomer ? implode(' · ', array_filter([$selectedCustomer['email'], $selectedCustomer['phone']])) : 'No customer profile is required.' }}</p><div class="actions"><button type="button" class="secondary" id="customer-picker-open" {{ $blocked ? 'disabled' : '' }}>Find or create customer</button><button type="button" class="secondary" id="customer-clear" {{ $selectedCustomer ? '' : 'hidden' }}>Use guest checkout</button><a id="selected-customer-history" {{ $selectedCustomer ? '' : 'hidden' }} href="{{ $selectedCustomer ? route('pos.customers.history', ['register' => $register->id, 'customer' => $selectedCustomer['id'], 'tenant' => $tenant->slug]) : '#' }}">Practice history</a></div></div>
                <p class="muted compact" id="basket-count" aria-live="polite">Select quantities from the catalog.</p>
                <dl class="totals"><div><dt>Estimated subtotal</dt><dd id="basket-subtotal">$0.00</dd></div><div><dt>Simulation tax {{ rtrim(rtrim(number_format($register->tax_rate_bps / 100, 2), '0'), '.') }}%</dt><dd id="basket-tax">$0.00</dd></div><div class="total"><dt>Estimated total</dt><dd id="basket-total">$0.00</dd></div></dl>
                <p class="muted compact">The server confirms current prices and tax before the reader step. This estimate does not create a sale.</p>
                <fieldset class="tender-fields"><legend>Practice payment</legend><div class="tender-options"><label><input type="radio" name="tender" value="card" {{ old('tender', 'card') === 'card' ? 'checked' : '' }}> Card rehearsal</label><label><input type="radio" name="tender" value="cash" {{ old('tender') === 'cash' ? 'checked' : '' }}> Cash rehearsal</label></div><div id="cash-fields" hidden><div class="field"><label for="cash-received">Cash received (USD)</label><input type="text" id="cash-received" inputmode="decimal" placeholder="30.00" value="{{ old('cash_received_cents') !== null ? number_format((int) old('cash_received_cents') / 100, 2, '.', '') : '' }}" disabled><input type="hidden" id="cash-cents" name="cash_received_cents" disabled></div><p class="helper-copy" id="cash-change" aria-live="polite">Enter the practice cash amount. The server confirms change.</p></div></fieldset>
                <p class="helper-copy">Card rehearsal continues to reader outcomes. Cash rehearsal records the received amount and server-confirmed change.</p>
                <button class="full" type="submit" disabled>{{ old('tender') === 'cash' ? 'Record cash rehearsal' : 'Review simulated sale' }}</button>
                <p class="muted compact">Tax basis: {{ $register->tax_basis }}</p>
            </aside>
        </div>
    </form>
    <section class="panel customer-picker" id="customer-picker" aria-labelledby="customer-heading" hidden>
        <div class="section-heading"><h2 id="customer-heading">Choose a native customer</h2><button type="button" class="secondary" id="customer-picker-close">Close customer panel</button></div>
        <p class="muted compact">Guest checkout is the default. Select a customer explicitly to attach this practice sale to their private history. This does not enroll anyone in marketing or send a message.</p>
        <div class="grid"><div><label for="customer-search">Find by name, email or phone</label><div class="scan-input"><input type="search" id="customer-search" minlength="2" maxlength="120" autocomplete="off" placeholder="Enter at least 2 characters"><button type="button" class="secondary" id="customer-search-button">Find customer</button></div><p id="customer-search-feedback" class="helper-copy" role="status" aria-live="polite">Search active native customers in this workspace.</p><div id="customer-results" class="customer-results"></div></div>
            <div><h3>Create a native customer</h3><p class="helper-copy">Enter only the details needed for this practice sale. An existing email must be found and selected instead of merged.</p><form id="customer-create-form" method="POST" action="{{ $customerCreateUrl }}">@csrf<input type="hidden" name="request_id" value="{{ $customerRequestId }}"><div class="field"><label for="customer-first-name">First name</label><input id="customer-first-name" name="first_name" type="text" maxlength="120" autocomplete="off" required></div><div class="field"><label for="customer-last-name">Last name (optional)</label><input id="customer-last-name" name="last_name" type="text" maxlength="120" autocomplete="off"></div><div class="field"><label for="customer-email">Email (optional)</label><input id="customer-email" name="email" type="email" maxlength="190" autocomplete="off"></div><div class="field"><label for="customer-phone">Phone (optional)</label><input id="customer-phone" name="phone" type="text" maxlength="80" autocomplete="off"></div><button type="submit" disabled>Create & select customer</button><button type="button" class="secondary" id="customer-create-new" hidden>Prepare a different customer</button><p id="customer-create-feedback" class="helper-copy" role="status" aria-live="polite">Checking saved creation requests…</p><div id="customer-create-recovery"></div></form></div>
        </div>
    </section>
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
@include('pos.register-script')
@include('pos.customer-script')
<script>
(() => {
    const form = document.getElementById('sale-form');
    const request = form.querySelector('[data-start-request]');
    const scope = @json('everbranch-pos-start:'.$tenant->id.':'.$register->id);
    PosRequestKeys.bind({ form, input: request, scope, freshKey: @json($freshRequestId), blocked: @json($blocked),
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
        const cash = form.querySelector('input[name="tender"]:checked')?.value === 'cash';
        form.querySelector('button[type="submit"]').textContent = cash ? 'Record cash rehearsal' : 'Review simulated sale';
        const received = document.getElementById('cash-received');
        const cents = document.getElementById('cash-cents');
        document.getElementById('cash-fields').hidden = !cash; received.disabled = !cash; received.required = cash; cents.disabled = !cash;
        const cashCents = PosRegisterUI.parseCashCents(received.value); cents.value = cashCents === null ? '' : String(cashCents);
        received.setCustomValidity(cash && cashCents === null ? 'Enter a valid cash amount with at most two decimal places.' : '');
        document.getElementById('cash-change').textContent = cashCents === null ? 'Enter the practice cash amount. The server confirms change.'
            : cashCents < subtotal + tax ? `Short by ${money(subtotal + tax - cashCents)}. The server will reject underpayment.` : `Estimated change ${money(cashCents - subtotal - tax)}. The server confirms change.`;
    };
    form.addEventListener('input', update); update();
    document.getElementById('catalog-search').addEventListener('input', event => {
        const query = event.target.value.trim().toLowerCase();
        form.querySelectorAll('[data-product]').forEach(row => { row.hidden = !row.dataset.search.includes(query); });
    });
    PosRegisterUI.bindScanner({ form, input: document.getElementById('sku-scan'), button: document.getElementById('sku-lookup'), feedback: document.getElementById('sku-feedback'),
        tbody: document.getElementById('catalog-rows'), update, blocked: @json($blocked), lookup: async code => {
            const url = new URL(@json($catalogLookupUrl)); url.searchParams.set('code', code);
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Unable to look up this SKU. No item was added.');
            return data.item;
        }
    });
})();
</script>
@endsection
