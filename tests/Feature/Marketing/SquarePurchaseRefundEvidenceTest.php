<?php

use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TeamOperationRecord;
use App\Services\Marketing\SquarePurchaseEvidence;
use App\Services\Reporting\ReviewedSquareEventPurchases;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

function omittedRefundEvidence(): array
{
    $order = new SquareOrder(['tenant_id' => 1, 'square_order_id' => 'fictional-order', 'location_id' => 'fictional-location',
        'state' => 'COMPLETED', 'closed_at' => now()->subDay(), 'total_money_amount' => 1100, 'total_money_currency' => 'USD',
        'raw_payload' => ['net_amounts' => ['total_money' => ['amount' => 1100, 'currency' => 'USD']],
            'total_tax_money' => ['amount' => 70, 'currency' => 'USD'], 'total_tip_money' => ['amount' => 30, 'currency' => 'USD']]]);
    $payment = new SquarePayment(['tenant_id' => 1, 'square_payment_id' => 'fictional-payment', 'square_order_id' => 'fictional-order',
        'location_id' => 'fictional-location', 'status' => 'COMPLETED', 'amount_money' => 1070, 'currency' => 'USD', 'source_type' => 'CARD',
        'created_at_source' => now()->subDay(), 'raw_payload' => ['id' => 'fictional-payment', 'status' => 'COMPLETED',
            'order_id' => 'fictional-order', 'location_id' => 'fictional-location', 'amount_money' => ['amount' => 1070, 'currency' => 'USD'],
            'tip_money' => ['amount' => 30, 'currency' => 'USD'], 'total_money' => ['amount' => 1100, 'currency' => 'USD']]]);

    return [$order, $payment];
}

test('a full original unreturned payment can omit Square zero-refund fields', function () {
    [$order, $payment] = omittedRefundEvidence();
    $evidence = app(SquarePurchaseEvidence::class);
    expect($evidence->confirmedOrder($order, collect([$payment])))->toBeTrue()
        ->and($evidence->recordedPaymentRefund($payment, $order))->toBe(0)
        ->and($evidence->recordedRefunds($order, collect([$payment])))->toBe(0);
    $payment->raw_payload = [...$payment->raw_payload, 'refund_ids' => []];
    expect($evidence->recordedRefunds($order, collect([$payment])))->toBe(0);
});

test('omitted refunds stay unknown when original payment or return evidence is incomplete or conflicting', function (string $target, string $key, mixed $value) {
    [$order, $payment] = omittedRefundEvidence();
    $model = $target === 'order' ? $order : $payment;
    $raw = $model->raw_payload;
    data_set($raw, $key, $value);
    $model->raw_payload = $raw;
    expect(app(SquarePurchaseEvidence::class)->recordedPaymentRefund($payment, $order))->toBeNull()
        ->and(app(SquarePurchaseEvidence::class)->recordedRefunds($order, collect([$payment])))->toBeNull();
})->with([
    'missing original payment identity' => ['payment', 'id', null],
    'different original location' => ['payment', 'location_id', 'another-location'],
    'different original order' => ['payment', 'order_id', 'another-order'],
    'pending original payment' => ['payment', 'status', 'PENDING'],
    'unresolved refund IDs' => ['payment', 'refund_ids', ['fictional-refund']],
    'malformed refund IDs' => ['payment', 'refund_ids', 'fictional-refund'],
    'null refund IDs' => ['payment', 'refund_ids', null],
    'null recorded refunds' => ['payment', 'refunded_money', null],
    'missing refund amount' => ['payment', 'refunded_money', ['currency' => 'USD']],
    'foreign refund currency' => ['payment', 'refunded_money', ['amount' => 0, 'currency' => 'EUR']],
    'negative refund' => ['payment', 'refunded_money', ['amount' => -1, 'currency' => 'USD']],
    'excess refund' => ['payment', 'refunded_money', ['amount' => 1101, 'currency' => 'USD']],
    'conflicting collected money' => ['payment', 'total_money.amount', 1099],
    'missing original money' => ['payment', 'amount_money', null],
    'missing net order money' => ['order', 'net_amounts', null],
    'net order money includes a return' => ['order', 'net_amounts.total_money.amount', 1000],
    'foreign net order currency' => ['order', 'net_amounts.total_money.currency', 'EUR'],
    'original order has refunds' => ['order', 'refunds', [['id' => 'fictional-return']]],
    'original order has returns' => ['order', 'returns', [['source_order_id' => 'fictional-order']]],
    'original order has returned money' => ['order', 'return_amounts.total_money', ['amount' => 100, 'currency' => 'USD']],
]);

test('omitted refund evidence does not cross tenants or accept an empty source snapshot', function () {
    [$order, $payment] = omittedRefundEvidence();
    $payment->tenant_id = 2;
    expect(app(SquarePurchaseEvidence::class)->recordedPaymentRefund($payment, $order))->toBeNull();
    $payment->tenant_id = 1;
    $payment->raw_payload = [];
    expect(app(SquarePurchaseEvidence::class)->recordedPaymentRefund($payment, $order))->toBeNull();
});

test('explicit original partial and full refunds remain recorded including tipped totals', function (int $refund) {
    [$order, $payment] = omittedRefundEvidence();
    $payment->raw_payload = ['refunded_money' => ['amount' => $refund, 'currency' => 'USD'],
        'tip_money' => ['amount' => 30, 'currency' => 'USD']];
    expect(app(SquarePurchaseEvidence::class)->recordedPaymentRefund($payment, $order))->toBe($refund)
        ->and(app(SquarePurchaseEvidence::class)->recordedRefunds($order, collect([$payment])))->toBe($refund);
})->with([0, 500, 1100]);

test('reviewed event sales and tender refunds use the same documented omitted-zero evidence', function () {
    $this->withoutVite();
    config(['pos_live.runtime_id' => '']);
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    [$order, $payment] = omittedRefundEvidence();
    $order->tenant_id = $tenant->id;
    $payment->tenant_id = $tenant->id;
    $order->save();
    $payment->save();
    $event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id,
        'kind' => 'event', 'title' => 'Fictional annual show', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1, 'data' => []]);
    $report = app(ReviewedSquareEventPurchases::class)->forOccurrence($tenant->id, $event, null,
        ['ids' => [$order->square_order_id], 'reviewed_by' => $owner->id, 'reviewed_at' => now()->toIso8601String()]);
    expect($report['review_items'])->toBe([])->and($report['purchases'])->toHaveCount(1)
        ->and($report['purchases'][0]['refunded_minor'])->toBe(0)
        ->and($report['purchases'][0]['net_tax_minor'])->toBe(70)
        ->and($report['purchases'][0]['net_tip_minor'])->toBe(30)
        ->and($report['purchases'][0]['net_sales_minor'])->toBe(1000)
        ->and($report['purchases'][0]['tenders'])->toBe([['tender' => 'card', 'collected_minor' => 1100, 'refunded_minor' => 0]]);
});
