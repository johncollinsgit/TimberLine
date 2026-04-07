<?php

use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\EventInstance;
use App\Models\MarketingProfile;
use App\Models\MarketingStorefrontEvent;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\CandleCashShopifyDiscountService;

test('storefront contract responses include standardized envelope states and recovery states', function () {
    config()->set('marketing.shopify.signing_secret', 'stage9-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('marketing.candle_cash.temporary_storefront_live_email_allowlist', ['stage9.contract@example.com']);
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage9-retail-client');
    $tenant = stage9MapStore('retail', 'modernforestry.myshopify.com', 'stage9-contract-tenant');

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Stage9',
        'email' => 'stage9.contract@example.com',
        'normalized_email' => 'stage9.contract@example.com',
        'phone' => '5551119999',
        'normalized_phone' => '+15551119999',
        'accepts_sms_marketing' => true,
    ]);

    $query = [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'shop' => 'modernforestry.myshopify.com',
    ];
    $headers = stage9SignedHeaders('GET', '/shopify/marketing/rewards/balance', $query, '', 'stage9-secret');
    $this->withHeaders($headers)
        ->getJson(route('marketing.shopify.rewards.balance', $query))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('version', 'v1')
        ->assertJsonStructure(['ok', 'version', 'data', 'meta' => ['states']]);

    $reward = app(CandleCashService::class)->storefrontReward($tenant->id);
    expect($reward)->not->toBeNull();
    $errorPayload = [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'reward_id' => $reward->id,
        'reuse_existing_code' => false,
        'shop' => 'modernforestry.myshopify.com',
    ];
    $errorHeaders = stage9SignedHeaders('POST', '/shopify/marketing/rewards/redeem', [], json_encode($errorPayload), 'stage9-secret');
    $this->withHeaders($errorHeaders)
        ->postJson(route('marketing.shopify.rewards.redeem'), $errorPayload)
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'insufficient_candle_cash')
        ->assertJsonStructure(['error' => ['states', 'recovery_states']]);
});

test('storefront security supports valid signatures and rejects invalid or stale requests', function () {
    config()->set('marketing.shopify.signing_secret', 'stage9-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage9-retail-client');
    stage9MapStore('retail', 'modernforestry.myshopify.com', 'stage9-security-tenant');

    $query = ['shop' => 'modernforestry.myshopify.com'];
    $valid = stage9SignedHeaders('GET', '/shopify/marketing/rewards/available', $query, '', 'stage9-secret');
    $this->withHeaders($valid)
        ->getJson(route('marketing.shopify.rewards.available', $query))
        ->assertOk();

    $bad = stage9SignedHeaders('GET', '/shopify/marketing/rewards/available', $query, '', 'wrong-secret');
    $this->withHeaders($bad)
        ->getJson(route('marketing.shopify.rewards.available', $query))
        ->assertStatus(401);

    $stale = stage9SignedHeaders('GET', '/shopify/marketing/rewards/available', $query, '', 'stage9-secret', time() - 3600);
    $this->withHeaders($stale)
        ->getJson(route('marketing.shopify.rewards.available', $query))
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthorized_storefront_request');
});

test('storefront legacy token auth is rejected even when configured', function () {
    config()->set('marketing.shopify.signing_secret', 'stage9-secret');
    config()->set('marketing.shopify.widget_token', 'stage9-token');
    config()->set('marketing.shopify.allow_legacy_token', true);

    $this->withHeaders(['X-Marketing-Token' => 'stage9-token'])
        ->getJson(route('marketing.shopify.rewards.available'))
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthorized_storefront_request')
        ->assertJsonPath('error.details.reason', 'missing_signature_headers');
});

test('storefront app proxy signature mode is accepted when enabled', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage9-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'unused');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage9-retail-client');
    stage9MapStore('retail', 'timberline.example.myshopify.com', 'stage9-proxy-tenant');

    $query = stage9AppProxySignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
    ], 'stage9-proxy-secret');

    $this->getJson(route('marketing.shopify.rewards.available', $query))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('meta.auth_mode', 'app_proxy');
});

