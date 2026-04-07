<?php

use App\Models\CustomerExternalProfile;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingConsentRequest;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config()->set('queue.default', 'sync');
    config()->set('services.shopify.allow_env_token_fallback', false);
    config()->set('services.shopify.api_version', '2026-01');
    config()->set('services.shopify.stores.retail.shop', 'retail-test.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'retail-client');
    config()->set('services.shopify.stores.retail.client_secret', 'retail-secret');

    $tenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-tenant',
    ]);

    $this->tenantId = (int) $tenant->id;

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'tenant_id' => $this->tenantId,
            'shop_domain' => 'retail-test.myshopify.com',
            'access_token' => 'retail-token',
            'scopes' => 'read_customers,write_customers,read_orders',
            'installed_at' => now(),
        ]
    );
});

test('customers create webhook with existing linked profile does not duplicate canonical records', function (): void {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenantId,
        'first_name' => 'Existing',
        'last_name' => 'Linked',
        'email' => 'existing.linked@example.com',
        'normalized_email' => 'existing.linked@example.com',
    ]);

    MarketingProfileLink::query()->create([
        'tenant_id' => $this->tenantId,
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:9001',
        'source_meta' => ['shopify_store_key' => 'retail'],
        'match_method' => 'seed',
        'confidence' => 1.00,
    ]);

    postShopifyCustomerWebhook($this, '/webhooks/shopify/customers/create', shopifyCustomerPayload([
        'id' => 9001,
        'email' => 'existing.linked@example.com',
        'first_name' => 'Existing',
        'last_name' => 'Linked',
    ]))->assertOk();

    expect(MarketingProfile::query()->forTenantId($this->tenantId)->count())->toBe(1)
        ->and(MarketingProfileLink::query()
            ->forTenantId($this->tenantId)
            ->where('source_type', 'shopify_customer')
            ->where('source_id', 'retail:9001')
            ->count())->toBe(1)
        ->and(CustomerExternalProfile::query()
            ->forTenantId($this->tenantId)
            ->where('provider', 'shopify')
            ->where('integration', 'shopify_customer')
            ->where('store_key', 'retail')
            ->where('external_customer_id', '9001')
            ->where('marketing_profile_id', $profile->id)
            ->count())->toBe(1)
        ->and(MarketingConsentRequest::query()->count())->toBe(0)
        ->and(MarketingConsentEvent::query()->count())->toBe(0);
});

test('customers create webhook links to existing canonical profile matched by tenant-scoped identity', function (): void {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenantId,
        'first_name' => 'Matched',
        'last_name' => 'Profile',
        'email' => 'matched.profile@example.com',
        'normalized_email' => 'matched.profile@example.com',
    ]);

    postShopifyCustomerWebhook($this, '/webhooks/shopify/customers/create', shopifyCustomerPayload([
        'id' => 9002,
        'email' => 'matched.profile@example.com',
        'first_name' => 'Matched',
        'last_name' => 'Profile',
    ]))->assertOk();

    expect(MarketingProfile::query()->forTenantId($this->tenantId)->count())->toBe(1)
        ->and(MarketingProfileLink::query()
            ->forTenantId($this->tenantId)
            ->where('source_type', 'shopify_customer')
            ->where('source_id', 'retail:9002')
            ->value('marketing_profile_id'))->toBe((int) $profile->id)
        ->and(CustomerExternalProfile::query()
            ->forTenantId($this->tenantId)
            ->where('store_key', 'retail')
            ->where('external_customer_id', '9002')
            ->value('marketing_profile_id'))->toBe((int) $profile->id);
});

test('customers create webhook creates canonical profile when no tenant-scoped match exists', function (): void {
    postShopifyCustomerWebhook($this, '/webhooks/shopify/customers/create', shopifyCustomerPayload([
        'id' => 9003,
        'email' => 'new.customer@example.com',
        'first_name' => 'New',
        'last_name' => 'Customer',
        'phone' => '+15556667777',
    ]))->assertOk();

    $profile = MarketingProfile::query()
        ->forTenantId($this->tenantId)
        ->where('normalized_email', 'new.customer@example.com')
        ->first();

    expect($profile)->not->toBeNull()
        ->and(MarketingProfileLink::query()
            ->forTenantId($this->tenantId)
            ->where('source_type', 'shopify_customer')
            ->where('source_id', 'retail:9003')
            ->value('marketing_profile_id'))->toBe((int) $profile?->id)
        ->and(CustomerExternalProfile::query()
            ->forTenantId($this->tenantId)
            ->where('store_key', 'retail')
            ->where('external_customer_id', '9003')
            ->where('marketing_profile_id', $profile?->id)
            ->exists())->toBeTrue();
});

