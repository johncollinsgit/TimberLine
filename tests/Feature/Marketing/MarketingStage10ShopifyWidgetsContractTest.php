<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashTask;
use App\Models\CustomerExternalProfile;
use App\Models\GoogleBusinessProfileConnection;
use App\Models\GoogleBusinessProfileSyncRun;
use App\Models\MarketingProfile;
use App\Models\MarketingSetting;
use App\Services\Marketing\CandleCashService;
use Illuminate\Support\Str;

function configureStage10RewardsStorefront(): void
{
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage10-retail-client');
    config()->set('services.google_gbp.enabled', true);
    config()->set('services.google_gbp.client_id', 'stage10-google-gbp-client-id');
    config()->set('services.google_gbp.client_secret', 'stage10-google-gbp-client-secret');
    config()->set('services.google_gbp.redirect_uri', 'http://localhost/marketing/candle-cash/google-business/callback');
    config()->set('services.google_gbp.scopes', 'https://www.googleapis.com/auth/business.manage');
}

function seedStage10GoogleReviewConfig(array $overrides = []): MarketingSetting
{
    return MarketingSetting::query()->updateOrCreate(
        ['key' => 'candle_cash_integration_config'],
        [
            'value' => array_merge([
                'google_review_enabled' => true,
                'google_review_url' => 'https://g.page/r/CTucm4R1-wmOEAI/review',
                'google_review_matching_strategy' => 'recent_click_name_match',
            ], $overrides),
            'description' => 'stage10 test google review config',
        ]
    );
}

function seedStage10GoogleBusinessConnection(array $overrides = []): GoogleBusinessProfileConnection
{
    return GoogleBusinessProfileConnection::query()->create(array_merge([
        'provider_key' => 'google_business_profile',
        'connection_status' => 'connected',
        'access_token' => 'gbp-access-token',
        'refresh_token' => 'gbp-refresh-token',
        'token_type' => 'Bearer',
        'expires_at' => now()->addHour(),
        'granted_scopes' => ['https://www.googleapis.com/auth/business.manage'],
        'linked_account_name' => 'accounts/123',
        'linked_account_id' => '123',
        'linked_account_display_name' => 'Modern Forestry',
        'linked_location_name' => 'locations/456',
        'linked_location_id' => '456',
        'linked_location_title' => 'Forestry HQ',
        'linked_location_place_id' => 'place-123',
        'project_approval_status' => 'approved',
    ], $overrides));
}

function seedStage10GoogleBusinessLiveConnection(array $overrides = []): GoogleBusinessProfileConnection
{
    $connection = seedStage10GoogleBusinessConnection($overrides);

    $run = GoogleBusinessProfileSyncRun::query()->create([
        'google_business_profile_connection_id' => $connection->id,
        'trigger_type' => 'manual',
        'status' => 'completed',
        'fetched_reviews_count' => 1,
        'new_reviews_count' => 1,
        'updated_reviews_count' => 0,
        'matched_reviews_count' => 0,
        'awarded_reviews_count' => 0,
        'duplicate_reviews_count' => 0,
        'unmatched_reviews_count' => 1,
        'started_at' => now()->subMinutes(10),
        'finished_at' => now()->subMinutes(4),
        'metadata' => [
            'linked_location_id' => $connection->linked_location_id,
            'linked_location_title' => $connection->linked_location_title,
        ],
    ]);

    $connection->forceFill([
        'last_synced_at' => $run->finished_at,
    ])->save();

    return $connection->fresh();
}

test('shopify v1 consent status returns unknown customer state when identity is missing', function () {
    config()->set('marketing.shopify.signing_secret', 'stage10-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage10-retail-client');

    $query = ['shop' => 'timberline.example.myshopify.com'];
    $headers = stage10SignedHeaders('GET', '/shopify/marketing/v1/consent/status', $query, '', 'stage10-secret');

    $this->withHeaders($headers)
        ->getJson(route('marketing.shopify.v1.consent.status', $query))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.state', 'unknown_customer')
        ->assertJsonPath('data.consent.sms', false)
        ->assertJsonPath('data.consent.email', false)
        ->assertJsonPath('data.verification_required', false)
        ->assertJsonPath('meta.states.0', 'unknown_customer');
});

