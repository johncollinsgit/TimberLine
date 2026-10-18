<?php

use App\Http\Controllers\SubscriptionStorefrontController;
use App\Models\MarketingProfile;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Mobile\ModernForestryMobileAccountService;
use App\Services\Mobile\ModernForestryMobileCustomerSession;
use App\Services\Shopify\ShopifyStores;
use App\Services\Subscriptions\SubscriptionAccessService;
use App\Services\Subscriptions\SubscriptionModuleService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../ShopifyEmbeddedTestHelpers.php';
require_once __DIR__.'/../../Support/SubscriptionFoundationHelpers.php';

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->tenant = Tenant::query()->create(['name' => 'Subscription merchant', 'slug' => 'subscription-merchant']);
    $this->actor = subscriptionFoundationFixture($this, $this->tenant);
    $this->profile = MarketingProfile::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->contract = DB::table('subscription_contracts')->insertGetId([
        'tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile->id,
        'shopify_subscription_contract_gid' => 'gid://shopify/SubscriptionContract/101',
        'status' => 'active', 'is_candle_club' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
});

test('subscription pricing is a separate canonical paid add-on without an external charge', function (): void {
    expect(config('module_catalog.addons.subscriptions.purchase_key'))->toBe('addon.subscriptions')
        ->and(config('module_catalog.addons.subscriptions.pricing.recurring_price_cents'))->toBe(9900)
        ->and(config('module_catalog.addons.subscriptions.stripe.recurring_price_lookup_key'))->toBe('addon_subscriptions_monthly')
        ->and(config('module_catalog.modules.subscriptions.included_in_plans'))->toBe([]);
    Http::assertNothingSent();
});

test('Modern Forestry access comes from the explicit grant and never its tenant id or name', function (): void {
    $mf = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $access = app(SubscriptionAccessService::class);
    expect($access->entitled((int) $mf->id))->toBeFalse();
    TenantModuleEntitlement::query()->create([
        'tenant_id' => $mf->id, 'module_key' => 'subscriptions', 'availability_status' => 'available',
        'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped',
        'entitlement_source' => 'modern_forestry_subscription_bootstrap', 'price_source' => 'landlord_pending',
    ]);
    app()->forgetInstance(TenantModuleAccessResolver::class);
    expect($access->entitled((int) $mf->id))->toBeTrue();
});

test('all customer public admin webhook and future worker paths respect an explicit disabled entitlement', function (): void {
    TenantModuleEntitlement::query()->where('tenant_id', $this->tenant->id)->where('module_key', 'subscriptions')->update(['enabled_status' => 'disabled']);
    app()->forgetInstance(TenantModuleAccessResolver::class);
    $service = app(SubscriptionModuleService::class);
    $events = DB::table('subscription_lifecycle_events')->count();
    expect($service->customerCandleClubPayload($this->profile)['status'])->toBe('module_locked')
        ->and($service->recordShopifyWebhook((int) $this->tenant->id, 'subscription_contracts/update', ['id' => 101], ShopifyStores::find('retail', true), 'event-1'))->toBeFalse();
    foreach ([
        fn () => $service->adminPayload((int) $this->tenant->id),
        fn () => $service->recordCustomerCandleClubAction($this->profile, 'pause', ['duration_months' => 1]),
        fn () => $service->storefrontPollPayload((int) $this->tenant->id),
        fn () => $service->requestVoteCode((int) $this->tenant->id, 1, 'member@example.com'),
        fn () => app(SubscriptionAccessService::class)->assertCanExecute((int) $this->tenant->id),
    ] as $operation) {
        expect($operation)->toThrow(HttpException::class);
    }
    expect(DB::table('subscription_lifecycle_events')->count())->toBe($events);
    $this->artisan('subscriptions:import-candle-club-recipes', ['--tenant' => $this->tenant->id, '--apply' => true, '--limit' => 1])->assertExitCode(1);
    Http::assertNothingSent();
});