test('customers create webhook promotes matching legacy source-linked profile into tenant scope instead of duplicating it', function (): void {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => null,
        'first_name' => 'Legacy',
        'last_name' => 'Linked',
        'email' => 'legacy.linked@example.com',
        'normalized_email' => 'legacy.linked@example.com',
    ]);

    MarketingProfileLink::query()->create([
        'tenant_id' => null,
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:9007',
        'source_meta' => ['shopify_store_key' => 'retail'],
        'match_method' => 'legacy_seed',
        'confidence' => 1.00,
    ]);

    postShopifyCustomerWebhook($this, '/webhooks/shopify/customers/create', shopifyCustomerPayload([
        'id' => 9007,
        'email' => 'legacy.linked@example.com',
        'first_name' => 'Legacy',
        'last_name' => 'Linked',
    ]))->assertOk();

    $profile->refresh();

    expect(MarketingProfile::query()->count())->toBe(1)
        ->and((int) $profile->tenant_id)->toBe((int) $this->tenantId)
        ->and(MarketingProfileLink::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('tenant_id', $this->tenantId)
            ->where('source_type', 'shopify_customer')
            ->where('source_id', 'retail:9007')
            ->count())->toBe(1)
        ->and(MarketingProfileLink::query()
            ->where('marketing_profile_id', $profile->id)
            ->whereNull('tenant_id')
            ->where('source_type', 'shopify_customer')
            ->where('source_id', 'retail:9007')
            ->count())->toBe(0)
        ->and(CustomerExternalProfile::query()
            ->forTenantId($this->tenantId)
            ->where('store_key', 'retail')
            ->where('external_customer_id', '9007')
            ->value('marketing_profile_id'))->toBe((int) $profile->id);
});

test('customers create webhook promotes matching legacy exact-email profile into tenant scope instead of creating a duplicate', function (): void {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => null,
        'first_name' => 'Legacy',
        'last_name' => 'Email',
        'email' => 'legacy.email@example.com',
        'normalized_email' => 'legacy.email@example.com',
    ]);

    postShopifyCustomerWebhook($this, '/webhooks/shopify/customers/create', shopifyCustomerPayload([
        'id' => 9008,
        'email' => 'legacy.email@example.com',
        'first_name' => 'Legacy',
        'last_name' => 'Email',
    ]))->assertOk();

    $profile->refresh();

    expect(MarketingProfile::query()->count())->toBe(1)
        ->and((int) $profile->tenant_id)->toBe((int) $this->tenantId)
        ->and(MarketingProfileLink::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('tenant_id', $this->tenantId)
            ->where('source_type', 'shopify_customer')
            ->where('source_id', 'retail:9008')
            ->count())->toBe(1)
        ->and(CustomerExternalProfile::query()
            ->forTenantId($this->tenantId)
            ->where('store_key', 'retail')
            ->where('external_customer_id', '9008')
            ->value('marketing_profile_id'))->toBe((int) $profile->id);
});

test('repeated customers create webhook delivery is idempotent', function (): void {
    $payload = shopifyCustomerPayload([
        'id' => 9004,
        'email' => 'idempotent.customer@example.com',
        'first_name' => 'Idempotent',
        'last_name' => 'Customer',
    ]);

    postShopifyCustomerWebhook($this, '/webhooks/shopify/customers/create', $payload)->assertOk();
    postShopifyCustomerWebhook($this, '/webhooks/shopify/customers/create', $payload)->assertOk();

    expect(MarketingProfile::query()
        ->forTenantId($this->tenantId)
        ->where('normalized_email', 'idempotent.customer@example.com')
        ->count())->toBe(1)
        ->and(MarketingProfileLink::query()
            ->forTenantId($this->tenantId)
            ->where('source_type', 'shopify_customer')
            ->where('source_id', 'retail:9004')
            ->count())->toBe(1)
        ->and(CustomerExternalProfile::query()
            ->forTenantId($this->tenantId)
            ->where('provider', 'shopify')
            ->where('integration', 'shopify_customer')
            ->where('store_key', 'retail')
            ->where('external_customer_id', '9004')
            ->count())->toBe(1);
});