test('shopify v1 customer-sensitive storefront reads require verified store context', function () {
    config()->set('marketing.shopify.signing_secret', 'stage10-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Store',
        'email' => 'stage10.store.scope@example.com',
        'normalized_email' => 'stage10.store.scope@example.com',
        'phone' => '5554447777',
        'normalized_phone' => '+15554447777',
    ]);

    $cases = [
        [
            'path' => '/shopify/marketing/v1/rewards/balance',
            'route' => 'marketing.shopify.v1.rewards.balance',
            'query' => ['email' => $profile->email, 'phone' => $profile->phone],
        ],
        [
            'path' => '/shopify/marketing/v1/rewards/history',
            'route' => 'marketing.shopify.v1.rewards.history',
            'query' => ['email' => $profile->email, 'phone' => $profile->phone],
        ],
        [
            'path' => '/shopify/marketing/v1/rewards/available',
            'route' => 'marketing.shopify.v1.rewards.available',
            'query' => ['email' => $profile->email],
        ],
        [
            'path' => '/shopify/marketing/v1/customer/status',
            'route' => 'marketing.shopify.v1.customer.status',
            'query' => ['email' => $profile->email, 'phone' => $profile->phone],
        ],
        [
            'path' => '/shopify/marketing/v1/consent/status',
            'route' => 'marketing.shopify.v1.consent.status',
            'query' => ['email' => $profile->email, 'phone' => $profile->phone],
        ],
        [
            'path' => '/shopify/marketing/v1/birthday/status',
            'route' => 'marketing.shopify.v1.birthday.status',
            'query' => ['email' => $profile->email, 'phone' => $profile->phone],
        ],
        [
            'path' => '/shopify/marketing/v1/candle-cash/status',
            'route' => 'marketing.shopify.v1.candle-cash.status',
            'query' => ['email' => $profile->email, 'phone' => $profile->phone],
        ],
    ];

    foreach ($cases as $case) {
        $headers = stage10SignedHeaders('GET', $case['path'], $case['query'], '', 'stage10-secret');

        $this->withHeaders($headers)
            ->getJson(route($case['route'], $case['query']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'missing_store_context')
            ->assertJsonPath('error.states.0', 'store_context_required');
    }
});

test('shopify v1 customer-sensitive storefront writes require verified store context', function () {
    config()->set('marketing.shopify.signing_secret', 'stage10-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Store',
        'email' => 'stage10.store.write.scope@example.com',
        'normalized_email' => 'stage10.store.write.scope@example.com',
        'phone' => '5554448899',
        'normalized_phone' => '+15554448899',
    ]);

    $cases = [
        [
            'path' => '/shopify/marketing/v1/consent/request',
            'route' => 'marketing.shopify.v1.consent.request',
            'payload' => [
                'email' => $profile->email,
                'phone' => $profile->phone,
                'consent_sms' => true,
            ],
        ],
        [
            'path' => '/shopify/marketing/v1/birthday/capture',
            'route' => 'marketing.shopify.v1.birthday.capture',
            'payload' => [
                'email' => $profile->email,
                'phone' => $profile->phone,
                'birth_month' => 1,
                'birth_day' => 15,
            ],
        ],
        [
            'path' => '/shopify/marketing/v1/birthday/claim',
            'route' => 'marketing.shopify.v1.birthday.claim',
            'payload' => [
                'email' => $profile->email,
                'phone' => $profile->phone,
            ],
        ],
        [
            'path' => '/shopify/marketing/v1/candle-cash/tasks/submit',
            'route' => 'marketing.shopify.v1.candle-cash.tasks.submit',
            'payload' => [
                'task_handle' => 'sms-signup',
                'email' => $profile->email,
                'phone' => $profile->phone,
            ],
        ],
        [
            'path' => '/shopify/marketing/v1/google-business/review/start',
            'route' => 'marketing.shopify.v1.google-business.review.start',
            'payload' => [
                'request_key' => 'missing-store-context-check',
                'email' => $profile->email,
                'phone' => $profile->phone,
            ],
        ],
    ];

    foreach ($cases as $case) {
        $headers = stage10SignedHeaders(
            'POST',
            $case['path'],
            [],
            json_encode($case['payload']),
            'stage10-secret'
        );

        $this->withHeaders($headers)
            ->postJson(route($case['route']), $case['payload'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'missing_store_context')
            ->assertJsonPath('error.states.0', 'store_context_required');
    }
});

