<?php

require_once __DIR__.'/../ShopifyEmbeddedTestHelpers.php';

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\MessagingConversation;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Marketing\MessagingContactChannelStateService;
use App\Services\Marketing\MessagingConversationService;
use App\Services\Shopify\ShopifyAppContentService;

function modernForestryAppProxyHeaders(string $path, array $query = [], string $body = '', string $secret = 'modern-forestry-proxy-secret', string $method = 'GET'): array
{
    $timestamp = (string) time();
    $canonicalQuery = modernForestryStorefrontCanonicalQuery($query);
    $payload = implode("\n", [$timestamp, strtoupper($method), $path, $canonicalQuery, hash('sha256', $body)]);
    $signature = hash_hmac('sha256', $payload, $secret);

    return [
        'X-Marketing-Timestamp' => $timestamp,
        'X-Marketing-Signature' => $signature,
    ];
}

/**
 * @param  array<string,mixed>  $query
 */
function modernForestryStorefrontCanonicalQuery(array $query): string
{
    if ($query === []) {
        return '';
    }

    ksort($query);
    $parts = [];
    foreach ($query as $key => $value) {
        if (is_array($value)) {
            $value = modernForestryStorefrontCanonicalQuery($value);
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif ($value === null) {
            $value = '';
        } else {
            $value = (string) $value;
        }

        $parts[] = rawurlencode((string) $key).'='.rawurlencode((string) $value);
    }

    return implode('&', $parts);
}

function modernForestryGrantMessagingEntitlement(Tenant $tenant): void
{
    \App\Models\TenantModuleEntitlement::query()->updateOrCreate(
        [
            'tenant_id' => $tenant->id,
            'module_key' => 'messaging',
        ],
        [
            'availability_status' => 'available',
            'enabled_status' => 'enabled',
            'billing_status' => 'add_on_comped',
            'currency' => 'USD',
            'entitlement_source' => 'test',
            'price_source' => 'test',
        ]
    );
}

function modernForestryRetailSettingsApiHeaders(array $headers = []): array
{
    return array_merge([
        'Authorization' => 'Bearer '.retailShopifySessionToken(),
    ], $headers);
}

function modernForestryResponsesApiHeaders(array $headers = []): array
{
    return array_merge([
        'Authorization' => 'Bearer '.retailShopifySessionToken(),
    ], $headers);
}

beforeEach(function (): void {
    $this->withoutVite();
});

test('modern forestry edit app page exposes the app content editor', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $response = $this->get(route('shopify.app.edit', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('App Content')
        ->assertSeeText('Customer Dashboard')
        ->assertSeeText('Mobile Home')
        ->assertSeeText('Draft Preview')
        ->assertSeeText('Publish Live')
        ->assertSeeText('Update customer dashboard and mobile app copy.')
        ->assertSee('const contentPayload = collectPayload();', false)
        ->assertSee('body: JSON.stringify(contentPayload)', false);
});

test('modern forestry settings page links to edit app instead of rendering the full editor', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $response = $this->get(route('shopify.app.settings', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('Edit App')
        ->assertSeeText('Open Edit App')
        ->assertDontSee('id="app-content-form"', false);
});

test('modern forestry app content draft and publish snapshots persist separately', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $payload = [
        'brand_name' => 'Modern Forestry',
        'hero_eyebrow' => 'Account',
        'hero_title' => 'A calmer account page',
        'hero_body' => 'Short, direct, and ready for customers.',
        'primary_cta_label' => 'View rewards',
        'secondary_cta_label' => 'View orders',
        'rewards_title' => 'Rewards',
        'rewards_body' => 'Redeem on Shopify checkout.',
        'orders_title' => 'Orders',
        'orders_body' => 'Reorder with Shopify cart handoff.',
        'support_title' => 'Support',
        'support_body' => 'Need help? Reach out.',
        'support_cta_label' => 'Contact support',
        'support_email' => 'support@modernforestry.com',
        'support_url' => 'https://modernforestry.com/support',
        'privacy_url' => 'https://modernforestry.com/policies/privacy-policy',
        'terms_url' => 'https://modernforestry.com/policies/terms-of-service',
        'data_deletion_url' => 'https://modernforestry.com/pages/data-requests',
        'data_deletion_email' => 'privacy@modernforestry.com',
        'empty_rewards' => 'No rewards yet.',
        'empty_orders' => 'No orders yet.',
        'account_note' => 'Published copy only.',
    ];

    $draftResponse = $this
        ->withHeaders(modernForestryRetailSettingsApiHeaders())
        ->postJson(route('shopify.app.api.settings.content.save', [], false), $payload);

    $draftResponse->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.settings.draft.hero_title', 'A calmer account page')
        ->assertJsonPath('data.settings.published', null);

    $storedDraft = TenantMarketingSetting::query()
        ->where('tenant_id', $tenant->id)
        ->where('key', ShopifyAppContentService::SETTING_KEY)
        ->first();

    expect($storedDraft)->not->toBeNull()
        ->and(data_get($storedDraft?->value, 'draft.hero_title'))->toBe('A calmer account page')
        ->and(data_get($storedDraft?->value, 'published'))->toBeNull();

    $publishResponse = $this
        ->withHeaders(modernForestryRetailSettingsApiHeaders())
        ->postJson(route('shopify.app.api.settings.content.publish', [], false), $payload);

    $publishResponse->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.settings.published.hero_title', 'A calmer account page');

    $storedPublished = TenantMarketingSetting::query()
        ->where('tenant_id', $tenant->id)
        ->where('key', ShopifyAppContentService::SETTING_KEY)
        ->first();

    expect(data_get($storedPublished?->value, 'published.hero_title'))->toBe('A calmer account page')
        ->and(data_get($storedPublished?->value, 'published.support_url'))->toBe('https://modernforestry.com/support')
        ->and(data_get($storedPublished?->value, 'published.data_deletion_url'))->toBe('https://modernforestry.com/pages/data-requests');
});

test('modern forestry account dashboard renders rewards and Shopify reorder links', function (): void {
    config()->set('marketing.shopify.signing_secret', 'modern-forestry-proxy-secret');

    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    TenantMarketingSetting::query()->updateOrCreate(
        [
            'tenant_id' => $tenant->id,
            'key' => ShopifyAppContentService::SETTING_KEY,
        ],
        [
            'value' => [
                'draft' => [
                    'brand_name' => 'Modern Forestry',
                    'hero_eyebrow' => 'Account',
                    'hero_title' => 'A calmer account page',
                    'hero_body' => 'Draft copy should stay hidden.',
                    'primary_cta_label' => 'View rewards',
                    'secondary_cta_label' => 'Review orders',
                    'rewards_title' => 'Rewards',
                    'rewards_body' => 'Redeem on Shopify checkout.',
                    'orders_title' => 'Recent orders',
                    'orders_body' => 'Reorder with Shopify cart handoff.',
                    'support_title' => 'Support',
                    'support_body' => 'Need help?',
                    'support_cta_label' => 'Contact support',
                    'support_email' => 'support@modernforestry.com',
                    'support_url' => null,
                    'privacy_url' => 'https://modernforestry.com/policies/privacy-policy',
                    'terms_url' => 'https://modernforestry.com/policies/terms-of-service',
                    'data_deletion_url' => null,
                    'data_deletion_email' => 'privacy@modernforestry.com',
                    'empty_rewards' => 'No rewards yet.',
                    'empty_orders' => 'No orders yet.',
                    'account_note' => 'Draft copy only.',
                ],
                'published' => [
                    'brand_name' => 'Modern Forestry',
                    'hero_eyebrow' => 'Account',
                    'hero_title' => 'Your account',
                    'hero_body' => 'Check rewards and orders.',
                    'primary_cta_label' => 'View rewards',
                    'secondary_cta_label' => 'Review orders',
                    'rewards_title' => 'Rewards',
                    'rewards_body' => 'Redeem on Shopify checkout.',
                    'orders_title' => 'Recent orders',
                    'orders_body' => 'Reorder with Shopify cart handoff.',
                    'support_title' => 'Support',
                    'support_body' => 'Need help?',
                    'support_cta_label' => 'Contact support',
                    'support_email' => 'support@modernforestry.com',
                    'support_url' => 'https://modernforestry.com/support',
                    'privacy_url' => 'https://modernforestry.com/policies/privacy-policy',
                    'terms_url' => 'https://modernforestry.com/policies/terms-of-service',
                    'data_deletion_url' => 'https://modernforestry.com/pages/data-requests',
                    'data_deletion_email' => 'privacy@modernforestry.com',
                    'empty_rewards' => 'No rewards yet.',
                    'empty_orders' => 'No orders yet.',
                    'account_note' => 'Published support note.',
                ],
                'published_at' => now()->toIso8601String(),
                'draft_updated_at' => now()->toIso8601String(),
            ],
            'description' => 'Modern Forestry customer dashboard copy.',
        ]
    );

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Forest',
        'last_name' => 'Customer',
        'email' => 'customer@modernforestry.test',
        'normalized_email' => 'customer@modernforestry.test',
        'phone' => '+15555550123',
        'normalized_phone' => '+15555550123',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => true,
    ]);

    MarketingProfileLink::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:123456',
    ]);

    $order = Order::query()->create([
        'tenant_id' => $tenant->id,
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_customer_id' => '123456',
        'ordered_at' => now()->subDays(2),
        'order_number' => '#MF-1001',
        'status' => 'complete',
        'currency_code' => 'USD',
        'total_price' => 48.50,
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'shopify_variant_id' => 987654321,
        'quantity' => 2,
        'ordered_qty' => 2,
        'raw_title' => 'Cedar Candle',
        'raw_variant' => '12 oz',
    ]);

    $query = [
        'shop' => 'modernforestry.myshopify.com',
        'store_key' => 'retail',
        'email' => 'customer@modernforestry.test',
        'phone' => '+15555550123',
        'logged_in_customer_id' => '123456',
    ];
    $response = $this
        ->withHeaders(modernForestryAppProxyHeaders('/shopify/marketing/v1/account', $query))
        ->get(route('marketing.shopify.v1.account', $query));

    $response->assertOk()
        ->assertSeeText('Your account')
        ->assertSeeText('Check rewards and orders.')
        ->assertSeeText('Reorder in Shopify')
        ->assertSeeText('Cedar Candle')
        ->assertSeeText('Contact support')
        ->assertSeeText('Privacy')
        ->assertSeeText('Data requests')
        ->assertDontSeeText('Draft copy should stay hidden.')
        ->assertDontSeeText('Draft copy only.');
});

