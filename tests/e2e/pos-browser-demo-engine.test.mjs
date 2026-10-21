import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = vm.createContext({});
vm.runInContext(readFileSync(new URL('../../resources/js/pos-browser-demo-engine.js', import.meta.url), 'utf8'), context);
const Demo = context.PosBrowserDemoEngine;
const plain = value => JSON.parse(JSON.stringify(value));
const key = index => `00000000-0000-4000-8000-${String(index).padStart(12, '0')}`;
const errorCode = code => error => error.code === code;
function paid(engine, index = 1, customerId = null) {
    engine.setQuantity('ALPHA-BC-250', 1);
    engine.selectCustomer(customerId);
    const started = engine.startPayment({ requestId: key(index), tender: 'card' });
    return engine.simulatePayment(started.id, 'approve');
}

test('the fixture has fixed catalog/customer identities and declares no external authenticity or payment capability', () => {
    const engine = Demo.create(), state = engine.snapshot();
    assert.equal(Demo.storageKey, 'everbranch:fictional-pos-demo:v1');
    assert.equal(Demo.capabilities.externalPayments, false);
    assert.equal(Demo.capabilities.physicalReaders, false);
    assert.equal(Demo.capabilities.offlineCardAcceptance, false);
    assert.equal(state.authenticated, false);
    assert.equal(state.simulation, true);
    assert.match(state.label, /Fictional browser demo/);
    assert.equal(state.selectedCustomerId, null);
    assert.equal(state.catalog.length, 4);
    assert.equal(state.customers.length, 2);
    assert.ok(state.customers.every(customer => customer.email.endsWith('@example.test')));
    state.catalog[0].priceCents = 1;
    state.customers[0].name = 'Tampered contact';
    assert.equal(engine.snapshot().catalog[0].priceCents, 3500);
    assert.equal(engine.customers()[0].name, 'Jordan Sample');
});

test('cash parsing uses exact bounded cents without scientific, negative or fractional-cent inputs', () => {
    assert.equal(Demo.parseCashCents('30.01'), 3001);
    assert.equal(Demo.parseCashCents('0.1'), 10);
    assert.equal(Demo.parseCashCents('1000000.00'), 100000000);
    for (const value of ['', '1e3', '-1.00', '2.345', '1,000.00', '1000000.01', {}, null]) assert.equal(Demo.parseCashCents(value), null);
});

test('exact SKU and stock validation cannot mutate prices or accept fractions, unknown IDs or prototype keys', () => {
    const engine = Demo.create();
    assert.equal(engine.lookupSku('ALPHA-POSTER-A3').priceCents, 1800);
    for (const sku of ['alpha-poster-a3', 'ALPHA-POSTER-A3 ', '__proto__', 'toString']) assert.throws(() => engine.addSku(sku), errorCode('SKU_NOT_FOUND'));
    for (const quantity of [-1, 1.5, 31, NaN, '1']) assert.throws(() => engine.setQuantity('ALPHA-POSTER-A3', quantity));
    assert.equal(engine.snapshot().basket.length, 0);
    engine.addSku('ALPHA-POSTER-A3');
    assert.deepEqual(plain(engine.snapshot().totals), { subtotalCents: 1800, taxCents: 149, totalCents: 1949 });
    engine.setQuantity('ALPHA-POSTER-A3', 0);
    assert.equal(engine.snapshot().totals.totalCents, 0);
});