test('shopify v1 consent request alias and consent status endpoint share the same contract states', function () {
    config()->set('marketing.shopify.signing_secret', 'stage10-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('marketing.sms.enabled', false);
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage10-retail-client');

    $email = 'stage10.' . Str::lower(Str::random(8)) . '@example.com';
    $payload = [
        'email' => $email,
        'phone' => '5554441234',
        'first_name' => 'Stage10',
        'consent_sms' => true,
        'consent_email' => true,
        'flow' => 'verification',
        'award_bonus' => true,
    ];
    $requestQuery = ['shop' => 'timberline.example.myshopify.com'];
    $requestHeaders = stage10SignedHeaders(
        'POST',
        '/shopify/marketing/v1/consent/request',
        $requestQuery,
        json_encode($payload),
        'stage10-secret'
    );

    $requested = $this->withHeaders($requestHeaders)
        ->postJson(route('marketing.shopify.v1.consent.request', $requestQuery), $payload)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.state', 'sms_requested')
        ->assertJsonPath('data.verification_required', true)
        ->json();

    $token = (string) data_get($requested, 'data.verification_token');
    expect($token)->not->toBe('');

    $statusQuery = [
        'email' => $email,
        'phone' => '5554441234',
        'shop' => 'timberline.example.myshopify.com',
    ];
    $statusHeaders = stage10SignedHeaders(
        'GET',
        '/shopify/marketing/v1/consent/status',
        $statusQuery,
        '',
        'stage10-secret'
    );

    $this->withHeaders($statusHeaders)
        ->getJson(route('marketing.shopify.v1.consent.status', $statusQuery))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.state', 'sms_requested')
        ->assertJsonPath('data.verification_required', true)
        ->assertJsonPath('data.incentive.available', true);

    $confirmPayload = ['token' => $token];
    $confirmHeaders = stage10SignedHeaders(
        'POST',
        '/shopify/marketing/v1/consent/confirm',
        [],
        json_encode($confirmPayload),
        'stage10-secret'
    );

    $this->withHeaders($confirmHeaders)
        ->postJson(route('marketing.shopify.v1.consent.confirm'), $confirmPayload)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.state', 'sms_confirmed');

    $this->withHeaders($statusHeaders)
        ->getJson(route('marketing.shopify.v1.consent.status', $statusQuery))
        ->assertOk()
        ->assertJsonPath('data.state', 'sms_confirmed')
        ->assertJsonPath('data.consent.sms', true)
        ->assertJsonPath('data.verification_required', false);
});

test('shopify v1 consent status accepts app proxy signed requests', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'unused');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage10-retail-client');

    $query = stage10AppProxySignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
    ], 'stage10-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.consent.status', $query))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('meta.auth_mode', 'app_proxy');
});

test('shopify v1 consent status falls back to retail shopify secret for app proxy verification', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', null);
    config()->set('marketing.shopify.signing_secret', null);
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage10-retail-client');
    config()->set('services.shopify.stores.retail.client_secret', 'stage10-retail-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);

    $query = stage10AppProxySignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
    ], 'stage10-retail-secret');

    $this->getJson(route('marketing.shopify.v1.consent.status', $query))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('meta.auth_mode', 'app_proxy');
});

test('shopify v1 app proxy health endpoint confirms transport and runtime secret loading', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);

    $query = stage10AppProxySignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
    ], 'stage10-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.health', $query))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('meta.auth_mode', 'app_proxy')
        ->assertJsonPath('data.transport', 'ok')
        ->assertJsonPath('data.identity.state', 'unknown_customer')
        ->assertJsonPath('data.runtime.app_proxy_enabled', true)
        ->assertJsonPath('data.runtime.has_signing_secret', true)
        ->assertJsonPath('data.runtime.has_app_proxy_secret', true);
});