test('storefront reward balance stays isolated to the verified shop tenant', function () {
    config()->set('marketing.shopify.signing_secret', 'stage9-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage9-retail-client');
    config()->set('services.shopify.stores.wholesale.shop', 'cedar-wholesale.example.myshopify.com');
    config()->set('services.shopify.stores.wholesale.client_id', 'stage9-wholesale-client');

    $retailTenant = Tenant::query()->create([
        'name' => 'Stage9 Retail Tenant',
        'slug' => 'stage9-retail-tenant',
    ]);
    $wholesaleTenant = Tenant::query()->create([
        'name' => 'Stage9 Wholesale Tenant',
        'slug' => 'stage9-wholesale-tenant-reads',
    ]);

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'shop_domain' => 'modernforestry.myshopify.com',
            'access_token' => 'stage9-retail-token',
            'tenant_id' => $retailTenant->id,
        ]
    );
    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'wholesale'],
        [
            'shop_domain' => 'cedar-wholesale.example.myshopify.com',
            'access_token' => 'stage9-wholesale-token',
            'tenant_id' => $wholesaleTenant->id,
        ]
    );

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $wholesaleTenant->id,
        'first_name' => 'Scoped',
        'email' => 'scoped.balance@example.com',
        'normalized_email' => 'scoped.balance@example.com',
        'phone' => '5551112345',
        'normalized_phone' => '+15551112345',
    ]);

    $matchingQuery = [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'shop' => 'cedar-wholesale.example.myshopify.com',
    ];
    $matchingHeaders = stage9SignedHeaders('GET', '/shopify/marketing/rewards/balance', $matchingQuery, '', 'stage9-secret');
    $this->withHeaders($matchingHeaders)
        ->getJson(route('marketing.shopify.rewards.balance', $matchingQuery))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.profile_id', $profile->id);

    $mismatchedQuery = [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'shop' => 'modernforestry.myshopify.com',
    ];
    $mismatchedHeaders = stage9SignedHeaders('GET', '/shopify/marketing/rewards/balance', $mismatchedQuery, '', 'stage9-secret');
    $this->withHeaders($mismatchedHeaders)
        ->getJson(route('marketing.shopify.rewards.balance', $mismatchedQuery))
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'profile_not_found')
        ->assertJsonPath('error.details.status', 'not_found');
});

test('storefront rewards fail closed when the shop is known but no tenant mapping exists', function () {
    config()->set('marketing.shopify.signing_secret', 'stage9-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage9-retail-client');

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'shop_domain' => 'modernforestry.myshopify.com',
            'access_token' => 'stage9-retail-token',
            'tenant_id' => null,
            'installed_at' => now(),
        ]
    );

    $query = ['shop' => 'modernforestry.myshopify.com'];
    $headers = stage9SignedHeaders('GET', '/shopify/marketing/rewards/available', $query, '', 'stage9-secret');

    $this->withHeaders($headers)
        ->getJson(route('marketing.shopify.rewards.available', $query))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'tenant_context_required');
});

test('reward redemption feedback loop returns code issued and already has active code states', function () {
    config()->set('marketing.shopify.signing_secret', 'stage9-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('marketing.candle_cash.temporary_storefront_live_email_allowlist', ['reward.feedback@example.com']);
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage9-retail-client');
    $tenant = stage9MapStore('retail', 'modernforestry.myshopify.com', 'stage9-feedback-tenant');

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Reward',
        'email' => 'reward.feedback@example.com',
        'normalized_email' => 'reward.feedback@example.com',
        'phone' => '5552229999',
        'normalized_phone' => '+15552229999',
        'accepts_sms_marketing' => true,
    ]);
    app(CandleCashService::class)->addPoints($profile, 400, 'earn', 'admin', 'seed', 'seed');
    $reward = app(CandleCashService::class)->storefrontReward($tenant->id);
    expect($reward)->not->toBeNull();

    $discountSync = \Mockery::mock(CandleCashShopifyDiscountService::class);
    $discountSync->shouldReceive('ensureDiscountForRedemption')
        ->twice()
        ->withArgs(function (CandleCashRedemption $redemption, ?string $preferredStoreKey): bool {
            return $preferredStoreKey === 'retail'
                && data_get($redemption->redemption_context, 'shopify_store_key') === 'retail';
        })
        ->andReturn([
            'discount_id' => 'gid://shopify/DiscountCodeNode/stage9',
            'discount_node_id' => 'gid://shopify/DiscountCodeNode/stage9',
            'store_key' => 'retail',
            'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->addDays(30)->toIso8601String(),
        ]);
    app()->instance(CandleCashShopifyDiscountService::class, $discountSync);

    $payload = [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'reward_id' => $reward->id,
        'shop' => 'modernforestry.myshopify.com',
    ];
    $headers = stage9SignedHeaders('POST', '/shopify/marketing/rewards/redeem', [], json_encode($payload), 'stage9-secret');

    $first = $this->withHeaders($headers)
        ->postJson(route('marketing.shopify.rewards.redeem'), $payload)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.state', 'code_issued')
        ->json();

    expect((string) data_get($first, 'data.redemption_code'))->toStartWith('CC-');

    $second = $this->withHeaders($headers)
        ->postJson(route('marketing.shopify.rewards.redeem'), $payload)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.state', 'already_has_active_code')
        ->json();

    expect((string) data_get($second, 'data.redemption_code'))->toBe((string) data_get($first, 'data.redemption_code'));
});

