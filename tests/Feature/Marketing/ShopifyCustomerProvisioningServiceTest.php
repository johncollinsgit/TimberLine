<?php

use App\Jobs\ProvisionShopifyCustomerForMarketingProfile;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Services\Marketing\ShopifyCustomerProvisioningService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('services.shopify.api_version', '2026-01');
    config()->set('services.shopify.allow_env_token_fallback', false);
    config()->set('services.shopify.stores.retail.shop', 'retail-test.myshopify.com');

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
            'scopes' => 'read_orders,read_products,read_customers,write_customers',
            'installed_at' => now(),
        ]
    );
});

test('existing Shopify link for profile and store is idempotent and does not call Shopify', function (): void {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenantId,
        'email' => 'linked@example.com',
        'normalized_email' => 'linked@example.com',
    ]);

    MarketingProfileLink::query()->create([
        'tenant_id' => $this->tenantId,
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:1001',
        'source_meta' => ['shopify_store_key' => 'retail'],
        'match_method' => 'seed',
        'confidence' => 1.00,
    ]);

    Http::fake();

    $result = app(ShopifyCustomerProvisioningService::class)->provisionForProfile($profile, [
        'store_key' => 'retail',
        'tenant_id' => $this->tenantId,
    ]);

    expect($result['status'])->toBe('linked_existing_profile_link')
        ->and($result['shopify_customer_id'])->toBe('1001')
        ->and(MarketingProfileLink::query()
            ->where('tenant_id', $this->tenantId)
            ->where('source_type', 'shopify_customer')
            ->where('source_id', 'retail:1001')
            ->count())->toBe(1);

    Http::assertNothingSent();
});

test('existing Shopify customer found by email is linked instead of created', function (): void {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenantId,
        'first_name' => 'Remote',
        'last_name' => 'Existing',
        'email' => 'remote.existing@example.com',
        'normalized_email' => 'remote.existing@example.com',
    ]);

    Http::fake([
        'https://retail-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyLookupPayload([
            ['id' => 'gid://shopify/Customer/2002', 'legacyResourceId' => '2002', 'email' => 'remote.existing@example.com', 'firstName' => 'Remote', 'lastName' => 'Existing'],
        ]), 200),
    ]);

    $result = app(ShopifyCustomerProvisioningService::class)->provisionForProfile($profile, [
        'store_key' => 'retail',
        'tenant_id' => $this->tenantId,
    ]);

    expect($result['status'])->toBe('linked_existing_remote_customer')
        ->and($result['shopify_customer_id'])->toBe('2002')
        ->and(MarketingProfileLink::query()
            ->where('tenant_id', $this->tenantId)
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'shopify_customer')
            ->where('source_id', 'retail:2002')
            ->exists())->toBeTrue()
        ->and(CustomerExternalProfile::query()
            ->where('tenant_id', $this->tenantId)
            ->where('marketing_profile_id', $profile->id)
            ->where('provider', 'shopify')
            ->where('integration', 'shopify_customer')
            ->where('store_key', 'retail')
            ->where('external_customer_id', '2002')
            ->exists())->toBeTrue();
});

test('existing local external profile by email is linked before remote lookup', function (): void {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenantId,
        'first_name' => 'Local',
        'last_name' => 'Snapshot',
        'email' => 'local.snapshot@example.com',
        'normalized_email' => 'local.snapshot@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'tenant_id' => $this->tenantId,
        'marketing_profile_id' => null,
        'provider' => 'shopify',
        'integration' => 'shopify_customer',
        'store_key' => 'retail',
        'external_customer_id' => '2112',
        'external_customer_gid' => 'gid://shopify/Customer/2112',
        'email' => 'local.snapshot@example.com',
        'normalized_email' => 'local.snapshot@example.com',
        'first_name' => 'Local',
        'last_name' => 'Snapshot',
        'source_channels' => ['shopify'],
        'synced_at' => now(),
    ]);

    Http::fake();

    $result = app(ShopifyCustomerProvisioningService::class)->provisionForProfile($profile, [
        'store_key' => 'retail',
        'tenant_id' => $this->tenantId,
    ]);

    expect($result['status'])->toBe('linked_existing_external_profile')
        ->and(MarketingProfileLink::query()
            ->where('tenant_id', $this->tenantId)
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'shopify_customer')
            ->where('source_id', 'retail:2112')
            ->exists())->toBeTrue()
        ->and(CustomerExternalProfile::query()
            ->where('tenant_id', $this->tenantId)
            ->where('provider', 'shopify')
            ->where('integration', 'shopify_customer')
            ->where('store_key', 'retail')
            ->where('external_customer_id', '2112')
            ->where('marketing_profile_id', $profile->id)
            ->exists())->toBeTrue();

    Http::assertNothingSent();
});

