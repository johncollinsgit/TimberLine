@php
    $shippingThreshold = (int) data_get($site->publishedSiteVersion?->settings, 'free_shipping_threshold_cents', 0);
    $shippingSubtotal = $cart->items->sum(fn ($item) => $item->variant->price_cents * $item->quantity);
@endphp
<fieldset id="website-shipping-fields" style="border:0;padding:0;margin:18px 0" data-rates-url="{{ route('managed-website.store.shipping-rates') }}" data-checkout-ready="{{ $checkoutReady ? 1 : 0 }}" data-threshold="{{ $shippingThreshold }}" data-subtotal="{{ $shippingSubtotal }}">
    <legend style="font-weight:700;margin-bottom:12px">Delivery address</legend>
    <input type="hidden" name="shipping_address[name]">
    @foreach(['street1' => ['Street address', 'address-line1'], 'street2' => ['Apartment / suite', 'address-line2'], 'city' => ['City', 'address-level2'], 'state' => ['State', 'address-level1'], 'zip' => ['ZIP code', 'postal-code']] as $key => [$label, $autocomplete])
        <label style="display:block;margin:10px 0">{{ $label }}<input @required($key !== 'street2') name="shipping_address[{{ $key }}]" autocomplete="{{ $autocomplete }}" @if($key === 'state') maxlength="2" @endif style="display:block;width:100%;margin-top:5px;padding:10px;border:1px solid #b7c3b7;border-radius:5px"></label>
    @endforeach
    <label style="display:block;margin:10px 0">Address type<select name="shipping_address[residential]" style="display:block;width:100%;margin-top:5px;padding:10px;border:1px solid #b7c3b7;border-radius:5px"><option value="1">Home / residential</option><option value="0">Business / commercial</option></select></label>
    <label style="display:block;margin:10px 0">Delivery contact phone (needed for FedEx)<input type="tel" name="shipping_address[phone]" autocomplete="shipping tel" maxlength="40" style="display:block;width:100%;margin-top:5px;padding:10px;border:1px solid #b7c3b7;border-radius:5px"></label>
    <p style="font-size:13px">Include a US phone number to compare FedEx options. Carrier transit estimates start after handoff.</p>
    <input type="hidden" name="shipping_address[country]" value="US">
    <button type="button" data-find-rates class="store-button sn-btn sn-btn-light" style="width:100%;margin-top:12px">Find shipping options</button>
    <div data-shipping-rates role="status" aria-live="polite" style="margin-top:12px"></div>
