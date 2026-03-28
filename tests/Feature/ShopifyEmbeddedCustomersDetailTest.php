<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\CandleCashBalance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReferral;
use App\Models\CandleCashReward;
use App\Models\CandleCashTask;
use App\Models\CandleCashTaskCompletion;
use App\Models\CandleCashTransaction;
use App\Models\CustomerBirthdayProfile;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\MarketingShortLink;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\CandleCashService;
use App\Services\Shopify\ShopifyEmbeddedCustomerActionUrlGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

function seedEmbeddedCustomerDetailFixture(?int $tenantId = null): MarketingProfile
{
    $now = CarbonImmutable::parse('2026-03-05 14:00:00');

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenantId,
        'first_name' => 'Daria',
        'last_name' => 'Drift',
        'email' => 'daria@example.com',
        'normalized_email' => 'daria@example.com',
        'phone' => '555-234-9876',
        'normalized_phone' => '15552349876',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => false,
    ]);

    $profile->forceFill([
        'created_at' => $now->subDays(20),
        'updated_at' => $now->subDays(2),
    ])->save();

    MarketingConsentEvent::query()->create([
        'marketing_profile_id' => $profile->id,
        'channel' => 'email',
        'event_type' => 'confirmed',
        'source_type' => 'seed',
        'source_id' => 'seed',
        'occurred_at' => $now->subDays(9),
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $profile->id,
        'balance' => 90,
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'points' => 40,
        'source' => 'admin',
        'source_id' => 'seed',
        'description' => 'Seed earn',
        'created_at' => $now->subDays(3),
        'updated_at' => $now->subDays(3),
    ]);

    $reward = CandleCashReward::query()->create([
        'name' => '$10 Candle Cash',
        'candle_cash_cost' => 100,
        'reward_type' => 'coupon',
        'reward_value' => '10USD',
        'is_active' => true,
    ]);

    CandleCashRedemption::query()->create([
        'marketing_profile_id' => $profile->id,
        'reward_id' => $reward->id,
        'candle_cash_spent' => 100,
        'platform' => 'shopify',
        'redemption_code' => 'TESTCODE',
        'status' => 'issued',
        'issued_at' => $now->subDays(4),
        'created_at' => $now->subDays(4),
        'updated_at' => $now->subDays(4),
    ]);

    $task = CandleCashTask::query()->firstOrCreate(
        ['handle' => 'candle-club-join'],
        [
            'title' => 'Candle Club Join',
            'task_type' => 'manual_submission',
            'reward_amount' => 1,
            'enabled' => true,
            'display_order' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );

    CandleCashTaskCompletion::query()->create([
        'candle_cash_task_id' => $task->id,
        'marketing_profile_id' => $profile->id,
        'status' => 'awarded',
        'reward_amount' => 1,
        'reward_candle_cash' => 10,
        'created_at' => $now->subDays(6),
        'updated_at' => $now->subDays(6),
        'awarded_at' => $now->subDays(6),
    ]);

    CandleCashReferral::query()->create([
        'referrer_marketing_profile_id' => $profile->id,
        'referral_code' => 'DARIA1',
        'status' => 'rewarded',
        'rewarded_at' => $now->subDays(5),
        'created_at' => $now->subDays(7),
        'updated_at' => $now->subDays(5),
    ]);

    CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => 8,
        'birth_day' => 12,
        'reward_last_issued_at' => $now->subDays(30),
        'created_at' => $now->subDays(40),
        'updated_at' => $now->subDays(30),
    ]);

    CustomerExternalProfile::query()->create([
        'tenant_id' => $tenantId,
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'shopify',
        'store_key' => 'wholesale',
        'external_customer_id' => 'wholesale-123',
        'points_balance' => 120,
        'last_activity_at' => $now->subDay(),
        'synced_at' => $now->subDay(),
        'created_at' => $now->subDay(),
        'updated_at' => $now->subDay(),
    ]);

    return $profile;
}

test('candle cash transactions include gift metadata columns', function () {
    expect(Schema::hasColumn('candle_cash_transactions', 'gift_intent'))->toBeTrue()
        ->and(Schema::hasColumn('candle_cash_transactions', 'gift_origin'))->toBeTrue()
        ->and(Schema::hasColumn('candle_cash_transactions', 'notified_via'))->toBeTrue()
        ->and(Schema::hasColumn('candle_cash_transactions', 'notification_status'))->toBeTrue()
        ->and(Schema::hasColumn('candle_cash_transactions', 'campaign_key'))->toBeTrue();
});

