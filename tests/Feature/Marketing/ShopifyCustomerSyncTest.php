<?php

use App\Models\CustomerExternalProfile;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfileLink;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.shopify.api_version', '2026-01');
    config()->set('services.shopify.stores.retail.shop', 'retail-test.myshopify.com');
    config()->set('services.shopify.stores.wholesale.shop', null);
    config()->set('services.shopify.allow_env_token_fallback', false);

    $tenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-tenant',
    ]);

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'tenant_id' => (int) $tenant->id,
            'shop_domain' => 'retail-test.myshopify.com',
            'access_token' => 'retail-token',
            'scopes' => 'read_orders,read_all_orders,read_customers',
            'installed_at' => now(),
        ]
    );
});

test('shopify customer sync backfills canonical links and shopify customer metrics', function () {
    Http::fake([
        'https://retail-test.myshopify.com/admin/api/2026-01/customers.json*' => Http::response([
            'customers' => [[
                'id' => 7577017156,
                'admin_graphql_api_id' => 'gid://shopify/Customer/7577017156',
                'email' => 'bakery25@gmail.com',
                'first_name' => 'Rynda',
                'last_name' => 'Baker',
                'phone' => '+15555550101',
                'orders_count' => 178,
                'total_spent' => '7144.22',
                'accepts_marketing' => true,
                'email_marketing_consent' => ['state' => 'subscribed'],
                'sms_marketing_consent' => ['state' => 'not_subscribed'],
                'created_at' => '2017-09-01T00:00:00Z',
                'updated_at' => '2026-04-07T10:00:00Z',
                'tags' => 'retail',
                'verified_email' => true,
            ]],
        ], 200),
    ]);

    $this->artisan('shopify:sync-customers retail --limit=10')
        ->expectsOutputToContain('status=completed')
        ->expectsOutputToContain('processed=1')
        ->assertExitCode(0);

    $external = CustomerExternalProfile::query()
        ->where('provider', 'shopify')
        ->where('integration', 'shopify_customer')
        ->where('store_key', 'retail')
        ->where('external_customer_id', '7577017156')
        ->first();

    expect($external)->not->toBeNull()
        ->and((int) $external?->order_count)->toBe(178)
        ->and((string) $external?->total_spent)->toBe('7144.22')
        ->and(MarketingProfileLink::query()
            ->where('source_type', 'shopify_customer')
            ->where('source_id', 'retail:7577017156')
            ->exists())->toBeTrue()
        ->and(MarketingImportRun::query()
            ->where('type', 'shopify_customers_sync')
            ->where('status', 'completed')
            ->exists())->toBeTrue();
});
