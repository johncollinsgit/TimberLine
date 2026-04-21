<?php

use App\Models\BirthdayRewardIssuance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReferral;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Marketing\MarketingAttributionSourceMetaBuilder;
use App\Services\Marketing\MarketingProfileSyncService;
use App\Services\Marketing\StorefrontOrderLinkageService;
use App\Services\Shopify\ShopifyOrderIngestor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

test('attribution source meta builder captures shopify payload source fields and utm signals', function () {
    Carbon::setTestNow('2026-03-17 10:30:00');

    $meta = app(MarketingAttributionSourceMetaBuilder::class)->fromShopifyOrderPayload([
        'landing_site' => 'https://theforestrystudio.com/pages/rewards?utm_source=instagram&utm_medium=social&utm_campaign=spring-launch',
        'referring_site' => 'https://l.instagram.com/?u=https%3A%2F%2Ftheforestrystudio.com',
        'source_name' => 'web',
        'source_identifier' => 'instagram-ad-42',
        'note_attributes' => [
            ['name' => 'utm_content', 'value' => 'story-1'],
            ['name' => 'utm_term', 'value' => 'forest-candle'],
            ['name' => 'landing_page', 'value' => 'https://theforestrystudio.com/pages/rewards'],
        ],
    ], 'retail');

    expect($meta['utm_source'])->toBe('instagram')
        ->and($meta['utm_medium'])->toBe('social')
        ->and($meta['utm_campaign'])->toBe('spring-launch')
        ->and($meta['utm_content'])->toBe('story-1')
        ->and($meta['utm_term'])->toBe('forest-candle')
        ->and($meta['referrer'])->toContain('instagram.com')
        ->and($meta['referring_site'])->toContain('instagram.com')
        ->and($meta['landing_page'])->toBe('https://theforestrystudio.com/pages/rewards')
        ->and($meta['shopify_store_key'])->toBe('retail')
        ->and($meta['capture_context'])->toBe('shopify_order_payload')
        ->and($meta['last_enriched_at'])->toBe(now()->toIso8601String());

    Carbon::setTestNow();
});

test('attribution source meta builder captures email module query signals from landing URLs', function () {
    $meta = app(MarketingAttributionSourceMetaBuilder::class)->fromShopifyOrderPayload([
        'landing_site' => 'https://theforestrystudio.com/products/spring-favorite?utm_source=backstage&utm_medium=email&utm_campaign=backstage-email-42&mf_module_type=product-grid-4&mf_module_position=2&mf_product_id=spring-favorite&mf_tile_position=3&mf_template_key=merch-grid-4&mf_source_label=shopify-embedded-messaging-group&mf_link_label=Spring+Favorite',
    ], 'retail');

    expect($meta['email_module_type'])->toBe('product-grid-4')
        ->and($meta['email_module_position'])->toBe('2')
        ->and($meta['email_product_id'])->toBe('spring-favorite')
        ->and($meta['email_tile_position'])->toBe('3')
        ->and($meta['email_template_key'])->toBe('merch-grid-4')
        ->and($meta['email_source_label'])->toBe('shopify-embedded-messaging-group')
        ->and($meta['email_link_label'])->toBe('Spring Favorite');
});

test('attribution source meta builder derives utm meta and linkage tokens from landing URLs when fields are missing', function () {
    $builder = app(MarketingAttributionSourceMetaBuilder::class);

    $meta = $builder->fromMeta([
        'landing_site' => 'https://theforestrystudio.com/products/ember?utm_source=meta&utm_medium=paid_social&utm_campaign=spring-relaunch&fbclid=IwZXh0bgNHM&checkout_token=CHK-1234&cart_token=CART-6789&session_key=sess-42&client_id=client-55',
        'capture_context' => 'shopify_order_payload',
        'capture_contexts' => ['shopify_order_payload'],
        'confidence' => 'medium',
    ]);

    expect($meta['utm_source'])->toBe('meta')
        ->and($meta['utm_medium'])->toBe('paid_social')
        ->and($meta['utm_campaign'])->toBe('spring-relaunch')
        ->and($meta['fbclid'])->toBe('IwZXh0bgNHM')
        ->and($meta['checkout_token'])->toBe('CHK-1234')
        ->and($meta['cart_token'])->toBe('CART-6789')
        ->and($meta['session_key'])->toBe('sess-42')
        ->and($meta['session_id'])->toBe('sess-42')
        ->and($meta['client_id'])->toBe('client-55');
});