function startEmbeddedCustomersDetailSession(\Illuminate\Foundation\Testing\TestCase $testCase): void
{
    $testCase->get(route('shopify.app', retailEmbeddedSignedQuery()))->assertOk();
}

test('customer detail renders identity and summary data', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $response = $this->get(route('shopify.app.customers.detail', ['marketingProfile' => $profile->id], false));

    $response->assertOk()
        ->assertSeeText('Customer Detail')
        ->assertSeeText($profile->email)
        ->assertSeeText('Candle Cash')
        ->assertSeeText('Candle Cash Adjustment')
        ->assertSeeText('Message Customer')
        ->assertSeeText('Recent Activity')
        ->assertSeeText('Consent');
});

test('customer detail alias route resolves with embedded context', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();

    $response = $this->get(route('shopify.app.customers.detail', array_merge([
        'marketingProfile' => $profile->id,
    ], retailEmbeddedExtendedSignedQuery())));

    $response->assertOk()
        ->assertSeeText($profile->email)
        ->assertSeeText('Candle Cash');
});

test('customer detail handles missing optional data gracefully', function () {
    configureEmbeddedRetailStore();
    $profile = MarketingProfile::query()->create([
        'first_name' => 'No',
        'last_name' => 'Extras',
        'email' => null,
        'normalized_email' => null,
    ]);
    startEmbeddedCustomersDetailSession($this);

    $response = $this->get(route('shopify.app.customers.detail', ['marketingProfile' => $profile->id], false));

    $response->assertOk()
        ->assertSeeText('Email not set')
        ->assertSeeText('Loading recent items across rewards, adjustments, and messaging activity.');
});

test('customer identity json update succeeds with shopify session token auth', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureEmbeddedRetailStore($tenant->id);
    $profile = seedEmbeddedCustomerDetailFixture($tenant->id);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . retailShopifySessionToken()])
        ->patchJson(
            route('shopify.app.api.customers.update', ['marketingProfile' => $profile->id], false),
            [
                'first_name' => 'Json',
                'last_name' => 'Updated',
                'email' => 'json.updated@example.com',
                'phone' => '555-000-9999',
            ]
        );

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Customer identity updated.')
        ->assertJsonPath('data.customer.display_name', 'Json Updated')
        ->assertJsonPath('data.customer.email_display', 'json.updated@example.com')
        ->assertJsonPath('data.customer.phone_display', '555-000-9999');

    $profile->refresh();

    expect($profile->first_name)->toBe('Json')
        ->and($profile->last_name)->toBe('Updated')
        ->and($profile->email)->toBe('json.updated@example.com')
        ->and($profile->phone)->toBe('555-000-9999');
});

test('customer identity json requires embedded api auth and does not fall back to page session state', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();

    startEmbeddedCustomersDetailSession($this);

    $response = $this->patchJson(
        route('shopify.app.api.customers.update', ['marketingProfile' => $profile->id], false),
        [
            'first_name' => 'Blocked',
        ]
    );

    $response->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'missing_api_auth')
        ->assertJsonPath('message', 'This embedded customer action requires a verified Shopify session token.');
});

test('customer identity json rejects legacy embedded context token fallback', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();

    $response = $this
        ->withHeaders(['X-Forestry-Embedded-Context' => retailEmbeddedContextToken()])
        ->patchJson(
            route('shopify.app.api.customers.update', ['marketingProfile' => $profile->id], false),
            [
                'first_name' => 'Blocked',
            ]
        );

    $response->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'missing_api_auth')
        ->assertJsonPath('message', 'This embedded customer action requires a verified Shopify session token.');
});

test('customer identity json rejects invalid shopify session token auth', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureEmbeddedRetailStore($tenant->id);
    $profile = seedEmbeddedCustomerDetailFixture($tenant->id);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer not-a-valid-shopify-token'])
        ->patchJson(
            route('shopify.app.api.customers.update', ['marketingProfile' => $profile->id], false),
            [
                'first_name' => 'Blocked',
            ]
        );

    $response->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'invalid_session_token')
        ->assertJsonPath('message', 'This Shopify session token could not be verified.');
});

test('customer identity json rejects expired shopify session token auth', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureEmbeddedRetailStore($tenant->id);
    $profile = seedEmbeddedCustomerDetailFixture($tenant->id);
    $expiredNow = time() - 120;

    $response = $this
        ->withHeaders([
            'Authorization' => 'Bearer ' . retailShopifySessionToken([
                'nbf' => $expiredNow - 60,
                'iat' => $expiredNow - 60,
                'exp' => $expiredNow,
            ]),
        ])
        ->patchJson(
            route('shopify.app.api.customers.update', ['marketingProfile' => $profile->id], false),
            [
                'first_name' => 'Blocked',
            ]
        );

    $response->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'expired_session_token')
        ->assertJsonPath('message', 'This Shopify session expired. Reload the app from Shopify Admin.');
});