test('canonical activation cannot unlock subscriptions while billing is pending', function (): void {
    // Even after a later reviewed purchase rollout opens, activation is not payment.
    config()->set('module_catalog.addons.subscriptions.purchase_enabled', true);
    TenantModuleEntitlement::query()->where('tenant_id', $this->tenant->id)->where('module_key', 'subscriptions')->delete();
    app()->forgetInstance(TenantModuleAccessResolver::class);
    $result = app(\App\Services\Tenancy\TenantModuleCatalogService::class)->activateModuleForTenant((int) $this->tenant->id, 'subscriptions', (int) $this->actor->id);
    expect($result['ok'])->toBeTrue()
        ->and(TenantModuleEntitlement::query()->where('tenant_id', $this->tenant->id)->where('module_key', 'subscriptions')->value('billing_status'))->toBe('pending_billing');
    app()->forgetInstance(TenantModuleAccessResolver::class);
    expect(app(SubscriptionAccessService::class)->entitled((int) $this->tenant->id))->toBeFalse()
        ->and(app(TenantModuleAccessResolver::class)->module((int) $this->tenant->id, 'subscriptions')['has_access'])->toBeFalse();
    Http::assertNothingSent();
});

test('approved subscription price remains unavailable for catalog purchase or direct activation', function (): void {
    TenantModuleEntitlement::query()->where('tenant_id', $this->tenant->id)->where('module_key', 'subscriptions')->delete();
    app()->forgetInstance(TenantModuleAccessResolver::class);
    $catalog = app(\App\Services\Tenancy\TenantModuleCatalogService::class);
    $module = collect($catalog->tenantStorePayload((int) $this->tenant->id)['modules'])->firstWhere('module_key', 'subscriptions');
    expect(config('module_catalog.addons.subscriptions.pricing.recurring_price_cents'))->toBe(9900)
        ->and($module['purchase'])->toBe([])
        ->and($module['module_state']['cta'])->toBe('request')
        ->and($catalog->activateModuleForTenant((int) $this->tenant->id, 'subscriptions', (int) $this->actor->id)['ok'])->toBeFalse();
    Http::assertNothingSent();
});

test('direct add-on and combined-plan Stripe checkout cannot sell the paused subscription purchase key', function (): void {
    config()->set('commercial.billing_readiness.checkout_active', true);
    config()->set('commercial.billing_readiness.lifecycle_mutations_enabled', true);
    config()->set('services.stripe.secret', 'sk_test_no_provider_requests');
    $stripe = app(\App\Services\Billing\StripeHostedBillingService::class);
    expect($stripe->createAddonCheckoutSession($this->tenant, $this->actor, 'subscriptions')['ok'])->toBeFalse();
    foreach (['subscriptions', 'addon.subscriptions'] as $key) {
        $result = $stripe->createCheckoutSession($this->tenant, $this->actor, ['preferred_plan_key' => 'growth', 'addons_interest' => [$key]]);
        expect($result['ok'])->toBeFalse()->and($result['message'])->toContain('Purchasing this add-on is paused');
    }
    expect(app(SubscriptionAccessService::class)->entitled((int) $this->tenant->id))->toBeTrue();
    Http::assertNothingSent();
});

test('posted purchase key and client-enabled flag cannot open the paused checkout HTTP route', function (): void {
    config()->set('commercial.billing_readiness.checkout_active', true);
    config()->set('commercial.billing_readiness.lifecycle_mutations_enabled', true);
    config()->set('services.stripe.secret', 'sk_test_no_provider_requests');
    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('tenancy.domains.canonical.base_domain', 'theeverbranch.com');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.auth.flagship_hosts', ['app.theeverbranch.com']);
    $this->actor->update(['role' => 'manager', 'requested_via' => 'customer_production']);
    $this->post('http://subscription-merchant.theeverbranch.com/billing/addons/subscriptions/checkout', [
        'purchase_key' => 'addon.subscriptions', 'purchase_enabled' => true, 'price_id' => 'price_client_override',
    ])->assertRedirect()->assertSessionHas('status_error', 'Purchasing this add-on is paused until servicing and billing are ready.');
    Http::assertNothingSent();
});

