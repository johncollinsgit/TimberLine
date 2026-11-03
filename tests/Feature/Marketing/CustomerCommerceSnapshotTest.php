<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantSite;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteOrder;
use App\Services\Marketing\CustomerCommerceSnapshotService;
use App\Services\Shopify\ShopifyOrderIngestor;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function commerceCustomer(string $slug): MarketingProfile
{
    $tenant = Tenant::query()->create(['name' => $slug, 'slug' => $slug]);

    return MarketingProfile::query()->create(['tenant_id' => $tenant->id, 'first_name' => 'Customer',
        'email' => 'same@example.com', 'normalized_email' => 'same@example.com']);
}

function commerceLink(MarketingProfile $profile, string $type, string|int $id): MarketingProfileLink
{
    return MarketingProfileLink::query()->create(['tenant_id' => $profile->tenant_id,
        'marketing_profile_id' => $profile->id, 'source_type' => $type, 'source_id' => (string) $id]);
}

test('every plan and operating mode includes customer history and rewards without a paid add-on', function (string $plan, string $mode): void {
    $profile = commerceCustomer($plan.'-'.$mode);
    TenantAccessProfile::query()->create(['tenant_id' => $profile->tenant_id, 'plan_key' => $plan,
        'operating_mode' => $mode, 'source' => 'test']);
    $modules = app(TenantModuleAccessResolver::class)->resolveForTenant($profile->tenant_id, ['customers', 'rewards'])['modules'];
    expect($modules['customers']['has_recorded_access'])->toBeTrue()
        ->and($modules['rewards']['has_recorded_access'])->toBeTrue()
        ->and(config('module_catalog.modules.customers.capabilities'))->toContain('customers.purchase_history', 'customers.rewards_history');
})->with(['base', 'starter', 'growth', 'pro'])->with(['direct', 'shopify']);

