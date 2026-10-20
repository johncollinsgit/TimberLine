const root = document.querySelector('[data-marketplace-wallet]');
if (root) {
  const config = JSON.parse(document.querySelector('#marketplace-wallet-config').textContent);
  const message = document.querySelector('#marketplace-wallet-message');
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const run = async () => {
    if (!window.ShopPay?.PaymentRequest) throw new Error('Shop Pay could not load. Choose another payment method.');
    const api = window.ShopPay.PaymentRequest;
    api.configure({ shopId: config.shopId, clientId: config.clientId, locale: 'en', debug: false, discountCodeField: false });
    // Decimal conversion is display only. The server calculates and signs all amounts.
    const displayMoney = (value) => {
      if (Array.isArray(value)) return value.map(displayMoney);
      if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([k,v]) => [k, k === 'amount' && typeof v === 'string' ? Number(v) : displayMoney(v)]));
      return value;
    };
    const build = data => api.build(displayMoney(data));
    const session = api.createSession({ paymentRequest: build(config.initial) });
    let contact = null, quoteHash = null, submitUuid = null, quoteChain = Promise.resolve();
    const post = async (body) => {
      const response = await fetch(root.dataset.eventUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body) });
      const data = await response.json();
      if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Your payment needs review. Please try again.');
      return data;
    };
    const failure = error => ({ errors: [{ type: 'generalError', message: error.message || 'We could not confirm this update. Try again.' }] });
    const quote = () => { const address=contact; const work=quoteChain.then(()=>post({event:'quote',contact:address})); quoteChain=work.catch(()=>undefined); return work.then(data=>{quoteHash=data.quote_hash;return {updatedPaymentRequest:build(data.payment_request)};}); };
    session.addEventListener('sessionrequested', async () => {
      try { const data = await post({ event: 'session' }); session.completeSessionRequest({ token: data.token, checkoutUrl: data.checkoutUrl, sourceIdentifier: data.sourceIdentifier, updatedPaymentRequest: build(data.payment_request) }); }
      catch(error) { message.textContent = error.message; session.close(); }
    });
    session.addEventListener('shippingaddresschanged', async ev => { contact = ev.shippingAddress; try { session.completeShippingAddressChange(await quote()); } catch(error) { session.completeShippingAddressChange(failure(error)); } });
    session.addEventListener('deliverymethodtypechanged', async ev => {
      const wanted = config.group === 'shipping' ? 'SHIPPING' : 'PICKUP';
      if(ev.deliveryMethodType !== wanted) return session.completeDeliveryMethodTypeChange(failure(new Error('Use your other bag group to change between pickup and shipping.')));
      try { session.completeDeliveryMethodTypeChange(await quote()); } catch(error) { session.completeDeliveryMethodTypeChange(failure(error)); }
    });
    session.addEventListener('deliverymethodchanged', async () => { try { session.completeDeliveryMethodChange(await quote()); } catch(error) { session.completeDeliveryMethodChange(failure(error)); } });
    session.addEventListener('pickuplocationchanged', async ev => {
      if(ev.pickupLocation.code !== config.group) return session.completePickupLocationChange(failure(new Error('Select the market in this checkout group.')));
      try { session.completePickupLocationChange(await quote()); } catch(error) { session.completePickupLocationChange(failure(error)); }
    });
    session.addEventListener('pickuplocationfilterchanged', async () => { try { session.completePickupLocationFilterChange(await quote()); } catch(error) { session.completePickupLocationFilterChange(failure(error)); } });
    session.addEventListener('discountcodechanged', () => session.completeDiscountCodeChange(failure(new Error('Marketplace promotions are already included in your total.'))));
    session.addEventListener('paymentconfirmationrequested', async ev => {
      try {
        await quoteChain;
        if(config.group !== 'shipping' && !quoteHash) { const update = await quote(); session.completePaymentConfirmationRequest({ ...update, ...failure(new Error('Your pickup tax has been confirmed. Review the total and choose Pay again.')) }); return; }
        if(!quoteHash) throw new Error('Confirm your delivery address before paying.');
        submitUuid ||= crypto.randomUUID();
        await post({ event: 'submit', quote_hash: quoteHash, request_uuid: submitUuid, payment_method_token: session.paymentRequest.paymentMethod });
        session.completePaymentConfirmationRequest({}); message.textContent = 'Your payment is being confirmed.';
      } catch(error) { message.textContent = error.message; session.completePaymentConfirmationRequest(failure(error)); }
    });
    session.addEventListener('paymentcomplete', () => { location.assign(root.dataset.confirmationUrl); });
    session.addEventListener('paymentattemptfailed', async () => { message.textContent = 'Checking the payment attempt before another payment…'; try { const data=await post({event:'status'}); if(data.status==='wallet_session'){submitUuid=null;message.textContent='Shop Pay did not confirm this payment. You can choose another payment method in the Shop Pay window.';} else message.textContent='Your payment is awaiting confirmation. Please check its status before paying again.'; } catch(error){message.textContent=error.message;} });
    api.createButton({buyWith: true}).render('#marketplace-shop-pay-button');
  };
  const start = () => run().catch(error => { message.textContent = error.message; });
  if(document.readyState === 'complete') start(); else window.addEventListener('load', start, {once: true});
}