test('mobile US billing handoff cannot open a paused subscription purchase', function (): void {
    config()->set('commercial.billing_readiness.checkout_active', true);
    config()->set('commercial.billing_readiness.lifecycle_mutations_enabled', true);
    config()->set('services.stripe.secret', 'sk_test_no_provider_requests');
    // Exercise the purchase gate even after a future mobile catalog mapping is ready.
    config()->set('module_catalog.modules.subscriptions.mobile.purchase_key', 'addon.subscriptions');
    \Laravel\Sanctum\Sanctum::actingAs($this->actor, ['mobile:read', 'mobile:write']);
    $this->postJson('/api/mobile/v1/workspaces/subscription-merchant/branches/subscriptions/billing-handoff', [
        'platform' => 'ios', 'storefront_country' => 'US', 'purchase_key' => 'addon.subscriptions', 'purchase_enabled' => true,
    ])->assertStatus(409)->assertJsonPath('ok', false)->assertJsonPath('url', null)
        ->assertJsonPath('message', 'Purchasing this add-on is paused until servicing and billing are ready.');
    Http::assertNothingSent();
});

test('confirmed commercial grants are required and validity windows cannot be bypassed', function (?string $status, ?string $starts, ?string $ends, bool $expected): void {
    TenantModuleEntitlement::query()->where('tenant_id', $this->tenant->id)->update(['billing_status' => $status, 'starts_at' => $starts, 'ends_at' => $ends]);
    \App\Models\TenantModuleState::query()->updateOrCreate(['tenant_id' => $this->tenant->id, 'module_key' => 'subscriptions'], ['enabled_override' => true]);
    app()->forgetInstance(TenantModuleAccessResolver::class);
    expect(app(SubscriptionAccessService::class)->entitled((int) $this->tenant->id))->toBe($expected);
})->with([
    ['add_on_paid', null, null, true], ['add_on_comped', null, null, true], ['included_in_plan', null, null, true], ['custom_contract', null, null, true],
    ['pending_billing', null, null, false], [null, null, null, false], ['unavailable', null, null, false],
    ['add_on_paid', '2099-01-01', null, false], ['add_on_comped', null, '2000-01-01', false],
]);

test('canonical paid addon fulfillment grants access while manual pending or expired addon rows do not', function (string $source, bool $enabled, ?string $ends, bool $expected): void {
    TenantModuleEntitlement::query()->where('tenant_id', $this->tenant->id)->update(['billing_status' => 'pending_billing']);
    \App\Models\TenantAccessAddon::query()->create([
        'tenant_id' => $this->tenant->id, 'addon_key' => 'subscriptions', 'source' => $source,
        'enabled' => $enabled, 'ends_at' => $ends,
    ]);
    app()->forgetInstance(TenantModuleAccessResolver::class);
    expect(app(SubscriptionAccessService::class)->entitled((int) $this->tenant->id))->toBe($expected);
})->with([
    ['stripe_fulfillment', true, null, true], ['manual', true, null, false],
    ['stripe_fulfillment', false, null, false], ['stripe_fulfillment', true, '2000-01-01', false],
]);

test('management authority requires active membership and never a global role', function (string $role, bool $active, bool $userActive, bool $expected): void {
    $user = User::factory()->create(['role' => 'platform_admin', 'is_active' => $userActive]);
    $user->tenants()->attach($this->tenant->id, ['role' => $role, 'membership_active' => $active]);
    expect(app(SubscriptionAccessService::class)->canManage((int) $this->tenant->id, $user))->toBe($expected);
})->with([
    ['admin', true, true, true], ['manager', true, true, true], ['owner', true, true, true],
    ['member', true, true, false], ['admin', false, true, false], ['admin', true, false, false],
]);

test('Shopify session alone or forged staff headers cannot manage subscriptions', function (): void {
    auth()->logout();
    $this->withHeaders(['Authorization' => 'Bearer '.retailShopifySessionToken(), 'X-Shopify-Admin-Email' => $this->actor->email])
        ->postJson(route('shopify.app.api.subscriptions.contracts.action', ['contract' => $this->contract]), ['action' => 'pause', 'payload' => ['duration_months' => 1]])
        ->assertForbidden();
    $this->get(route('shopify.app.subscriptions', retailEmbeddedSignedQuery()))->assertForbidden()
        ->assertSeeText('active Everbranch workspace owner, admin or manager sign-in');
    expect(DB::table('subscription_lifecycle_events')->where('source', 'shopify_embedded_admin')->count())->toBe(0);
    Http::assertNothingSent();
});

