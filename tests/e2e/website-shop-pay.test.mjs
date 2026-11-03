import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../resources/js/website-shop-pay.js', import.meta.url), 'utf8');
function harness({submitted = false, handle = () => ({})} = {}) {
    const events = new Map(), clicks = new Map(), calls = [], confirmations = [], redirects = [];
    const message = {textContent: ''};
    let rendered = false;
    const session = {paymentRequest: {paymentMethod: 'fictional-provider-token'},
        addEventListener: (name, handler) => events.set(name, handler),
        completePaymentConfirmationRequest: value => confirmations.push(value),
        completeSessionRequest: () => {}, completeShippingAddressChange: () => {},
        completeDeliveryMethodTypeChange: () => {}, completeDeliveryMethodChange: () => {},
        completePickupLocationChange: () => {}, completePickupLocationFilterChange: () => {},
        completeDiscountCodeChange: () => {}, close: () => {},
    };
    const button = {disabled: false, addEventListener: (name, handler) => clicks.set(name, handler)};
    const root = {dataset: {eventUrl: '/checkout/website/shop-pay/fictional'}, querySelector: () => button};
    const api = {configure: () => {}, build: value => value, createSession: () => session,
        createButton: () => ({render: () => {rendered = true;}})};
    vm.runInNewContext(source, {
        document: {readyState: 'complete', querySelector: selector => ({
            '[data-website-wallet]': root, '#wallet-message': message,
            '#website-wallet-config': {textContent: JSON.stringify({shopId: 12345, clientId: 'fictional', initial: {}, method: 'pickup', submitted})},
            'meta[name="csrf-token"]': {content: 'fictional-csrf'},
        })[selector]},
        window: {ShopPay: {PaymentRequest: api}}, location: {assign: url => redirects.push(url)},
        fetch: async (url, options) => {
            const body = JSON.parse(options.body);
            calls.push(body);
            const data = await handle(body);
            return {ok: true, json: async () => data};
        },
    });
    return {events, clicks, calls, confirmations, redirects, message, button, rendered};
}

test('requires a reviewed server total before the explicit payment confirmation', async () => {
    const h = harness({handle: body => body.event === 'quote' ? {quote_hash: 'a'.repeat(64), payment_request: {total: {amount: '21.75', currencyCode: 'USD'}}} : {}});
    assert.equal(h.rendered, true);
    await h.events.get('paymentconfirmationrequested')();
    assert.deepEqual(h.calls.map(x => x.event), ['quote']);
    assert.match(h.confirmations[0].errors[0].message, /Review/);
    assert.equal(h.confirmations[0].updatedPaymentRequest.total.amount, 21.75);
    await h.events.get('paymentconfirmationrequested')();
    assert.deepEqual(h.calls.map(x => x.event), ['quote', 'submit']);
    assert.equal(h.calls[1].payment_method_token, 'fictional-provider-token');
    assert.equal(h.calls[1].quote_hash, 'a'.repeat(64));
});

test('a lost submission response never sends another payment when Pay is tapped again', async () => {
    const h = harness({handle: body => {
        if (body.event === 'quote') return {quote_hash: 'a'.repeat(64), payment_request: {}};
        throw new Error('Fictional lost response');
    }});
    await h.events.get('paymentconfirmationrequested')();
    await h.events.get('paymentconfirmationrequested')();
    await h.events.get('paymentconfirmationrequested')();
    assert.equal(h.calls.filter(x => x.event === 'submit').length, 1);
    assert.match(h.message.textContent, /already submitted/);
});

test('a provider completion event retrieves status and waits for server-confirmed payment', async () => {
    let paid = false;
    const h = harness({handle: () => paid ? {status: 'paid', confirmation_url: '/checkout/website/success?order=WEB-FICTIONAL'} : {status: 'submitted'}});
    await h.events.get('paymentcomplete')();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(h.redirects.length, 0);
    paid = true;
    h.clicks.get('click')({currentTarget: h.button});
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(h.redirects[0], '/checkout/website/success?order=WEB-FICTIONAL');
    assert.deepEqual(h.calls.map(x => x.event), ['status', 'status']);
});

test('opening a submitted checkout offers recovery without creating another SDK session', () => {
    const h = harness({submitted: true});
    assert.equal(h.rendered, false);
    assert.equal(h.events.size, 0);
    assert.equal(h.clicks.has('click'), true);
    assert.equal(h.calls.length, 0);
});
