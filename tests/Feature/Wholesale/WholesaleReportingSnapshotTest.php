<?php

require_once dirname(__DIR__).'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Order;
use App\Models\Tenant;
use App\Services\Wholesale\WholesaleOperationsService;
use App\Services\Wholesale\WholesaleReportingSnapshot;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->travelTo(now()->parse('2026-11-09 13:00:00', 'UTC'));
    $this->tenant = Tenant::query()->create(['name' => 'Fictional Wholesale', 'slug' => 'fictional-wholesale']);
    configureEmbeddedWholesaleStore((int) $this->tenant->id);
});

function reportingRawOrder(int $id, array $extra = []): array
{
    return array_replace_recursive([
        'id' => $id, 'name' => '#'.$id, 'created_at' => '2026-07-01T12:00:00Z',
        'cancelled_at' => null, 'test' => false, 'financial_status' => 'paid', 'currency' => 'USD',
        'customer' => ['id' => 701, 'first_name' => 'Sample', 'last_name' => 'Buyer', 'email' => 'sample@example.com'],
        'shipping_address' => ['company' => 'Fictional Shop'],
        'line_items' => [['title' => 'Cedar Candle', 'variant_title' => '16 oz', 'sku' => 'CEDAR-16', 'quantity' => 12, 'price' => '10.00', 'total_discount' => '20.00']],
    ], $extra);
}

function fakeWholesaleReporting(array $orders, array $payments): void
{
    Http::swap(new \Illuminate\Http\Client\Factory);
    $pages = 0;
    Http::fake(function ($request) use ($orders, $payments, &$pages) {
        expect(parse_url($request->url(), PHP_URL_HOST))->toBe('s2vscq-rf.myshopify.com');
        if (str_contains($request->url(), '/orders/count.json')) {
            return Http::response(['count' => count($orders)]);
        }
        if (str_contains($request->url(), '/orders.json')) {
            $pages++;
            if ($pages > 2) {
                throw new RuntimeException('Cursor was lost or repeated');
            }
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);
            if (($query['page_info'] ?? null) === 'second') {
                return Http::response(['orders' => array_slice($orders, 1)]);
            }

            return Http::response(['orders' => array_slice($orders, 0, 1)], 200, count($orders) > 1 ? ['Link' => '<https://s2vscq-rf.myshopify.com/admin/api/2026-01/orders.json?page_info=second&limit=250>; rel="next"'] : []);
        }
        if (str_contains($request->url(), '/graphql.json')) {
            if ($request['query'] === WholesaleReportingSnapshot::SALES_QUERY) {
                $query = $request['variables']['query'];
                expect($query)->toContain('FROM sales SHOW total_sales, net_sales, orders, average_order_value', 'UNTIL 2026-11-09');
                $total = str_contains($query, 'SINCE 2026-11-01') ? '12.34' : (str_contains($query, 'SINCE 2026-01-01') ? '123.45' : '234.56');

                return Http::response(['data' => ['shopifyqlQuery' => ['parseErrors' => [], 'tableData' => ['rows' => [['total_sales' => $total, 'net_sales' => '100.00', 'orders' => '2', 'average_order_value' => '61.725']]]]]]);
            }
            expect($request['query'])->toBe(WholesaleReportingSnapshot::PAYMENT_QUERY);

            return Http::response(['data' => ['nodes' => array_map(function ($id) use ($payments, $orders) {
                $facts = $payments[basename($id)] ?? null;
                if ($facts === null) {
                    return null;
                }
                [$received, $refunded, $status, $currency] = array_pad($facts, 4, 'USD');
                $money = fn ($amount) => ['shopMoney' => ['amount' => (string) $amount, 'currencyCode' => $currency]];

                $raw = collect($orders)->first(fn ($order) => (string) $order['id'] === basename($id));

                return ['id' => $id, 'processedAt' => $raw['processed_at'] ?? $raw['created_at'], 'displayFinancialStatus' => $status, 'totalReceivedSet' => $money($received), 'totalRefundedSet' => $money($refunded), 'netPaymentSet' => $money(number_format((float) $received - (float) $refunded, 2, '.', ''))];
            }, $request['variables']['ids'])]]);
        }
        throw new RuntimeException('Unexpected provider request');
    });
}