test('an alternate embedded app token cannot masquerade as the bound subscription app', function (): void {
    config()->set('services.shopify.stores.retail.embedded_client_id', 'alternate-app');
    config()->set('services.shopify.stores.retail.embedded_client_secret', 'alternate-app-secret');
    $token = shopifySessionToken('retail', ['aud' => 'alternate-app'], 'alternate-app-secret');
    $verified = app(\App\Services\Shopify\ShopifySessionTokenVerifier::class)->verify($token);
    expect($verified['ok'])->toBeTrue()->and($verified['shopify_app_client_id'])->toBe('alternate-app');
    $this->withToken($token)->postJson(route('shopify.app.api.subscriptions.contracts.action', ['contract' => $this->contract]), ['action' => 'pause', 'payload' => ['duration_months' => 1]])
        ->assertForbidden();
    expect(app(SubscriptionAccessService::class)->binding((int) $this->tenant->id)['app_client_id'])->toBe('shopify-client-id')
        ->and(DB::table('subscription_lifecycle_events')->where('source', 'shopify_embedded_admin')->count())->toBe(0);
});

test('a legacy configured store cannot be silently replaced during first binding', function (): void {
    $access = app(SubscriptionAccessService::class);
    DB::table('subscription_module_settings')->where('tenant_id', $this->tenant->id)->update(['metadata' => null, 'shopify_store_key' => 'wholesale']);
    expect(fn () => $access->bindStore((int) $this->tenant->id, ShopifyStores::find('retail', true), $this->actor))->toThrow(HttpException::class)
        ->and($access->binding((int) $this->tenant->id))->toBeNull()
        ->and(DB::table('subscription_module_settings')->where('tenant_id', $this->tenant->id)->value('shopify_store_key'))->toBe('wholesale');
});

test('verified store plus manager can save an encrypted request but cannot change a contract or send an email', function (): void {
    $before = (array) DB::table('subscription_contracts')->find($this->contract);
    $this->withHeader('Authorization', 'Bearer '.retailShopifySessionToken())
        ->postJson(route('shopify.app.api.subscriptions.contracts.action', ['contract' => $this->contract]), ['action' => 'send_payment_update_email', 'payload' => []])
        ->assertOk()->assertJsonPath('status', 'intent_recorded')->assertJsonPath('message', 'Request saved for review. No Shopify change, payment email or charge was sent.');
    $event = DB::table('subscription_lifecycle_events')->where('source', 'shopify_embedded_admin')->first();
    $envelope = json_decode($event->after_payload, true);
    expect(json_decode(Crypt::decryptString($envelope['encrypted']), true))->toBe([])
        ->and(json_decode($event->metadata, true)['provider_binding']['api_version'])->toBe('2026-10')
        ->and((array) DB::table('subscription_contracts')->find($this->contract))->toBe($before)
        ->and(DB::table('subscription_billing_attempts')->count())->toBe(0);
    expect(fn () => app(SubscriptionAccessService::class)->assertCanExecute((int) $this->tenant->id))->toThrow(HttpException::class);
    Http::assertNothingSent();
});

test('strict intent validation rejects card data unknown nested metadata and incomplete addresses before saving', function (string $action, array $payload): void {
    expect(fn () => app(SubscriptionModuleService::class)->recordAdminAction((int) $this->tenant->id, $this->contract, $action, (int) $this->actor->id, $payload))->toThrow(ValidationException::class);
    expect(DB::table('subscription_lifecycle_events')->where('source', 'shopify_embedded_admin')->count())->toBe(0);
})->with([
    ['send_payment_update_email', ['card_number' => '4111111111111111']],
    ['pause', ['duration_months' => 1, 'metadata' => ['cvv' => '123']]],
    ['update_shipping_address', ['address' => ['address1' => '1 Oak', 'city' => 'Asheville']]],
    ['update_shipping_address', ['address' => ['first_name' => 'A', 'last_name' => 'B', 'address1' => '1 Oak', 'city' => 'Asheville', 'zip' => '28801', 'country_code' => 'US', 'card' => '4111111111111111']]],
    ['update_next_billing_date', ['next_billing_at' => '2027-01-01']],
    ['swap_product', ['product_variant_gid' => 'gid://shopify/ProductVariant/not-a-number']],
]);