test('shopify reward redemption persists and uses verified storefront store context', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage9-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'unused');
    config()->set('marketing.candle_cash.temporary_storefront_live_email_allowlist', ['wholesale.stage9@example.com']);
    config()->set('services.shopify.stores.wholesale.client_id', 'stage9-wholesale-client');

    $tenant = Tenant::query()->create([
        'name' => 'Wholesale Tenant',
        'slug' => 'stage9-wholesale-tenant',
    ]);

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'wholesale'],
        [
            'shop_domain' => 'cedar-wholesale.example.myshopify.com',
            'access_token' => 'stage9-wholesale-access-token',
            'tenant_id' => $tenant->id,
        ]
    );

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Wholesale',
        'email' => 'wholesale.stage9@example.com',
        'normalized_email' => 'wholesale.stage9@example.com',
        'phone' => '5552224444',
        'normalized_phone' => '+15552224444',
        'accepts_sms_marketing' => true,
    ]);
    app(CandleCashService::class)->addPoints($profile, 400, 'earn', 'admin', 'seed', 'seed');
    $reward = app(CandleCashService::class)->storefrontReward();
    expect($reward)->not->toBeNull();

    $mock = \Mockery::mock(CandleCashShopifyDiscountService::class);
    $mock->shouldReceive('ensureDiscountForRedemption')
        ->once()
        ->withArgs(function (CandleCashRedemption $redemption, ?string $preferredStoreKey) use ($tenant): bool {
            return $preferredStoreKey === 'wholesale'
                && data_get($redemption->redemption_context, 'shopify_store_key') === 'wholesale'
                && (int) data_get($redemption->redemption_context, 'tenant_id') === (int) $tenant->id;
        })
        ->andReturn([
            'discount_id' => 'gid://shopify/DiscountCodeNode/1',
            'discount_node_id' => 'gid://shopify/DiscountCodeNode/1',
            'store_key' => 'wholesale',
            'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->addDays(30),
        ]);
    app()->instance(CandleCashShopifyDiscountService::class, $mock);

    $payload = [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'reward_id' => $reward->id,
    ];
    $query = stage9AppProxySignedQuery([
        'shop' => 'cedar-wholesale.example.myshopify.com',
        'timestamp' => (string) time(),
    ], 'stage9-proxy-secret');

    $response = $this->postJson(route('marketing.shopify.rewards.redeem', $query), $payload)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.discount_sync_status', 'synced');

    $redemption = CandleCashRedemption::query()->findOrFail((int) data_get($response->json(), 'data.redemption_id'));

    expect(data_get($redemption->redemption_context, 'shopify_store_key'))->toBe('wholesale')
        ->and((int) data_get($redemption->redemption_context, 'tenant_id'))->toBe((int) $tenant->id);
});

test('shopify reward redemption is gated to the temporary beta email allowlist', function () {
    config()->set('marketing.shopify.signing_secret', 'stage9-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('marketing.candle_cash.temporary_storefront_live_email_allowlist', ['sarahcollins0816@gmail.com']);
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage9-retail-client');
    $tenant = stage9MapStore('retail', 'modernforestry.myshopify.com', 'stage9-gated-tenant');

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Blocked',
        'email' => 'blocked.stage9@example.com',
        'normalized_email' => 'blocked.stage9@example.com',
        'phone' => '5552221234',
        'normalized_phone' => '+15552221234',
    ]);
    app(CandleCashService::class)->addPoints($profile, 400, 'earn', 'admin', 'seed', 'seed');

    $reward = app(CandleCashService::class)->storefrontReward($tenant->id);
    expect($reward)->not->toBeNull();

    $discountSync = \Mockery::mock(CandleCashShopifyDiscountService::class);
    $discountSync->shouldReceive('ensureDiscountForRedemption')->never();
    app()->instance(CandleCashShopifyDiscountService::class, $discountSync);

    $payload = [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'reward_id' => $reward->id,
        'shop' => 'modernforestry.myshopify.com',
    ];
    $headers = stage9SignedHeaders('POST', '/shopify/marketing/rewards/redeem', [], json_encode($payload), 'stage9-secret');

    $this->withHeaders($headers)
        ->postJson(route('marketing.shopify.rewards.redeem'), $payload)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'coming_soon')
        ->assertJsonPath('error.details.state', 'coming_soon')
        ->assertJsonPath('error.details.redemption_access.cta_label', 'COMING SOON!');

    expect(CandleCashRedemption::query()->where('marketing_profile_id', $profile->id)->exists())->toBeFalse()
        ->and((float) \App\Models\CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->value('balance'))->toBe(400.0);
});