test('customer identity json returns validation errors', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureEmbeddedRetailStore($tenant->id);
    $profile = seedEmbeddedCustomerDetailFixture($tenant->id);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . retailShopifySessionToken()])
        ->patchJson(
            route('shopify.app.api.customers.update', ['marketingProfile' => $profile->id], false),
            [
                'email' => 'not-an-email',
                'phone' => str_repeat('1', 41),
            ]
        );

    $response->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Customer identity could not be saved.')
        ->assertJsonValidationErrors(['email', 'phone']);
});

test('customer consent json update succeeds with shopify session token auth', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureEmbeddedRetailStore($tenant->id);
    $profile = seedEmbeddedCustomerDetailFixture($tenant->id);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . retailShopifySessionToken()])
        ->postJson(
            route('shopify.app.api.customers.update-consent', ['marketingProfile' => $profile->id], false),
            [
                'channel' => 'both',
                'consented' => true,
                'notes' => 'Consent granted by JSON admin',
            ]
        );

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Consent updated.')
        ->assertJsonPath('data.consent.email_label', 'Consented')
        ->assertJsonPath('data.consent.sms_label', 'Consented')
        ->assertJsonPath('data.consent.sms_message_eligibility', 'Consented');

    expect($response->headers->get('Location'))->toBeNull();

    $profile->refresh();

    expect($profile->accepts_email_marketing)->toBeTrue()
        ->and($profile->accepts_sms_marketing)->toBeTrue();
});

test('customer consent json requires embedded api auth and does not fall back to page session state', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();

    startEmbeddedCustomersDetailSession($this);

    $response = $this->postJson(
        route('shopify.app.api.customers.update-consent', ['marketingProfile' => $profile->id], false),
        [
            'channel' => 'email',
            'consented' => true,
        ]
    );

    $response->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'missing_api_auth')
        ->assertJsonPath('message', 'This embedded customer action requires a verified Shopify session token.');
});

test('customer consent json rejects legacy embedded context token fallback', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();

    $response = $this
        ->withHeaders(['X-Forestry-Embedded-Context' => retailEmbeddedContextToken()])
        ->postJson(
            route('shopify.app.api.customers.update-consent', ['marketingProfile' => $profile->id], false),
            [
                'channel' => 'email',
                'consented' => true,
            ]
        );

    $response->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'missing_api_auth')
        ->assertJsonPath('message', 'This embedded customer action requires a verified Shopify session token.');
});

test('customer consent json rejects invalid shopify session token auth', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureEmbeddedRetailStore($tenant->id);
    $profile = seedEmbeddedCustomerDetailFixture($tenant->id);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer not-a-valid-shopify-token'])
        ->postJson(
            route('shopify.app.api.customers.update-consent', ['marketingProfile' => $profile->id], false),
            [
                'channel' => 'email',
                'consented' => true,
            ]
        );

    $response->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'invalid_session_token')
        ->assertJsonPath('message', 'This Shopify session token could not be verified.');
});

test('customer consent json rejects expired shopify session token auth', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureEmbeddedRetailStore($tenant->id);
    $profile = seedEmbeddedCustomerDetailFixture($tenant->id);
    $expiredNow = time() - 120;

    $response = $this
        ->withHeaders([
            'Authorization' => 'Bearer ' . retailShopifySessionToken([
                'nbf' => $expiredNow - 60,
                'iat' => $expiredNow - 60,
                'exp' => $expiredNow,
            ]),
        ])
        ->postJson(
            route('shopify.app.api.customers.update-consent', ['marketingProfile' => $profile->id], false),
            [
                'channel' => 'email',
                'consented' => true,
            ]
        );

    $response->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'expired_session_token')
        ->assertJsonPath('message', 'This Shopify session expired. Reload the app from Shopify Admin.');
});

test('customer consent json returns validation errors', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureEmbeddedRetailStore($tenant->id);
    $profile = seedEmbeddedCustomerDetailFixture($tenant->id);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . retailShopifySessionToken()])
        ->postJson(
            route('shopify.app.api.customers.update-consent', ['marketingProfile' => $profile->id], false),
            [
                'channel' => 'invalid',
                'consented' => 'nope',
                'notes' => str_repeat('a', 2001),
            ]
        );

    $response->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Consent could not be saved.')
        ->assertJsonValidationErrors(['channel', 'consented', 'notes']);
});