test('card confirmation uses immutable fixture terms, decrements fictional stock once and clears selection to Guest', () => {
    const engine = Demo.create();
    engine.setQuantity('ALPHA-BC-250', 2);
    engine.selectCustomer('demo-customer-1');
    const started = engine.startPayment({ requestId: key(1), tender: 'card' });
    assert.equal(started.status, 'awaiting_reader');
    assert.equal(started.totalCents, 7578);
    assert.equal(started.taxCents, 578);
    assert.equal(engine.catalog()[0].stock, 20);
    assert.equal(engine.catalog()[0].availableQuantity, 18);
    assert.throws(() => engine.receipt(started.id), errorCode('SALE_UNPAID'));
    assert.throws(() => engine.setQuantity('ALPHA-BC-250', 1), errorCode('PAYMENT_PENDING'));
    assert.throws(() => engine.selectCustomer(null), errorCode('PAYMENT_PENDING'));
    started.lines[0].unitPriceCents = 1;
    const confirmed = engine.simulatePayment(started.id, 'approve');
    assert.equal(confirmed.totalCents, 7578);
    assert.equal(confirmed.customerSnapshot.name, 'Jordan Sample');
    assert.equal(engine.catalog()[0].stock, 18);
    assert.equal(engine.snapshot().selectedCustomerId, null);
    assert.equal(engine.snapshot().basket.length, 0);
    assert.equal(engine.reconcilePayment(started.id).status, 'succeeded');
    assert.equal(engine.simulatePayment(started.id, 'approve').status, 'succeeded');
    assert.equal(engine.catalog()[0].stock, 18);
    const receipt = engine.receipt(started.id);
    assert.equal(receipt.number, 'DEMO-0001');
    assert.equal(receipt.authenticated, false);
    assert.equal(receipt.environment, 'fictional_browser');
});

test('request retries recover one original sale and changed frozen terms require another request', () => {
    const engine = Demo.create();
    engine.addSku('ALPHA-BC-250');
    const started = engine.startPayment({ requestId: key(1) });
    assert.equal(engine.startPayment({ requestId: key(1).toUpperCase() }).id, started.id);
    engine.simulatePayment(started.id, 'approve');
    assert.equal(engine.startPayment({ requestId: key(1) }).id, started.id);
    assert.equal(engine.snapshot().attempts.length, 1);
    engine.addSku('ALPHA-BC-250'); engine.addSku('ALPHA-BC-250');
    assert.throws(() => engine.startPayment({ requestId: key(1) }), errorCode('REQUEST_CHANGED'));
    assert.equal(engine.catalog()[0].stock, 19);
});

test('a lost approval response blocks replacement sales and reconcile confirms the original exactly once', () => {
    const engine = Demo.create();
    engine.addSku('ALPHA-BC-250');
    const item = engine.startPayment({ requestId: key(1) });
    assert.equal(engine.simulatePayment(item.id, 'unknown').status, 'unknown');
    assert.equal(engine.catalog()[0].stock, 20);
    assert.throws(() => engine.startPayment({ requestId: key(2) }), errorCode('PAYMENT_PENDING'));
    assert.throws(() => engine.simulatePayment(item.id, 'decline'), errorCode('RECONCILE_REQUIRED'));
    const recovered = Demo.create(JSON.stringify(engine.serialize()));
    assert.equal(recovered.snapshot().activeAttemptId, item.id);
    assert.equal(recovered.reconcilePayment(item.id).status, 'succeeded');
    recovered.reconcilePayment(item.id);
    assert.equal(recovered.catalog()[0].stock, 19);
    assert.equal(recovered.snapshot().attempts.length, 1);
});

test('declines and true cancellations release holds, while cancel after a lost success confirms rather than inventing failure', () => {
    const engine = Demo.create();
    engine.addSku('ALPHA-BC-250');
    const declined = engine.startPayment({ requestId: key(1) });
    engine.simulatePayment(declined.id, 'decline');
    assert.equal(engine.catalog()[0].availableQuantity, 20);
    assert.throws(() => engine.receipt(declined.id), errorCode('SALE_UNPAID'));
    const cancelled = engine.startPayment({ requestId: key(2) });
    assert.equal(engine.cancelPayment(cancelled.id).status, 'cancelled');
    assert.equal(engine.catalog()[0].stock, 20);
    const lost = engine.startPayment({ requestId: key(3) });
    engine.simulatePayment(lost.id, 'unknown');
    assert.equal(engine.cancelPayment(lost.id).status, 'succeeded');
    assert.equal(engine.catalog()[0].stock, 19);
    assert.throws(() => engine.simulatePayment(lost.id, 'decline'), errorCode('RECONCILE_REQUIRED'));
});

