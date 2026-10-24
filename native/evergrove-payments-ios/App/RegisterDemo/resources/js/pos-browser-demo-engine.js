/* Fictional browser-only rehearsal. No provider, network, database or real-money authority. */
(() => {
    'use strict';
    const VERSION = 1;
    const DEMO = 'alpha-printing-fixture-v1';
    const LABEL = 'Fictional browser demo · no real payment or inventory';
    const TAX_RATE_BPS = 825;
    const MAX_EVENTS = 500;
    const PRODUCTS = [
        { variantId: 1, sku: 'ALPHA-BC-250', title: 'Practice business cards · 250 pack', priceCents: 3500, stock: 20 },
        { variantId: 2, sku: 'ALPHA-FLYER-100', title: 'Practice flyers · 100 pack', priceCents: 4500, stock: 15 },
        { variantId: 3, sku: 'ALPHA-POSTER-A3', title: 'Practice A3 poster', priceCents: 1800, stock: 30 },
        { variantId: 4, sku: 'ALPHA-SHIRT', title: 'Practice printed shirt', priceCents: 2500, stock: 12 },
    ];
    const SEED_CUSTOMERS = [
        { id: 'demo-customer-1', name: 'Jordan Sample', email: 'jordan.sample@example.test', phone: '202-555-0101' },
        { id: 'demo-customer-2', name: 'Taylor Example', email: 'taylor.example@example.test', phone: '202-555-0102' },
    ];
    const clone = value => JSON.parse(JSON.stringify(value));
    function fail(code, message) { const error = new Error(message); error.code = code; throw error; }
    function requireValue(condition, code, message) { if (!condition) fail(code, message); }
    function keys(value, expected) {
        requireValue(value && typeof value === 'object' && !Array.isArray(value)
            && Object.keys(value).length === expected.length && expected.every(key => Object.hasOwn(value, key)),
        'INVALID_STATE', 'The fictional demo data needs a reset.');
    }
    function requestId(value) {
        requireValue(typeof value === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(value),
            'INVALID_REQUEST', 'Start a new fictional request with a valid request ID.');
        return value.toLowerCase();
    }
    function cents(value, allowZero = false) {
        requireValue(Number.isSafeInteger(value) && value >= (allowZero ? 0 : 1) && value <= 100000000,
            'INVALID_AMOUNT', 'Enter a supported whole number of cents for the fictional amount.');
        return value;
    }
    function parseCashCents(value) {
        if (typeof value !== 'string' || !/^(0|[1-9]\d{0,6})(\.\d{1,2})?$/.test(value.trim())) return null;
        const [whole, fraction = ''] = value.trim().split('.');
        const result = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
        return Number.isSafeInteger(result) && result <= 100000000 ? result : null;
    }
    function initialState() {
        return { basket: {}, selectedCustomerId: null, operatorRole: 'cashier', generated: 0,
            customers: clone(SEED_CUSTOMERS), stock: Object.fromEntries(PRODUCTS.map(item => [item.sku, item.stock])), attempts: [], refunds: [] };
    }
    function validateEvent(event) {
        const shapes = { quantity: ['type', 'sku', 'quantity'], customer: ['type', 'customerId'], generate_customer: ['type'], role: ['type', 'role'],
            start: ['type', 'requestId', 'tender', 'cashReceivedCents'], payment_outcome: ['type', 'attemptId', 'outcome'],
            reconcile: ['type', 'attemptId'], cancel: ['type', 'attemptId'], refund: ['type', 'requestId', 'saleId', 'amountCents', 'outcome'],
            refund_reconcile: ['type', 'refundId'] };
        requireValue(event && typeof event.type === 'string' && Object.hasOwn(shapes, event.type), 'INVALID_STATE', 'The fictional demo data needs a reset.');
        keys(event, shapes[event.type]);
        for (const value of Object.values(event)) {
            requireValue(value === null || (typeof value === 'string' && value.length <= 120)
                || (typeof value === 'number' && Number.isSafeInteger(value) && value >= 0 && value <= 100000000),
            'INVALID_STATE', 'The fictional demo data needs a reset.');
        }
    }

    function create(saved = null) {
        let state = initialState();
        let journal = [];
        const product = sku => {
            const item = PRODUCTS.find(candidate => candidate.sku === sku);
            requireValue(typeof sku === 'string' && item, 'SKU_NOT_FOUND', 'Scan or enter an exact fictional catalog SKU.');
            return item;
        };
        const customer = id => {
            const item = state.customers.find(candidate => candidate.id === id);
            requireValue(item, 'CUSTOMER_NOT_FOUND', 'Select one of the fictional sample customers.');
            return item;
        };
        const attempt = id => {
            const item = state.attempts.find(candidate => candidate.id === id);
            requireValue(item, 'SALE_NOT_FOUND', 'This fictional payment is unavailable in this demo session.');
            return item;
        };
        const refundRecord = id => {
            const item = state.refunds.find(candidate => candidate.id === id);
            requireValue(item, 'REFUND_NOT_FOUND', 'This fictional refund is unavailable in this demo session.');
            return item;
        };
        const openAttempt = () => state.attempts.find(item => ['awaiting_reader', 'unknown'].includes(item.status)) || null;
        const editable = () => requireValue(!openAttempt(), 'PAYMENT_PENDING', 'Reconcile or cancel the original simulated payment before changing the basket or starting another sale.');
        const lines = () => PRODUCTS.filter(item => state.basket[item.sku] > 0).map(item => ({
            sku: item.sku, title: item.title, quantity: state.basket[item.sku], unitPriceCents: item.priceCents,
            lineTotalCents: item.priceCents * state.basket[item.sku],
        }));
        const totals = values => {
            const subtotalCents = values.reduce((sum, item) => sum + item.lineTotalCents, 0);
            const taxCents = Math.floor((subtotalCents * TAX_RATE_BPS + 5000) / 10000);
            return { subtotalCents, taxCents, totalCents: subtotalCents + taxCents };
        };
        const available = sku => {
            const active = openAttempt();
            const held = active?.lines.find(item => item.sku === sku)?.quantity || 0;
            return state.stock[sku] - held;
        };
        const refundsFor = saleId => state.refunds.filter(item => item.saleId === saleId);
        const refundTotals = saleId => {
            const values = refundsFor(saleId);
            return { refundedCents: values.filter(item => item.status === 'succeeded').reduce((sum, item) => sum + item.amountCents, 0),
                pendingRefundCents: values.filter(item => item.status === 'unknown').reduce((sum, item) => sum + item.amountCents, 0) };
        };
        const publicRefund = item => ({ id: item.id, requestId: item.requestId, saleId: item.saleId, paymentId: item.paymentId,
            providerKey: item.providerKey, merchantId: item.merchantId, environment: item.environment,
            amountCents: item.amountCents, currency: item.currency, status: item.status, simulation: true, authenticated: false });
        const publicAttempt = item => {
            const summary = refundTotals(item.id);
            const { canonicalStatus, terms, ...safe } = item;
            return clone({ ...safe, ...summary, netCents: item.status === 'succeeded' ? item.totalCents - summary.refundedCents : 0,
                refunds: refundsFor(item.id).map(publicRefund), simulation: true, authenticated: false, label: LABEL });
        };
        const complete = item => {
            if (item.status === 'succeeded') return;
            requireValue(item.canonicalStatus === 'succeeded', 'PAYMENT_UNCONFIRMED', 'The fictional provider has not confirmed this payment.');
            requireValue(item.lines.every(line => state.stock[line.sku] >= line.quantity), 'STOCK_CONFLICT', 'Fictional stock needs review before completion.');
            for (const line of item.lines) state.stock[line.sku] -= line.quantity;
            item.status = 'succeeded';
            item.number = `DEMO-${String(state.attempts.indexOf(item) + 1).padStart(4, '0')}`;
            state.basket = {};
            state.selectedCustomerId = null;
        };
        const termsFor = (tender, received) => ({ lines: lines().map(item => ({ sku: item.sku, quantity: item.quantity })),
            customerId: state.selectedCustomerId, tender, cashReceivedCents: received });

        function apply(event) {
            requireValue(event && typeof event.type === 'string', 'INVALID_STATE', 'The fictional demo data needs a reset.');
            if (event.type === 'quantity') {
                keys(event, ['type', 'sku', 'quantity']); product(event.sku); editable();
                requireValue(Number.isSafeInteger(event.quantity) && event.quantity >= 0 && event.quantity <= 99
                    && event.quantity <= available(event.sku), 'STOCK_LIMIT', 'Choose a whole quantity within the fictional available stock.');
                if ((state.basket[event.sku] || 0) === event.quantity) return { value: event.quantity, changed: false };
                if (event.quantity) state.basket[event.sku] = event.quantity;
                else delete state.basket[event.sku];
                return { value: event.quantity, changed: true };
            }
            if (event.type === 'customer') {
                keys(event, ['type', 'customerId']); editable();
                if (event.customerId !== null) customer(event.customerId);
                const changed = event.customerId !== state.selectedCustomerId; state.selectedCustomerId = event.customerId;
                return { value: event.customerId === null ? null : clone(customer(event.customerId)), changed };
            }
            if (event.type === 'generate_customer') {
                keys(event, ['type']); editable();
                requireValue(state.generated < 18, 'CUSTOMER_LIMIT', 'Reset the demo before adding more fictional sample customers.');
                const index = 3 + state.generated++;
                const item = { id: `demo-customer-${index}`, name: `Sample Customer ${index}`,
                    email: `practice.customer${index}@example.test`, phone: `202-555-${String(100 + index).padStart(4, '0')}` };
                state.customers.push(item);
                return { value: clone(item), changed: true };
            }
            if (event.type === 'role') {
                keys(event, ['type', 'role']);
                requireValue(['cashier', 'manager'].includes(event.role), 'INVALID_ROLE', 'Choose Cashier or Manager simulation.');
                const changed = state.operatorRole !== event.role; state.operatorRole = event.role;
                return { value: event.role, changed };
            }
            if (event.type === 'start') {
                keys(event, ['type', 'requestId', 'tender', 'cashReceivedCents']);
                const key = requestId(event.requestId);
                requireValue(['card', 'cash'].includes(event.tender), 'INVALID_TENDER', 'Choose simulated card or cash.');
                if (event.tender === 'cash') cents(event.cashReceivedCents, true);
                else requireValue(event.cashReceivedCents === null, 'INVALID_TENDER', 'Simulated card payments cannot include cash received.');
                const existing = state.attempts.find(item => item.requestId === key);
                if (existing) {
                    const clearedAfterSuccess = existing.status === 'succeeded' && lines().length === 0 && state.selectedCustomerId === null;
                    requireValue(existing.tender === event.tender && existing.cashReceivedCents === event.cashReceivedCents
                        && (clearedAfterSuccess || JSON.stringify(existing.terms) === JSON.stringify(termsFor(event.tender, event.cashReceivedCents))),
                    'REQUEST_CHANGED', 'This request already belongs to different frozen fictional sale terms. Start a new request.');
                    return { value: publicAttempt(existing), changed: false };
                }
                editable();
                const frozenLines = lines();
                requireValue(frozenLines.length > 0, 'EMPTY_BASKET', 'Add fictional merchandise before preparing a sale.');
                requireValue(frozenLines.every(line => line.quantity <= state.stock[line.sku]), 'STOCK_LIMIT', 'Review the fictional available stock.');
                const amount = totals(frozenLines);
                requireValue(event.tender !== 'cash' || event.cashReceivedCents >= amount.totalCents,
                    'CASH_SHORT', 'Simulated cash received must cover the frozen sale total.');
                const id = `demo-payment-${state.attempts.length + 1}`;
                const selected = state.selectedCustomerId === null ? null : clone(customer(state.selectedCustomerId));
                const item = { id, requestId: key, providerId: `demo_provider_${id}`, providerKey: 'fictional_browser_simulator_v1',
                    merchantId: 'demo-merchant-alpha', locationId: 'demo-location-alpha', readerId: 'demo-reader-alpha', environment: 'fictional_browser',
                    currency: 'usd', taxRateBps: TAX_RATE_BPS, taxBasis: 'Fictional 8.25% demo rate; no tax advice',
                    status: 'awaiting_reader', canonicalStatus: event.tender === 'cash' ? 'succeeded' : 'requires_action',
                    lines: clone(frozenLines), ...amount, customerId: state.selectedCustomerId, customerName: selected?.name || 'Guest', customerSnapshot: selected,
                    tender: event.tender, cashReceivedCents: event.cashReceivedCents,
                    changeCents: event.tender === 'cash' ? event.cashReceivedCents - amount.totalCents : null,
                    terms: termsFor(event.tender, event.cashReceivedCents), number: null };
                state.attempts.push(item);
                if (event.tender === 'cash') complete(item);
                return { value: publicAttempt(item), changed: true };
            }
            if (event.type === 'payment_outcome') {
                keys(event, ['type', 'attemptId', 'outcome']);
                requireValue(['approve', 'decline', 'unknown'].includes(event.outcome), 'INVALID_OUTCOME', 'Choose a supported fictional card outcome.');
                const item = attempt(event.attemptId);
                requireValue(item.tender === 'card', 'INVALID_TENDER', 'Cash completion does not use a simulated card reader.');
                if (item.status === 'succeeded' && event.outcome === 'approve') return { value: publicAttempt(item), changed: false };
                if (item.status === 'failed' && event.outcome === 'decline') return { value: publicAttempt(item), changed: false };
                if (item.status === 'unknown' && event.outcome === 'unknown') return { value: publicAttempt(item), changed: false };
                requireValue(item.status === 'awaiting_reader', 'RECONCILE_REQUIRED', 'This fictional payment already has an outcome. Reconcile the original request.');
                if (event.outcome === 'decline') { item.canonicalStatus = 'failed'; item.status = 'failed'; }
                else {
                    item.canonicalStatus = 'succeeded';
                    if (event.outcome === 'unknown') item.status = 'unknown';
                    else complete(item);
                }
                return { value: publicAttempt(item), changed: true };
            }
            if (event.type === 'reconcile' || event.type === 'cancel') {
                keys(event, ['type', 'attemptId']);
                const item = attempt(event.attemptId);
                if (['succeeded', 'failed', 'cancelled'].includes(item.status)) return { value: publicAttempt(item), changed: false };
                if (item.canonicalStatus === 'succeeded') complete(item);
                else if (event.type === 'cancel') { item.status = 'cancelled'; item.canonicalStatus = 'cancelled'; }
                else return { value: publicAttempt(item), changed: false };
                return { value: publicAttempt(item), changed: true };
            }
            if (event.type === 'refund') {
                keys(event, ['type', 'requestId', 'saleId', 'amountCents', 'outcome']);
                requireValue(state.operatorRole === 'manager', 'MANAGER_REQUIRED', 'Switch explicitly to Manager simulation for a fictional refund.');
                const key = requestId(event.requestId); cents(event.amountCents);
                requireValue(['succeeded', 'unknown'].includes(event.outcome), 'INVALID_OUTCOME', 'Choose a supported fictional refund outcome.');
                const sale = attempt(event.saleId);
                requireValue(sale.status === 'succeeded' && sale.canonicalStatus === 'succeeded', 'SALE_UNPAID', 'Only a confirmed fictional sale can be refunded.');
                const existing = state.refunds.find(item => item.requestId === key);
                if (existing) {
                    requireValue(existing.saleId === sale.id && existing.amountCents === event.amountCents && existing.originalOutcome === event.outcome,
                        'REQUEST_CHANGED', 'This refund request already has different frozen fictional terms.');
                    return { value: publicRefund(existing), changed: false };
                }
                const reserved = refundTotals(sale.id);
                requireValue(reserved.refundedCents + reserved.pendingRefundCents + event.amountCents <= sale.totalCents,
                    'REFUND_LIMIT', 'This exceeds the remaining fictional amount, including unresolved refunds.');
                const item = { id: `demo-refund-${state.refunds.length + 1}`, requestId: key, saleId: sale.id, paymentId: sale.providerId,
                    providerKey: sale.providerKey, merchantId: sale.merchantId, environment: sale.environment, currency: sale.currency,
                    amountCents: event.amountCents, status: event.outcome, originalOutcome: event.outcome, canonicalStatus: 'succeeded' };
                state.refunds.push(item);
                return { value: publicRefund(item), changed: true };
            }
            if (event.type === 'refund_reconcile') {
                keys(event, ['type', 'refundId']);
                requireValue(state.operatorRole === 'manager', 'MANAGER_REQUIRED', 'Switch explicitly to Manager simulation to reconcile this fictional refund.');
                const item = refundRecord(event.refundId), sale = attempt(item.saleId);
                requireValue(sale.status === 'succeeded' && item.paymentId === sale.providerId && item.providerKey === sale.providerKey
                    && item.merchantId === sale.merchantId && item.currency === sale.currency && item.environment === 'fictional_browser',
                'REFUND_BINDING', 'Review the fictional refund and its original sale.');
                if (item.status === 'succeeded') return { value: publicRefund(item), changed: false };
                item.status = 'succeeded';
                return { value: publicRefund(item), changed: true };
            }
            fail('INVALID_STATE', 'The fictional demo data needs a reset.');
        }

        function dispatch(event) {
            requireValue(journal.length < MAX_EVENTS, 'DEMO_LIMIT', 'This fictional demo session is full. Reset it before continuing.');
            validateEvent(event);
            const result = apply(event);
            if (result.changed) journal.push(clone(event));
            return result.value;
        }
        function snapshot() {
            return clone({ simulation: true, authenticated: false, label: LABEL, currency: 'usd', taxRateBps: TAX_RATE_BPS,
                operatorRole: state.operatorRole, selectedCustomerId: state.selectedCustomerId, activeAttemptId: openAttempt()?.id || null,
                catalog: PRODUCTS.map(item => ({ ...item, stock: state.stock[item.sku], availableQuantity: available(item.sku) })),
                customers: state.customers, basket: Object.entries(state.basket).map(([sku, quantity]) => ({ sku, quantity })),
                totals: totals(lines()), attempts: state.attempts.map(publicAttempt), sales: state.attempts.filter(item => item.status === 'succeeded').map(publicAttempt) });
        }
        function restore(value) {
            try {
                if (typeof value === 'string') requireValue(value.length <= 250000, 'INVALID_STATE', 'The fictional demo data needs a reset.');
                const envelope = typeof value === 'string' ? JSON.parse(value) : value;
                keys(envelope, ['version', 'demo', 'journal']);
                requireValue(envelope.version === VERSION && envelope.demo === DEMO && Array.isArray(envelope.journal)
                    && envelope.journal.length <= MAX_EVENTS, 'INVALID_STATE', 'The fictional demo data needs a reset.');
                // Inspect the entire bounded primitive command journal before
                // allocating/replaying any state. Stored contacts/prices/totals,
                // nested objects, prototype keys and authority flags are invalid.
                for (const event of envelope.journal) validateEvent(event);
                for (const event of envelope.journal) { apply(event); journal.push(clone(event)); }
            } catch (_) {
                fail('INVALID_STATE', 'Saved fictional demo data is invalid or incompatible. Reset this browser demo; no real transaction was affected.');
            }
        }
        if (saved !== null && saved !== undefined) restore(saved);

        return {
            snapshot,
            catalog: () => snapshot().catalog,
            customers: () => clone(state.customers),
            lookupSku: sku => { const item = product(sku); return clone({ ...item, stock: state.stock[sku], availableQuantity: available(sku) }); },
            addSku: sku => dispatch({ type: 'quantity', sku, quantity: (state.basket[product(sku).sku] || 0) + 1 }),
            setQuantity: (sku, quantity) => dispatch({ type: 'quantity', sku, quantity }),
            selectCustomer: customerId => dispatch({ type: 'customer', customerId }),
            generateFictionalCustomer: () => dispatch({ type: 'generate_customer' }),
            setOperatorRole: role => dispatch({ type: 'role', role }),
            startPayment: ({ requestId: key, tender = 'card', cashReceivedCents = null }) => dispatch({ type: 'start', requestId: key, tender, cashReceivedCents }),
            simulatePayment: (attemptId, outcome) => dispatch({ type: 'payment_outcome', attemptId, outcome }),
            reconcilePayment: attemptId => dispatch({ type: 'reconcile', attemptId }),
            cancelPayment: attemptId => dispatch({ type: 'cancel', attemptId }),
            refund: ({ requestId: key, saleId, amountCents, outcome = 'succeeded' }) => dispatch({ type: 'refund', requestId: key, saleId, amountCents, outcome }),
            reconcileRefund: refundId => dispatch({ type: 'refund_reconcile', refundId }),
            receipt: saleId => {
                const item = attempt(saleId);
                requireValue(item.status === 'succeeded', 'SALE_UNPAID', 'A receipt is available only after fictional payment confirmation.');
                return publicAttempt(item);
            },
            history: customerId => {
                const selected = customer(customerId);
                const sales = state.attempts.filter(item => item.status === 'succeeded' && item.customerId === selected.id).map(publicAttempt);
                return clone({ simulation: true, authenticated: false, label: LABEL, customer: selected, sales,
                    grossCents: sales.reduce((sum, item) => sum + item.totalCents, 0), confirmedRefundsCents: sales.reduce((sum, item) => sum + item.refundedCents, 0),
                    pendingRefundsCents: sales.reduce((sum, item) => sum + item.pendingRefundCents, 0), netCents: sales.reduce((sum, item) => sum + item.netCents, 0), paidSaleCount: sales.length });
            },
            serialize: () => clone({ version: VERSION, demo: DEMO, journal }),
            reset: () => { state = initialState(); journal = []; return snapshot(); },
        };
    }
    globalThis.PosBrowserDemoEngine = Object.freeze({ create, parseCashCents, version: VERSION,
        storageKey: 'everbranch:fictional-pos-demo:v1', label: LABEL,
        capabilities: Object.freeze({ simulation: true, authenticated: false, externalPayments: false, physicalReaders: false, offlineCardAcceptance: false }) });
})();
