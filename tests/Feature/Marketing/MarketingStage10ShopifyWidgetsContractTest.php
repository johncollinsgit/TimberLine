<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashTask;
use App\Models\MarketingProfile;
use App\Models\CustomerExternalProfile;
use App\Services\Marketing\CandleCashService;
use Illuminate\Support\Str;

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
    $requestHeaders = stage10SignedHeaders(
        'POST',
        '/shopify/marketing/v1/consent/request',
        [],
        json_encode($payload),
        'stage10-secret'
    );

    $requested = $this->withHeaders($requestHeaders)
        ->postJson(route('marketing.shopify.v1.consent.request'), $payload)
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
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage10-retail-client');

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
