/* Preparation only. No card data, payment credentials, offline collection or service worker. */
(() => {
    'use strict';
    const boot = window.posStaff;
    const el = id => document.getElementById(id);
    const money = value => new Intl.NumberFormat('en-US', {style: 'currency', currency: 'USD'}).format(value / 100);
    let register, items = [], pending, handoff, busy = false, retryBody, timer;
    const storageKey = () => `pos-handoff:${boot.tenant}:${boot.device}:${register}`;
    const base = () => `${boot.base}/registers/${register}`;
    function save(value) {
        // Opaque recovery identifiers only; never store buyer details or basket contents.
        sessionStorage.setItem(storageKey(), JSON.stringify(value));
        pending = value;
    }
    async function api(path, body) {
        const response = await fetch(base() + path, {method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
            body: body === undefined ? undefined : JSON.stringify(body)});
        const value = await response.json();
        if (!response.ok) {
            const error = Error((value.error?.code || (response.status === 419 ? 'Sign in again, then check the original basket.' : 'Request not confirmed.')).replaceAll('_', ' '));
            error.status = response.status; throw error;
        }
        return value;
    }
    async function run(action) {
        if (busy) return;
        busy = true; el('message').textContent = '';
        document.querySelectorAll('button, input, select').forEach(node => { node.disabled = true; });
        try { await action(); }
        catch (error) {
            if (error.status === 422 && retryBody) { sessionStorage.removeItem(storageKey()); pending = null; retryBody = null; }
            el('message').textContent = error.message || 'Connection lost. Check the original basket before starting another.';
        }
        finally { busy = false; document.querySelectorAll('button, input, select').forEach(node => { node.disabled = false; }); }
    }
    function lines(parent, values) {
        parent.replaceChildren();
        for (const line of values) {
            const row = document.createElement('div'); row.className = 'line';
            const title = document.createElement('span'); title.textContent = `${line.quantity} × ${line.title}`;
            const amount = document.createElement('strong'); amount.textContent = money(line.subtotal_minor + line.tax_minor);
            row.append(title, amount); parent.append(row);
        }
    }
    function show(value) {
        handoff = value; save({request: value.request_id, id: value.id});
        el('basket').hidden = true; el('handoff').hidden = false;
        el('handoff-code').textContent = value.code; lines(el('lines'), value.lines); el('total').textContent = money(value.amount_minor);
        const labels = {ready: 'Waiting for the authorized iPhone.', claimed: 'The iPhone is checking this basket.', quoted: 'On the iPhone. Ready for payment review.',
            unknown: 'Payment is being checked. Do not take another payment.', succeeded: 'Payment confirmed.', failed: 'Payment failed.', cancelled: 'Cancelled.', invalid: 'The basket changed. Prepare a fresh basket.', expired: 'Basket expired. Prepare a fresh basket.'};
        el('payment-status').textContent = labels[value.state] || 'Payment needs review on the iPhone. Do not charge again.';
        el('cancel').hidden = value.state !== 'ready';
        el('new').hidden = !value.can_leave;
        if (value.state === 'succeeded' && !value.receipt_available) {
            el('payment-status').textContent = 'Payment confirmed. Order finalization needs reconciliation on the iPhone. Do not charge again.';
            el('new').hidden = true;
        }
        el('stock-note').hidden = value.state !== 'ready';
        el('handoff-title').textContent = value.receipt_available ? 'Sale complete' : 'Sale handoff';
    }
    async function check() {
        if (!pending) return;
        const value = await api(pending.id ? `/handoffs/${pending.id}` : `/requests/${pending.request}`);
        show(value);
        if (value.receipt_available) {
            const receipt = await api(`/handoffs/${value.id}/receipt`);
            el('receipt').textContent = [receipt.merchant_location, `Receipt ${receipt.receipt_number}`,
                ...receipt.lines.map(line => `${line.quantity} × ${line.title}`), `Paid: ${money(receipt.amount_minor)}`,
                `Refunded: ${money(receipt.refunded_minor)}`, `Payment: ${receipt.payment_reference}`].join('\n');
            el('receipt').hidden = false; el('print').hidden = false;
        }
    }
    async function load() {
        const value = await api('/catalog'); items = value.items;
        el('products').replaceChildren();
        for (const item of items) {
            const row = document.createElement('div'); row.className = 'item';
            const label = document.createElement('label'); label.htmlFor = `qty-${item.item_id}`; label.style.margin = '0';
            const title = document.createElement('strong'); title.textContent = item.title;
            const detail = document.createElement('small'); detail.textContent = `${money(item.unit_amount_minor)} before tax${item.available_quantity == null ? '' : ` · ${item.available_quantity} available`}`;
            label.append(title, detail);
            const quantity = document.createElement('input'); quantity.id = label.htmlFor; quantity.type = 'number'; quantity.inputMode = 'numeric'; quantity.min = '0'; quantity.max = Math.min(99, item.available_quantity ?? 99); quantity.value = '0';
            row.append(label, quantity); el('products').append(row);
        }
        if (!items.length) el('products').textContent = 'No products are available for this register.';
        el('printing-quote').replaceChildren(new Option('Choose a quote', ''));
        for (const quote of value.quotes) el('printing-quote').add(new Option(`${quote.number} · ${money(quote.amount_minor)}`, quote.id));
        el('printing').hidden = !value.quotes.length;
        el('basket').hidden = false;
    }
    for (const option of boot.registers) el('register').add(new Option(`${option.name} · ${option.location}`, option.id));
    if (!boot.registers.length) el('register-note').textContent = 'No register is assigned to this browser yet. Open Browser enrollment below.';
    el('register').addEventListener('change', () => run(async () => {
        clearInterval(timer); register = el('register').value; pending = null; handoff = null; retryBody = null;
        el('basket').hidden = true; el('handoff').hidden = true; el('receipt').hidden = true; el('print').hidden = true;
        if (!register) return;
        const stored = sessionStorage.getItem(storageKey());
        pending = stored ? JSON.parse(stored) : null;
        if (pending) { el('handoff').hidden = false; await check(); }
        else await load();
        timer = setInterval(() => { if (pending && !busy && document.visibilityState === 'visible' && navigator.onLine) run(check); }, 8000);
    }));
    el('refresh').addEventListener('click', () => run(load));
    el('basket').addEventListener('submit', event => {
        event.preventDefault(); run(async () => {
            if (pending && !retryBody) { await check(); return; }
            if (!retryBody) {
                const body = {request_id: crypto.randomUUID()};
                if (el('printing-quote').value) body.source_order_id = Number(el('printing-quote').value);
                else body.items = items.map(item => ({item_id: item.item_id, quantity: Number(el(`qty-${item.item_id}`).value), revision: item.revision})).filter(item => item.quantity > 0);
                if (!body.source_order_id && !body.items.length) throw Error('Choose a product or an approved printing quote.');
                const name = el('customer-name').value.trim(), email = el('customer-email').value.trim();
                if (name || email) body.customer = {...(name ? {name} : {}), ...(email ? {email} : {})};
                retryBody = body; save({request: body.request_id});
            }
            show(await api('/handoffs', retryBody)); retryBody = null;
        });
    });
    el('check').addEventListener('click', () => run(check));
    el('cancel').addEventListener('click', () => run(async () => {
        if (!pending) return;
        await api(handoff ? `/handoffs/${handoff.id}/cancel` : `/requests/${pending.request}/cancel`, {}); retryBody = null; await check();
    }));
    el('new').addEventListener('click', () => run(async () => {
        // Server status is checked again before clearing the recovery identifier.
        await check(); if (el('new').hidden) return;
        sessionStorage.removeItem(storageKey()); pending = null; handoff = null; retryBody = null;
        el('handoff').hidden = true; el('receipt').hidden = true; el('print').hidden = true;
        el('customer-name').value = ''; el('customer-email').value = ''; await load();
    }));
    el('print').addEventListener('click', () => window.print());
    window.addEventListener('offline', () => { el('message').textContent = 'You are offline. Reconnect before preparing or checking a sale.'; });
})();