test('linked confirmed online and physical purchases share one existing balance without writes or duplicate Square tenders', function (): void {
    $profile = commerceCustomer('modern-forestry-pilot');
    $other = commerceCustomer('service-pilot');
    $order = Order::factory()->create(['tenant_id' => $profile->tenant_id, 'shopify_order_id' => 910,
        'shopify_store_key' => 'pilot', 'attribution_meta' => ['customer_commerce' => [
            'tenant_id' => $profile->tenant_id, 'shopify_order_id' => '910', 'store_key' => 'pilot',
            'financial_status' => 'paid', 'source_name' => 'web', 'total_price' => '18.25', 'refund_total' => '0.00', 'currency' => 'USD']]]);
    commerceLink($profile, 'order', $order->id);
    SquareOrder::query()->create(['tenant_id' => $profile->tenant_id, 'square_order_id' => 'physical-1',
        'state' => 'COMPLETED', 'closed_at' => now(), 'total_money_amount' => 3000, 'total_money_currency' => 'USD']);
    commerceLink($profile, 'square_order', 'physical-1');
    SquarePayment::query()->create(['tenant_id' => $profile->tenant_id, 'square_payment_id' => 'payment-1',
        'square_order_id' => 'physical-1', 'status' => 'COMPLETED', 'amount_money' => 3000, 'currency' => 'USD',
        'created_at_source' => now(), 'raw_payload' => ['card_details' => ['entry_method' => 'CONTACTLESS'], 'refunded_money' => ['amount' => 500]]]);
    commerceLink($profile, 'square_payment', 'payment-1');
    CandleCashBalance::query()->create(['marketing_profile_id' => $profile->id, 'balance' => 7.25]);
    CandleCashTransaction::query()->create(['marketing_profile_id' => $profile->id, 'type' => 'earn',
        'points' => 7, 'candle_cash_delta' => 7.25, 'source' => 'admin', 'description' => 'PRIVATE staff memo']);
    $foreign = Order::factory()->create(['tenant_id' => $other->tenant_id, 'customer_email' => $profile->email]);
    commerceLink($profile, 'order', $foreign->id); // malformed source association must not cross the tenant.
    Http::preventStrayRequests();
    DB::enableQueryLog();
    DB::flushQueryLog();

    $snapshot = app(CustomerCommerceSnapshotService::class)->forProfile($profile, $profile->tenant_id);
    $again = app(CustomerCommerceSnapshotService::class)->forProfile($profile, $profile->tenant_id);
    expect($snapshot)->toBe($again)
        ->and($snapshot['confirmed_purchase_count'])->toBe(2)
        ->and(collect($snapshot['purchases'])->pluck('channel')->all())->toContain('online', 'in_person')
        ->and(collect($snapshot['purchases'])->firstWhere('source', 'square_order')['refunded_minor'])->toBe(500)
        ->and(collect($snapshot['purchases'])->firstWhere('source', 'square_order')['status'])->toBe('partially_refunded')
        ->and($snapshot['rewards']['balance'])->toBe('7.25')
        ->and($snapshot['rewards']['history'][0]['amount'])->toBe('7.25')
        ->and(json_encode($snapshot))->not->toContain('PRIVATE')
        ->and(collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/^\s*(insert|update|delete)/i', $query['query'])))->toHaveCount(0);
    DB::disableQueryLog();
    Http::assertNothingSent();
    expect(app(CustomerCommerceSnapshotService::class)->forProfile($other, $other->tenant_id)['purchases'])->toBe([]);
});

test('workflow completion and unsupported currency are not payment confirmation', function (): void {
    $profile = commerceCustomer('workflow');
    $legacy = Order::factory()->create(['tenant_id' => $profile->tenant_id, 'status' => 'complete']);
    commerceLink($profile, 'order', $legacy->id);
    SquarePayment::query()->create(['tenant_id' => $profile->tenant_id, 'square_payment_id' => 'jpy',
        'status' => 'COMPLETED', 'amount_money' => 500, 'currency' => 'JPY']);
    commerceLink($profile, 'square_payment', 'jpy');
    $snapshot = app(CustomerCommerceSnapshotService::class)->forProfile($profile, $profile->tenant_id);
    expect($snapshot['confirmed_purchase_count'])->toBe(0)
        ->and($snapshot['unverified_record_count'])->toBe(2)
        ->and(collect($snapshot['purchases'])->firstWhere('source_id', 'jpy')['amount_minor'])->toBeNull();
});

test('website records need explicit association and practice sales stay out', function (): void {
    $profile = commerceCustomer('website');
    $site = TenantSite::query()->create(['tenant_id' => $profile->tenant_id, 'subdomain' => 'commerce']);
    $customer = WebsiteCustomer::query()->create(['tenant_id' => $profile->tenant_id, 'email' => $profile->email]);
    foreach (['website', 'pos_simulation'] as $source) {
        WebsiteOrder::query()->create(['tenant_id' => $profile->tenant_id, 'tenant_site_id' => $site->id,
            'website_customer_id' => $customer->id, 'source' => $source, 'number' => $source,
            'lookup_token' => $source, 'payment_status' => 'paid', 'paid_at' => now(), 'total_cents' => 1900, 'currency' => 'USD']);
    }
    $service = app(CustomerCommerceSnapshotService::class);
    expect($service->forProfile($profile, $profile->tenant_id)['purchases'])->toBe([]);
    commerceLink($profile, 'website_customer', $customer->id);
    $snapshot = $service->forProfile($profile, $profile->tenant_id);
    expect($snapshot['purchases'])->toHaveCount(1)
        ->and($snapshot['purchases'][0]['number'])->toBe('website')
        ->and($snapshot['confirmed_purchase_count'])->toBe(1);
});

test('retained customer aliases resolve to the survivor and broken tenant chains fail closed', function (): void {
    $profile = commerceCustomer('aliases');
    $alias = MarketingProfile::query()->create(['tenant_id' => $profile->tenant_id, 'merged_into_profile_id' => $profile->id]);
    $order = Order::factory()->create(['tenant_id' => $profile->tenant_id]);
    commerceLink($alias, 'order', $order->id);
    $service = app(CustomerCommerceSnapshotService::class);
    expect($service->forProfile($alias, $profile->tenant_id)['customer_id'])->toBe($profile->id)
        ->and($service->forProfile($profile, $profile->tenant_id)['purchases'])->toHaveCount(1);
    $other = commerceCustomer('foreign-alias');
    $alias->update(['merged_into_profile_id' => $other->id]);
    expect(fn () => $service->forProfile($alias->fresh(), $profile->tenant_id))->toThrow(ModelNotFoundException::class);
});

test('provider evidence preserves original currency totals and does not regress on an older replay', function (): void {
    $profile = commerceCustomer('ingestion');
    $store = ['key' => 'commerce-ingestion', 'source' => 'shopify_retail', 'tenant_id' => $profile->tenant_id];
    $payload = ['id' => 12121, 'name' => '#12121', 'created_at' => '2026-10-28T12:00:00Z',
        'updated_at' => '2026-10-30T12:00:00Z', 'financial_status' => 'paid', 'source_name' => 'pos',
        'currency' => 'USD', 'presentment_currency' => 'CAD', 'total_price' => '30.25', 'current_total_price' => '20.25', 'line_items' => []];
    $service = app(ShopifyOrderIngestor::class);
    $id = $service->ingest($store, $payload, ['dispatch_profile_sync' => false])['order_id'];
    $order = Order::query()->findOrFail($id);
    commerceLink($profile, 'order', $order->id);
    $payload['updated_at'] = '2026-10-29T12:00:00Z';
    $payload['financial_status'] = 'pending';
    $payload['total_price'] = '99.00';
    $service->ingest($store, $payload, ['dispatch_profile_sync' => false]);
    $snapshot = app(CustomerCommerceSnapshotService::class)->forProfile($profile, $profile->tenant_id);
    expect($snapshot['purchases'][0])->toMatchArray(['confirmed' => true, 'channel' => 'in_person', 'amount_minor' => 3025, 'currency' => 'USD']);

    $payload['updated_at'] = '2026-10-30T13:00:00Z';
    $payload['financial_status'] = 'partially_refunded';
    $payload['total_price'] = '30.25';
    $refund = ['id' => 'refund-1', 'transactions' => [
        ['id' => 'transaction-1', 'kind' => 'refund', 'status' => 'success', 'amount' => '5.00', 'currency' => 'USD'],
        ['id' => 'transaction-2', 'kind' => 'refund', 'status' => 'success', 'amount' => '2.25', 'currency' => 'USD'],
        ['id' => 'pending', 'kind' => 'refund', 'status' => 'pending', 'amount' => '10.00', 'currency' => 'USD'],
    ]];
    $payload['refunds'] = [$refund, $refund]; // A repeated representation cannot double a refund.
    $service->ingest($store, $payload, ['dispatch_profile_sync' => false]);
    $snapshot = app(CustomerCommerceSnapshotService::class)->forProfile($profile, $profile->tenant_id);
    expect($snapshot['purchases'][0])->toMatchArray(['amount_minor' => 3025, 'refunded_minor' => 725, 'status' => 'partially_refunded']);

    $payload['updated_at'] = '2026-10-30T14:00:00Z';
    $payload['test'] = true;
    $service->ingest($store, $payload, ['dispatch_profile_sync' => false]);
    expect(app(CustomerCommerceSnapshotService::class)->forProfile($profile, $profile->tenant_id)['purchases'])->toBe([]);
});

test('contradictory order and tender customer associations hide the sale for both customers', function (): void {
    $profile = commerceCustomer('conflicting-tenders');
    $other = MarketingProfile::query()->create(['tenant_id' => $profile->tenant_id, 'first_name' => 'Other']);
    SquareOrder::query()->create(['tenant_id' => $profile->tenant_id, 'square_order_id' => 'conflicting-order',
        'state' => 'COMPLETED', 'closed_at' => now(), 'total_money_amount' => 2000, 'total_money_currency' => 'USD']);
    SquarePayment::query()->create(['tenant_id' => $profile->tenant_id, 'square_payment_id' => 'conflicting-payment',
        'square_order_id' => 'conflicting-order', 'status' => 'COMPLETED', 'amount_money' => 2000, 'currency' => 'USD']);
    commerceLink($profile, 'square_order', 'conflicting-order');
    commerceLink($other, 'square_payment', 'conflicting-payment');
    foreach ([$profile, $other] as $customer) {
        $snapshot = app(CustomerCommerceSnapshotService::class)->forProfile($customer, $profile->tenant_id);
        expect($snapshot['purchases'])->toBe([])
            ->and($snapshot['issues'])->toContain('customer_identity_review_required');
    }
});
