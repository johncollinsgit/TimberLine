<?php

use App\Models\CandleCashTask;
use App\Models\CandleCashTaskCompletion;
use App\Models\GoogleBusinessProfileConnection;
use App\Models\GoogleBusinessProfileLocation;
use App\Models\GoogleBusinessProfileReview;
use App\Models\MarketingProfile;
use App\Models\MarketingStorefrontEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.google_gbp.enabled', true);
    config()->set('services.google_gbp.client_id', 'test-google-gbp-client-id');
    config()->set('services.google_gbp.client_secret', 'test-google-gbp-client-secret');
    config()->set('services.google_gbp.redirect_uri', 'http://localhost/marketing/candle-cash/google-business/callback');
    config()->set('services.google_gbp.scopes', 'https://www.googleapis.com/auth/business.manage');
});

function candleCashMarketingUser(): User
{
    return User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
}

function seedGoogleBusinessConnection(array $overrides = []): GoogleBusinessProfileConnection
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
        'project_approval_status' => 'approved',
    ], $overrides));
}

test('google business connect route redirects to oauth with business manage scope', function () {
    $user = candleCashMarketingUser();

    $response = $this->actingAs($user)
        ->get(route('marketing.candle-cash.google-business.connect'));

    $response->assertRedirect();

    $target = $response->headers->get('Location');
    expect($target)->toContain('https://accounts.google.com/o/oauth2/v2/auth');
    expect($target)->toContain('client_id=test-google-gbp-client-id');
    expect(urldecode($target))->toContain('https://www.googleapis.com/auth/business.manage');
    expect($target)->toContain(rawurlencode('http://localhost/marketing/candle-cash/google-business/callback'));
});

test('google business callback exchanges code stores encrypted tokens and auto links a single location', function () {
    $user = candleCashMarketingUser();

    $connect = $this->actingAs($user)
        ->get(route('marketing.candle-cash.google-business.connect'));

    parse_str(parse_url((string) $connect->headers->get('Location'), PHP_URL_QUERY), $params);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'scope' => 'https://www.googleapis.com/auth/business.manage',
        ], 200),
        'https://mybusinessaccountmanagement.googleapis.com/v1/accounts*' => Http::response([
            'accounts' => [
                [
                    'name' => 'accounts/123',
                    'accountName' => 'Modern Forestry',
                ],
            ],
        ], 200),
        'https://mybusinessbusinessinformation.googleapis.com/v1/accounts/123/locations*' => Http::response([
            'locations' => [
                [
                    'name' => 'locations/456',
                    'title' => 'Forestry HQ',
                    'metadata' => [
                        'placeId' => 'place-123',
                        'mapsUri' => 'https://maps.google.com/?cid=123',
                    ],
                ],
            ],
        ], 200),
    ]);

    $response = $this->get(route('marketing.candle-cash.google-business.callback', [
        'code' => 'oauth-code',
        'state' => $params['state'] ?? '',
    ]));

    $response->assertRedirect(route('marketing.candle-cash.settings'));

    $connection = GoogleBusinessProfileConnection::query()->firstOrFail();
    expect($connection->connection_status)->toBe('connected')
        ->and($connection->linked_location_id)->toBe('456')
        ->and($connection->linked_location_title)->toBe('Forestry HQ');

    $rawAccessToken = DB::table('google_business_profile_connections')->value('access_token');
    expect($rawAccessToken)->not->toBe('google-access-token');

    $this->assertDatabaseHas('google_business_profile_locations', [
        'location_id' => '456',
        'is_selected' => 1,
    ]);
});

