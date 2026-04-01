<?php

use App\Models\CandleCashTaskCompletion;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileWishlistItem;
use App\Models\MarketingWishlistList;
use App\Models\ShopifyStore;
use App\Models\Tenant;

test('shopify wishlist status returns guest payload when identity is missing', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'wishlist-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'wishlist-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    configureWishlistStorefrontStores();

    $query = wishlistSignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'product_id' => 'wsku-100',
        'product_handle' => 'forest-glow',
    ], 'wishlist-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.wishlist.status', $query))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.profile_id', null)
        ->assertJsonPath('data.viewer.state', 'guest_ready')
        ->assertJsonPath('data.summary.active_count', 0)
        ->assertJsonPath('data.items', [])
        ->assertJsonPath('data.recent_items', [])
        ->assertJsonPath('data.product.in_wishlist', false);
});

test('shopify wishlist add remove and status remain idempotent for the canonical marketing profile', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'wishlist-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'wishlist-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    configureWishlistStorefrontStores();

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Willow',
        'last_name' => 'North',
        'email' => 'willow.north@example.com',
        'normalized_email' => 'willow.north@example.com',
    ]);

    $payload = [
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'email' => $profile->email,
        'product_id' => 'wsku-101',
        'product_handle' => 'cedar-glow',
        'product_title' => 'Cedar Glow',
        'product_url' => '/products/cedar-glow',
        'request_key' => 'wishlist-add-cedar-glow',
    ];

    $this->postJson(route('marketing.shopify.v1.wishlist.add', wishlistSignedQuery([
        'shop' => $payload['shop'],
        'timestamp' => $payload['timestamp'],
    ], 'wishlist-proxy-secret')), $payload)
        ->assertOk()
        ->assertJsonPath('data.state', 'wishlist_added')
        ->assertJsonPath('data.product.in_wishlist', true)
        ->assertJsonPath('data.summary.active_count', 1);

    $item = MarketingProfileWishlistItem::query()->where([
        'marketing_profile_id' => $profile->id,
        'store_key' => 'retail',
        'product_id' => 'wsku-101',
    ])->first();

    expect($item)->not->toBeNull()
        ->and($item->provider)->toBe('backstage')
        ->and($item->integration)->toBe('native')
        ->and($item->status)->toBe(MarketingProfileWishlistItem::STATUS_ACTIVE)
        ->and(MarketingProfile::query()->count())->toBe(1);

    $this->postJson(route('marketing.shopify.v1.wishlist.add', wishlistSignedQuery([
        'shop' => $payload['shop'],
        'timestamp' => (string) (time() + 1),
    ], 'wishlist-proxy-secret')), $payload)
        ->assertOk()
        ->assertJsonPath('data.state', 'wishlist_already_saved')
        ->assertJsonPath('data.summary.active_count', 1);

    expect(MarketingProfileWishlistItem::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('store_key', 'retail')
        ->where('product_id', 'wsku-101')
        ->count())->toBe(1)
        ->and(CandleCashTaskCompletion::query()->count())->toBe(0);

    $statusQuery = wishlistSignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) (time() + 2),
        'email' => $profile->email,
        'product_id' => 'wsku-101',
        'product_handle' => 'cedar-glow',
    ], 'wishlist-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.wishlist.status', $statusQuery))
        ->assertOk()
        ->assertJsonPath('data.profile_id', $profile->id)
        ->assertJsonPath('data.product.in_wishlist', true)
        ->assertJsonPath('data.summary.active_count', 1)
        ->assertJsonPath('data.items.0.product_handle', 'cedar-glow');

    $removePayload = [
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) (time() + 3),
        'email' => $profile->email,
        'product_id' => 'wsku-101',
        'product_handle' => 'cedar-glow',
        'request_key' => 'wishlist-remove-cedar-glow',
    ];

    $this->postJson(route('marketing.shopify.v1.wishlist.remove', wishlistSignedQuery([
        'shop' => $removePayload['shop'],
        'timestamp' => $removePayload['timestamp'],
    ], 'wishlist-proxy-secret')), $removePayload)
        ->assertOk()
        ->assertJsonPath('data.state', 'wishlist_removed')
        ->assertJsonPath('data.product.in_wishlist', false)
        ->assertJsonPath('data.summary.active_count', 0);

    $this->postJson(route('marketing.shopify.v1.wishlist.remove', wishlistSignedQuery([
        'shop' => $removePayload['shop'],
        'timestamp' => (string) (time() + 4),
    ], 'wishlist-proxy-secret')), $removePayload)
        ->assertOk()
        ->assertJsonPath('data.state', 'wishlist_already_cleared')
        ->assertJsonPath('data.summary.active_count', 0);

    expect(MarketingProfileWishlistItem::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('store_key', 'retail')
        ->where('product_id', 'wsku-101')
        ->first())
        ->not->toBeNull()
        ->status->toBe(MarketingProfileWishlistItem::STATUS_REMOVED);
});