test('future address intent is encrypted and leaves existing contract and order evidence unchanged', function (): void {
    $before = (array) DB::table('subscription_contracts')->find($this->contract);
    app(SubscriptionModuleService::class)->recordCustomerCandleClubAction($this->profile, 'update_shipping_address', [
        'address' => ['first_name' => 'Alex', 'last_name' => 'Merchant', 'address1' => '1 Private Oak Road', 'city' => 'Asheville', 'zip' => '28801', 'country_code' => 'US'],
    ]);
    $event = DB::table('subscription_lifecycle_events')->where('source', 'mobile_app')->first();
    expect($event->after_payload)->not->toContain('Private Oak')
        ->and(json_decode(Crypt::decryptString(json_decode($event->after_payload, true)['encrypted']), true)['address']['address1'])->toBe('1 Private Oak Road')
        ->and((array) DB::table('subscription_contracts')->find($this->contract))->toBe($before);
    Http::assertNothingSent();
});

test('binding rejects cross-tenant store app rotation and client replacement while surviving setup review', function (): void {
    $access = app(SubscriptionAccessService::class);
    $original = $access->binding((int) $this->tenant->id);
    expect(fn () => app(SubscriptionModuleService::class)->saveCandleClubSettings((int) $this->tenant->id, ['provider_binding' => ['shop_domain' => 'other.myshopify.com']], (int) $this->actor->id))->toThrow(ValidationException::class);
    app(SubscriptionModuleService::class)->saveCandleClubSettings((int) $this->tenant->id, ['allowed_pauses_per_commitment' => 2], (int) $this->actor->id);
    $batch = app(SubscriptionModuleService::class)->createMigrationDryRun((int) $this->tenant->id, (int) $this->actor->id, []);
    expect($batch['status'])->toBe('needs_review')
        ->and($access->binding((int) $this->tenant->id))->toBe($original);
    $store = ShopifyStores::find('retail', true);
    $store['client_id'] = 'other-installation-app';
    expect(fn () => $access->bindStore((int) $this->tenant->id, $store, $this->actor))->toThrow(HttpException::class);
    ShopifyStore::query()->where('store_key', 'retail')->update(['tenant_id' => Tenant::query()->create(['name' => 'Other', 'slug' => 'other'])->id]);
    expect(fn () => $access->assertBinding((int) $this->tenant->id))->toThrow(HttpException::class)
        ->and($access->binding((int) $this->tenant->id))->toBe($original);
});

test('another tenant contract cannot be targeted by a manager request', function (): void {
    $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other']);
    $contract = DB::table('subscription_contracts')->insertGetId(['tenant_id' => $other->id, 'shopify_subscription_contract_gid' => 'gid://shopify/SubscriptionContract/102', 'created_at' => now(), 'updated_at' => now()]);
    expect(app(SubscriptionModuleService::class)->recordAdminAction((int) $this->tenant->id, $contract, 'pause', (int) $this->actor->id, ['duration_months' => 1])['status'])->toBe('contract_not_found');
});

test('verified webhook evidence is encrypted deduplicated store-bound and never changes a contract', function (): void {
    $service = app(SubscriptionModuleService::class);
    $store = ShopifyStores::find('retail', true);
    $payload = ['id' => 101, 'status' => 'CANCELLED', 'customer' => ['email' => 'private@example.com']];
    expect($service->recordShopifyWebhook((int) $this->tenant->id, 'subscription_contracts/update', $payload, $store, 'delivery-101'))->toBeTrue()
        ->and($service->recordShopifyWebhook((int) $this->tenant->id, 'subscription_contracts/update', $payload, $store, 'delivery-101'))->toBeTrue()
        ->and($service->recordShopifyWebhook((int) $this->tenant->id, 'subscription_contracts/update', $payload, [...$store, 'shop' => 'other.myshopify.com'], 'delivery-102'))->toBeFalse()
        ->and($service->recordShopifyWebhook((int) $this->tenant->id, 'subscription_contracts/update', $payload, $store, ''))->toBeFalse();
    $events = DB::table('subscription_lifecycle_events')->where('source', 'shopify_webhook')->get();
    expect($events)->toHaveCount(1)
        ->and($events[0]->status)->toBe('received_unprocessed')
        ->and($events[0]->after_payload)->not->toContain('private@example.com')
        ->and(json_decode(Crypt::decryptString(json_decode($events[0]->after_payload, true)['encrypted']), true))->toBe($payload)
        ->and(DB::table('subscription_contracts')->where('id', $this->contract)->value('status'))->toBe('active');
    Http::assertNothingSent();
});