test('source meta merge backfills missing attribution query signals from landing urls', function () {
    $builder = app(MarketingAttributionSourceMetaBuilder::class);

    $merged = $builder->mergeSourceMeta(
        [
            'landing_site' => 'https://theforestrystudio.com/products/ember?utm_source=facebook&utm_medium=paid_social&utm_campaign=spring-launch&fbclid=fbclid-123',
            'capture_context' => 'shopify_order_payload',
            'capture_contexts' => ['shopify_order_payload'],
            'confidence' => 'medium',
        ],
        []
    );

    expect($merged['utm_source'])->toBe('facebook')
        ->and($merged['utm_medium'])->toBe('paid_social')
        ->and($merged['utm_campaign'])->toBe('spring-launch')
        ->and($merged['fbclid'])->toBe('fbclid-123')
        ->and((array) ($merged['field_confidence'] ?? []))->toHaveKey('utm_source');
});

test('source meta merge preserves stronger values and is idempotent', function () {
    Carbon::setTestNow('2026-03-17 09:00:00');

    $builder = app(MarketingAttributionSourceMetaBuilder::class);

    $existing = [
        'utm_source' => 'klaviyo',
        'source_system' => 'orders',
        'field_confidence' => ['utm_source' => 'high'],
        'capture_context' => 'existing_link',
        'capture_contexts' => ['existing_link'],
        'confidence' => 'high',
        'last_enriched_at' => now()->toIso8601String(),
    ];

    Carbon::setTestNow('2026-03-17 09:05:00');

    $candidate = [
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'field_confidence' => [
            'utm_source' => 'medium',
            'utm_medium' => 'medium',
        ],
        'capture_context' => 'shopify_order_payload',
        'capture_contexts' => ['shopify_order_payload'],
        'confidence' => 'medium',
    ];

    $merged = $builder->mergeSourceMeta($existing, $candidate);

    expect($merged['utm_source'])->toBe('klaviyo')
        ->and($merged['utm_medium'])->toBe('cpc')
        ->and($merged['source_system'])->toBe('orders')
        ->and($merged['capture_contexts'])->toContain('existing_link', 'shopify_order_payload')
        ->and($merged['last_enriched_at'])->toBe(now()->toIso8601String());

    Carbon::setTestNow('2026-03-17 09:10:00');

    expect($builder->mergeSourceMeta($merged, $candidate))->toBe($merged);

    Carbon::setTestNow();
});

test('marketing profile sync persists attribution source meta across order links', function () {
    $order = Order::factory()->create([
        'source' => 'shopify_retail',
        'order_type' => 'retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 4101,
        'shopify_customer_id' => '8101',
        'email' => 'attribution-sync@example.com',
    ]);

    $result = app(MarketingProfileSyncService::class)->syncOrder($order, [
        'identity_context' => [
            'email' => 'attribution-sync@example.com',
            'attribution_meta' => [
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'referrer' => 'https://www.google.com/search?q=forest+candle',
                'field_confidence' => [
                    'utm_source' => 'high',
                    'utm_medium' => 'high',
                    'referrer' => 'high',
                ],
                'capture_context' => 'shopify_order_payload',
                'capture_contexts' => ['shopify_order_payload'],
                'confidence' => 'high',
            ],
        ],
    ]);

    expect($result['profiles_created'])->toBe(1);

    $links = MarketingProfileLink::query()
        ->whereIn('source_type', ['order', 'shopify_order', 'shopify_customer'])
        ->get()
        ->keyBy('source_type');

    expect($links['order']->source_meta['utm_source'])->toBe('google')
        ->and($links['order']->source_meta['utm_medium'])->toBe('cpc')
        ->and($links['order']->source_meta['source_system'])->toBe('orders')
        ->and($links['shopify_order']->source_meta['shopify_order_id'])->toBe('4101')
        ->and($links['shopify_order']->source_meta['utm_source'])->toBe('google')
        ->and($links['shopify_customer']->source_meta['shopify_customer_id'])->toBe('8101')
        ->and($links['shopify_customer']->source_meta['utm_source'])->toBe('google');
});