</fieldset>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const fields = document.getElementById('website-shipping-fields');
    if (!fields) return;
    const form = fields.closest('form'), method = form.querySelector('[name="fulfillment_method"]'), finder = fields.querySelector('[data-find-rates]'), results = fields.querySelector('[data-shipping-rates]'), pay = form.querySelector('button[type="submit"]'), name = form.querySelector('[name="name"]');
    let revision = 0, controller, expiryTimer;
    const ships = () => method.value === 'ship';
    const delivers = () => ['ship', 'local_delivery'].includes(method.value);
    const money = cents => new Intl.NumberFormat('en-US', {style:'currency', currency:'USD'}).format(cents / 100);
    function reset() {
        revision++; controller?.abort(); clearTimeout(expiryTimer); finder.disabled = false;
        results.replaceChildren(); pay.disabled = fields.dataset.checkoutReady !== '1' || ships();
        document.querySelectorAll('[data-shipping-price]').forEach(e => e.textContent = ships() ? 'Calculated next' : '—');
        document.querySelectorAll('[data-estimated-total]').forEach(e => e.textContent = money(Number(fields.dataset.subtotal)));
    }
    function toggle() {
        reset(); fields.hidden = !delivers(); fields.disabled = !delivers(); finder.hidden = !ships();
    }
    method.addEventListener('change', toggle);
    fields.addEventListener('input', event => { if (event.target.name?.startsWith('shipping_address[')) reset(); });
    name.addEventListener('input', reset);
    finder.addEventListener('click', async () => {
        fields.querySelector('[name="shipping_address[name]"]').value = name.value;
        if (!form.reportValidity()) return;
        reset(); const requestedRevision = revision; controller = new AbortController(); finder.disabled = true;
        const address = {};
        fields.querySelectorAll('[name^="shipping_address["]').forEach(input => address[input.name.slice(17, -1)] = input.value);
        results.textContent = 'Finding shipping options…';
        try {
            const response = await fetch(fields.dataset.ratesUrl, {method:'POST', signal:controller.signal, headers:{'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':form.querySelector('[name="_token"]').value}, body:JSON.stringify({shipping_address:address})});
            const data = await response.json();
            if (requestedRevision !== revision) return;
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Shipping options are unavailable.');
            results.replaceChildren();
            const quotes = (Array.isArray(data.quotes) ? data.quotes : []).filter(quote => Date.parse(quote.expires_at) > Date.now());
            if (!quotes.length) throw new Error('No shipping options are available. Check your address, try again, or contact the store for delivery help.');
            const expiresAt = Math.min(...quotes.map(quote => Date.parse(quote.expires_at)));
            expiryTimer = setTimeout(() => {
                if (requestedRevision !== revision) return;
                reset(); results.textContent = 'These shipping options expired. Find shipping options again before paying.';
            }, Math.max(0, expiresAt - Date.now()));
            for (const quote of quotes) {
                const shippingCents = Number(fields.dataset.threshold) > 0 && Number(fields.dataset.subtotal) >= Number(fields.dataset.threshold) ? 0 : quote.amount_cents;
                const label = document.createElement('label'), radio = document.createElement('input');
                label.style.cssText = 'display:block;padding:12px 0'; radio.type = 'radio'; radio.name = 'shipping_rate_quote_id'; radio.value = quote.id; radio.dataset.expiresAt = quote.expires_at;
                radio.addEventListener('change', () => {
                    pay.disabled = fields.dataset.checkoutReady !== '1';
                    document.querySelectorAll('[data-shipping-price]').forEach(e => e.textContent = shippingCents === 0 ? 'Free shipping' : money(shippingCents));
                    document.querySelectorAll('[data-estimated-total]').forEach(e => e.textContent = money(Number(fields.dataset.subtotal) + shippingCents));
                });
                const service = {GroundAdvantage:'Ground Advantage', Priority:'Priority Mail', Express:'Priority Mail Express'}[quote.service] || quote.service.replace(/^FEDEX_/, '').replace(/_/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2');
                label.append(radio, document.createTextNode(' ' + quote.carrier + ' · ' + service + ' · ' + (shippingCents === 0 ? 'Free shipping' : money(shippingCents)) + (quote.mode === 'test' ? ' · Test rate' : '')));
                const estimate = document.createElement('small');
                estimate.style.cssText = 'display:block;margin:5px 0 0 22px';
                estimate.textContent = Number.isInteger(quote.delivery_days) && quote.delivery_days > 0
                    ? 'Estimated carrier transit: ' + quote.delivery_days + ' day' + (quote.delivery_days === 1 ? '' : 's') + ' after handoff. Processing time is additional.'
                    : 'Delivery estimate unavailable. Contact the store for timing.';
                label.append(estimate); results.append(label);
            }
        } catch (error) {
            if (requestedRevision === revision && error.name !== 'AbortError') results.textContent = error.message;
        } finally {
            if (requestedRevision === revision) finder.disabled = false;
        }
    });
    form.addEventListener('submit', event => {
        fields.querySelector('[name="shipping_address[name]"]').value = name.value;
        const selected = fields.querySelector('[name="shipping_rate_quote_id"]:checked');
        if (ships() && (!selected || !(Date.parse(selected.dataset.expiresAt) > Date.now()))) {
            event.preventDefault(); results.textContent = 'Find and select a shipping option before continuing.';
        }
    });
    toggle();
});
</script>