test('webhook HTTP boundary verifies the actual raw-body HMAC before retaining evidence', function (): void {
    $body = json_encode(['id' => 101, 'status' => 'PAUSED'], JSON_THROW_ON_ERROR);
    $server = [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'modernforestry.myshopify.com',
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'http-delivery-1',
        'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, 'shopify-client-secret', true)),
    ];
    $route = route('shopify.webhooks.subscription-contracts.update');
    $this->call('POST', $route, [], [], [], [...$server, 'HTTP_X_SHOPIFY_HMAC_SHA256' => 'invalid'], $body)->assertUnauthorized();
    expect(DB::table('subscription_lifecycle_events')->where('source', 'shopify_webhook')->count())->toBe(0);
    $this->call('POST', $route, [], [], [], $server, $body)->assertOk();
    $this->call('POST', $route, [], [], [], $server, $body)->assertOk();
    expect(DB::table('subscription_lifecycle_events')->where('source', 'shopify_webhook')->count())->toBe(1);
    Http::assertNothingSent();
});

test('setup preserves omitted policy fields creation time and original binding through cutover review', function (): void {
    $service = app(SubscriptionModuleService::class);
    $tenantId = (int) $this->tenant->id;
    $actorId = (int) $this->actor->id;
    $binding = app(SubscriptionAccessService::class)->binding($tenantId);
    $service->saveCandleClubSettings($tenantId, ['commitment_months' => 9], $actorId);
    $created = DB::table('subscription_candle_club_settings')->where('tenant_id', $tenantId)->value('created_at');
    $service->saveCandleClubSettings($tenantId, ['allowed_pauses_per_commitment' => 3], $actorId);
    $batch = $service->createMigrationDryRun($tenantId, $actorId, [[
        'id' => 'review-only', 'customer' => ['email' => 'member@example.com'],
        'shopify_product_variant_gid' => 'gid://shopify/ProductVariant/11',
        'shopify_selling_plan_gid' => 'gid://shopify/SellingPlan/11',
    ]]);
    expect($service->approveCutover($tenantId, $batch['id'], $actorId, true)['status'])->toBe('cutover_review_requested')
        ->and(app(SubscriptionAccessService::class)->binding($tenantId))->toBe($binding)
        ->and(DB::table('subscription_candle_club_settings')->where('tenant_id', $tenantId)->value('commitment_months'))->toBe(9)
        ->and(DB::table('subscription_candle_club_settings')->where('tenant_id', $tenantId)->value('created_at'))->toBe($created)
        ->and(app(SubscriptionAccessService::class)->readiness($tenantId)['provider_execution_enabled'])->toBeFalse();
});

test('production voting-code request reports unavailable delivery without creating tokens', function (): void {
    $environment = app()->environment();
    app()->instance('env', 'production');
    try {
        $result = app(SubscriptionModuleService::class)->requestVoteCode((int) $this->tenant->id, 999, 'member@example.com');
        expect($result['status'])->toBe('verification_delivery_unavailable')
            ->and($result['message'])->toContain('No code has been sent')
            ->and(DB::table('subscription_voter_verification_tokens')->count())->toBe(0);
    } finally {
        app()->instance('env', $environment);
    }
    Http::assertNothingSent();
});

test('a valid code issued while open cannot vote after the poll closes or consume its token', function (): void {
    DB::table('subscription_contracts')->where('id', $this->contract)->update(['metadata' => json_encode(['normalized_email' => 'closed-poll@example.test'])]);
    $poll = DB::table('subscription_polls')->insertGetId(['tenant_id' => $this->tenant->id, 'title' => 'Closing poll', 'status' => 'open', 'opens_at' => now()->subHour(), 'closes_at' => now()->addHour(), 'share_token' => Str::random(40), 'created_at' => now(), 'updated_at' => now()]);
    $option = DB::table('subscription_poll_options')->insertGetId(['tenant_id' => $this->tenant->id, 'subscription_poll_id' => $poll, 'label' => 'Test option', 'created_at' => now(), 'updated_at' => now()]);
    $service = app(SubscriptionModuleService::class);
    $code = $service->requestVoteCode((int) $this->tenant->id, $poll, 'closed-poll@example.test');
    expect($code['ok'])->toBeTrue();
    DB::table('subscription_polls')->where('id', $poll)->update(['status' => 'closed', 'closes_at' => now()->subSecond()]);
    $result = $service->castVoteWithCode((int) $this->tenant->id, $poll, $option, $code['verification_token_id'], $code['debug_code']);
    expect($result['ok'])->toBeFalse()->and($result['status'])->toBe('poll_not_open')
        ->and(DB::table('subscription_votes')->count())->toBe(0)
        ->and(DB::table('subscription_voter_verification_tokens')->where('id', $code['verification_token_id'])->value('status'))->toBe('pending');
});