test('candle cash adjustment json succeeds with shopify session token auth', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureEmbeddedRetailStore($tenant->id);
    $profile = seedEmbeddedCustomerDetailFixture($tenant->id);
    $user = User::factory()->create([
        'name' => 'Json Admin',
        'email' => 'json.admin@example.com',
    ]);

    CandleCashBalance::query()->updateOrCreate(
        ['marketing_profile_id' => $profile->id],
        ['balance' => 10]
    );

    $expectedDisplay = app(CandleCashService::class)->formatRewardCurrency(
        app(CandleCashService::class)->amountFromPoints(35)
    );

    $response = $this
        ->actingAs($user)
        ->withHeaders(['Authorization' => 'Bearer ' . retailShopifySessionToken()])
        ->postJson(
            route('shopify.app.api.customers.candle-cash.adjust', ['marketingProfile' => $profile->id], false),
            [
                'direction' => 'add',
                'amount' => 25,
                'reason' => 'JSON support adjustment',
            ]
        );

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.balance', 35)
        ->assertJsonPath('data.balance_display', $expectedDisplay);

    $transaction = CandleCashTransaction::query()
        ->where('marketing_profile_id', $profile->id)
        ->latest('id')
        ->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction?->description)->toBe('JSON support adjustment')
        ->and((int) $transaction?->source_id)->toBe($user->id);
});

test('candle cash adjustment json returns validation errors', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureEmbeddedRetailStore($tenant->id);
    $profile = seedEmbeddedCustomerDetailFixture($tenant->id);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . retailShopifySessionToken()])
        ->postJson(
            route('shopify.app.api.customers.candle-cash.adjust', ['marketingProfile' => $profile->id], false),
            [
                'direction' => 'invalid',
                'amount' => 0,
                'reason' => '',
            ]
        );

    $response->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Candle Cash adjustment could not be saved.')
        ->assertJsonValidationErrors(['direction', 'amount', 'reason']);
});

test('canonical embedded customer write routes are retired', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();

    CandleCashBalance::query()->updateOrCreate(
        ['marketing_profile_id' => $profile->id],
        ['balance' => 90]
    );

    $requests = [
        [
            'method' => 'patch',
            'uri' => '/shopify/app/customers/manage/' . $profile->id,
            'payload' => ['first_name' => 'Blocked'],
            'status' => 405,
        ],
        [
            'method' => 'post',
            'uri' => '/shopify/app/customers/manage/' . $profile->id . '/consent',
            'payload' => ['channel' => 'email', 'consented' => false],
            'status' => 404,
        ],
        [
            'method' => 'post',
            'uri' => '/shopify/app/customers/manage/' . $profile->id . '/candle-cash',
            'payload' => ['direction' => 'add', 'amount' => 5, 'reason' => 'Blocked'],
            'status' => 404,
        ],
        [
            'method' => 'post',
            'uri' => '/shopify/app/customers/manage/' . $profile->id . '/message',
            'payload' => ['channel' => 'sms', 'message' => 'Blocked'],
            'status' => 404,
        ],
        [
            'method' => 'post',
            'uri' => '/shopify/app/customers/manage/' . $profile->id . '/candle-cash/send',
            'payload' => ['amount' => 5, 'reason' => 'Blocked'],
            'status' => 404,
        ],
    ];

    foreach ($requests as $request) {
        $response = $this->{$request['method']}($request['uri'], $request['payload']);
        $response->assertStatus($request['status']);
    }

    $profile->refresh();
    $balance = CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->first();

    expect($profile->first_name)->toBe('Daria')
        ->and($profile->accepts_email_marketing)->toBeTrue()
        ->and((int) ($balance?->balance ?? 0))->toBe(90)
        ->and(MarketingMessageDelivery::query()->where('marketing_profile_id', $profile->id)->count())->toBe(0)
        ->and(CandleCashTransaction::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('type', 'gift')
            ->count())->toBe(0);
});