test('shopify v1 customer status supports anonymous visitor and logged in customer states via app proxy', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage10-retail-client');

    $anonymousQuery = stage10AppProxySignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
    ], 'stage10-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.customer.status', $anonymousQuery))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.state', 'unknown_customer');

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Stage10',
        'email' => 'stage10.live@example.com',
        'normalized_email' => 'stage10.live@example.com',
        'phone' => '5559012233',
        'normalized_phone' => '+15559012233',
    ]);

    $linkedQuery = stage10AppProxySignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'email' => $profile->email,
        'phone' => $profile->phone,
    ], 'stage10-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.customer.status', $linkedQuery))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('meta.auth_mode', 'app_proxy')
        ->assertJsonPath('data.profile_id', $profile->id)
        ->assertJsonPath('data.state', 'linked_customer');

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'shopify_admin',
        'store_key' => 'retail',
        'external_customer_id' => '987654321',
        'external_customer_gid' => 'gid://shopify/Customer/987654321',
        'email' => $profile->email,
        'normalized_email' => $profile->normalized_email,
        'phone' => $profile->phone,
        'normalized_phone' => $profile->normalized_phone,
        'source_channels' => ['shopify', 'online'],
        'synced_at' => now(),
    ]);

    $proxyLoggedInQuery = stage10AppProxySignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'logged_in_customer_id' => 'gid://shopify/Customer/987654321',
    ], 'stage10-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.customer.status', $proxyLoggedInQuery))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('meta.auth_mode', 'app_proxy')
        ->assertJsonPath('data.profile_id', $profile->id)
        ->assertJsonPath('data.state', 'linked_customer');
});

test('shopify v1 candle cash status returns central contract for linked customer', function () {
    configureStage10RewardsStorefront();
    seedStage10GoogleReviewConfig();
    seedStage10GoogleBusinessLiveConnection();

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Cash',
        'last_name' => 'Customer',
        'email' => 'cash.customer@example.com',
        'normalized_email' => 'cash.customer@example.com',
        'phone' => '5557771212',
        'normalized_phone' => '+15557771212',
        'accepts_email_marketing' => true,
    ]);

    app(CandleCashService::class)->addPoints(
        profile: $profile,
        points: 50,
        type: 'earn',
        source: 'test',
        sourceId: 'stage10-candle-cash-status',
        description: 'Stage10 Candle Cash'
    );

    CandleCashTask::query()->where('handle', 'google-review')->firstOrFail();

    $query = stage10AppProxySignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'email' => $profile->email,
    ], 'stage10-proxy-secret');

    $response = $this->getJson(route('marketing.shopify.v1.candle-cash.status', $query))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('version', 'v1')
        ->assertJsonPath('meta.auth_mode', 'app_proxy')
        ->assertJsonPath('data.profile_id', $profile->id)
        ->assertJsonPath('data.copy.title', 'Candle Cash Central')
        ->assertJsonPath('data.balance.candle_cash', 50)
        ->assertJsonPath('data.consent.email', true)
        ->assertJsonPath('data.referral.enabled', true)
        ->assertJsonPath('data.google_review.enabled', true)
        ->assertJsonPath('data.google_review.ready', true)
        ->assertJsonPath('data.google_review.reason', 'live')
        ->assertJsonPath('data.google_review.review_url', 'https://g.page/r/CTucm4R1-wmOEAI/review')
        ->assertJsonCount(10, 'data.tasks');

    expect(data_get($response->json(), 'data.balance.points'))->toBeNull();

    $tasks = collect($response->json('data.tasks'));
    $googleReview = $tasks->firstWhere('handle', 'google-review');
    $productReview = $tasks->firstWhere('handle', 'product-review');
    $vote = $tasks->firstWhere('handle', 'candle-club-vote');

    expect($googleReview)->not->toBeNull()
        ->and(data_get($googleReview, 'verification_mode'))->toBe('google_business_review')
        ->and(data_get($googleReview, 'auto_award'))->toBeTrue()
        ->and(data_get($googleReview, 'action_url'))->toBe('https://g.page/r/CTucm4R1-wmOEAI/review')
        ->and($productReview)->not->toBeNull()
        ->and(data_get($productReview, 'verification_mode'))->toBe('product_review_platform_event')
        ->and(data_get($productReview, 'auto_award'))->toBeTrue()
        ->and($vote)->not->toBeNull()
        ->and(data_get($vote, 'eligibility.state'))->toBe('locked')
        ->and(data_get($vote, 'eligibility.claimable'))->toBeFalse();
});

