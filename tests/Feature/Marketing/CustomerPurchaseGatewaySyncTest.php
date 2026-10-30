<?php

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\CustomerPurchaseGatewaySyncService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Fictional customer history', 'slug' => 'history-merchant']);
    $this->actor = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $this->actor->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    $this->profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Fictional', 'email' => 'customer@example.test']);
    $this->store = ShopifyStore::create(['tenant_id' => $this->tenant->id, 'store_key' => 'retail', 'shop_domain' => 'fictional-history.myshopify.com', 'access_token' => 'fictional-only-token']);
    $this->order = Order::create(['tenant_id' => $this->tenant->id, 'shopify_store_key' => 'retail', 'shopify_order_id' => 123,
        'shopify_customer_id' => '456', 'source' => 'shopify', 'order_type' => 'retail', 'status' => 'fulfilled', 'total_price' => '25.00',
        'attribution_meta' => ['existing_evidence' => 'preserved'], 'ordered_at' => now()]);
    foreach ([['order', (string) $this->order->id], ['shopify_customer', 'retail:456']] as [$type, $id]) {
        MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile->id, 'source_type' => $type, 'source_id' => $id]);
    }
    $this->service = app(CustomerPurchaseGatewaySyncService::class);
    $this->defect = null;
    Http::fake(function ($request) {
        if ($this->defect === 'credential') {
            $this->store->update(['access_token' => 'rotated-token']);
        }
        $row = ['id' => 'gid://shopify/Order/123', 'updatedAt' => '2026-10-30T12:00:00Z', 'customer' => ['id' => 'gid://shopify/Customer/456'],
            'paymentGatewayNames' => ['shopify_payments'], 'transactions' => [['gateway' => 'shopify_payments', 'kind' => 'SALE', 'status' => 'SUCCESS',
                'paymentDetails' => ['paymentMethodName' => 'shop_pay']]]];
        if ($this->defect === 'customer') {
            $row['customer']['id'] = 'gid://shopify/Customer/999';
        }
        if ($this->defect === 'failure') {
            return Http::response(['errors' => [['message' => 'Access denied']]], 200, ['X-Shopify-API-Version' => '2026-10']);
        }

        return Http::response(['data' => ['shop' => ['myshopifyDomain' => $this->store->shop_domain], 'order' => $row]], 200, ['X-Shopify-API-Version' => '2026-10']);
    });
});

test('historical gateway refresh previews first then writes only gateway metadata for the exact linked purchase', function () {
    $before = $this->order->fresh()->getAttributes();
    $preview = $this->service->refresh($this->tenant->id, $this->actor, $this->profile->id);
    expect($preview['status'])->toBe('preview_only')->and($preview['orders'][0]['gateways'])->toBe(['Shopify Payments', 'Shop Pay'])
        ->and($this->order->fresh()->getAttributes())->toBe($before);
    $result = $this->service->refresh($this->tenant->id, $this->actor, $this->profile->id, true);
    $after = $this->order->fresh()->getAttributes();
    expect($result['provider_mutations'])->toBeFalse()->and($result['customer_messages'])->toBeFalse()
        ->and($this->order->fresh()->attribution_meta['existing_evidence'])->toBe('preserved');
    unset($before['attribution_meta'], $after['attribution_meta']);
    expect($after)->toBe($before);
    Http::assertSent(fn ($request) => $request->url() === 'https://fictional-history.myshopify.com/admin/api/2026-10/graphql.json'
        && $request['query'] === CustomerPurchaseGatewaySyncService::QUERY && $request['variables']['id'] === 'gid://shopify/Order/123');
});

test('wrong customer failed reads and changed credentials cannot enrich history', function (string $defect) {
    $this->defect = $defect;
    expect(fn () => $this->service->refresh($this->tenant->id, $this->actor, $this->profile->id, true))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->order->fresh()->attribution_meta)->toBe(['existing_evidence' => 'preserved']);
})->with(['customer', 'failure', 'credential']);

test('gateway refresh rejects another tenant or unlinked source customer before provider reads', function () {
    $other = Tenant::create(['name' => 'Other history', 'slug' => 'other-history']);
    expect(fn () => $this->service->refresh($other->id, $this->actor, $this->profile->id, true))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    MarketingProfileLink::where('source_type', 'shopify_customer')->delete();
    expect(fn () => $this->service->refresh($this->tenant->id, $this->actor, $this->profile->id, true))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});