test('embedded customer detail forms use helper-generated urls with Shopify query params', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $signature = retailEmbeddedExtendedSignedQuery();
    $response = $this->get(
        route('shopify.app.customers.detail', array_merge(['marketingProfile' => $profile->id], $signature))
    );

    $response->assertOk();
    $content = $response->getContent();

    $generator = new ShopifyEmbeddedCustomerActionUrlGenerator();
    $signedRequest = Request::create('/', 'GET', $signature);

    $expected = $generator->url('customers.detail', ['marketingProfile' => $profile->id], $signedRequest);
    $escapedAction = htmlspecialchars($expected, ENT_QUOTES, 'UTF-8');
    $this->assertGreaterThanOrEqual(5, substr_count($content, 'action="' . $escapedAction . '"'));

    $expectedApiEndpoints = [
        route('shopify.app.api.customers.update', ['marketingProfile' => $profile->id], false),
        route('shopify.app.api.customers.candle-cash.adjust', ['marketingProfile' => $profile->id], false),
        route('shopify.app.api.customers.candle-cash.send', ['marketingProfile' => $profile->id], false),
        route('shopify.app.api.customers.update-consent', ['marketingProfile' => $profile->id], false),
        route('shopify.app.api.customers.message', ['marketingProfile' => $profile->id], false),
        route('shopify.app.api.customers.detail-sections', ['marketingProfile' => $profile->id], false),
    ];

    foreach (array_slice($expectedApiEndpoints, 0, 5) as $endpoint) {
        $escaped = htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8');
        $this->assertStringContainsString('data-api-endpoint="' . $escaped . '"', $content);
    }

    $deferredSectionsEndpoint = htmlspecialchars($expectedApiEndpoints[5], ENT_QUOTES, 'UTF-8');
    $this->assertStringContainsString('data-deferred-sections-endpoint="' . $deferredSectionsEndpoint . '"', $content);

    $this->assertStringContainsString('id_token=', $content);
    $this->assertStringContainsString('locale=en', $content);
    $this->assertStringContainsString('session=embedded-session-token', $content);
});

test('customer detail exposes deferred sections bootstrap and server timing', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $response = $this->get(route('shopify.app.customers.detail', ['marketingProfile' => $profile->id], false));

    $response->assertOk()
        ->assertHeader('Server-Timing');

    $content = $response->getContent();

    expect($content)->toContain('data-deferred-sections-endpoint="' . route('shopify.app.api.customers.detail-sections', ['marketingProfile' => $profile->id], false) . '"')
        ->and($content)->toContain('Loading recent items across rewards, adjustments, and messaging activity.')
        ->and($content)->toContain('Loading linked source records…');
});

test('customer detail deferred sections api returns live activity and linked sources', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . retailShopifySessionToken()])
        ->getJson(route('shopify.app.api.customers.detail-sections', ['marketingProfile' => $profile->id], false));

    $response->assertOk()
        ->assertHeader('Server-Timing')
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.external_profiles_count', 1);

    $payload = $response->json('data');

    expect((int) ($payload['activity_count'] ?? 0))->toBeGreaterThan(0)
        ->and($payload['activity_html'] ?? '')->toContain('Seed earn')
        ->and($payload['external_profiles_html'] ?? '')->toContain('wholesale-123')
        ->and($payload['last_activity_display'] ?? null)->not->toBe('Loading recent activity…');
});

test('embedded customer detail navigation links preserve Shopify query params', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $signature = retailEmbeddedExtendedSignedQuery();
    $response = $this->get(
        route('shopify.app.customers.detail', array_merge(['marketingProfile' => $profile->id], $signature))
    );

    $response->assertOk();
    $content = $response->getContent();
    $this->assertStringContainsString('href="/shopify/app/customers/manage?', $content);
    $this->assertStringContainsString('href="/shopify/app/customers/activity?', $content);
    $this->assertStringContainsString('href="/shopify/app/customers/questions?', $content);
    $this->assertStringContainsString('shop=modernforestry.myshopify.com', $content);
    $this->assertStringContainsString('host=admin-host-token', $content);
    $this->assertStringContainsString('embedded=1', $content);
    $this->assertStringContainsString('id_token=', $content);
    $this->assertStringContainsString('locale=en', $content);
    $this->assertStringContainsString('session=embedded-session-token', $content);
    $this->assertStringContainsString('href="/?shop=', $content);
    $this->assertStringContainsString('href="/shopify/app/settings?shop=', $content);
    $this->assertStringContainsString('/marketing/customers/' . $profile->id, $content);
});

test('legacy manage route redirects to the embedded customers manage page', function () {
    configureEmbeddedRetailStore();

    $response = $this->get(route('shopify.embedded.customers.manage', retailEmbeddedExtendedSignedQuery()));

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    $this->assertStringContainsString('/shopify/app/customers/manage', $location);
    $this->assertStringContainsString('shop=modernforestry.myshopify.com', $location);
    $this->assertStringContainsString('id_token=', $location);
    $this->assertStringContainsString('locale=en', $location);
    $this->assertStringContainsString('session=embedded-session-token', $location);
});