test('mobile customer cannot bypass the add-on through an older installed client', function (): void {
    TenantModuleEntitlement::query()->where('tenant_id', $this->tenant->id)->update(['enabled_status' => 'disabled']);
    app()->forgetInstance(TenantModuleAccessResolver::class);
    $this->withToken('mf-test-profile:'.$this->profile->id)
        ->getJson('/api/mobile/v1/modern-forestry/account/candle-club')
        ->assertOk()->assertJsonPath('data.status', 'module_locked')->assertJsonPath('data.eligible', false);
    $this->withToken('mf-test-profile:'.$this->profile->id)
        ->postJson('/api/mobile/v1/modern-forestry/account/candle-club/action', ['action' => 'pause', 'duration_months' => 1])
        ->assertForbidden();
    expect(DB::table('subscription_lifecycle_events')->where('source', 'mobile_app')->count())->toBe(0);
});

test('storefront refuses a conflicting client-selected store even with a known shop', function (): void {
    $request = Request::create('/shopify/marketing/candle-club/poll', 'GET', ['shop' => 'modernforestry.myshopify.com', 'store_key' => 'wholesale']);
    $response = app(SubscriptionStorefrontController::class)->poll($request, app(TenantResolver::class), app(SubscriptionModuleService::class));
    expect($response->getStatusCode())->toBe(422);
});

test('app proxy POST body cannot replace the signed query shop', function (): void {
    $request = Request::create('/shopify/marketing/candle-club/poll?shop=modernforestry.myshopify.com', 'POST', ['shop' => 'other.myshopify.com']);
    $request->attributes->set('marketing_storefront_auth_mode', 'app_proxy');
    $response = app(SubscriptionStorefrontController::class)->poll($request, app(TenantResolver::class), app(SubscriptionModuleService::class));
    expect($response->getStatusCode())->toBe(422);
});

test('legacy empty-body HMAC compatibility cannot replace the signed subscription shop', function (): void {
    $other = Tenant::query()->create(['name' => 'Other store', 'slug' => 'other-store']);
    configureEmbeddedWholesaleStore((int) $other->id);
    $path = '/shopify/marketing/candle-club/vote/code';
    $timestamp = (string) time();
    $query = 'shop=modernforestry.myshopify.com';
    $signature = hash_hmac('sha256', implode("\n", [$timestamp, 'POST', $path, $query, hash('sha256', '')]), 'shopify-client-secret');
    $this->withHeaders(['X-Marketing-Timestamp' => $timestamp, 'X-Marketing-Signature' => $signature])
        ->postJson($path.'?'.$query, ['shop' => 's2vscq-rf.myshopify.com', 'poll_id' => 1, 'identifier' => 'member@example.com'])
        ->assertStatus(422)->assertJsonPath('error.code', 'missing_store_context');
    expect(DB::table('subscription_voter_verification_tokens')->count())->toBe(0);
});

test('public poll link is closed when its tenant loses the add-on', function (): void {
    $poll = DB::table('subscription_polls')->insertGetId(['tenant_id' => $this->tenant->id, 'title' => 'Private poll', 'status' => 'open', 'share_token' => $token = Str::random(40), 'created_at' => now(), 'updated_at' => now()]);
    TenantModuleEntitlement::query()->where('tenant_id', $this->tenant->id)->update(['enabled_status' => 'disabled']);
    app()->forgetInstance(TenantModuleAccessResolver::class);
    $this->get(route('subscriptions.public.poll', ['poll' => $poll, 'token' => $token]))->assertForbidden()->assertDontSeeText('Private poll');
});

