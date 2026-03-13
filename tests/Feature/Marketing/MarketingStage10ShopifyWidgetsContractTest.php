<?php

use App\Models\CandleCashTask;
use App\Models\MarketingProfile;
use App\Models\CustomerExternalProfile;
use App\Services\Marketing\CandleCashService;
use Illuminate\Support\Str;

test('shopify v1 consent status returns unknown customer state when identity is missing', function () {
    config()->set('marketing.shopify.signing_secret', 'stage10-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);

    $headers = stage10SignedHeaders('GET', '/shopify/marketing/v1/consent/status', [], '', 'stage10-secret');

    $this->withHeaders($headers)
        ->getJson(route('marketing.shopify.v1.consent.status'))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.state', 'unknown_customer')
        ->assertJsonPath('data.consent.sms', false)
        ->assertJsonPath('data.consent.email', false)
        ->assertJsonPath('data.verification_required', false)
        ->assertJsonPath('meta.states.0', 'unknown_customer');
});

test('shopify v1 consent request alias and consent status endpoint share the same contract states', function () {
    config()->set('marketing.shopify.signing_secret', 'stage10-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('marketing.sms.enabled', false);

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

    $statusQuery = ['email' => $email, 'phone' => '5554441234'];
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
        'store_key' => 'timberline.example.myshopify.com',
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

    $this->getJson(route('marketing.shopify.v1.candle-cash.status', $query))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('version', 'v1')
        ->assertJsonPath('meta.auth_mode', 'app_proxy')
        ->assertJsonPath('data.profile_id', $profile->id)
        ->assertJsonPath('data.copy.title', 'Candle Cash Central')
        ->assertJsonPath('data.balance.points', 50)
        ->assertJsonPath('data.consent.email', true)
        ->assertJsonPath('data.referral.enabled', true)
        ->assertJsonCount(10, 'data.tasks');
});

test('shopify v1 endpoints used by extension resolve under app proxy transport', function () {
    config()->set('marketing.shopify.app_proxy_enabled', true);
    config()->set('marketing.shopify.app_proxy_secret', 'stage10-proxy-secret');
    config()->set('marketing.shopify.signing_secret', 'stage10-signing-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);

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
function stage10SignedHeaders(
    string $method,
    string $path,
    array $query,
    string $body,
    string $secret,
    ?int $timestamp = null
): array {
    $timestamp = $timestamp ?? time();
    $canonicalQuery = stage10CanonicalQuery($query);
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
function stage10CanonicalQuery(array $query): string
{
    if ($query === []) {
        return '';
    }

    ksort($query);
    $parts = [];
    foreach ($query as $key => $value) {
        if (is_array($value)) {
            $value = stage10CanonicalQuery($value);
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
function stage10AppProxySignedQuery(array $params, string $secret): array
{
    $canonical = stage10AppProxyCanonical($params);
    $signature = hash_hmac('sha256', $canonical, $secret);

    return [...$params, 'signature' => $signature];
}

/**
 * @param array<string,mixed> $params
 */
function stage10AppProxyCanonical(array $params): string
{
    if ($params === []) {
        return '';
    }

    ksort($params);
    $parts = [];
    foreach ($params as $key => $value) {
        if (is_array($value)) {
            $value = stage10AppProxyCanonical($value);
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
