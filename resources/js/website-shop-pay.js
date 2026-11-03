const root = document.querySelector('[data-website-wallet]');
if (root) {
    const config = JSON.parse(document.querySelector('#website-wallet-config').textContent);
    const message = document.querySelector('#wallet-message');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    let submitted = config.submitted;
    const post = async body => {
        const response = await fetch(root.dataset.eventUrl, {method: 'POST', credentials: 'same-origin', headers: {
            'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf,
        }, body: JSON.stringify(body)});
        const data = await response.json();
        if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'This payment needs review. Check the original payment status.');
        return data;
    };
    const check = async () => {
        const data = await post({event: 'status'});
        if (data.confirmation_url) {
            location.assign(data.confirmation_url);
            return;
        }
        if (data.status === 'failed') {
            message.textContent = 'Shop Pay confirmed that this payment failed. Return to your cart to choose a new payment.';
        } else {
            message.textContent = submitted ? 'Payment is not confirmed yet. Your original purchase is saved; check again before paying.' : 'No payment has been submitted. You can continue with Shop Pay or cancel this checkout.';
        }
    };
    root.querySelector('[data-check-status]').addEventListener('click', event => {
        const button = event.currentTarget;
        button.disabled = true;
        check().catch(error => {message.textContent = error.message;}).finally(() => {button.disabled = false;});
    });
    const start = async () => {
        if (submitted) return;
        if (!window.ShopPay?.PaymentRequest) throw new Error('Shop Pay could not load. You can cancel this unsubmitted checkout and choose another payment method.');
        const api = window.ShopPay.PaymentRequest;
        api.configure({shopId: config.shopId, clientId: config.clientId, locale: 'en', debug: false, discountCodeField: false});
        // Display conversion only; the server freezes all amounts as integer cents.
        const display = value => Array.isArray(value) ? value.map(display) : value && typeof value === 'object'
            ? Object.fromEntries(Object.entries(value).map(([key, item]) => [key, key === 'amount' && typeof item === 'string' ? Number(item) : display(item)])) : value;
        const build = value => api.build(display(value));
        const session = api.createSession({paymentRequest: build(config.initial)});
        let contact = null, quoteHash = null, chain = Promise.resolve();
        const failure = error => ({errors: [{type: 'generalError', message: error.message || 'The total could not be confirmed.'}]});
        const quote = () => {
            const currentContact = contact;
            const work = chain.then(() => post({event: 'quote', contact: currentContact}));
            chain = work.catch(() => undefined);
            return work.then(data => {quoteHash = data.quote_hash; return {updatedPaymentRequest: build(data.payment_request)};});
        };
        session.addEventListener('sessionrequested', async () => {
            try {
                const data = await post({event: 'session'});
                session.completeSessionRequest({token: data.token, checkoutUrl: data.checkoutUrl, sourceIdentifier: data.sourceIdentifier, updatedPaymentRequest: build(data.payment_request)});
            } catch (error) {message.textContent = error.message; session.close();}
        });
        session.addEventListener('shippingaddresschanged', async event => {
            contact = event.shippingAddress;
            quoteHash = null;
            try {session.completeShippingAddressChange(await quote());} catch (error) {session.completeShippingAddressChange(failure(error));}
        });
        session.addEventListener('deliverymethodtypechanged', async event => {
            quoteHash = null;
            try {
                if (event.deliveryMethodType !== (config.method === 'ship' ? 'SHIPPING' : 'PICKUP')) throw new Error('Use the delivery choice selected in your cart. Cancel this checkout to change it.');
                session.completeDeliveryMethodTypeChange(await quote());
            } catch (error) {session.completeDeliveryMethodTypeChange(failure(error));}
        });
        session.addEventListener('deliverymethodchanged', async event => {
            quoteHash = null;
            try {
                if (event.deliveryMethod?.code !== 'ship') throw new Error('Choose the shipping service selected in your cart.');
                session.completeDeliveryMethodChange(await quote());
            } catch (error) {session.completeDeliveryMethodChange(failure(error));}
        });
        session.addEventListener('pickuplocationchanged', async () => {
            try {session.completePickupLocationChange(await quote());} catch (error) {session.completePickupLocationChange(failure(error));}
        });
        session.addEventListener('pickuplocationfilterchanged', async () => {
            try {session.completePickupLocationFilterChange(await quote());} catch (error) {session.completePickupLocationFilterChange(failure(error));}
        });
        session.addEventListener('discountcodechanged', () => session.completeDiscountCodeChange(failure(new Error('Your cart total is fixed for this checkout.'))));
        session.addEventListener('paymentconfirmationrequested', async () => {
            try {
                await chain;
                if (submitted) throw new Error('This payment was already submitted. Check its status before paying again.');
                if (!quoteHash) {
                    session.completePaymentConfirmationRequest({...await quote(), ...failure(new Error('Your tax and total are confirmed. Review them, then choose Pay again.'))});
                    return;
                }
                // An interrupted response retains the original attempt on both sides.
                submitted = true;
                await post({event: 'submit', quote_hash: quoteHash, payment_method_token: session.paymentRequest.paymentMethod});
                session.completePaymentConfirmationRequest({});
                message.textContent = 'Your payment is being confirmed.';
            } catch (error) {message.textContent = error.message; session.completePaymentConfirmationRequest(failure(error));}
        });
        session.addEventListener('paymentcomplete', () => {check().catch(error => {message.textContent = error.message;});});
        session.addEventListener('paymentattemptfailed', () => {check().catch(error => {message.textContent = error.message;});});
        api.createButton({buyWith: true}).render('#website-shop-pay-button');
    };
    const run = () => start().catch(error => {message.textContent = error.message;});
    if (document.readyState === 'complete') run(); else window.addEventListener('load', run, {once: true});
}