test('demo session never seeds a poll records an action or pollutes merchant metrics', function (): void {
    DB::table('subscription_contracts')->insert(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile->id, 'shopify_subscription_contract_gid' => 'gid://evergrove/AppReviewSubscriptionContract/demo', 'status' => 'active', 'is_candle_club' => true, 'metadata' => json_encode(['app_review_demo' => true]), 'created_at' => now(), 'updated_at' => now()]);
    $session = new ModernForestryMobileCustomerSession($this->profile, 'server-verified-demo', ['source_type' => 'app_review_demo']);
    $account = app(ModernForestryMobileAccountService::class);
    $before = DB::table('subscription_lifecycle_events')->count();
    $settings = DB::table('subscription_candle_club_settings')->count();
    expect($account->candleClub($session)['preview'])->toBeTrue()
        ->and($account->candleClubAction($session, ['action' => 'vote', 'poll_id' => 0, 'option_id' => 0])['status'])->toBe('preview_recorded')
        ->and($account->candleClubScentFeedback($session, 999, ['body' => 'demo'])['status'])->toBe('preview_recorded')
        ->and(DB::table('subscription_lifecycle_events')->count())->toBe($before)
        ->and(DB::table('subscription_votes')->count())->toBe(0)
        ->and(DB::table('subscription_polls')->count())->toBe(0)
        ->and(DB::table('subscription_candle_club_settings')->count())->toBe($settings)
        ->and(app(SubscriptionModuleService::class)->adminPayload((int) $this->tenant->id)['metrics']['active_subscribers'])->toBe(1);
    Http::assertNothingSent();
});

test('retained historical demo and unlinked votes never inflate real poll totals', function (): void {
    $demoContract = DB::table('subscription_contracts')->insertGetId([
        'tenant_id' => $this->tenant->id, 'shopify_subscription_contract_gid' => 'gid://evergrove/AppReviewSubscriptionContract/old-demo',
        'status' => 'active', 'is_candle_club' => true, 'metadata' => json_encode(['app_review_demo' => true]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $poll = DB::table('subscription_polls')->insertGetId(['tenant_id' => $this->tenant->id, 'title' => 'Real poll', 'status' => 'open', 'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(), 'share_token' => Str::random(40), 'created_at' => now(), 'updated_at' => now()]);
    $option = DB::table('subscription_poll_options')->insertGetId(['tenant_id' => $this->tenant->id, 'subscription_poll_id' => $poll, 'label' => 'Real option', 'created_at' => now(), 'updated_at' => now()]);
    foreach ([$this->contract => 'gid://shopify/SubscriptionContract/101', $demoContract => 'gid://evergrove/AppReviewSubscriptionContract/old-demo'] as $contract => $gid) {
        DB::table('subscription_votes')->insert(['tenant_id' => $this->tenant->id, 'subscription_poll_id' => $poll, 'subscription_poll_option_id' => $option, 'subscription_contract_id' => $contract, 'shopify_subscription_contract_gid' => $gid, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('subscription_votes')->insert(['tenant_id' => $this->tenant->id, 'subscription_poll_id' => $poll, 'subscription_poll_option_id' => $option, 'subscription_contract_id' => null, 'shopify_subscription_contract_gid' => 'gid://shopify/SubscriptionContract/999', 'created_at' => now(), 'updated_at' => now()]);
    $demoPoll = DB::table('subscription_polls')->insertGetId(['tenant_id' => $this->tenant->id, 'title' => 'Old demo poll', 'description' => 'App Review demo ballot for Candle Club scent voting.', 'status' => 'open', 'share_token' => $token = Str::random(40), 'created_at' => now(), 'updated_at' => now()]);
    $service = app(SubscriptionModuleService::class);
    $payload = $service->adminPayload((int) $this->tenant->id);
    expect($payload['active_poll']['id'])->toBe($poll)
        ->and($payload['recent_votes'])->toBe(1)
        ->and($service->pollPayload($poll)['options'][0]['votes'])->toBe(1)
        ->and($service->pollPayload($demoPoll))->toBeNull()
        ->and(DB::table('subscription_votes')->count())->toBe(3)
        ->and(DB::table('subscription_contracts')->count())->toBe(2);
    $this->get(route('subscriptions.public.poll', ['poll' => $demoPoll, 'token' => $token]))->assertNotFound();
});