test('shopify wishlist guest token flows work end to end through the app proxy', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'wishlist-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'wishlist-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    configureWishlistStorefrontStores();

    $guestToken = 'guest-wishlist-token-123';
    $payload = [
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'guest_token' => $guestToken,
        'product_id' => 'wsku-guest-101',
        'product_handle' => 'guest-glow',
        'product_title' => 'Guest Glow',
        'product_url' => '/products/guest-glow',
        'request_key' => 'wishlist-guest-add-guest-glow',
    ];

    $this->postJson(route('marketing.shopify.v1.wishlist.add', wishlistSignedQuery([
        'shop' => $payload['shop'],
        'timestamp' => $payload['timestamp'],
    ], 'wishlist-proxy-secret')), $payload)
        ->assertOk()
        ->assertJsonPath('data.profile_id', null)
        ->assertJsonPath('data.guest_token', $guestToken)
        ->assertJsonPath('data.state', 'wishlist_added')
        ->assertJsonPath('data.product.in_wishlist', true)
        ->assertJsonPath('data.summary.active_count', 1);

    $item = MarketingProfileWishlistItem::query()->where([
        'guest_token' => $guestToken,
        'store_key' => 'retail',
        'product_id' => 'wsku-guest-101',
    ])->first();

    expect($item)->not->toBeNull()
        ->and($item->marketing_profile_id)->toBeNull()
        ->and($item->status)->toBe(MarketingProfileWishlistItem::STATUS_ACTIVE);

    $statusQuery = wishlistSignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) (time() + 1),
        'guest_token' => $guestToken,
        'product_id' => 'wsku-guest-101',
        'product_handle' => 'guest-glow',
    ], 'wishlist-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.wishlist.status', $statusQuery))
        ->assertOk()
        ->assertJsonPath('data.profile_id', null)
        ->assertJsonPath('data.guest_token', $guestToken)
        ->assertJsonPath('data.viewer.state', 'wishlist_ready')
        ->assertJsonPath('data.summary.active_count', 1)
        ->assertJsonPath('data.product.in_wishlist', true)
        ->assertJsonPath('data.items.0.product_handle', 'guest-glow');

    $this->postJson(route('marketing.shopify.v1.wishlist.lists.create', wishlistSignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) (time() + 2),
    ], 'wishlist-proxy-secret')), [
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) (time() + 2),
        'guest_token' => $guestToken,
        'name' => 'Weekend Burn',
    ])
        ->assertOk()
        ->assertJsonPath('data.profile_id', null)
        ->assertJsonPath('data.state', 'wishlist_list_created')
        ->assertJsonPath('data.list.name', 'Weekend Burn')
        ->assertJsonPath('data.guest_token', $guestToken);

    expect(MarketingWishlistList::query()
        ->where('guest_token', $guestToken)
        ->where('name', 'Weekend Burn')
        ->exists())->toBeTrue();

    $removePayload = [
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) (time() + 3),
        'guest_token' => $guestToken,
        'product_id' => 'wsku-guest-101',
        'product_handle' => 'guest-glow',
        'request_key' => 'wishlist-guest-remove-guest-glow',
    ];

    $this->postJson(route('marketing.shopify.v1.wishlist.remove', wishlistSignedQuery([
        'shop' => $removePayload['shop'],
        'timestamp' => $removePayload['timestamp'],
    ], 'wishlist-proxy-secret')), $removePayload)
        ->assertOk()
        ->assertJsonPath('data.profile_id', null)
        ->assertJsonPath('data.guest_token', $guestToken)
        ->assertJsonPath('data.state', 'wishlist_removed')
        ->assertJsonPath('data.product.in_wishlist', false)
        ->assertJsonPath('data.summary.active_count', 0);
});

test('shopify wishlist status returns the product actual wishlist list id when saved to a non-default list', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'wishlist-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'wishlist-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    configureWishlistStorefrontStores();

    $guestToken = 'guest-wishlist-list-actual';

    $createListResponse = $this->postJson(route('marketing.shopify.v1.wishlist.lists.create', wishlistSignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
    ], 'wishlist-proxy-secret')), [
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'guest_token' => $guestToken,
        'name' => 'Weekend Burn',
    ])->assertOk();

    $listId = (int) data_get($createListResponse->json(), 'data.list.id');

    $this->postJson(route('marketing.shopify.v1.wishlist.add', wishlistSignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) (time() + 1),
    ], 'wishlist-proxy-secret')), [
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) (time() + 1),
        'guest_token' => $guestToken,
        'wishlist_list_id' => $listId,
        'product_id' => 'wsku-list-101',
        'product_handle' => 'ember-jar',
        'product_title' => 'Ember Jar',
        'product_url' => '/products/ember-jar',
        'request_key' => 'wishlist-list-specific-add',
    ])->assertOk();

    $this->getJson(route('marketing.shopify.v1.wishlist.status', wishlistSignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) (time() + 2),
        'guest_token' => $guestToken,
        'product_id' => 'wsku-list-101',
        'product_handle' => 'ember-jar',
    ], 'wishlist-proxy-secret')))
        ->assertOk()
        ->assertJsonPath('data.product.in_wishlist', true)
        ->assertJsonPath('data.product.wishlist_list_id', $listId);
});