test('shopify order ingest persists raw attribution meta on orders and downstream sync prefers it', function () {
    Queue::fake();

    $store = ['key' => 'retail', 'source' => 'shopify_retail'];
    $orderData = [
        'id' => 4301,
        'name' => '#4301',
        'created_at' => '2026-03-17T12:00:00Z',
        'email' => 'order-attribution@example.com',
        'phone' => '+1 (555) 123-4567',
        'source_name' => 'web',
        'source_identifier' => 'online-store',
        'landing_site' => 'https://theforestrystudio.com/pages/rewards?utm_source=google&utm_medium=cpc&utm_campaign=spring-launch',
        'source_url' => 'https://theforestrystudio.com/pages/rewards?utm_source=google&utm_medium=cpc&utm_campaign=spring-launch',
        'referring_site' => 'https://www.google.com/search?q=the+forestry+studio',
        'browser_ip' => '203.0.113.42',
        'client_details' => [
            'user_agent' => 'Mozilla/5.0',
            'accept_language' => 'en-US',
        ],
        'tags' => 'vip, repeat',
        'note_attributes' => [
            ['name' => 'utm_content', 'value' => 'hero-banner'],
            ['name' => 'utm_term', 'value' => 'forest-candle'],
        ],
        'customer' => [
            'id' => 8301,
            'first_name' => 'River',
            'last_name' => 'Stone',
            'email' => 'order-attribution@example.com',
            'phone' => '+1 (555) 123-4567',
        ],
        'line_items' => [],
    ];

    app(ShopifyOrderIngestor::class)->ingest($store, $orderData);

    $order = Order::query()->sole();

    expect($order->attribution_meta['utm_source'])->toBe('google')
        ->and($order->attribution_meta['utm_medium'])->toBe('cpc')
        ->and($order->attribution_meta['utm_campaign'])->toBe('spring-launch')
        ->and($order->attribution_meta['utm_content'])->toBe('hero-banner')
        ->and($order->attribution_meta['utm_term'])->toBe('forest-candle')
        ->and($order->attribution_meta['source_name'])->toBe('web')
        ->and($order->attribution_meta['source_identifier'])->toBe('online-store')
        ->and($order->attribution_meta['browser_ip'])->toBe('203.0.113.42')
        ->and($order->attribution_meta['user_agent'])->toBe('Mozilla/5.0')
        ->and($order->attribution_meta['order_tags'])->toContain('vip', 'repeat')
        ->and($order->attribution_meta['shopify_store_key'])->toBe('retail')
        ->and($order->attribution_meta['ingested_attribution_version'])->toBe(1);

    $result = app(MarketingProfileSyncService::class)->syncOrder($order, [
        'identity_context' => [
            'email' => 'order-attribution@example.com',
            'shopify_customer_id' => '8301',
        ],
    ]);

    expect($result['profiles_created'])->toBe(1);

    $links = MarketingProfileLink::query()
        ->whereIn('source_type', ['order', 'shopify_order', 'shopify_customer'])
        ->get()
        ->keyBy('source_type');

    expect($links['order']->source_meta['utm_source'])->toBe('google')
        ->and($links['shopify_order']->source_meta['utm_source'])->toBe('google')
        ->and($links['shopify_customer']->source_meta['utm_source'])->toBe('google');
});

test('storefront order linkage persists deterministic checkout linkage and purchase lineage', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Storefront Linkage Tenant',
        'slug' => 'storefront-linkage-tenant',
    ]);

    $order = Order::query()->create([
        'source' => 'shopify_retail',
        'tenant_id' => $tenant->id,
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 7771,
        'ordered_at' => now(),
        'order_number' => '#7771',
        'status' => 'complete',
        'attribution_meta' => [
            'checkout_token' => 'checkout-7771',
            'session_key' => 'session-7771',
            'client_id' => 'client-7771',
        ],
    ]);

    $checkoutEvent = MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenant->id,
        'event_type' => 'checkout_completed',
        'status' => 'ok',
        'source_surface' => 'shopify_storefront',
        'endpoint' => '/apps/forestry/funnel/event',
        'source_type' => 'shopify_storefront_funnel',
        'source_id' => 'checkout:checkout-7771',
        'meta' => [
            'store_key' => 'retail',
            'checkout_token' => 'checkout-7771',
            'session_key' => 'session-7771',
            'client_id' => 'client-7771',
            'utm_source' => 'facebook',
            'utm_medium' => 'paid_social',
            'utm_campaign' => 'spring-retargeting',
            'fbclid' => 'IwZXh0bgNHM',
            'fbc' => 'fb.1.1700000000.IwZXh0bgNHM',
            'fbp' => 'fb.1.1700000000.123456789',
        ],
        'occurred_at' => now()->subMinutes(8),
        'resolution_status' => 'resolved',
    ]);

    $result = app(StorefrontOrderLinkageService::class)->linkOrder($order, [], [
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
    ]);

    $order->refresh();
    $purchaseEvent = MarketingStorefrontEvent::query()
        ->where('event_type', 'purchase')
        ->where('source_type', 'shopify_storefront_purchase')
        ->latest('id')
        ->first();

    expect($result['linked'])->toBeTrue()
        ->and($result['method'])->toBe('checkout_token_exact')
        ->and((float) ($result['confidence'] ?? 0))->toBeGreaterThan(0.99)
        ->and($order->storefront_checkout_token)->toBe('checkout-7771')
        ->and($order->storefront_link_method)->toBe('checkout_token_exact')
        ->and((float) ($order->storefront_link_confidence ?? 0))->toBeGreaterThan(0.99)
        ->and((int) ($order->storefront_linked_event_id ?? 0))->toBe((int) ($purchaseEvent?->id ?? 0))
        ->and((string) ($order->attribution_meta['utm_campaign'] ?? ''))->toBe('spring-retargeting')
        ->and((string) ($order->attribution_meta['fbclid'] ?? ''))->toBe('IwZXh0bgNHM')
        ->and($purchaseEvent)->not->toBeNull()
        ->and((int) ($purchaseEvent?->meta['linked_storefront_event_id'] ?? 0))->toBe((int) $checkoutEvent->id);
});

