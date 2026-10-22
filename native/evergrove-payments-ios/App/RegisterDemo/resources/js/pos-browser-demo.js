/* Fictional browser-only register. This file deliberately has no network transport. */
(() => {
    'use strict';
    const STORAGE_KEY = 'everbranch:fictional-pos-demo:v1';
    const money = cents => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(cents / 100);
    function requestId() {
        if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.floor(Math.random() * 16); return (c === 'x' ? r : (r & 3) | 8).toString(16); });
    }
    function sessionStore(host = globalThis) {
        let store = null, available = true;
        try { store = host.sessionStorage; const value = store.getItem(STORAGE_KEY); return { available: true, saved: value,
            save: state => { try { store.setItem(STORAGE_KEY, JSON.stringify(state)); return true; } catch { available = false; return false; } },
            reset: () => { try { store.removeItem(STORAGE_KEY); return true; } catch { return false; } } }; }
        catch { available = false; }
        return { available, saved: null, save: () => false, reset: () => false };
    }
    function mount({ document = globalThis.document, host = globalThis, factory = globalThis.PosBrowserDemoEngine.create, store = sessionStore(host) } = {}) {
        const $ = id => document.getElementById(id);
        let engine, currentAttemptId = null, refundSaleId = null, refundKey = null, currentTab = 'register';
        let storedWarning = !store.available;
        try { engine = factory(store.saved); }
        catch { engine = factory(); storedWarning = true; $('storage-warning').textContent = 'Saved demo activity could not be recovered. You have a fresh fictional session; no business records were changed.'; }
        const node = (tag, className, text) => { const el = document.createElement(tag); if (className) el.className = className; if (text !== undefined) el.textContent = text; return el; };
        const button = (label, fn, className = 'secondary', disabled = false) => { const el = node('button', className, label); el.type = 'button'; el.disabled = disabled; el.addEventListener('click', fn); return el; };
        const announce = (text, error = false, focus = false) => { const el = $('demo-feedback'); el.textContent = text; el.classList.toggle('error', error); if (focus) el.focus(); };
        const persist = () => { if (!store.save(engine.serialize())) storedWarning = true; $('storage-warning').hidden = !storedWarning; };
        const action = (run, success) => { try { const result = run(); persist(); render(); if (success) announce(typeof success === 'function' ? success(result) : success); return result; } catch (error) { announce(error.message || 'This practice action needs review.', true, true); return null; } };
        const active = state => state.attempts.find(attempt => attempt.id === state.activeAttemptId && !['succeeded', 'failed', 'cancelled'].includes(attempt.status));
        const toggleDialog = (id, open) => { const dialog = $(id); if (open) { if (!dialog.open) dialog.showModal(); } else dialog.close(); };
        const totals = state => { let subtotal = 0; for (const line of state.basket) { const product = state.catalog.find(item => item.sku === line.sku); if (product) subtotal += product.priceCents * line.quantity; } const tax = Math.floor((subtotal * state.taxRateBps + 5000) / 10000); return { subtotal, tax, total: subtotal + tax }; };
        const selectTab = (name, focus = false) => { currentTab = name; for (const el of document.querySelectorAll('[data-tab]')) { const chosen = el.dataset.tab === name; el.setAttribute('aria-selected', String(chosen)); el.tabIndex = chosen ? 0 : -1; } for (const el of document.querySelectorAll('[data-view]')) el.hidden = el.dataset.view !== name; if (focus) $('tab-' + name).focus(); };
        const fillCustomers = state => {
            const historyId = $('history-customer').value;
            const fill = (select, includeGuest, selected) => { const options = includeGuest ? [node('option', '', 'Guest checkout')] : []; if (includeGuest) options[0].value = ''; for (const customer of state.customers) { const option = node('option', '', customer.name); option.value = customer.id; options.push(option); } select.replaceChildren(...options); select.value = selected || (includeGuest ? '' : state.customers[0]?.id || ''); };
            fill($('sale-customer'), true, state.selectedCustomerId); fill($('history-customer'), false, state.customers.some(customer => customer.id === historyId) ? historyId : null);
        };
        const productArt = () => { const art = node('span', 'product-art'); art.setAttribute('aria-hidden', 'true'); const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); svg.setAttribute('viewBox', '0 0 24 24'); const path = document.createElementNS('http://www.w3.org/2000/svg', 'path'); path.setAttribute('d', 'm3 7 9-4 9 4v11l-9 4-9-4V7Zm0 0 9 4 9-4M12 11v11M8 5l9 4'); svg.append(path); art.append(svg); return art; };
        function renderProducts(state) {
            const query = $('product-search').value.trim().toLowerCase(), locked = Boolean(active(state));
            const rows = state.catalog.filter(item => `${item.title} ${item.sku}`.toLowerCase().includes(query)).map(item => {
                const card = button('', () => action(() => engine.addSku(item.sku), `Added ${item.title}.`), 'product-card', locked || item.availableQuantity < 1);
                card.setAttribute('aria-label', `Add ${item.title}, ${money(item.priceCents)}, SKU ${item.sku}`);
                const detail = node('div', 'product-bottom'); detail.append(node('span', 'product-price', money(item.priceCents)), node('span', 'stock-label', `${item.availableQuantity} available`));
                card.append(productArt(), node('span', 'add-mark', '+'), node('strong', '', item.title), node('span', 'sku', item.sku), detail); return card;
            });
            $('product-grid').replaceChildren(...rows); $('product-empty').hidden = rows.length > 0; $('catalog-count').textContent = `${state.catalog.length} sample items`;
            $('sku-entry').disabled = locked; $('sku-form').querySelector('button').disabled = locked;
        }
        function renderBasket(state) {
            const locked = Boolean(active(state)), total = totals(state);
            let count = 0;
            const rows = state.basket.filter(line => line.quantity > 0).map(line => {
                count += line.quantity; const item = state.catalog.find(product => product.sku === line.sku);
                const row = node('div', 'basket-line'), title = node('div'); title.append(node('strong', '', item.title), node('small', '', `${item.sku} · ${money(item.priceCents)} each`));
                const controls = node('div', 'quantity-control'); controls.append(button('−', () => action(() => engine.setQuantity(line.sku, line.quantity - 1)), '', locked), node('span', '', String(line.quantity)), button('+', () => action(() => engine.setQuantity(line.sku, line.quantity + 1)), '', locked));
                controls.children[0].setAttribute('aria-label', `Remove one ${item.title}`); controls.children[2].setAttribute('aria-label', `Add one ${item.title}`);
                row.append(title, node('span', 'line-total', money(item.priceCents * line.quantity)), button('Remove', () => action(() => engine.setQuantity(line.sku, 0)), 'remove-line', locked), controls); return row;
            });
            if (!rows.length) { const empty = node('div', 'empty-basket'); empty.append(node('span', '', '⌑'), node('strong', '', 'Your next practice sale starts here.'), node('p', '', 'Tap a sample product or enter its SKU.')); rows.push(empty); }
            $('basket-lines').replaceChildren(...rows); $('basket-count').textContent = count ? `${count} sample item${count === 1 ? '' : 's'}` : 'No items yet';
            $('basket-subtotal').textContent = money(total.subtotal); $('basket-tax').textContent = money(total.tax); $('basket-total').textContent = money(total.total);
            $('tax-label').textContent = `Fictional tax ${(state.taxRateBps / 100).toFixed(2)}%`;
            $('sale-customer').disabled = locked; $('generate-customer').disabled = locked;
            for (const input of document.querySelectorAll('input[name="demo_tender"]')) input.disabled = locked;
            const cash = document.querySelector('input[name="demo_tender"]:checked')?.value === 'cash'; $('cash-fields').hidden = !cash;
            $('cash-received').disabled = locked || !cash; $('cash-received').required = cash;
            const received = globalThis.PosRegisterUI.parseCashCents($('cash-received').value);
            $('cash-change').textContent = received === null ? 'Enter the practice amount. Change is calculated from the frozen total.' : received < total.total ? `Practice cash is short by ${money(total.total - received)}.` : `Estimated change: ${money(received - total.total)}.`;
            $('checkout-button').disabled = locked || count === 0;
            $('checkout-button').textContent = locked ? 'Resolve the existing sale below' : cash ? 'Record cash rehearsal →' : 'Prepare card rehearsal →';
        }
        function receipt(saleId) {
            const record = engine.receipt(saleId); const article = $('demo-receipt');
            const header = node('header', 'receipt-header'); header.append(node('div', 'eyebrow', 'Fictional demo receipt'), node('h2', '', 'Alpha Printing'), node('p', '', `${record.number} · Browser-only practice register`), node('p', '', `Sample customer: ${record.customerName}`), node('strong', '', 'SIMULATION — NO MONEY MOVED'));
            const table = node('table', 'receipt-lines'), head = node('tr'); for (const label of ['Sample item', 'Qty', 'USD']) head.append(node('th', '', label)); table.append(head);
            for (const line of record.lines) { const row = node('tr'); row.append(node('td', '', line.title), node('td', '', String(line.quantity)), node('td', '', money(line.lineTotalCents))); table.append(row); }
            const summary = node('dl', 'totals'); const add = (label, value, className = '') => { const row = node('div', className); row.append(node('dt', '', label), node('dd', '', value)); summary.append(row); };
            add('Subtotal', money(record.subtotalCents)); add('Fictional tax', money(record.taxCents)); add('Confirmed demo total', money(record.totalCents), 'grand-total'); add('Tender', record.tender === 'cash' ? 'Cash rehearsal' : 'Card rehearsal');
            if (record.tender === 'cash') { add('Practice cash received', money(record.cashReceivedCents)); add('Practice change', money(record.changeCents)); }
            if (record.refundedCents) { add('Confirmed demo refunds', money(record.refundedCents)); add('Demo net', money(record.netCents)); }
            if (record.pendingRefundCents) add('Pending demo refunds', money(record.pendingRefundCents));
            const footer = node('footer', 'receipt-footer', 'Fictional products, people, tax and stock. No card charge, real cash movement or merchant transaction occurred. Browser printing does not verify a physical receipt printer.');
            article.replaceChildren(header, table, summary, footer); $('receipt-print').disabled = false; toggleDialog('receipt-dialog', true); return record;
        }
        function openRefund(saleId) {
            try { if (engine.snapshot().operatorRole !== 'manager') throw new Error('Choose Manager simulation above to rehearse a refund. No real authorization is granted.'); const sale = engine.receipt(saleId); refundSaleId = saleId; refundKey = requestId(); $('refund-amount').value = ((sale.totalCents - sale.refundedCents - sale.pendingRefundCents) / 100).toFixed(2); $('refund-remaining').textContent = `Available for demo refund: ${money(sale.totalCents - sale.refundedCents - sale.pendingRefundCents)}. Original ${sale.tender} rehearsal.`; $('refund-error').textContent = ''; toggleDialog('refund-dialog', true); $('refund-amount').focus(); } catch (error) { announce(error.message, true, true); }
        }
        function saleActions(record, state) {
            const actions = node('div', 'actions');
            if (record.status === 'succeeded') { const confirmed = engine.receipt(record.id); actions.append(button('View / print demo receipt', () => { try { receipt(record.id); } catch (error) { announce(error.message, true); } }, 'primary'));
                if (confirmed.totalCents > confirmed.refundedCents + confirmed.pendingRefundCents) actions.append(button('Rehearse partial refund', () => openRefund(record.id), 'secondary', state.operatorRole !== 'manager'));
                for (const refund of record.refunds || []) if (['unknown', 'pending'].includes(refund.status)) actions.append(button('Reconcile original demo refund', () => action(() => engine.reconcileRefund(refund.id), 'Original demo refund reconciled.')));
            } else if (['awaiting_reader', 'processing', 'unknown', 'creating'].includes(record.status)) {
                if (record.status === 'awaiting_reader') for (const [outcome, label] of [['approve', 'Approve demo card'], ['decline', 'Decline demo card'], ['unknown', 'Simulate lost response']]) actions.append(button(label, () => action(() => engine.simulatePayment(record.id, outcome), outcome === 'unknown' ? 'Outcome uncertain. Keep this sale and reconcile its original result.' : `Demo card ${outcome === 'approve' ? 'approved' : 'declined'}.`), outcome === 'approve' ? 'primary' : 'secondary'));
                actions.append(button('Reconcile original sale', () => action(() => engine.reconcilePayment(record.id), 'Original demo result checked.')), button('Cancel / check original sale', () => action(() => engine.cancelPayment(record.id), 'Original demo sale checked. A saved approval is preserved.')));
            }
            return actions;
        }
        function renderActivity(state) {
            const record = active(state) || state.attempts.find(attempt => attempt.id === currentAttemptId) || state.attempts.at(-1);
            $('activity-count').textContent = state.attempts.length ? `${state.attempts.length} practice sale${state.attempts.length === 1 ? '' : 's'}` : 'No practice sales';
            $('demo-role').value = state.operatorRole;
            if (!record) $('payment-activity').replaceChildren(node('div', 'activity-empty', 'Prepare a basket above. Reader outcomes and the confirmed receipt will appear here.'));
            else { const card = node('article', 'payment-card'), title = node('div', 'payment-title'), info = node('div'); info.append(node('strong', '', `${record.id} · ${record.tender === 'cash' ? 'Cash rehearsal' : 'Card rehearsal'}`), node('p', '', `Sample customer: ${record.customerName}`));
                const labels = { awaiting_reader: 'Awaiting demo reader', unknown: 'Needs confirmation', succeeded: 'Demo sale confirmed', failed: 'Demo declined', cancelled: 'Demo cancelled', processing: 'Checking demo result' };
                title.append(info, node('span', `status ${record.status === 'succeeded' ? 'success' : ['failed', 'cancelled'].includes(record.status) ? 'failed' : 'warning'}`, labels[record.status] || record.status));
                const copy = record.status === 'unknown' ? 'The demo simulates a lost approval response. Stock is held and a replacement sale is blocked. Reconcile the original sale to recover its saved outcome.' : record.status === 'succeeded' ? 'Confirmed in this browser demo. Stock changed once. No real money moved.' : record.status === 'awaiting_reader' ? 'No card details are collected. Choose a fictional reader outcome below.' : 'This practice sale did not complete. Its stock reservation is released.';
                card.append(title, node('div', 'payment-total', money(record.totalCents)), node('p', 'payment-copy', copy));
                if (record.tender === 'cash') card.append(node('p', 'payment-copy', `Practice cash received ${money(record.cashReceivedCents)} · Change ${money(record.changeCents)}`));
                card.append(saleActions(record, state)); $('payment-activity').replaceChildren(card);
            }
            const previous = state.attempts.filter(attempt => attempt.id !== record?.id).slice().reverse();
            $('recent-sales').replaceChildren(...previous.map(sale => { const row = node('div', 'recent-row'), info = node('div'); info.append(node('strong', '', `${sale.id} · ${money(sale.totalCents)}`), node('p', '', `${sale.customerName} · ${sale.status}`)); row.append(info, button('Open', () => { currentAttemptId = sale.id; render(); $('payment-activity').scrollIntoView({ block: 'nearest', behavior: 'smooth' }); })); return row; }));
            if (!previous.length) $('recent-sales').append(node('p', '', 'No earlier practice sales in this tab.'));
        }
        function renderHistory(state) {
            const id = $('history-customer').value, container = $('customer-history');
            if (!id) { container.replaceChildren(node('p', 'history-empty', 'Choose or generate a fictional customer to see their demo history.')); return; }
            const history = engine.history(id), summary = node('div', 'history-summary');
            for (const [label, amount] of [['Confirmed demo gross', history.grossCents], ['Demo refunds', history.confirmedRefundsCents], ['Confirmed demo net', history.netCents]]) { const metric = node('div', 'metric'); metric.append(node('span', '', label), node('strong', '', money(amount))); summary.append(metric); }
            container.replaceChildren(summary, node('p', 'helper', `${history.paidSaleCount} confirmed identified practice sale(s). Guest and unconfirmed sales are excluded. Pending refunds: ${money(history.pendingRefundsCents)} (not deducted).`));
            if (!history.sales.length) container.append(node('p', 'history-empty', 'No confirmed identified demo sales yet. Select this sample customer before checkout.'));
            for (const sale of history.sales) { const record = state.attempts.find(attempt => attempt.id === sale.id); if (!record) continue; const row = node('article', 'payment-card'); row.append(node('h3', '', `${record.id} · ${money(record.totalCents)}`), node('p', 'payment-copy', `${record.tender === 'cash' ? 'Cash' : 'Card'} rehearsal · ${record.customerName}`), saleActions(record, state)); container.append(row); }
        }
        function render() { const state = engine.snapshot(); fillCustomers(state); renderProducts(state); renderBasket(state); renderActivity(state); renderHistory(state);
            $('inventory-list').replaceChildren(...state.catalog.map(item => { const row = node('div', 'inventory-row'), info = node('div'); info.append(node('strong', '', item.title), node('small', '', item.sku)); row.append(info, node('span', '', money(item.priceCents)), node('span', '', `${item.availableQuantity} available / ${item.stock} on hand`)); return row; })); $('storage-warning').hidden = !storedWarning; }
        for (const tab of document.querySelectorAll('[data-tab]')) { tab.addEventListener('click', () => selectTab(tab.dataset.tab)); tab.addEventListener('keydown', event => { if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) { event.preventDefault(); const names = ['register', 'history', 'inventory', 'setup']; const index = names.indexOf(currentTab); selectTab(event.key === 'Home' ? names[0] : event.key === 'End' ? names.at(-1) : names[(index + (event.key === 'ArrowRight' ? 1 : -1) + names.length) % names.length], true); } }); }
        $('product-search').addEventListener('input', () => renderProducts(engine.snapshot()));
        $('sku-form').addEventListener('submit', event => { event.preventDefault(); const code = $('sku-entry').value.trim(); if (!code) { announce('Enter an exact sample SKU first.', true); return; } if (action(() => engine.addSku(code), `Added sample SKU ${code}.`)) $('sku-entry').value = ''; });
        $('sale-customer').addEventListener('change', () => action(() => engine.selectCustomer($('sale-customer').value || null), 'Sample customer choice updated.'));
        $('generate-customer').addEventListener('click', () => action(() => { const customer = engine.generateFictionalCustomer(); engine.selectCustomer(customer.id); return customer; }, customer => `${customer.name} generated and selected. No real contact details were collected.`));
        $('history-customer').addEventListener('change', () => renderHistory(engine.snapshot()));
        $('demo-role').addEventListener('change', () => action(() => engine.setOperatorRole($('demo-role').value), 'Simulation role changed. This grants no real staff authorization.'));
        for (const input of document.querySelectorAll('input[name="demo_tender"]')) input.addEventListener('change', () => renderBasket(engine.snapshot()));
        $('cash-received').addEventListener('input', () => renderBasket(engine.snapshot()));
        $('checkout-form').addEventListener('submit', event => { event.preventDefault(); const tender = document.querySelector('input[name="demo_tender"]:checked')?.value || 'card'; const input = { requestId: requestId(), tender };
            if (tender === 'cash') { const cents = globalThis.PosRegisterUI.parseCashCents($('cash-received').value); if (cents === null) { announce('Enter a valid practice cash amount with up to two decimal places.', true, true); $('cash-received').focus(); return; } input.cashReceivedCents = cents; }
            action(() => { const record = engine.startPayment(input); currentAttemptId = record.id; return record; }, record => record.status === 'succeeded' ? 'Cash rehearsal confirmed. Its demo receipt is ready.' : 'Card rehearsal prepared. Choose a demo reader outcome below.'); $('payment-activity').scrollIntoView({ block: 'nearest', behavior: 'smooth' }); });
        $('receipt-close').addEventListener('click', () => toggleDialog('receipt-dialog', false)); $('receipt-print').addEventListener('click', () => { if ($('receipt-dialog').open) host.print(); });
        $('refund-close').addEventListener('click', () => toggleDialog('refund-dialog', false)); $('refund-form').addEventListener('submit', event => { event.preventDefault(); const amount = globalThis.PosRegisterUI.parseCashCents($('refund-amount').value); if (amount === null || amount < 1) { $('refund-error').textContent = 'Enter a positive demo refund amount with up to two decimal places.'; return; } try { engine.refund({ requestId: refundKey, saleId: refundSaleId, amountCents: amount }); persist(); render(); toggleDialog('refund-dialog', false); announce('Demo refund confirmed against the original sale. No real money returned or stock restocked.'); } catch (error) { $('refund-error').textContent = error.message; } });
        $('reset-open').addEventListener('click', () => toggleDialog('reset-dialog', true)); $('reset-close').addEventListener('click', () => toggleDialog('reset-dialog', false));
        $('reset-confirm').addEventListener('click', () => { engine.reset(); store.reset(); currentAttemptId = refundSaleId = refundKey = null; $('product-search').value = ''; $('sku-entry').value = ''; $('cash-received').value = ''; for (const input of document.querySelectorAll('input[name="demo_tender"]')) input.checked = input.value === 'card'; toggleDialog('reset-dialog', false); selectTab('register'); render(); announce('Only this fictional demo was reset. No other browser or business data changed.', false, true); });
        render(); return { engine, render, receipt, selectTab };
    }
    globalThis.PosBrowserDemoUI = { mount, sessionStore, money, requestId, STORAGE_KEY };
    if (globalThis.document?.getElementById('view-register')) { try { mount(); } catch { const feedback = globalThis.document.getElementById('demo-feedback'); feedback.textContent = 'The fictional demo could not start. Reload to try again; no business data or money was changed.'; feedback.classList.add('error'); } }
})();