test('modern forestry account dashboard can post into the shared messages thread and surface replies', function (): void {
    config()->set('marketing.shopify.signing_secret', 'modern-forestry-proxy-secret');

    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);
    modernForestryGrantMessagingEntitlement($tenant);

    TenantMarketingSetting::query()->updateOrCreate(
        [
            'tenant_id' => $tenant->id,
            'key' => ShopifyAppContentService::SETTING_KEY,
        ],
        [
            'value' => [
                'draft' => [
                    'brand_name' => 'Modern Forestry',
                    'hero_eyebrow' => 'Account',
                    'hero_title' => 'Your account',
                    'hero_body' => 'Check rewards and messages.',
                    'primary_cta_label' => 'View rewards',
                    'secondary_cta_label' => 'Review orders',
                    'rewards_title' => 'Rewards',
                    'rewards_body' => 'Redeem on Shopify checkout.',
                    'orders_title' => 'Recent orders',
                    'orders_body' => 'Reorder with Shopify cart handoff.',
                    'support_title' => 'Support',
                    'support_body' => 'Need help?',
                    'support_cta_label' => 'Contact support',
                    'support_email' => 'support@modernforestry.com',
                    'support_url' => null,
                    'privacy_url' => 'https://modernforestry.com/policies/privacy-policy',
                    'terms_url' => 'https://modernforestry.com/policies/terms-of-service',
                    'data_deletion_url' => null,
                    'data_deletion_email' => 'privacy@modernforestry.com',
                    'empty_rewards' => 'No rewards yet.',
                    'empty_orders' => 'No orders yet.',
                    'account_note' => 'Draft copy only.',
                ],
                'published' => [
                    'brand_name' => 'Modern Forestry',
                    'hero_eyebrow' => 'Account',
                    'hero_title' => 'Your account',
                    'hero_body' => 'Check rewards and messages.',
                    'primary_cta_label' => 'View rewards',
                    'secondary_cta_label' => 'Review orders',
                    'rewards_title' => 'Rewards',
                    'rewards_body' => 'Redeem on Shopify checkout.',
                    'orders_title' => 'Recent orders',
                    'orders_body' => 'Reorder with Shopify cart handoff.',
                    'support_title' => 'Support',
                    'support_body' => 'Need help?',
                    'support_cta_label' => 'Contact support',
                    'support_email' => 'support@modernforestry.com',
                    'support_url' => 'https://modernforestry.com/support',
                    'privacy_url' => 'https://modernforestry.com/policies/privacy-policy',
                    'terms_url' => 'https://modernforestry.com/policies/terms-of-service',
                    'data_deletion_url' => 'https://modernforestry.com/pages/data-requests',
                    'data_deletion_email' => 'privacy@modernforestry.com',
                    'empty_rewards' => 'No rewards yet.',
                    'empty_orders' => 'No orders yet.',
                    'account_note' => 'Published support note.',
                ],
                'published_at' => now()->toIso8601String(),
                'draft_updated_at' => now()->toIso8601String(),
            ],
            'description' => 'Modern Forestry customer dashboard copy.',
        ]
    );

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Forest',
        'last_name' => 'Customer',
        'email' => 'customer@modernforestry.test',
        'normalized_email' => 'customer@modernforestry.test',
        'phone' => '+15555550123',
        'normalized_phone' => '+15555550123',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => true,
    ]);

    MarketingProfileLink::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:123456',
    ]);

    $messageBody = 'I need help with my Candle Club order.';
    $query = [
        'shop' => 'modernforestry.myshopify.com',
        'store_key' => 'retail',
        'email' => 'customer@modernforestry.test',
        'phone' => '+15555550123',
        'logged_in_customer_id' => '123456',
        'message_body' => $messageBody,
    ];
    $postResponse = $this
        ->withHeaders(modernForestryAppProxyHeaders('/shopify/marketing/v1/message', $query, '', 'modern-forestry-proxy-secret', 'POST'))
        ->post(route('marketing.shopify.v1.message', $query), [
        ]);

    $postResponse->assertOk()
        ->assertSeeText('Message sent. We will continue the conversation here and in the Shopify inbox.')
        ->assertSeeText($messageBody)
        ->assertSeeText('Messages')
        ->assertSeeText('Conversation');

    $conversation = MessagingConversation::query()->where('marketing_profile_id', $profile->id)->firstOrFail();
    expect((string) $conversation->status)->toBe('open');

    $this->withHeaders(modernForestryResponsesApiHeaders())
        ->getJson(route('shopify.app.api.messaging.responses.index', ['channel' => 'sms']))
        ->assertOk()
        ->assertJsonPath('data.conversations.0.id', (int) $conversation->id);

    app(MessagingConversationService::class)->appendMessage($conversation, [
        'marketing_profile_id' => $profile->id,
        'channel' => 'sms',
        'direction' => 'outbound',
        'provider' => 'twilio',
        'body' => 'Thanks. We replied from the Shopify inbox.',
        'normalized_body' => 'Thanks. We replied from the Shopify inbox.',
        'from_identity' => '+18339625949',
        'to_identity' => '+15555550123',
        'sent_at' => now()->addMinute(),
        'message_type' => 'normal',
    ]);

    $getResponse = $this
        ->withHeaders(modernForestryAppProxyHeaders('/shopify/marketing/v1/account', $query))
        ->get(route('marketing.shopify.v1.account', $query));

    $getResponse->assertOk()
        ->assertSeeText('Thanks. We replied from the Shopify inbox.')
        ->assertSeeText('Modern Forestry');
});