test('missing Shopify customer is created and linked to canonical profile', function (): void {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenantId,
        'first_name' => 'Create',
        'last_name' => 'Needed',
        'email' => 'create.needed@example.com',
        'normalized_email' => 'create.needed@example.com',
    ]);

    Http::fake([
        'https://retail-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::sequence()
            ->push(shopifyLookupPayload([]), 200)
            ->push([
                'data' => [
                    'customerCreate' => [
                        'customer' => [
                            'id' => 'gid://shopify/Customer/3003',
                            'legacyResourceId' => '3003',
                            'email' => 'create.needed@example.com',
                            'firstName' => 'Create',
                            'lastName' => 'Needed',
                            'phone' => null,
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200),
    ]);

    $result = app(ShopifyCustomerProvisioningService::class)->provisionForProfile($profile, [
        'store_key' => 'retail',
        'tenant_id' => $this->tenantId,
    ]);

    expect($result['status'])->toBe('created_remote_customer')
        ->and($result['shopify_customer_id'])->toBe('3003')
        ->and(MarketingProfileLink::query()
            ->where('tenant_id', $this->tenantId)
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'shopify_customer')
            ->where('source_id', 'retail:3003')
            ->exists())->toBeTrue();
});

test('create duplicate race falls back to lookup and links safely', function (): void {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenantId,
        'first_name' => 'Race',
        'last_name' => 'Condition',
        'email' => 'race.condition@example.com',
        'normalized_email' => 'race.condition@example.com',
    ]);

    Http::fake([
        'https://retail-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::sequence()
            ->push(shopifyLookupPayload([]), 200)
            ->push([
                'data' => [
                    'customerCreate' => [
                        'customer' => null,
                        'userErrors' => [
                            ['message' => 'Email has already been taken'],
                        ],
                    ],
                ],
            ], 200)
            ->push(shopifyLookupPayload([
                ['id' => 'gid://shopify/Customer/3010', 'legacyResourceId' => '3010', 'email' => 'race.condition@example.com', 'firstName' => 'Race', 'lastName' => 'Condition'],
            ]), 200),
    ]);

    $result = app(ShopifyCustomerProvisioningService::class)->provisionForProfile($profile, [
        'store_key' => 'retail',
        'tenant_id' => $this->tenantId,
    ]);

    expect($result['status'])->toBe('linked_existing_remote_customer')
        ->and($result['shopify_customer_id'])->toBe('3010')
        ->and(MarketingProfileLink::query()
            ->where('tenant_id', $this->tenantId)
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'shopify_customer')
            ->where('source_id', 'retail:3010')
            ->exists())->toBeTrue();
});

test('missing store context skips provisioning safely', function (): void {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenantId,
        'email' => 'no.store@example.com',
        'normalized_email' => 'no.store@example.com',
    ]);

    Http::fake();

    $result = app(ShopifyCustomerProvisioningService::class)->provisionForProfile($profile, [
        'store_key' => null,
        'tenant_id' => $this->tenantId,
    ]);

    expect($result['status'])->toBe('skipped_store_context_missing')
        ->and(MarketingProfileLink::query()->count())->toBe(0)
        ->and(CustomerExternalProfile::query()->count())->toBe(0);

    Http::assertNothingSent();
});

test('missing tenant context skips provisioning safely', function (): void {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => null,
        'email' => 'no.tenant@example.com',
        'normalized_email' => 'no.tenant@example.com',
    ]);

    Http::fake();

    $result = app(ShopifyCustomerProvisioningService::class)->provisionForProfile($profile, [
        'store_key' => 'retail',
        'tenant_id' => null,
    ]);

    expect($result['status'])->toBe('skipped_tenant_context_missing')
        ->and(MarketingProfileLink::query()->count())->toBe(0)
        ->and(CustomerExternalProfile::query()->count())->toBe(0);

    Http::assertNothingSent();
});

test('provisioning failure does not invalidate canonical profile record', function (): void {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenantId,
        'first_name' => 'Failure',
        'email' => 'failure.path@example.com',
        'normalized_email' => 'failure.path@example.com',
    ]);

    Http::fake([
        'https://retail-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::sequence()
            ->push(shopifyLookupPayload([]), 200)
            ->push([
                'data' => [
                    'customerCreate' => [
                        'customer' => null,
                        'userErrors' => [
                            ['message' => 'Access denied'],
                        ],
                    ],
                ],
            ], 200),
    ]);

    try {
        app(ShopifyCustomerProvisioningService::class)->provisionForProfile($profile, [
            'store_key' => 'retail',
            'tenant_id' => $this->tenantId,
        ]);
        $this->fail('Expected provisioning to throw when Shopify rejects customer creation.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Shopify customerCreate failed');
    }

    expect(MarketingProfile::query()->whereKey($profile->id)->exists())->toBeTrue()
        ->and(MarketingProfileLink::query()->count())->toBe(0)
        ->and(CustomerExternalProfile::query()->count())->toBe(0);
});