test('complete private history preserves pagination and uses provider net payments without creating production orders', function (): void {
    fakeWholesaleReporting([
        reportingRawOrder(1001, ['created_at' => '2026-11-01T03:59:00Z']),
        reportingRawOrder(1002, ['created_at' => '2026-11-03T12:00:00Z', 'financial_status' => 'pending']),
        reportingRawOrder(1003, ['cancelled_at' => '2026-07-01T13:00:00Z']),
    ], ['1001' => ['100.00', '20.00', 'PARTIALLY_REFUNDED'], '1002' => ['0.00', '0.00', 'PENDING'], '1003' => ['0.00', '0.00', 'VOIDED']]);
    $snapshot = app(WholesaleReportingSnapshot::class)->refresh((int) $this->tenant->id);
    expect($snapshot['source_order_count'])->toBe(3)->and($snapshot['included_order_count'])->toBe(2)
        ->and($snapshot['excluded']['cancelled'])->toBe(1)->and(Order::query()->count())->toBe(0);
    $report = app(WholesaleOperationsService::class)->overview((int) $this->tenant->id);
    // Native sales reporting is deliberately different from net payments.
    expect($report['metrics']['revenue_year'])->toBe(123.45)
        ->and($report['metrics']['revenue_month'])->toBe(12.34)
        ->and($report['metrics']['order_count'])->toBe(2)
        ->and($report['reporting']['complete'])->toBeTrue();
    $customer = app(WholesaleOperationsService::class)->customers((int) $this->tenant->id)->first();
    expect($customer['timing_state'])->toBe('awaiting_payment')
        ->and($customer['lifetime_revenue'])->toBe(80.0)
        ->and($customer['orders']->first()->lines->first()->raw_title)->toBe('Cedar Candle');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'page_info=second'));
});

test('historical imports retain their original processed sales date for customer timing', function (): void {
    fakeWholesaleReporting([reportingRawOrder(1001, ['name' => '#HIST-1001', 'created_at' => '2026-05-12T11:56:32Z', 'processed_at' => '2025-12-17T19:21:58Z', 'source_name' => 'Matrixify App'])], ['1001' => ['115.00', '0.00', 'PAID']]);
    $snapshot = app(WholesaleReportingSnapshot::class)->refresh((int) $this->tenant->id);
    $customer = app(WholesaleOperationsService::class)->customers((int) $this->tenant->id)->first();
    expect($snapshot['rows'][0]['provider_created_at'])->toBe('2026-05-12T11:56:32+00:00')
        ->and($customer['last_order_at']->toDateString())->toBe('2025-12-17')
        ->and($customer['timing_state'])->toBe('lapsed');
});

test('a staff dismissal based on an old import timestamp remains effective after original date correction', function (): void {
    fakeWholesaleReporting([reportingRawOrder(1001, ['created_at' => '2026-05-12T07:56:32-04:00', 'processed_at' => '2025-12-17T19:21:58Z', 'source_name' => 'Matrixify App'])], ['1001' => ['115.00', '0.00', 'PAID']]);
    app(WholesaleReportingSnapshot::class)->refresh((int) $this->tenant->id);
    $generator = app(\App\Services\Wholesale\WholesaleSuggestionGenerator::class);
    $generator->refresh((int) $this->tenant->id);
    $suggestion = \App\Models\WholesaleSuggestion::query()->forAllTenants()->firstOrFail();
    $suggestion->update(['status' => 'dismissed', 'supporting_evidence' => ['last_wholesale_order_at' => '2026-05-12T07:56:32+00:00']]);
    expect($generator->refresh((int) $this->tenant->id)['created'])->toBe(0)
        ->and($suggestion->fresh()->status)->toBe('dismissed')
        ->and(app(\App\Services\Wholesale\WholesaleOutreachDigest::class)->snapshot($this->tenant)['rows'])->toBe([]);
});