test('shopify redemption access is live for non-allowlisted accounts when allowlist is empty', function () {
    config()->set('marketing.shopify.signing_secret', 'stage9-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('marketing.candle_cash.temporary_storefront_live_email_allowlist', []);
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage9-retail-client');
    $tenant = stage9MapStore('retail', 'modernforestry.myshopify.com', 'stage9-open-tenant');

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Open',
        'email' => 'open.stage9@example.com',
        'normalized_email' => 'open.stage9@example.com',
        'phone' => '5557012000',
        'normalized_phone' => '+15557012000',
    ]);
    app(CandleCashService::class)->addPoints($profile, 100, 'earn', 'admin', 'seed', 'seed');

    $query = [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'shop' => 'modernforestry.myshopify.com',
    ];
    $headers = stage9SignedHeaders('GET', '/shopify/marketing/rewards/balance', $query, '', 'stage9-secret');

    $this->withHeaders($headers)
        ->getJson(route('marketing.shopify.rewards.balance', $query))
        ->assertOk()
        ->assertJsonPath('data.redemption_access.redeem_enabled', true)
        ->assertJsonPath('data.redemption_access.mode', 'live')
        ->assertJsonPath('data.redemption_access.cta_label', 'Redeem Reward Credit');
});

test('ambiguous storefront identity returns verification required instead of silent linkage', function () {
    config()->set('marketing.shopify.signing_secret', 'stage9-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage9-retail-client');
    $tenant = stage9MapStore('retail', 'modernforestry.myshopify.com', 'stage9-ambiguous-tenant');

    $left = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Left',
        'email' => 'left.stage9@example.com',
        'normalized_email' => 'left.stage9@example.com',
        'phone' => '5553001000',
        'normalized_phone' => '+15553001000',
    ]);
    $right = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Right',
        'email' => 'right.stage9@example.com',
        'normalized_email' => 'right.stage9@example.com',
        'phone' => '5553002000',
        'normalized_phone' => '+15553002000',
    ]);
    expect($left->id)->not->toBe($right->id);

    $reward = CandleCashReward::query()->where('is_active', true)->orderBy('candle_cash_cost')->firstOrFail();
    $payload = [
        'email' => 'left.stage9@example.com',
        'phone' => '5553002000',
        'reward_id' => $reward->id,
        'shop' => 'modernforestry.myshopify.com',
    ];
    $headers = stage9SignedHeaders('POST', '/shopify/marketing/rewards/redeem', [], json_encode($payload), 'stage9-secret');

    $this->withHeaders($headers)
        ->postJson(route('marketing.shopify.rewards.redeem'), $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'identity_review_required')
        ->assertJsonPath('error.states.0', 'needs_verification');
});

test('reconciliation dashboard surfaces unresolved issues and supports resolution actions', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Stage9 Reconciliation Tenant',
        'slug' => 'stage9-reconciliation-tenant',
    ]);
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $admin->tenants()->attach($tenant->id, ['role' => 'admin']);
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Ops',
        'email' => 'ops.stage9@example.com',
        'normalized_email' => 'ops.stage9@example.com',
    ]);
    $reward = CandleCashReward::query()->where('is_active', true)->orderBy('candle_cash_cost')->firstOrFail();
    app(CandleCashService::class)->addPoints($profile, 400, 'earn', 'admin', 'seed', 'seed');
    $issued = app(CandleCashService::class)->redeemReward($profile, $reward, 'shopify');
    $redemption = CandleCashRedemption::query()->findOrFail((int) ($issued['redemption_id'] ?? 0));

    $event = MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenant->id,
        'event_type' => 'widget_redeem_request',
        'status' => 'error',
        'issue_type' => 'redemption_blocked',
        'source_surface' => 'shopify_widget',
        'endpoint' => '/shopify/marketing/rewards/redeem',
        'marketing_profile_id' => $profile->id,
        'candle_cash_redemption_id' => $redemption->id,
        'resolution_status' => 'open',
        'occurred_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.operations.reconciliation', ['status' => 'open']))
        ->assertOk()
        ->assertSeeText('Unresolved Storefront/Public Issues')
        ->assertSeeText('redemption_blocked');

    $this->actingAs($admin)
        ->post(route('marketing.operations.reconciliation.issues.resolve', $event), [
            'resolution_status' => 'resolved',
            'notes' => 'Manually reviewed.',
        ])
        ->assertRedirect();

    $event->refresh();
    expect($event->resolution_status)->toBe('resolved')
        ->and((int) $event->resolved_by)->toBe((int) $admin->id);
});