test('tenant boundaries are respected for local external lookup before linking', function (): void {
    $tenantTwo = Tenant::query()->create([
        'name' => 'Wholesale Tenant',
        'slug' => 'wholesale-tenant',
    ]);

    $profileTenantOne = MarketingProfile::query()->create([
        'tenant_id' => $this->tenantId,
        'email' => 'shared@example.com',
        'normalized_email' => 'shared@example.com',
    ]);

    $profileTenantTwo = MarketingProfile::query()->create([
        'tenant_id' => $tenantTwo->id,
        'email' => 'shared@example.com',
        'normalized_email' => 'shared@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'tenant_id' => $tenantTwo->id,
        'marketing_profile_id' => $profileTenantTwo->id,
        'provider' => 'shopify',
        'integration' => 'shopify_customer',
        'store_key' => 'retail',
        'external_customer_id' => '4999',
        'external_customer_gid' => 'gid://shopify/Customer/4999',
        'email' => 'shared@example.com',
        'normalized_email' => 'shared@example.com',
        'source_channels' => ['shopify'],
        'synced_at' => now(),
    ]);

    Http::fake([
        'https://retail-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::sequence()
            ->push(shopifyLookupPayload([]), 200)
            ->push([
                'data' => [
                    'customerCreate' => [
                        'customer' => [
                            'id' => 'gid://shopify/Customer/5005',
                            'legacyResourceId' => '5005',
                            'email' => 'shared@example.com',
                            'firstName' => null,
                            'lastName' => null,
                            'phone' => null,
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200),
    ]);

    $result = app(ShopifyCustomerProvisioningService::class)->provisionForProfile($profileTenantOne, [
        'store_key' => 'retail',
        'tenant_id' => $this->tenantId,
    ]);

    expect($result['status'])->toBe('created_remote_customer')
        ->and($result['shopify_customer_id'])->toBe('5005')
        ->and(MarketingProfileLink::query()
            ->where('tenant_id', $this->tenantId)
            ->where('marketing_profile_id', $profileTenantOne->id)
            ->where('source_type', 'shopify_customer')
            ->where('source_id', 'retail:5005')
            ->exists())->toBeTrue()
        ->and(MarketingProfileLink::query()
            ->where('tenant_id', $tenantTwo->id)
            ->where('marketing_profile_id', $profileTenantTwo->id)
            ->where('source_type', 'shopify_customer')
            ->count())->toBe(0);
});

test('consent opt-in flow queues provisioning when store context is known', function (): void {
    Queue::fake();

    $response = $this->post(route('marketing.consent.optin.store'), [
        'email' => 'queue.provision@example.com',
        'phone' => '5558883333',
        'first_name' => 'Queue',
        'last_name' => 'Provision',
        'store_key' => 'retail',
        'award_bonus' => 1,
    ]);

    $response->assertRedirect();

    Queue::assertPushed(ProvisionShopifyCustomerForMarketingProfile::class, function (ProvisionShopifyCustomerForMarketingProfile $job): bool {
        return $job->storeKey === 'retail' && (int) ($job->tenantId ?? 0) === (int) $this->tenantId;
    });
});

test('consent opt-in flow does not queue provisioning when tenant context is missing', function (): void {
    Queue::fake();

    $response = $this->post(route('marketing.consent.optin.store'), [
        'email' => 'queue.skip@example.com',
        'phone' => '5551113333',
        'first_name' => 'Queue',
        'last_name' => 'Skip',
        'store_key' => 'unknown-store',
    ]);

    $response->assertRedirect();

    Queue::assertNotPushed(ProvisionShopifyCustomerForMarketingProfile::class);
});

/**
 * @param  array<int,array<string,mixed>>  $customers
 * @return array<string,mixed>
 */
function shopifyLookupPayload(array $customers): array
{
    return [
        'data' => [
            'customers' => [
                'edges' => array_map(static fn (array $customer): array => [
                    'node' => [
                        'id' => $customer['id'] ?? null,
                        'legacyResourceId' => $customer['legacyResourceId'] ?? null,
                        'email' => $customer['email'] ?? null,
                        'firstName' => $customer['firstName'] ?? null,
                        'lastName' => $customer['lastName'] ?? null,
                        'phone' => $customer['phone'] ?? null,
                    ],
                ], $customers),
            ],
        ],
    ];
}