test('modern forestry account messages fall back to support when sms is opted out', function (): void {
    config()->set('marketing.shopify.signing_secret', 'modern-forestry-proxy-secret');

    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    TenantMarketingSetting::query()->updateOrCreate(
        [
            'tenant_id' => $tenant->id,
            'key' => ShopifyAppContentService::SETTING_KEY,
        ],
        [
            'value' => [
                'draft' => app(ShopifyAppContentService::class)->defaults(),
                'published' => app(ShopifyAppContentService::class)->defaults(),
                'draft_updated_at' => now()->toIso8601String(),
                'published_at' => now()->toIso8601String(),
            ],
            'description' => 'Modern Forestry customer dashboard copy.',
        ]
    );

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Forest',
        'last_name' => 'Customer',
        'email' => 'optedout@modernforestry.test',
        'normalized_email' => 'optedout@modernforestry.test',
        'phone' => '+15555550124',
        'normalized_phone' => '+15555550124',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => true,
    ]);

    app(MessagingContactChannelStateService::class)->markSmsUnsubscribed(
        tenantId: $tenant->id,
        profile: $profile,
        phone: $profile->phone,
        reason: 'stop',
        providerSource: 'test'
    );

    $query = [
        'shop' => 'modernforestry.myshopify.com',
        'store_key' => 'retail',
        'email' => 'optedout@modernforestry.test',
        'phone' => '+15555550124',
        'logged_in_customer_id' => '456789',
        'message_body' => 'Hello?',
    ];

    $response = $this
        ->withHeaders(modernForestryAppProxyHeaders('/shopify/marketing/v1/message', $query, '', 'modern-forestry-proxy-secret', 'POST'))
        ->post(route('marketing.shopify.v1.message', $query), [
        ]);

    $response->assertOk()
        ->assertSeeText('Contact support');
    $response->assertDontSeeText('Write your message here. Our team will see it in the Messages inbox and can reply by text.');

    expect(MessagingConversation::query()->where('marketing_profile_id', $profile->id)->exists())->toBeFalse();
});