test('customers create webhook respects tenant and store isolation during matching', function (): void {
    config()->set('services.shopify.stores.wholesale.shop', 'wholesale-test.myshopify.com');
    config()->set('services.shopify.stores.wholesale.client_id', 'wholesale-client');
    config()->set('services.shopify.stores.wholesale.client_secret', 'wholesale-secret');

    $tenantTwo = Tenant::query()->create([
        'name' => 'Wholesale Tenant',
        'slug' => 'wholesale-tenant',
    ]);

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'wholesale'],
        [
            'tenant_id' => (int) $tenantTwo->id,
            'shop_domain' => 'wholesale-test.myshopify.com',
            'access_token' => 'wholesale-token',
            'scopes' => 'read_customers,write_customers',
            'installed_at' => now(),
        ]
    );

    $otherTenantProfile = MarketingProfile::query()->create([
        'tenant_id' => (int) $tenantTwo->id,
        'first_name' => 'Shared',
        'last_name' => 'Identity',
        'email' => 'shared.identity@example.com',
        'normalized_email' => 'shared.identity@example.com',
    ]);

    postShopifyCustomerWebhook(
        $this,
        '/webhooks/shopify/customers/create',
        shopifyCustomerPayload([
            'id' => 9005,
            'email' => 'shared.identity@example.com',
            'first_name' => 'Shared',
            'last_name' => 'Identity',
        ]),
        'retail-test.myshopify.com',
        'retail-secret'
    )->assertOk();

    $retailProfile = MarketingProfile::query()
        ->forTenantId($this->tenantId)
        ->where('normalized_email', 'shared.identity@example.com')
        ->first();

    expect($retailProfile)->not->toBeNull()
        ->and((int) $retailProfile?->id)->not->toBe((int) $otherTenantProfile->id)
        ->and(MarketingProfileLink::query()
            ->forTenantId($this->tenantId)
            ->where('source_type', 'shopify_customer')
            ->where('source_id', 'retail:9005')
            ->value('marketing_profile_id'))->toBe((int) $retailProfile?->id)
        ->and(MarketingProfileLink::query()
            ->forTenantId((int) $tenantTwo->id)
            ->where('source_type', 'shopify_customer')
            ->where('source_id', 'retail:9005')
            ->count())->toBe(0);
});

test('customers create webhook fails safely when tenant cannot be resolved', function (): void {
    ShopifyStore::query()->where('store_key', 'retail')->update([
        'tenant_id' => null,
    ]);

    postShopifyCustomerWebhook($this, '/webhooks/shopify/customers/create', shopifyCustomerPayload([
        'id' => 9006,
        'email' => 'unresolved.tenant@example.com',
    ]))->assertStatus(202);

    expect(MarketingProfile::query()->count())->toBe(0)
        ->and(MarketingProfileLink::query()->count())->toBe(0)
        ->and(CustomerExternalProfile::query()->count())->toBe(0);
});