test('shopify v1 candle cash status returns google review in manual fallback mode until the first successful sync makes it live', function () {
    configureStage10RewardsStorefront();
    seedStage10GoogleReviewConfig();
    seedStage10GoogleBusinessConnection();

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Pending',
        'last_name' => 'Sync',
        'email' => 'pending.sync@example.com',
        'normalized_email' => 'pending.sync@example.com',
    ]);

    $query = stage10AppProxySignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'email' => $profile->email,
    ], 'stage10-proxy-secret');

    $response = $this->getJson(route('marketing.shopify.v1.candle-cash.status', $query))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.google_review.enabled', true)
        ->assertJsonPath('data.google_review.ready', false)
        ->assertJsonPath('data.google_review.reason', 'needs_first_sync')
        ->assertJsonPath('data.google_review.fallback_mode', 'manual_review')
        ->assertJsonPath('data.google_review.review_url', 'https://g.page/r/CTucm4R1-wmOEAI/review')
        ->assertJsonPath('data.google_review.last_sync_at', null);

    $googleReview = collect($response->json('data.tasks'))->firstWhere('handle', 'google-review');

    expect($googleReview)->not->toBeNull()
        ->and(data_get($googleReview, 'verification_mode'))->toBe('manual_review_fallback')
        ->and(data_get($googleReview, 'auto_award'))->toBeFalse()
        ->and(data_get($googleReview, 'requires_manual_approval'))->toBeTrue()
        ->and(data_get($googleReview, 'requires_customer_submission'))->toBeTrue()
        ->and(data_get($googleReview, 'action_url'))->toBe('https://g.page/r/CTucm4R1-wmOEAI/review');
});

test('shopify v1 candle cash status hides google review when auto-match is not ready and no review url can be resolved', function () {
    configureStage10RewardsStorefront();
    seedStage10GoogleReviewConfig([
        'google_review_url' => null,
    ]);
    seedStage10GoogleBusinessConnection([
        'linked_location_place_id' => null,
        'linked_location_maps_uri' => null,
    ]);

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Missing',
        'last_name' => 'Url',
        'email' => 'missing.google.url@example.com',
        'normalized_email' => 'missing.google.url@example.com',
    ]);

    $query = stage10AppProxySignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'email' => $profile->email,
    ], 'stage10-proxy-secret');

    $response = $this->getJson(route('marketing.shopify.v1.candle-cash.status', $query))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.google_review.enabled', true)
        ->assertJsonPath('data.google_review.ready', false)
        ->assertJsonPath('data.google_review.reason', 'needs_location')
        ->assertJsonPath('data.google_review.fallback_mode', null)
        ->assertJsonPath('data.google_review.review_url', null);

    expect(collect($response->json('data.tasks'))->firstWhere('handle', 'google-review'))->toBeNull();
});

test('shopify v1 customer reward reads preserve fractional candle cash balances after legacy correction', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage10-retail-client');

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Fractional',
        'last_name' => 'Balance',
        'email' => 'fractional.balance@example.com',
        'normalized_email' => 'fractional.balance@example.com',
        'phone' => '5557773434',
        'normalized_phone' => '+15557773434',
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $profile->id,
        'balance' => 0.300,
    ]);

    $statusQuery = stage10AppProxySignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'email' => $profile->email,
    ], 'stage10-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.candle-cash.status', $statusQuery))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.balance.candle_cash', 0.3)
        ->assertJsonPath('data.balance.candle_cash_amount', 0.3)
        ->assertJsonPath('data.summary.current_balance', 0.3)
        ->assertJsonPath('data.available_rewards.0.is_redeemable_now', false);

    $balanceQuery = stage10AppProxySignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'email' => $profile->email,
        'phone' => $profile->phone,
    ], 'stage10-proxy-secret');

    $this->getJson(route('marketing.shopify.v1.rewards.balance', $balanceQuery))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.balance.candle_cash', 0.3)
        ->assertJsonPath('data.balance.candle_cash_amount', 0.3);
});

test('shopify v1 reward balance returns policy messaging and candle club benefit state', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage10-retail-client');

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Member',
        'last_name' => 'Balance',
        'email' => 'member.balance@example.com',
        'normalized_email' => 'member.balance@example.com',
        'phone' => '5557773535',
        'normalized_phone' => '+15557773535',
        'source_channels' => ['shopify', 'candle_club'],
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $profile->id,
        'balance' => 12,
    ]);

    $query = stage10AppProxySignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
        'email' => $profile->email,
        'phone' => $profile->phone,
    ], 'stage10-proxy-secret');

    $response = $this->getJson(route('marketing.shopify.v1.rewards.balance', $query))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.program.membership.is_active', true)
        ->assertJsonPath('data.program.membership.multiplier_enabled', true)
        ->assertJsonPath('data.program.membership.multiplier_value', 2)
        ->assertJsonPath('data.program.membership.free_shipping_enabled', false)
        ->assertJsonPath('data.program.expiration.mode', 'days_from_issue')
        ->assertJsonPath('data.program.expiration.days', 90)
        ->assertJsonPath('data.program.redemption.redeem_increment_dollars', 10)
        ->assertJsonPath('data.program.redemption.max_redeemable_per_order_dollars', 10);

    expect((string) data_get($response->json(), 'data.program.expiration.message'))->toContain('90 days')
        ->and((string) data_get($response->json(), 'data.program.redemption.message'))->toContain('$10')
        ->and((string) data_get($response->json(), 'data.program.membership.message'))->toContain('2x');
});