test('storefront order linkage normalizes checkout token formats before matching', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Storefront Linkage Normalize Tenant',
        'slug' => 'storefront-linkage-normalize-tenant',
    ]);

    $order = Order::query()->create([
        'source' => 'shopify_retail',
        'tenant_id' => $tenant->id,
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 7781,
        'ordered_at' => now(),
        'order_number' => '#7781',
        'status' => 'complete',
        'attribution_meta' => [
            'checkout_token' => 'gid://shopify/Checkout/chk_token_7781?key=abc123',
        ],
    ]);

    MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenant->id,
        'event_type' => 'checkout_completed',
        'status' => 'ok',
        'source_surface' => 'shopify_storefront',
        'endpoint' => '/apps/forestry/funnel/event',
        'source_type' => 'shopify_storefront_funnel',
        'source_id' => 'checkout:chk_token_7781',
        'meta' => [
            'store_key' => 'retail',
            'checkout_token' => 'chk_token_7781',
        ],
        'occurred_at' => now()->subMinutes(4),
        'resolution_status' => 'resolved',
    ]);

    $result = app(StorefrontOrderLinkageService::class)->linkOrder($order, [], [
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
    ]);

    expect($result['linked'])->toBeTrue()
        ->and((string) ($result['method'] ?? ''))->toBe('checkout_token_exact')
        ->and((float) ($result['confidence'] ?? 0))->toBeGreaterThan(0.99);
});

test('attribution backfill command is dry run safe and enriches order linked records when executed', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Attribution Backfill Tenant',
        'slug' => 'attribution-backfill-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Ari',
        'email' => 'ari@example.com',
        'tenant_id' => $tenant->id,
    ]);

    $order = Order::query()->create([
        'source' => 'shopify_retail',
        'tenant_id' => $tenant->id,
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 5101,
        'shopify_customer_id' => '9101',
        'ordered_at' => now()->subDay(),
        'order_number' => '#5101',
        'status' => 'complete',
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'source_type' => 'order',
        'source_id' => (string) $order->id,
        'source_meta' => [
            'utm_source' => 'facebook',
            'utm_medium' => 'paid_social',
            'referrer' => 'https://l.facebook.com/l.php?u=https%3A%2F%2Ftheforestrystudio.com',
            'field_confidence' => [
                'utm_source' => 'high',
                'utm_medium' => 'high',
                'referrer' => 'high',
            ],
            'capture_context' => 'shopify_order_payload',
            'capture_contexts' => ['shopify_order_payload'],
            'confidence' => 'high',
        ],
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'source_type' => 'shopify_order',
        'source_id' => 'retail:5101',
        'source_meta' => [],
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:9101',
        'source_meta' => [],
    ]);

    $birthdayProfile = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => 3,
        'birth_day' => 17,
        'source' => 'import',
    ]);

    $referral = CandleCashReferral::query()->create([
        'referrer_marketing_profile_id' => $profile->id,
        'referred_marketing_profile_id' => $profile->id,
        'referral_code' => 'FOREST-TEST',
        'referred_identity_key' => 'profile:' . $profile->id,
        'status' => 'qualified',
        'qualifying_order_source' => 'shopify_order',
        'qualifying_order_id' => (string) $order->id,
        'qualified_at' => now()->subDay(),
        'metadata' => [],
    ]);

    $issuance = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthdayProfile->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => (int) now()->format('Y'),
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday reward',
        'status' => 'redeemed',
        'reward_value' => 10,
        'issued_at' => now()->subDays(2),
        'redeemed_at' => now()->subDay(),
        'order_id' => $order->id,
        'order_number' => '#5101',
        'order_total' => 125.50,
        'attributed_revenue' => 125.50,
        'metadata' => [],
    ]);

    $redemption = CandleCashRedemption::query()->create([
        'marketing_profile_id' => $profile->id,
        'reward_id' => 1,
        'candle_cash_spent' => 200,
        'platform' => 'shopify',
        'redemption_code' => 'CC-BACKFILL-5101',
        'status' => 'redeemed',
        'issued_at' => now()->subDays(2),
        'redeemed_at' => now()->subDay(),
        'external_order_source' => 'order',
        'external_order_id' => (string) $order->id,
        'redemption_context' => [],
    ]);

    $this->artisan('marketing:backfill-attribution-source-meta', [
        '--tenant-id' => $tenant->id,
        '--dry-run' => true,
        '--chunk' => 50,
    ])
        ->assertExitCode(0);

    expect($referral->fresh()->metadata)->toBe([])
        ->and($issuance->fresh()->metadata)->toBe([])
        ->and($redemption->fresh()->redemption_context)->toBe([]);

    $this->artisan('marketing:backfill-attribution-source-meta', [
        '--tenant-id' => $tenant->id,
        '--chunk' => 50,
    ])
        ->assertExitCode(0);

    expect($referral->fresh()->metadata['utm_source'])->toBe('facebook')
        ->and($issuance->fresh()->metadata['utm_source'])->toBe('facebook')
        ->and($redemption->fresh()->redemption_context['attribution_meta']['utm_source'])->toBe('facebook')
        ->and(MarketingProfileLink::query()
            ->where('source_type', 'shopify_order')
            ->where('source_id', 'retail:5101')
            ->firstOrFail()
            ->source_meta['utm_source'])->toBe('facebook');
});