test('customers update webhook refreshes external snapshot and preserves richer canonical profile fields', function (): void {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenantId,
        'first_name' => 'Preferred',
        'last_name' => 'Customer',
        'email' => 'update.customer@example.com',
        'normalized_email' => 'update.customer@example.com',
        'phone' => '+15554443333',
        'normalized_phone' => '5554443333',
    ]);

    MarketingProfileLink::query()->create([
        'tenant_id' => $this->tenantId,
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:9010',
        'source_meta' => ['shopify_store_key' => 'retail'],
        'match_method' => 'seed',
        'confidence' => 1.00,
    ]);

    CustomerExternalProfile::query()->create([
        'tenant_id' => $this->tenantId,
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'shopify_customer',
        'store_key' => 'retail',
        'external_customer_id' => '9010',
        'external_customer_gid' => 'gid://shopify/Customer/9010',
        'first_name' => 'Preferred',
        'last_name' => 'Customer',
        'full_name' => 'Preferred Customer',
        'email' => 'update.customer@example.com',
        'normalized_email' => 'update.customer@example.com',
        'phone' => '+15554443333',
        'normalized_phone' => '5554443333',
        'accepts_marketing' => true,
        'source_channels' => ['shopify', 'online'],
        'synced_at' => now()->subDay(),
    ]);

    postShopifyCustomerWebhook($this, '/webhooks/shopify/customers/update', shopifyCustomerPayload([
        'id' => 9010,
        'email' => 'update.customer@example.com',
        'first_name' => null,
        'last_name' => null,
        'phone' => null,
        'accepts_marketing' => false,
        'updated_at' => '2026-03-18T15:22:00Z',
    ]))->assertOk();

    $profile->refresh();
    $external = CustomerExternalProfile::query()
        ->forTenantId($this->tenantId)
        ->where('provider', 'shopify')
        ->where('integration', 'shopify_customer')
        ->where('store_key', 'retail')
        ->where('external_customer_id', '9010')
        ->first();

    expect($profile->first_name)->toBe('Preferred')
        ->and($profile->phone)->toBe('+15554443333')
        ->and($external)->not->toBeNull()
        ->and((int) $external?->marketing_profile_id)->toBe((int) $profile->id)
        ->and($external?->first_name)->toBe('Preferred')
        ->and($external?->phone)->toBe('+15554443333')
        ->and($external?->accepts_marketing)->toBeFalse()
        ->and(data_get($external?->raw_metafields, 'shopify_customer_webhook.topic'))->toBe('customers/update')
        ->and(MarketingConsentRequest::query()->count())->toBe(0)
        ->and(MarketingConsentEvent::query()->count())->toBe(0);
});

test('customers create webhook with unknown store fails safely', function (): void {
    postShopifyCustomerWebhook(
        $this,
        '/webhooks/shopify/customers/create',
        shopifyCustomerPayload(['id' => 9011, 'email' => 'unknown.shop@example.com']),
        'unknown-shop.myshopify.com',
        'retail-secret'
    )->assertStatus(404);

    expect(MarketingProfile::query()->count())->toBe(0)
        ->and(MarketingProfileLink::query()->count())->toBe(0)
        ->and(CustomerExternalProfile::query()->count())->toBe(0);
});

function postShopifyCustomerWebhook(
    \Illuminate\Foundation\Testing\TestCase $testCase,
    string $path,
    array $payload,
    string $shopDomain = 'retail-test.myshopify.com',
    string $secret = 'retail-secret'
): TestResponse {
    $encoded = json_encode($payload);
    $hmac = base64_encode(hash_hmac('sha256', (string) $encoded, $secret, true));

    /** @var TestResponse $response */
    $response = $testCase->call(
        'POST',
        $path,
        [],
        [],
        [],
        [
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shopDomain,
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'CONTENT_TYPE' => 'application/json',
        ],
        (string) $encoded
    );

    return $response;
}

/**
 * @param  array<string,mixed>  $overrides
 * @return array<string,mixed>
 */
function shopifyCustomerPayload(array $overrides = []): array
{
    $id = (int) ($overrides['id'] ?? 9000);
    $email = (string) ($overrides['email'] ?? "customer{$id}@example.com");

    return array_replace([
        'id' => $id,
        'email' => $email,
        'first_name' => $overrides['first_name'] ?? 'Shopify',
        'last_name' => $overrides['last_name'] ?? 'Customer',
        'phone' => $overrides['phone'] ?? '+15550001111',
        'accepts_marketing' => $overrides['accepts_marketing'] ?? true,
        'orders_count' => $overrides['orders_count'] ?? 0,
        'created_at' => $overrides['created_at'] ?? '2026-03-18T10:00:00Z',
        'updated_at' => $overrides['updated_at'] ?? '2026-03-18T10:00:00Z',
        'tags' => $overrides['tags'] ?? 'backstage-webhook',
        'verified_email' => $overrides['verified_email'] ?? true,
        'email_marketing_consent' => $overrides['email_marketing_consent'] ?? ['state' => 'subscribed'],
        'sms_marketing_consent' => $overrides['sms_marketing_consent'] ?? ['state' => 'not_subscribed'],
        'admin_graphql_api_id' => $overrides['admin_graphql_api_id'] ?? "gid://shopify/Customer/{$id}",
    ], $overrides);
}