test('incomplete payment evidence retains previous verified history and stale history is visible', function (): void {
    fakeWholesaleReporting([reportingRawOrder(1001)], ['1001' => ['100.00', '0.00', 'PAID']]);
    $service = app(WholesaleReportingSnapshot::class);
    $original = $service->refresh((int) $this->tenant->id);
    fakeWholesaleReporting([reportingRawOrder(1001)], []);
    expect(fn () => $service->refresh((int) $this->tenant->id))->toThrow(RuntimeException::class)
        ->and($service->read((int) $this->tenant->id))->toBe($original);
    $this->travel(91)->minutes();
    expect(app(WholesaleOperationsService::class)->reportingStatus((int) $this->tenant->id)['stale'])->toBeTrue();
});

test('reporting snapshot never crosses a reassigned store ownership boundary', function (): void {
    fakeWholesaleReporting([reportingRawOrder(1001)], ['1001' => ['100.00', '0.00', 'PAID']]);
    $service = app(WholesaleReportingSnapshot::class);
    $service->refresh((int) $this->tenant->id);
    $other = Tenant::query()->create(['name' => 'Other Private Workspace', 'slug' => 'other-private']);
    configureEmbeddedWholesaleStore((int) $other->id);
    expect($service->read((int) $this->tenant->id))->toBeNull()
        ->and($service->read((int) $other->id))->toBeNull();
    expect(fn () => $service->refresh((int) $this->tenant->id))->toThrow(DomainException::class);
});

test('mixed currency snapshots are rejected instead of adding incompatible amounts', function (): void {
    fakeWholesaleReporting([reportingRawOrder(1001), reportingRawOrder(1002)], [
        '1001' => ['100.00', '0.00', 'PAID', 'USD'], '1002' => ['100.00', '0.00', 'PAID', 'CAD'],
    ]);
    expect(fn () => app(WholesaleReportingSnapshot::class)->refresh((int) $this->tenant->id))->toThrow(RuntimeException::class)
        ->and(app(WholesaleReportingSnapshot::class)->read((int) $this->tenant->id))->toBeNull();
});

test('reordering supersedes old automated advice while staff decisions survive timing and value changes', function (): void {
    $source = app(WholesaleReportingSnapshot::class);
    $generator = app(\App\Services\Wholesale\WholesaleSuggestionGenerator::class);
    fakeWholesaleReporting([reportingRawOrder(1001, ['created_at' => '2026-06-01T12:00:00Z'])], ['1001' => ['100.00', '0.00', 'PAID']]);
    $source->refresh((int) $this->tenant->id);
    expect($generator->refresh((int) $this->tenant->id)['created'])->toBe(1);
    $original = \App\Models\WholesaleSuggestion::query()->forAllTenants()->firstOrFail();
    fakeWholesaleReporting([reportingRawOrder(1001, ['created_at' => '2026-06-01T12:00:00Z']), reportingRawOrder(1002, ['created_at' => '2026-10-31T12:00:00Z'])], ['1001' => ['100.00', '0.00', 'PAID'], '1002' => ['200.00', '0.00', 'PAID']]);
    $source->refresh((int) $this->tenant->id);
    $generator->refresh((int) $this->tenant->id);
    expect($original->fresh()->status)->toBe('superseded');
    // Backfill/refund changes on the same last order must not bypass a dismissal.
    fakeWholesaleReporting([reportingRawOrder(1001, ['created_at' => '2026-06-01T12:00:00Z'])], ['1001' => ['100.00', '10.00', 'PARTIALLY_REFUNDED']]);
    $source->refresh((int) $this->tenant->id);
    $original->update(['status' => 'dismissed']);
    $generator->refresh((int) $this->tenant->id);
    expect($original->fresh()->status)->toBe('dismissed')
        ->and(\App\Models\WholesaleSuggestion::query()->forAllTenants()->where('status', 'pending')->count())->toBe(0);
});