test('google business status route returns linked location details', function () {
    $user = candleCashMarketingUser();
    $connection = seedGoogleBusinessConnection();

    GoogleBusinessProfileLocation::query()->create([
        'google_business_profile_connection_id' => $connection->id,
        'account_name' => 'accounts/123',
        'account_id' => '123',
        'account_display_name' => 'Modern Forestry',
        'location_name' => 'locations/456',
        'location_id' => '456',
        'title' => 'Forestry HQ',
        'is_selected' => true,
        'selected_at' => now(),
        'last_seen_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson(route('marketing.candle-cash.google-business.status'))
        ->assertOk()
        ->assertJsonPath('data.connection_status', 'connected')
        ->assertJsonPath('data.linked_location_id', '456')
        ->assertJsonPath('data.locations.0.title', 'Forestry HQ');
});

test('location selection persists for the active google business connection', function () {
    $user = candleCashMarketingUser();
    $connection = seedGoogleBusinessConnection([
        'linked_location_id' => null,
        'linked_location_title' => null,
        'linked_location_name' => null,
    ]);

    $first = GoogleBusinessProfileLocation::query()->create([
        'google_business_profile_connection_id' => $connection->id,
        'account_name' => 'accounts/123',
        'account_id' => '123',
        'account_display_name' => 'Modern Forestry',
        'location_name' => 'locations/456',
        'location_id' => '456',
        'title' => 'Forestry HQ',
        'is_selected' => false,
        'last_seen_at' => now(),
    ]);

    $second = GoogleBusinessProfileLocation::query()->create([
        'google_business_profile_connection_id' => $connection->id,
        'account_name' => 'accounts/123',
        'account_id' => '123',
        'account_display_name' => 'Modern Forestry',
        'location_name' => 'locations/789',
        'location_id' => '789',
        'title' => 'Forestry Storefront',
        'is_selected' => false,
        'last_seen_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('marketing.candle-cash.google-business.select-location'), [
            'location_id' => $second->id,
        ])
        ->assertRedirect(route('marketing.candle-cash.settings'));

    expect($second->fresh()->is_selected)->toBeTrue()
        ->and($first->fresh()->is_selected)->toBeFalse()
        ->and($connection->fresh()->linked_location_id)->toBe('789');
});

test('google review sync uses the linked location and awards only once for duplicate review syncs', function () {
    $user = candleCashMarketingUser();
    $connection = seedGoogleBusinessConnection();

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Ava',
        'last_name' => 'Match',
        'email' => 'ava.match@example.com',
        'normalized_email' => 'ava.match@example.com',
    ]);

    MarketingStorefrontEvent::query()->create([
        'event_type' => 'google_business_review_start',
        'status' => 'ok',
        'marketing_profile_id' => $profile->id,
        'request_key' => 'gbp-review-start-1',
        'source_type' => 'shopify_widget_google_business_review',
        'source_id' => '456',
        'meta' => [
            'expected_reviewer_name' => 'Ava Match',
            'location_id' => '456',
        ],
        'occurred_at' => now()->subHour(),
        'resolution_status' => 'open',
    ]);

    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/123/locations/456/reviews*' => Http::sequence()
            ->push([
                'reviews' => [[
                    'reviewId' => 'review-123',
                    'reviewer' => ['displayName' => 'Ava Match'],
                    'starRating' => 'FIVE',
                    'comment' => 'Loved it',
                    'createTime' => now()->subMinutes(10)->toIso8601String(),
                    'updateTime' => now()->subMinutes(5)->toIso8601String(),
                ]],
            ], 200)
            ->push([
                'reviews' => [[
                    'reviewId' => 'review-123',
                    'reviewer' => ['displayName' => 'Ava Match'],
                    'starRating' => 'FIVE',
                    'comment' => 'Loved it',
                    'createTime' => now()->subMinutes(10)->toIso8601String(),
                    'updateTime' => now()->subMinutes(5)->toIso8601String(),
                ]],
            ], 200),
    ]);

    $this->actingAs($user)
        ->post(route('marketing.candle-cash.google-business.sync'))
        ->assertRedirect(route('marketing.candle-cash.settings'));

    $this->actingAs($user)
        ->post(route('marketing.candle-cash.google-business.sync'))
        ->assertRedirect(route('marketing.candle-cash.settings'));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'accounts/123/locations/456/reviews'));

    expect(GoogleBusinessProfileReview::query()->count())->toBe(1)
        ->and(GoogleBusinessProfileReview::query()->firstOrFail()->marketing_profile_id)->toBe($profile->id)
        ->and(CandleCashTaskCompletion::query()
            ->where('marketing_profile_id', $profile->id)
            ->whereHas('task', fn ($query) => $query->where('handle', 'google-review'))
            ->where('status', 'awarded')
            ->count())->toBe(1);
});

test('revoked google authorization is handled cleanly during sync', function () {
    $user = candleCashMarketingUser();
    $connection = seedGoogleBusinessConnection([
        'expires_at' => now()->subMinute(),
    ]);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Token has been expired or revoked.',
        ], 400),
    ]);

    $this->actingAs($user)
        ->post(route('marketing.candle-cash.google-business.sync'))
        ->assertRedirect(route('marketing.candle-cash.settings'));

    expect($connection->fresh()->connection_status)->toBe('action_required')
        ->and($connection->fresh()->last_error_code)->toBe('authorization_revoked');
});