test('legacy detail route redirects to the embedded customers detail page', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();

    $response = $this->get(
        route('shopify.embedded.customers.detail', array_merge(
            ['marketingProfile' => $profile->id],
            retailEmbeddedExtendedSignedQuery()
        ))
    );

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    $this->assertStringContainsString('/shopify/app/customers/manage/' . $profile->id, $location);
    $this->assertStringContainsString('host=admin-host-token', $location);
    $this->assertStringContainsString('id_token=', $location);
    $this->assertStringContainsString('locale=en', $location);
    $this->assertStringContainsString('session=embedded-session-token', $location);
});

test('legacy embedded customer write alias routes are retired', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();

    $requests = [
        ['method' => 'patch', 'uri' => '/customers/manage/' . $profile->id, 'status' => 405],
        ['method' => 'post', 'uri' => '/customers/manage/' . $profile->id . '/consent', 'status' => 404],
        ['method' => 'post', 'uri' => '/customers/manage/' . $profile->id . '/candle-cash', 'status' => 404],
        ['method' => 'post', 'uri' => '/customers/manage/' . $profile->id . '/message', 'status' => 404],
        ['method' => 'post', 'uri' => '/customers/manage/' . $profile->id . '/candle-cash/send', 'status' => 404],
    ];

    foreach ($requests as $request) {
        $response = $this->{$request['method']}($request['uri'], []);
        $response->assertStatus($request['status']);
    }
});

test('legacy manage route without signed context shows context missing notice', function () {
    $response = $this->get(route('shopify.embedded.customers.manage'));

    $response->assertStatus(400)
        ->assertSeeText('Context Missing')
        ->assertSeeText('This page must be opened from Shopify Admin');
});

test('customer detail without verified Shopify context suppresses actionable widgets', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();

    $response = $this->get(route('shopify.app.customers.detail', ['marketingProfile' => $profile->id], false));

    $response->assertOk()
        ->assertSeeText('Open this app from Shopify Admin')
        ->assertSeeText('Customer detail unavailable')
        ->assertSeeText('Context Required')
        ->assertDontSeeText('Save identity')
        ->assertDontSeeText('Apply adjustment')
        ->assertDontSeeText('Send Candle Cash')
        ->assertDontSeeText('Save consent')
        ->assertDontSeeText('Send message')
        ->assertDontSeeText($profile->email)
        ->assertDontSeeText('Marketing profile ID: ' . $profile->id);
});

test('customer detail with invalid hmac suppresses widgets and returns unauthorized', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();

    $query = retailEmbeddedExtendedSignedQuery();
    $query['hmac'] = 'invalid-hmac';

    $response = $this->get(route('shopify.app.customers.detail', array_merge([
        'marketingProfile' => $profile->id,
    ], $query)));

    $response->assertStatus(401)
        ->assertSeeText('We could not verify this Shopify request')
        ->assertSeeText('Customer detail unavailable')
        ->assertSeeText('signed Shopify query on this request could not be verified')
        ->assertDontSeeText('Save identity')
        ->assertDontSeeText('Apply adjustment')
        ->assertDontSeeText('Send Candle Cash')
        ->assertDontSeeText('Save consent')
        ->assertDontSeeText('Send message');
});

test('customer detail forms fall back to embedded routes without Shopify host', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $response = $this->get(route('shopify.app.customers.detail', ['marketingProfile' => $profile->id], false));

    $response->assertOk();
    $content = $response->getContent();

    $expectedAction = route('shopify.app.customers.detail', ['marketingProfile' => $profile->id], false);
    $this->assertGreaterThanOrEqual(5, substr_count($content, 'action="' . $expectedAction . '"'));
});

test('manual adjustment falls back to Admin actor label when user is not resolved', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'adjust',
        'points' => 5,
        'source' => 'shopify_embedded_admin',
        'source_id' => null,
        'description' => 'Legacy adjustment',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . retailShopifySessionToken()])
        ->getJson(route('shopify.app.api.customers.detail-sections', ['marketingProfile' => $profile->id], false));

    $response->assertOk()
        ->assertJsonPath('ok', true);

    $activityHtml = (string) $response->json('data.activity_html');

    expect($activityHtml)->toContain('Manual Adjustment')
        ->and($activityHtml)->toContain('Admin');
});