test('shopify wishlist add fails safely when identity matching is ambiguous', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'wishlist-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'wishlist-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    configureWishlistStorefrontStores();

    $email = 'wishlist.conflict@example.com';

    MarketingProfile::query()->create([
        'first_name' => 'Avery',
        'last_name' => 'North',
        'email' => $email,
        'normalized_email' => $email,
    ]);
    MarketingProfile::query()->create([
        'first_name' => 'Avery',
        'last_name' => 'South',
        'email' => $email,
        'normalized_email' => $email,
    ]);

    $payload = [
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'email' => $email,
        'product_id' => 'wsku-conflict',
        'product_handle' => 'identity-safe-wishlist',
        'product_title' => 'Identity Safe Wishlist',
    ];

    $this->postJson(route('marketing.shopify.v1.wishlist.add', wishlistSignedQuery([
        'shop' => $payload['shop'],
        'timestamp' => $payload['timestamp'],
    ], 'wishlist-proxy-secret')), $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'identity_review_required');

    expect(MarketingProfileWishlistItem::query()->count())->toBe(0);
});

test('shopify wishlist status respects the verified store tenant context', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'wishlist-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'wishlist-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);

    $retailTenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-tenant',
    ]);
    $wholesaleTenant = Tenant::query()->create([
        'name' => 'Wholesale Tenant',
        'slug' => 'wholesale-tenant',
    ]);

    configureWishlistStorefrontStores($retailTenant->id, $wholesaleTenant->id);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $wholesaleTenant->id,
        'first_name' => 'Harbor',
        'last_name' => 'Trade',
        'email' => 'harbor.trade@example.com',
        'normalized_email' => 'harbor.trade@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'tenant_id' => $wholesaleTenant->id,
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'shopify_admin',
        'store_key' => 'wholesale',
        'external_customer_id' => '99887766',
        'external_customer_gid' => 'gid://shopify/Customer/99887766',
        'email' => $profile->email,
        'normalized_email' => $profile->normalized_email,
        'source_channels' => ['shopify', 'online'],
        'synced_at' => now(),
    ]);

    MarketingProfileWishlistItem::query()->create([
        'tenant_id' => $wholesaleTenant->id,
        'marketing_profile_id' => $profile->id,
        'provider' => 'backstage',
        'integration' => 'native',
        'store_key' => 'wholesale',
        'product_id' => 'wsku-501',
        'product_handle' => 'ember-jar',
        'product_title' => 'Ember Jar',
        'status' => MarketingProfileWishlistItem::STATUS_ACTIVE,
        'source' => 'native_storefront',
        'added_at' => now()->subHour(),
        'last_added_at' => now()->subHour(),
    ]);

    $retailQuery = wishlistSignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'product_id' => 'wsku-501',
        'product_handle' => 'ember-jar',
        'logged_in_customer_id' => 'gid://shopify/Customer/99887766',
    ], 'wishlist-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.wishlist.status', $retailQuery))
        ->assertOk()
        ->assertJsonPath('data.profile_id', null)
        ->assertJsonPath('data.summary.active_count', 0)
        ->assertJsonPath('data.product.in_wishlist', false);

    $wholesaleQuery = wishlistSignedQuery([
        'shop' => 'cedar-wholesale.example.myshopify.com',
        'timestamp' => (string) (time() + 1),
        'product_id' => 'wsku-501',
        'product_handle' => 'ember-jar',
        'logged_in_customer_id' => 'gid://shopify/Customer/99887766',
    ], 'wishlist-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.wishlist.status', $wholesaleQuery))
        ->assertOk()
        ->assertJsonPath('data.profile_id', $profile->id)
        ->assertJsonPath('data.summary.active_count', 1)
        ->assertJsonPath('data.product.in_wishlist', true);
});

test('shopify wishlist storefront rejects invalid signatures', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'wishlist-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'wishlist-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    configureWishlistStorefrontStores();

    $this->getJson(route('marketing.shopify.v1.wishlist.status', [
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'product_id' => 'wsku-invalid',
        'signature' => 'invalid-signature',
    ]))
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthorized_storefront_request');
});

function wishlistSignedQuery(array $params, string $secret): array
{
    $params = array_filter($params, static fn ($value) => $value !== null);
    ksort($params);

    $pairs = [];
    foreach ($params as $key => $value) {
        $pairs[] = $key . '=' . $value;
    }

    $params['signature'] = hash_hmac('sha256', implode('', $pairs), $secret);

    return $params;
}

function configureWishlistStorefrontStores(?int $retailTenantId = null, ?int $wholesaleTenantId = null): void
{
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'retail-client');
    config()->set('services.shopify.stores.wholesale.shop', 'cedar-wholesale.example.myshopify.com');
    config()->set('services.shopify.stores.wholesale.client_id', 'wholesale-client');

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'tenant_id' => $retailTenantId,
            'shop_domain' => 'timberline.example.myshopify.com',
            'access_token' => 'retail-token',
        ]
    );

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'wholesale'],
        [
            'tenant_id' => $wholesaleTenantId,
            'shop_domain' => 'cedar-wholesale.example.myshopify.com',
            'access_token' => 'wholesale-token',
        ]
    );
}