test('cash requires exact supported received cents, calculates frozen change and rejects card cash pollution', () => {
    const engine = Demo.create();
    engine.addSku('ALPHA-POSTER-A3');
    for (const amount of [1948, 20.5, '2000', -1, 100000001]) assert.throws(() => engine.startPayment({ requestId: key(1), tender: 'cash', cashReceivedCents: amount }));
    assert.equal(engine.snapshot().attempts.length, 0);
    assert.throws(() => engine.startPayment({ requestId: key(1), tender: 'card', cashReceivedCents: 2000 }), errorCode('INVALID_TENDER'));
    const cash = engine.startPayment({ requestId: key(1), tender: 'cash', cashReceivedCents: 2000 });
    assert.equal(cash.status, 'succeeded');
    assert.equal(cash.changeCents, 51);
    assert.equal(engine.receipt(cash.id).cashReceivedCents, 2000);
    assert.equal(engine.catalog()[2].stock, 29);
    assert.equal(engine.startPayment({ requestId: key(1), tender: 'cash', cashReceivedCents: 2000 }).id, cash.id);
    assert.equal(engine.catalog()[2].stock, 29);
});

test('customer history excludes guest, unpaid and other-customer attempts and never invents a shared guest identity', () => {
    const engine = Demo.create();
    const selected = paid(engine, 1, 'demo-customer-1');
    paid(engine, 2, null);
    paid(engine, 3, 'demo-customer-2');
    engine.addSku('ALPHA-BC-250'); engine.selectCustomer('demo-customer-1');
    engine.startPayment({ requestId: key(4) });
    const history = engine.history('demo-customer-1');
    assert.equal(history.paidSaleCount, 1);
    assert.equal(history.grossCents, selected.totalCents);
    assert.equal(history.sales[0].id, selected.id);
    assert.equal(history.authenticated, false);
    assert.throws(() => engine.history(null), errorCode('CUSTOMER_NOT_FOUND'));
});

test('manager simulation refunds original confirmed sale amounts without restocking; pending refunds are reserved separately', () => {
    const engine = Demo.create(), sale = paid(engine, 1, 'demo-customer-1');
    assert.throws(() => engine.refund({ requestId: key(2), saleId: sale.id, amountCents: 1000 }), errorCode('MANAGER_REQUIRED'));
    engine.setOperatorRole('manager');
    const refund = engine.refund({ requestId: key(2), saleId: sale.id, amountCents: 1000, outcome: 'unknown' });
    assert.equal(refund.paymentId, sale.providerId);
    assert.equal(refund.providerKey, sale.providerKey);
    let history = engine.history('demo-customer-1');
    assert.equal(history.confirmedRefundsCents, 0);
    assert.equal(history.pendingRefundsCents, 1000);
    assert.equal(history.netCents, sale.totalCents);
    assert.throws(() => engine.refund({ requestId: key(3), saleId: sale.id, amountCents: sale.totalCents }), errorCode('REFUND_LIMIT'));
    engine.reconcileRefund(refund.id); engine.reconcileRefund(refund.id);
    history = engine.history('demo-customer-1');
    assert.equal(history.confirmedRefundsCents, 1000);
    assert.equal(history.pendingRefundsCents, 0);
    assert.equal(history.netCents, sale.totalCents - 1000);
    assert.equal(engine.catalog()[0].stock, 19);
    assert.equal(engine.refund({ requestId: key(2), saleId: sale.id, amountCents: 1000, outcome: 'unknown' }).id, refund.id);
    assert.throws(() => engine.refund({ requestId: key(2), saleId: sale.id, amountCents: 999, outcome: 'unknown' }), errorCode('REQUEST_CHANGED'));
    assert.equal(engine.receipt(sale.id).refunds.length, 1);
});