test('storefront redemption debug endpoint summarizes latest redeem issue', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Stage9 Debug Tenant',
        'slug' => 'stage9-debug-tenant',
    ]);
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $admin->tenants()->attach($tenant->id, ['role' => 'admin']);
    config()->set('marketing.candle_cash.temporary_storefront_live_email_allowlist', ['debug.stage9@example.com']);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Debug',
        'email' => 'debug.stage9@example.com',
        'normalized_email' => 'debug.stage9@example.com',
        'phone' => '5553102000',
        'normalized_phone' => '+15553102000',
    ]);

    app(CandleCashService::class)->addPoints($profile, 1000, 'earn', 'admin', 'seed', 'seed');

    MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenant->id,
        'event_type' => 'widget_redeem_request',
        'status' => 'error',
        'issue_type' => 'shopify_discount_sync_failed',
        'source_surface' => 'shopify_widget',
        'endpoint' => '/shopify/marketing/rewards/redeem',
        'marketing_profile_id' => $profile->id,
        'occurred_at' => now(),
        'resolution_status' => 'open',
    ]);

    $this->actingAs($admin)
        ->getJson(route('marketing.operations.storefront-redemption-debug', ['email' => $profile->email]))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.profile.id', $profile->id)
        ->assertJsonPath('data.latest_issue.issue_type', 'shopify_discount_sync_failed')
        ->assertJsonPath('data.primary_issue', 'shopify_discount_sync_failed');
});

test('dashboard mark redeemed action reconciles issued code for staff-assisted cases', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Stage9 Manual Redemption Tenant',
        'slug' => 'stage9-manual-redemption-tenant',
    ]);
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $admin->tenants()->attach($tenant->id, ['role' => 'admin']);
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Manual',
    ]);
    app(CandleCashService::class)->addPoints($profile, 300, 'earn', 'admin', 'seed', 'seed');
    $reward = CandleCashReward::query()->where('is_active', true)->orderBy('candle_cash_cost')->firstOrFail();
    $issued = app(CandleCashService::class)->redeemReward($profile, $reward, 'square');
    $redemption = CandleCashRedemption::query()->findOrFail((int) ($issued['redemption_id'] ?? 0));

    $this->actingAs($admin)
        ->post(route('marketing.operations.reconciliation.redemptions.mark-redeemed', $redemption), [
            'platform' => 'square',
            'external_order_source' => 'square_manual',
            'external_order_id' => 'SQ-STAGE9-001',
            'notes' => 'Booth redemption confirmed',
        ])
        ->assertRedirect();

    $redemption->refresh();
    expect($redemption->status)->toBe('redeemed')
        ->and((string) $redemption->external_order_id)->toBe('SQ-STAGE9-001')
        ->and((int) $redemption->redeemed_by)->toBe((int) $admin->id);
});