test('modern forestry account dashboard does not expose orders from email and phone alone', function (): void {
    config()->set('marketing.shopify.signing_secret', 'modern-forestry-proxy-secret');

    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Forest',
        'last_name' => 'Customer',
        'email' => 'customer@modernforestry.test',
        'normalized_email' => 'customer@modernforestry.test',
        'phone' => '+15555550123',
        'normalized_phone' => '+15555550123',
    ]);

    $order = Order::query()->create([
        'tenant_id' => $tenant->id,
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_customer_id' => '123456',
        'ordered_at' => now(),
        'order_number' => '#MF-PRIVATE',
        'total_price' => 48.50,
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'shopify_variant_id' => 987654321,
        'quantity' => 1,
        'raw_title' => 'Private Cedar Candle',
    ]);

    $query = [
        'shop' => 'modernforestry.myshopify.com',
        'store_key' => 'retail',
        'email' => 'customer@modernforestry.test',
        'phone' => '+15555550123',
    ];

    $response = $this
        ->withHeaders(modernForestryAppProxyHeaders('/shopify/marketing/v1/account', $query))
        ->get(route('marketing.shopify.v1.account', $query));

    $response->assertOk()
        ->assertSeeText('Sign in on the storefront to view orders and account activity.')
        ->assertDontSeeText('Private Cedar Candle')
        ->assertDontSeeText('#MF-PRIVATE')
        ->assertDontSeeText('Reorder in Shopify');
});

test('modern forestry account dashboard fails closed without storefront signature', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('marketing.shopify.account', [
        'shop' => 'modernforestry.myshopify.com',
        'store_key' => 'retail',
    ]))->assertStatus(401);
});