test('attribution backfill command is a no-op when no stronger source metadata exists', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Attribution No-op Tenant',
        'slug' => 'attribution-noop-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Nora',
        'email' => 'nora@example.com',
        'tenant_id' => $tenant->id,
    ]);

    $order = Order::query()->create([
        'source' => 'shopify_retail',
        'tenant_id' => $tenant->id,
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 5201,
        'ordered_at' => now()->subDay(),
        'order_number' => '#5201',
        'status' => 'complete',
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'source_type' => 'order',
        'source_id' => (string) $order->id,
        'source_meta' => [],
    ]);

    $this->artisan('marketing:backfill-attribution-source-meta', [
        '--tenant-id' => $tenant->id,
        '--chunk' => 50,
    ])
        ->assertExitCode(0);

    expect(MarketingProfileLink::query()->sole()->source_meta)->toBe([]);
});

test('order attribution backfill command is dry run safe and fills missing order attribution meta', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Order Attribution Backfill Tenant',
        'slug' => 'order-attribution-backfill-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Moss',
        'email' => 'moss@example.com',
        'tenant_id' => $tenant->id,
    ]);

    $order = Order::query()->create([
        'source' => 'shopify_retail',
        'tenant_id' => $tenant->id,
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 5301,
        'shopify_customer_id' => '9301',
        'ordered_at' => now()->subDay(),
        'order_number' => '#5301',
        'status' => 'complete',
        'attribution_meta' => null,
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'source_type' => 'order',
        'source_id' => (string) $order->id,
        'source_meta' => [
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
            'field_confidence' => [
                'utm_source' => 'high',
                'utm_medium' => 'high',
            ],
            'capture_context' => 'shopify_order_payload',
            'capture_contexts' => ['shopify_order_payload'],
            'confidence' => 'high',
        ],
    ]);

    $this->artisan('marketing:backfill-order-attribution-meta', [
        '--tenant-id' => $tenant->id,
        '--dry-run' => true,
        '--chunk' => 50,
    ])
        ->assertExitCode(0);

    expect($order->fresh()->attribution_meta)->toBeNull();

    $this->artisan('marketing:backfill-order-attribution-meta', [
        '--tenant-id' => $tenant->id,
        '--chunk' => 50,
    ])
        ->assertExitCode(0);

    expect($order->fresh()->attribution_meta['utm_source'])->toBe('instagram')
        ->and($order->fresh()->attribution_meta['utm_medium'])->toBe('social');

    $this->artisan('marketing:backfill-order-attribution-meta', [
        '--tenant-id' => $tenant->id,
        '--chunk' => 50,
    ])
        ->assertExitCode(0);

    expect($order->fresh()->attribution_meta['utm_source'])->toBe('instagram');
});