test('shopify v1 candle cash status keeps candle club vote locked for guests', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage10-retail-client');

    $query = stage10AppProxySignedQuery([
        'shop' => 'timberline.example.myshopify.com',
        'timestamp' => (string) time(),
    ], 'stage10-proxy-secret');

    $response = $this->getJson(route('marketing.shopify.v1.candle-cash.status', $query))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.state', 'unknown_customer');

    $tasks = collect($response->json('data.tasks'));
    $vote = $tasks->firstWhere('handle', 'candle-club-vote');

    expect($vote)->not->toBeNull()
        ->and(data_get($vote, 'eligibility.state'))->toBe('locked')
        ->and(data_get($vote, 'eligibility.claimable'))->toBeFalse();
});

test('shopify v1 endpoints used by extension resolve under app proxy transport', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage10-retail-client');

    $cases = [
        [
            'method' => 'GET',
            'route' => 'marketing.shopify.v1.health',
            'status' => 200,
            'query' => [],
            'has_contract_version' => true,
        ],
        [
            'method' => 'GET',
            'route' => 'marketing.shopify.v1.customer.status',
            'status' => 200,
            'query' => [],
            'has_contract_version' => true,
        ],
        [
            'method' => 'GET',
            'route' => 'marketing.shopify.v1.rewards.available',
            'status' => 200,
            'query' => [],
            'has_contract_version' => true,
        ],
        [
            'method' => 'GET',
            'route' => 'marketing.shopify.v1.rewards.balance',
            'status' => 422,
            'query' => [],
            'has_contract_version' => true,
        ],
        [
            'method' => 'GET',
            'route' => 'marketing.shopify.v1.rewards.history',
            'status' => 422,
            'query' => [],
            'has_contract_version' => true,
        ],
        [
            'method' => 'GET',
            'route' => 'marketing.shopify.v1.consent.status',
            'status' => 200,
            'query' => [],
            'has_contract_version' => true,
        ],
        [
            'method' => 'GET',
            'route' => 'marketing.shopify.v1.candle-cash.status',
            'status' => 200,
            'query' => [],
            'has_contract_version' => true,
        ],
        [
            'method' => 'POST',
            'route' => 'marketing.shopify.v1.rewards.redeem',
            'status' => 422,
            'query' => [],
            'payload' => [],
            'has_contract_version' => false,
        ],
        [
            'method' => 'POST',
            'route' => 'marketing.shopify.v1.consent.request',
            'status' => 422,
            'query' => [],
            'payload' => [],
            'has_contract_version' => false,
        ],
        [
            'method' => 'POST',
            'route' => 'marketing.shopify.v1.consent.confirm',
            'status' => 422,
            'query' => [],
            'payload' => [],
            'has_contract_version' => false,
        ],
        [
            'method' => 'POST',
            'route' => 'marketing.shopify.v1.candle-cash.tasks.submit',
            'status' => 422,
            'query' => [],
            'payload' => [],
            'has_contract_version' => false,
        ],
    ];

    foreach ($cases as $case) {
        $baseQuery = [
            'shop' => 'timberline.example.myshopify.com',
            'timestamp' => (string) time(),
            ...$case['query'],
        ];
        $signedQuery = stage10AppProxySignedQuery($baseQuery, 'stage10-proxy-secret');

        $response = ($case['method'] === 'POST')
            ? $this->postJson(route($case['route'], $signedQuery), $case['payload'] ?? [])
            : $this->getJson(route($case['route'], $signedQuery));

        $response->assertStatus($case['status']);
        if ($case['has_contract_version']) {
            $response->assertJsonPath('version', 'v1');
        } else {
            $response->assertJsonStructure(['message', 'errors']);
        }
    }
});

/**
 * @param array<string,mixed> $query
 * @return array<string,string>
 */