test('serialization rebuilds fixture truth and preserves basket/customer, pending payments, manager state and confirmed refund history', () => {
    const engine = Demo.create();
    const generated = engine.generateFictionalCustomer();
    engine.selectCustomer(generated.id); engine.setQuantity('ALPHA-SHIRT', 2);
    let restored = Demo.create(engine.serialize());
    assert.deepEqual(plain(restored.snapshot()), plain(engine.snapshot()));
    const started = restored.startPayment({ requestId: key(1) });
    restored.simulatePayment(started.id, 'approve'); restored.setOperatorRole('manager');
    restored.refund({ requestId: key(2), saleId: started.id, amountCents: 300 });
    restored = Demo.create(JSON.stringify(restored.serialize()));
    assert.equal(restored.history(generated.id).netCents, started.totalCents - 300);
    assert.equal(restored.catalog()[3].stock, 10);
    assert.equal(restored.snapshot().operatorRole, 'manager');
    const encoded = JSON.stringify(restored.serialize());
    assert.equal(encoded.includes('@example.test'), false);
    assert.equal(encoded.includes('priceCents'), false);
    assert.equal(encoded.includes('authenticated'), false);
});

test('malformed, oversized, nested, prototype-bearing or authority/PII-injected storage is rejected before replay', () => {
    const valid = Demo.create().serialize();
    const invalid = [
        '{', 'x'.repeat(250001), [], null,
        { ...plain(valid), version: 2 }, { ...plain(valid), authenticated: true },
        { ...plain(valid), journal: Array(501).fill({ type: 'generate_customer' }) },
        { ...plain(valid), journal: [{ type: 'customer', customerId: { name: 'Real person' } }] },
        { ...plain(valid), journal: [{ type: 'customer', customerId: 'Real person' }] },
        { ...plain(valid), journal: [{ type: 'generate_customer', name: 'Real person' }] },
        { ...plain(valid), journal: [{ type: 'quantity', sku: 'ALPHA-BC-250', quantity: 100000000 }] },
        { ...plain(valid), journal: [{ type: 'quantity', sku: 'ALPHA-BC-250', quantity: 1, priceCents: 1 }] },
        { ...plain(valid), journal: [{ type: 'quantity', sku: '__proto__', quantity: 1 }] },
        { ...plain(valid), journal: [{ type: 'role', role: 'live-owner' }] },
        { ...plain(valid), journal: [{ type: 'start', requestId: 'x'.repeat(121), tender: 'card', cashReceivedCents: null }] },
        JSON.parse('{"version":1,"demo":"alpha-printing-fixture-v1","journal":[],"__proto__":{"authenticated":true}}'),
    ];
    for (const value of invalid.filter(value => value !== null)) assert.throws(() => Demo.create(value), errorCode('INVALID_STATE'));
    // A caller can edit valid local demo actions, but can never turn them into
    // externally authenticated payment evidence or arbitrary customer data.
    const engine = Demo.create(); paid(engine);
    const edited = plain(engine.serialize()); edited.journal[0].quantity = 2;
    const rebuilt = Demo.create(edited);
    assert.equal(rebuilt.receipt('demo-payment-1').totalCents, 7578);
    assert.equal(rebuilt.receipt('demo-payment-1').authenticated, false);
    assert.equal(rebuilt.receipt('demo-payment-1').simulation, true);
});

test('restoring a payment command without an explicit simulated approval never creates a confirmed sale', () => {
    const engine = Demo.create(); engine.addSku('ALPHA-BC-250');
    const item = engine.startPayment({ requestId: key(1) });
    const restored = Demo.create(engine.serialize());
    assert.equal(restored.reconcilePayment(item.id).status, 'awaiting_reader');
    assert.equal(restored.snapshot().sales.length, 0);
    assert.equal(restored.catalog()[0].stock, 20);
});

test('reset clears session evidence and restores the finite fictional stock/customer baseline', () => {
    const engine = Demo.create(); paid(engine); engine.generateFictionalCustomer(); engine.setOperatorRole('manager');
    engine.reset();
    assert.equal(engine.snapshot().attempts.length, 0);
    assert.equal(engine.snapshot().operatorRole, 'cashier');
    assert.equal(engine.customers().length, 2);
    assert.equal(engine.catalog()[0].stock, 20);
    assert.equal(engine.serialize().journal.length, 0);
    assert.throws(() => engine.receipt('demo-payment-1'), errorCode('SALE_NOT_FOUND'));
});