test('sms message json send succeeds with shopify session token auth', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureEmbeddedRetailStore($tenant->id);
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);
    config()->set('marketing.twilio.messaging_service_sid', 'MG_TEST');

    $profile = seedEmbeddedCustomerDetailFixture($tenant->id);
    $profile->forceFill([
        'phone' => '555-222-3333',
        'normalized_phone' => '15552223333',
        'accepts_sms_marketing' => true,
    ])->save();

    $user = User::factory()->create([
        'name' => 'Jordan JSON',
        'email' => 'jordan.json@example.com',
    ]);

    $response = $this
        ->actingAs($user)
        ->withHeaders(['Authorization' => 'Bearer ' . retailShopifySessionToken()])
        ->postJson(
            route('shopify.app.api.customers.message', ['marketingProfile' => $profile->id], false),
            [
                'channel' => 'sms',
                'message' => 'Hello from the JSON customer detail flow.',
            ]
        );

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'SMS sent successfully.')
        ->assertJsonPath('notice_style', 'success');

    expect($response->headers->get('Location'))->toBeNull();

    $delivery = MarketingMessageDelivery::query()
        ->where('marketing_profile_id', $profile->id)
        ->latest('id')
        ->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery?->channel)->toBe('sms')
        ->and((int) ($delivery?->created_by ?? 0))->toBe($user->id);
});

test('customer message json requires embedded api auth and does not fall back to page session state', function () {
    configureEmbeddedRetailStore();
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);

    $profile = seedEmbeddedCustomerDetailFixture();

    startEmbeddedCustomersDetailSession($this);

    $response = $this->postJson(
        route('shopify.app.api.customers.message', ['marketingProfile' => $profile->id], false),
        [
            'channel' => 'sms',
            'message' => 'Blocked without bearer token.',
        ]
    );

    $response->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'missing_api_auth')
        ->assertJsonPath('message', 'This embedded customer action requires a verified Shopify session token.');
});

test('customer message json rejects legacy embedded context token fallback', function () {
    configureEmbeddedRetailStore();
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);

    $profile = seedEmbeddedCustomerDetailFixture();

    $response = $this
        ->withHeaders(['X-Forestry-Embedded-Context' => retailEmbeddedContextToken()])
        ->postJson(
            route('shopify.app.api.customers.message', ['marketingProfile' => $profile->id], false),
            [
                'channel' => 'sms',
                'message' => 'Blocked by legacy header.',
            ]
        );

    $response->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'missing_api_auth')
        ->assertJsonPath('message', 'This embedded customer action requires a verified Shopify session token.');
});

test('customer message json rejects invalid shopify session token auth', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureEmbeddedRetailStore($tenant->id);
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);
    config()->set('marketing.twilio.messaging_service_sid', 'MG_TEST');

    $profile = seedEmbeddedCustomerDetailFixture($tenant->id);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer not-a-valid-shopify-token'])
        ->postJson(
            route('shopify.app.api.customers.message', ['marketingProfile' => $profile->id], false),
            [
                'channel' => 'sms',
                'message' => 'Blocked invalid token.',
            ]
        );

    $response->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'invalid_session_token')
        ->assertJsonPath('message', 'This Shopify session token could not be verified.');
});

test('customer message json rejects expired shopify session token auth', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureEmbeddedRetailStore($tenant->id);
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);
    config()->set('marketing.twilio.messaging_service_sid', 'MG_TEST');

    $profile = seedEmbeddedCustomerDetailFixture($tenant->id);
    $expiredNow = time() - 120;

    $response = $this
        ->withHeaders([
            'Authorization' => 'Bearer ' . retailShopifySessionToken([
                'nbf' => $expiredNow - 60,
                'iat' => $expiredNow - 60,
                'exp' => $expiredNow,
            ]),
        ])
        ->postJson(
            route('shopify.app.api.customers.message', ['marketingProfile' => $profile->id], false),
            [
                'channel' => 'sms',
                'message' => 'Blocked expired token.',
            ]
        );

    $response->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'expired_session_token')
        ->assertJsonPath('message', 'This Shopify session expired. Reload the app from Shopify Admin.');
});

test('customer message json returns validation errors', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureEmbeddedRetailStore($tenant->id);
    $profile = seedEmbeddedCustomerDetailFixture($tenant->id);

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . retailShopifySessionToken()])
        ->postJson(
            route('shopify.app.api.customers.message', ['marketingProfile' => $profile->id], false),
            [
                'channel' => 'email',
                'message' => '',
                'sender_key' => str_repeat('s', 81),
            ]
        );

    $response->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Message could not be sent.')
        ->assertJsonValidationErrors(['channel', 'message', 'sender_key']);
});