test('disconnect clears the active google business connection safely', function () {
    $user = candleCashMarketingUser();
    $connection = seedGoogleBusinessConnection();

    $this->actingAs($user)
        ->post(route('marketing.candle-cash.google-business.disconnect'))
        ->assertRedirect(route('marketing.candle-cash.settings'));

    $fresh = $connection->fresh();
    expect($fresh->connection_status)->toBe('disconnected')
        ->and($fresh->linked_location_id)->toBeNull()
        ->and($fresh->access_token)->toBeNull();
});

test('google review reward remains seeded at three dollars and no qna task is active', function () {
    $googleTask = CandleCashTask::query()->where('handle', 'google-review')->firstOrFail();

    expect((float) $googleTask->reward_amount)->toBe(3.0)
        ->and(CandleCashTask::query()->where('handle', 'like', '%qna%')->exists())->toBeFalse()
        ->and(CandleCashTask::query()->where('handle', 'like', '%question%')->exists())->toBeFalse();
});

test('project approval failure surfaces a clear admin error state', function () {
    $user = candleCashMarketingUser();

    $connect = $this->actingAs($user)
        ->get(route('marketing.candle-cash.google-business.connect'));

    parse_str(parse_url((string) $connect->headers->get('Location'), PHP_URL_QUERY), $params);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'scope' => 'https://www.googleapis.com/auth/business.manage',
        ], 200),
        'https://mybusinessaccountmanagement.googleapis.com/v1/accounts*' => Http::response([
            'error' => [
                'code' => 403,
                'message' => 'Google My Business API has not been used in project 123 before or it is disabled. Quota is 0 QPM.',
                'status' => 'PERMISSION_DENIED',
            ],
        ], 403),
    ]);

    $this->get(route('marketing.candle-cash.google-business.callback', [
        'code' => 'oauth-code',
        'state' => $params['state'] ?? '',
    ]))->assertRedirect(route('marketing.candle-cash.settings'));

    $connection = GoogleBusinessProfileConnection::query()->firstOrFail();
    expect($connection->last_error_code)->toBe('project_not_approved_or_service_disabled')
        ->and($connection->project_approval_status)->toBe('not_approved');

    $this->actingAs($user)
        ->getJson(route('marketing.candle-cash.google-business.status'))
        ->assertOk()
        ->assertJsonPath('data.project_approval_status', 'not_approved')
        ->assertJsonPath('data.last_error_code', 'project_not_approved_or_service_disabled');
});

test('storefront google review start route records a verified start and returns the review url', function () {
    config()->set('marketing.shopify.signing_secret', 'stage10-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);

    seedGoogleBusinessConnection([
        'linked_location_place_id' => 'place-123',
    ]);

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Riley',
        'last_name' => 'Reviewer',
        'email' => 'riley@example.com',
        'normalized_email' => 'riley@example.com',
    ]);

    $payload = [
        'email' => $profile->email,
        'request_key' => 'google-review-start-1',
    ];

    $headers = gbpSignedHeaders(
        'POST',
        '/shopify/marketing/v1/google-business/review/start',
        [],
        json_encode($payload),
        'stage10-secret'
    );

    $this->withHeaders($headers)
        ->postJson(route('marketing.shopify.v1.google-business.review.start'), $payload)
        ->assertOk()
        ->assertJsonPath('data.state', 'google_review_started')
        ->assertJsonPath('data.review_url', 'https://search.google.com/local/writereview?placeid=place-123');

    $this->assertDatabaseHas('marketing_storefront_events', [
        'event_type' => 'google_business_review_start',
        'marketing_profile_id' => $profile->id,
        'request_key' => 'google-review-start-1',
    ]);
});

function gbpSignedHeaders(
    string $method,
    string $path,
    array $query,
    string $body,
    string $secret,
    ?int $timestamp = null
): array {
    $timestamp = $timestamp ?? time();
    $canonicalQuery = gbpCanonicalQuery($query);
    $bodyHash = hash('sha256', $body);
    $payload = implode("\n", [$timestamp, strtoupper($method), $path, $canonicalQuery, $bodyHash]);
    $signature = hash_hmac('sha256', $payload, $secret);

    return [
        'X-Marketing-Timestamp' => (string) $timestamp,
        'X-Marketing-Signature' => $signature,
    ];
}

function gbpCanonicalQuery(array $query): string
{
    if ($query === []) {
        return '';
    }

    ksort($query);
    $parts = [];
    foreach ($query as $key => $value) {
        if (is_array($value)) {
            $value = gbpCanonicalQuery($value);
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