test('storefront and public touchpoints are logged and visible on customer timeline', function () {
    config()->set('marketing.shopify.signing_secret', 'stage9-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'modernforestry.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage9-retail-client');
    $tenant = stage9MapStore('retail', 'modernforestry.myshopify.com', 'stage9-timeline-tenant');

    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $admin->tenants()->attach($tenant->id, ['role' => 'admin']);
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Timeline',
        'email' => 'timeline.stage9@example.com',
        'normalized_email' => 'timeline.stage9@example.com',
        'phone' => '5557001111',
        'normalized_phone' => '+15557001111',
        'accepts_sms_marketing' => true,
    ]);

    $query = [
        'email' => $profile->email,
        'phone' => $profile->phone,
        'shop' => 'modernforestry.myshopify.com',
    ];
    $headers = stage9SignedHeaders('GET', '/shopify/marketing/rewards/balance', $query, '', 'stage9-secret');
    $this->withHeaders($headers)
        ->getJson(route('marketing.shopify.rewards.balance', $query))
        ->assertOk();

    $this->get(route('marketing.public.rewards-lookup', ['email' => $profile->email, 'phone' => $profile->phone, 'store_key' => 'retail']))
        ->assertOk()
        ->assertSeeText('Rewards Account Lookup');

    expect(MarketingStorefrontEvent::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('event_type', 'widget_balance_lookup')
        ->exists())->toBeTrue()
        ->and(MarketingStorefrontEvent::query()
            ->where('event_type', 'public_reward_lookup')
            ->exists())->toBeTrue();

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', $profile))
        ->assertOk()
        ->assertSeeText('Widget/Public Event Timeline')
        ->assertSeeText('widget_balance_lookup');
});

function stage9MapStore(string $storeKey, string $shopDomain, string $slug): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => str_replace('-', ' ', ucfirst($slug)),
        'slug' => $slug,
    ]);

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => $storeKey],
        [
            'shop_domain' => $shopDomain,
            'access_token' => 'stage9-token-' . $storeKey,
            'tenant_id' => $tenant->id,
            'installed_at' => now(),
        ]
    );

    return $tenant;
}

test('festival public flow uses canonical slug and logs event context', function () {
    $event = EventInstance::query()->create([
        'title' => 'Flowertown Spring Festival',
        'public_slug' => 'flowertown-public',
        'starts_at' => now()->addDays(7),
    ]);

    $this->get(route('marketing.public.events.optin', ['eventSlug' => 'flowertown-spring-festival']))
        ->assertRedirect(route('marketing.public.events.optin', ['eventSlug' => 'flowertown-public']));

    $this->post(route('marketing.public.events.optin.store', ['eventSlug' => 'flowertown-public']), [
        'email' => 'festival.stage9@example.com',
        'phone' => '5558124444',
        'first_name' => 'Festival',
        'consent_sms' => 1,
        'award_bonus' => 0,
    ])->assertRedirect();

    expect(MarketingStorefrontEvent::query()
        ->where('event_type', 'public_event_optin_submit')
        ->where('event_instance_id', $event->id)
        ->exists())->toBeTrue();
});

test('reconciliation dashboard remains restricted to marketing roles', function () {
    $manager = User::factory()->create(['role' => 'manager', 'email_verified_at' => now()]);

    $this->actingAs($manager)
        ->get(route('marketing.operations.reconciliation'))
        ->assertForbidden();
});

/**
 * @param array<string,mixed> $query
 * @return array<string,string>
 */
function stage9SignedHeaders(
    string $method,
    string $path,
    array $query,
    string $body,
    string $secret,
    ?int $timestamp = null
): array {
    $timestamp = $timestamp ?? time();
    $canonicalQuery = stage9CanonicalQuery($query);
    $bodyHash = hash('sha256', $body);
    $payload = implode("\n", [$timestamp, strtoupper($method), $path, $canonicalQuery, $bodyHash]);
    $signature = hash_hmac('sha256', $payload, $secret);

    return [
        'X-Marketing-Timestamp' => (string) $timestamp,
        'X-Marketing-Signature' => $signature,
    ];
}

/**
 * @param array<string,mixed> $query
 */
function stage9CanonicalQuery(array $query): string
{
    if ($query === []) {
        return '';
    }

    ksort($query);
    $parts = [];
    foreach ($query as $key => $value) {
        if (is_array($value)) {
            $value = stage9CanonicalQuery($value);
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif ($value === null) {
            $value = '';
        } else {
            $value = (string) $value;
        }

        $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
    }

    return implode('&', $parts);
}

/**
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function stage9AppProxySignedQuery(array $params, string $secret): array
{
    $canonical = stage9AppProxyCanonical($params);
    $signature = hash_hmac('sha256', $canonical, $secret);

    return [...$params, 'signature' => $signature];
}

/**
 * @param array<string,mixed> $params
 */
function stage9AppProxyCanonical(array $params): string
{
    if ($params === []) {
        return '';
    }

    ksort($params);
    $parts = [];
    foreach ($params as $key => $value) {
        if (is_array($value)) {
            $value = stage9AppProxyCanonical($value);
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif ($value === null) {
            $value = '';
        } else {
            $value = (string) $value;
        }

        $parts[] = (string) $key . '=' . (string) $value;
    }

    return implode('', $parts);
}