test('customer message json returns warning when sms consent is missing', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureEmbeddedRetailStore($tenant->id);
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);
    config()->set('marketing.twilio.messaging_service_sid', 'MG_TEST');

    $profile = seedEmbeddedCustomerDetailFixture($tenant->id);
    $profile->forceFill([
        'phone' => '555-111-0000',
        'normalized_phone' => '15551110000',
        'accepts_sms_marketing' => false,
    ])->save();

    $response = $this
        ->withHeaders(['Authorization' => 'Bearer ' . retailShopifySessionToken()])
        ->postJson(
            route('shopify.app.api.customers.message', ['marketingProfile' => $profile->id], false),
            [
                'channel' => 'sms',
                'message' => 'Consent required test.',
            ]
        );

    $response->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('notice_style', 'warning')
        ->assertJsonPath('message', 'SMS not sent: the customer has not granted SMS consent yet.');

    expect(MarketingMessageDelivery::query()->where('marketing_profile_id', $profile->id)->count())->toBe(0);
});

test('send candle cash json succeeds with shopify session token auth', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureEmbeddedRetailStore($tenant->id);
    $profile = seedEmbeddedCustomerDetailFixture($tenant->id);
    $user = User::factory()->create(['name' => 'Gift Json Admin']);

    $expectedDisplay = app(CandleCashService::class)->formatRewardCurrency(
        app(CandleCashService::class)->amountFromPoints(105)
    );

    $response = $this
        ->actingAs($user)
        ->withHeaders(['Authorization' => 'Bearer ' . retailShopifySessionToken()])
        ->postJson(
            route('shopify.app.api.customers.candle-cash.send', ['marketingProfile' => $profile->id], false),
            [
                'amount' => 15,
                'reason' => 'JSON welcome gift',
                'gift_intent' => 'vip',
                'gift_origin' => 'marketing',
            ]
        );

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.balance', 105)
        ->assertJsonPath('data.balance_display', $expectedDisplay);

    $transaction = CandleCashTransaction::query()
        ->where('marketing_profile_id', $profile->id)
        ->latest('id')
        ->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction?->type)->toBe('gift')
        ->and($transaction?->description)->toBe('JSON welcome gift')
        ->and((int) $transaction?->source_id)->toBe($user->id)
        ->and($transaction?->gift_intent)->toBe('vip')
        ->and($transaction?->gift_origin)->toBe('marketing');
});

test('embedded customer detail mutations and page access are isolated by store tenant', function () {
    $tenantOne = Tenant::query()->create([
        'name' => 'Tenant One',
        'slug' => 'tenant-one',
    ]);
    $tenantTwo = Tenant::query()->create([
        'name' => 'Tenant Two',
        'slug' => 'tenant-two',
    ]);

    configureEmbeddedRetailStore($tenantOne->id);
    $profile = seedEmbeddedCustomerDetailFixture($tenantTwo->id);

    $detailResponse = $this->get(route('shopify.app.customers.detail', array_merge([
        'marketingProfile' => $profile->id,
    ], retailEmbeddedSignedQuery())));

    $detailResponse->assertNotFound();

    $mutationResponse = $this
        ->withHeaders(['Authorization' => 'Bearer ' . retailShopifySessionToken()])
        ->patchJson(
            route('shopify.app.api.customers.update', ['marketingProfile' => $profile->id], false),
            [
                'first_name' => 'Blocked',
            ]
        );

    $mutationResponse->assertNotFound()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Customer not found for this Shopify store.');

    $sectionsResponse = $this
        ->withHeaders(['Authorization' => 'Bearer ' . retailShopifySessionToken()])
        ->getJson(
            route('shopify.app.api.customers.detail-sections', ['marketingProfile' => $profile->id], false)
        );

    $sectionsResponse->assertNotFound()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Customer not found for this Shopify store.');

    $consentResponse = $this
        ->withHeaders(['Authorization' => 'Bearer ' . retailShopifySessionToken()])
        ->postJson(
            route('shopify.app.api.customers.update-consent', ['marketingProfile' => $profile->id], false),
            [
                'channel' => 'sms',
                'consented' => true,
            ]
        );

    $consentResponse->assertNotFound()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Customer not found for this Shopify store.');

    $messageResponse = $this
        ->withHeaders(['Authorization' => 'Bearer ' . retailShopifySessionToken()])
        ->postJson(
            route('shopify.app.api.customers.message', ['marketingProfile' => $profile->id], false),
            [
                'channel' => 'sms',
                'message' => 'Blocked by tenant isolation.',
            ]
        );

    $messageResponse->assertNotFound()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Customer not found for this Shopify store.');
});