test('an older unpaid invoice or a newer cancelled order requires review instead of reorder outreach', function (): void {
    foreach (['pending', 'cancelled'] as $condition) {
        fakeWholesaleReporting([
            reportingRawOrder(1001, ['created_at' => '2026-06-01T12:00:00Z']),
            reportingRawOrder(1002, ['created_at' => $condition === 'pending' ? '2026-01-01T12:00:00Z' : '2026-10-01T12:00:00Z', 'cancelled_at' => $condition === 'cancelled' ? '2026-10-02T12:00:00Z' : null]),
        ], ['1001' => ['100.00', '0.00', 'PAID'], '1002' => ['0.00', '0.00', $condition === 'pending' ? 'PENDING' : 'VOIDED']]);
        app(WholesaleReportingSnapshot::class)->refresh((int) $this->tenant->id);
        expect(app(WholesaleOperationsService::class)->customers((int) $this->tenant->id)->first()['timing_state'])->toBe($condition === 'pending' ? 'awaiting_payment' : 'account_review');
    }
});

test('explicit retail decisions contradictory source keys and foreign classifications cannot leak into wholesale', function (): void {
    $scope = app(\App\Services\Wholesale\WholesaleQualifiedOrderScope::class);
    $retail = Order::factory()->create(['tenant_id' => $this->tenant->id, 'shopify_store_key' => 'retail', 'shopify_store' => 'wholesale', 'order_type' => 'wholesale']);
    $excluded = Order::factory()->create(['tenant_id' => $this->tenant->id, 'shopify_store_key' => 'wholesale', 'shopify_store' => 'wholesale']);
    $other = Tenant::query()->create(['name' => 'Foreign Account', 'slug' => 'foreign-account']);
    $foreign = Order::factory()->create(['tenant_id' => $other->id, 'shopify_store_key' => 'retail']);
    foreach ([[$excluded, 'retail_only'], [$foreign, 'confirmed']] as [$order, $status]) {
        \App\Models\WholesaleOrderClassification::query()->create(['tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'status' => $status]);
    }
    expect($scope->isQualified((int) $this->tenant->id, (int) $retail->id))->toBeFalse()
        ->and($scope->isQualified((int) $this->tenant->id, (int) $excluded->id))->toBeFalse()
        ->and($scope->isQualified((int) $this->tenant->id, (int) $foreign->id))->toBeFalse();
});

test('unknown same name buyers stay separate and future dates cannot inflate statistics', function (): void {
    foreach ([10, 30] as $days) {
        Order::factory()->create(['tenant_id' => $this->tenant->id, 'shopify_store_key' => 'wholesale', 'customer_name' => 'Same Unverified Name', 'ordered_at' => now()->subDays($days)]);
    }
    Order::factory()->create(['tenant_id' => $this->tenant->id, 'shopify_store_key' => 'wholesale', 'customer_name' => 'Future Phantom', 'ordered_at' => now()->addMonth(), 'total_price' => 99999]);
    $operations = app(WholesaleOperationsService::class);
    expect($operations->customers((int) $this->tenant->id))->toHaveCount(2)
        ->and($operations->overview((int) $this->tenant->id)['metrics']['order_count'])->toBe(2);
});

test('prior and current trailing year values do not count their shared boundary twice', function (): void {
    Order::factory()->create(['tenant_id' => $this->tenant->id, 'shopify_store_key' => 'wholesale', 'shopify_customer_id' => 'boundary-buyer', 'ordered_at' => now()->subYear(), 'total_price' => 100, 'refund_total' => 0]);
    $operations = app(WholesaleOperationsService::class);
    $key = $operations->customers((int) $this->tenant->id)->first()['public_key'];
    $customer = $operations->customer((int) $this->tenant->id, $key);
    expect($customer['trailing_12_revenue'])->toBe(100.0)
        ->and($customer['prior_trailing_12_revenue'])->toBe(0.0);
});
