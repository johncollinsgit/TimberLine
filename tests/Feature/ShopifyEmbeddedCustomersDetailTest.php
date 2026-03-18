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
use App\Models\User;
use App\Services\Shopify\ShopifyEmbeddedCustomerActionUrlGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

function seedEmbeddedCustomerDetailFixture(): MarketingProfile
{
    $now = CarbonImmutable::parse('2026-03-05 14:00:00');

    $profile = MarketingProfile::query()->create([
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
        'points_cost' => 100,
        'reward_type' => 'coupon',
        'reward_value' => '10USD',
        'is_active' => true,
    ]);

    CandleCashRedemption::query()->create([
        'marketing_profile_id' => $profile->id,
        'reward_id' => $reward->id,
        'points_spent' => 100,
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
        'reward_points' => 10,
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

    $response = $this->get(route('shopify.embedded.customers.detail', ['marketingProfile' => $profile->id], false));

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
    ], retailEmbeddedSignedQuery())));

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

    $response = $this->get(route('shopify.embedded.customers.detail', ['marketingProfile' => $profile->id], false));

    $response->assertOk()
        ->assertSeeText('Email not set')
        ->assertSeeText('No recent activity recorded yet.');
});

test('customer identity update persists safe fields', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $token = csrf_token();

    $response = $this->withSession(['_token' => $token])->patch(
        route('shopify.embedded.customers.update', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'email' => 'updated@example.com',
            'phone' => '555-111-2222',
        ]
    );

    $response->assertRedirect();

    $profile->refresh();

    expect($profile->first_name)->toBe('Updated')
        ->and($profile->last_name)->toBe('Name')
        ->and($profile->email)->toBe('updated@example.com')
        ->and($profile->normalized_email)->toBe('updated@example.com');
});

test('customer identity update alias route works without csrf session token in embedded app context', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $response = $this->patch(
        route('shopify.app.customers.update', array_merge([
            'marketingProfile' => $profile->id,
        ], retailEmbeddedSignedQuery()), false),
        [
            'first_name' => 'John',
            'last_name' => 'Collinsretail',
            'email' => 'johncollinsemail@gmail.com',
            'phone' => '+18646165468',
        ]
    );

    $response->assertRedirect();

    $profile->refresh();

    expect($profile->first_name)->toBe('John')
        ->and($profile->last_name)->toBe('Collinsretail')
        ->and($profile->email)->toBe('johncollinsemail@gmail.com')
        ->and($profile->phone)->toBe('+18646165468')
        ->and($profile->normalized_phone)->toBe('8646165468');
});

test('customer consent update succeeds and records events', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $token = csrf_token();

    $response = $this->withSession(['_token' => $token])->post(
        route('shopify.embedded.customers.update-consent', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'channel' => 'both',
            'consented' => true,
            'notes' => 'Consent granted by admin',
        ]
    );

    $response->assertRedirect();

    $profile->refresh();

    expect($profile->accepts_email_marketing)->toBeTrue()
        ->and($profile->accepts_sms_marketing)->toBeTrue();

    expect(MarketingConsentEvent::query()->where('marketing_profile_id', $profile->id)->count())
        ->toBeGreaterThanOrEqual(2);
});

test('invalid consent update is rejected', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $token = csrf_token();

    $response = $this->withSession(['_token' => $token])->post(
        route('shopify.embedded.customers.update-consent', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'channel' => 'invalid',
            'consented' => 'nope',
        ]
    );

    $response->assertSessionHasErrors(['channel', 'consented']);
});

test('consent update alias route resolves with embedded context', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $token = csrf_token();

    $response = $this->withSession(['_token' => $token])->post(
        route('shopify.app.customers.update-consent', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'channel' => 'email',
            'consented' => false,
            'notes' => 'Opt-out requested',
        ]
    );

    $response->assertRedirect();
});

test('candle cash adjustment adds balance and records transaction', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $user = User::factory()->create([
        'name' => 'Alex Admin',
        'email' => 'alex.admin@example.com',
    ]);

    CandleCashBalance::query()->updateOrCreate(
        ['marketing_profile_id' => $profile->id],
        ['balance' => 10]
    );

    $token = csrf_token();

    $response = $this->actingAs($user)->withSession(['_token' => $token])->post(
        route('shopify.embedded.customers.candle-cash.adjust', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'direction' => 'add',
            'amount' => 25,
            'reason' => 'Manual adjustment for support',
        ]
    );

    $response->assertRedirect();

    $profile->refresh();
    $balance = CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->first();

    expect((int) ($balance?->balance ?? 0))->toBe(35);

    $transaction = CandleCashTransaction::query()
        ->where('marketing_profile_id', $profile->id)
        ->orderByDesc('id')
        ->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->type)->toBe('adjust')
        ->and((int) $transaction->points)->toBe(25)
        ->and($transaction->source)->toBe('shopify_embedded_admin')
        ->and((int) $transaction->source_id)->toBe($user->id)
        ->and($transaction->description)->toBe('Manual adjustment for support');

    $detailResponse = $this->get(route('shopify.embedded.customers.detail', ['marketingProfile' => $profile->id], false));
    $detailResponse->assertOk()
        ->assertSeeText('Manual Adjustment')
        ->assertSeeText('Alex Admin');
});

test('candle cash adjustment subtracts balance when allowed', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    CandleCashBalance::query()->updateOrCreate(
        ['marketing_profile_id' => $profile->id],
        ['balance' => 50]
    );

    $token = csrf_token();

    $response = $this->withSession(['_token' => $token])->post(
        route('shopify.embedded.customers.candle-cash.adjust', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'direction' => 'subtract',
            'amount' => 20,
            'reason' => 'Manual correction',
        ]
    );

    $response->assertRedirect();

    $balance = CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->first();
    expect((int) ($balance?->balance ?? 0))->toBe(30);
    expect(MarketingMessageDelivery::query()->where('marketing_profile_id', $profile->id)->count())->toBe(0);
});

test('positive candle cash adjustment auto-sends rewards sms with shortened link', function () {
    configureEmbeddedRetailStore();
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);

    $profile = seedEmbeddedCustomerDetailFixture();
    $profile->forceFill([
        'phone' => '555-222-3333',
        'normalized_phone' => '15552223333',
        'accepts_sms_marketing' => true,
    ])->save();

    startEmbeddedCustomersDetailSession($this);

    $user = User::factory()->create([
        'name' => 'Taylor Admin',
        'email' => 'taylor.admin@example.com',
    ]);

    $token = csrf_token();

    $response = $this->actingAs($user)->withSession(['_token' => $token])->post(
        route('shopify.embedded.customers.candle-cash.adjust', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'direction' => 'add',
            'amount' => 25,
            'reason' => 'Support recovery gift',
        ]
    );

    $response->assertRedirect();

    $delivery = MarketingMessageDelivery::query()
        ->where('marketing_profile_id', $profile->id)
        ->latest('id')
        ->first();

    $shortLink = MarketingShortLink::query()->latest('id')->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery->channel)->toBe('sms')
        ->and($delivery->rendered_message)->toContain('Modern Forestry Just Rewarded you $25 in Candle Cash!')
        ->and($delivery->rendered_message)->toContain('Click To Redeem!')
        ->and($delivery->rendered_message)->toContain('Stop to Opt out')
        ->and($delivery->rendered_message)->not->toContain('https://theforestrystudio.com/pages/rewards');

    expect($shortLink)->not->toBeNull()
        ->and($shortLink->destination_url)->toBe('https://theforestrystudio.com/pages/rewards')
        ->and($delivery->rendered_message)->toContain((string) $shortLink->code);
});

test('positive candle cash adjustment still succeeds when reward sms cannot send', function () {
    configureEmbeddedRetailStore();
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);

    $profile = seedEmbeddedCustomerDetailFixture();
    $profile->forceFill([
        'phone' => '555-222-3333',
        'normalized_phone' => '15552223333',
        'accepts_sms_marketing' => false,
    ])->save();

    CandleCashBalance::query()->updateOrCreate(
        ['marketing_profile_id' => $profile->id],
        ['balance' => 10]
    );

    startEmbeddedCustomersDetailSession($this);

    $token = csrf_token();

    $response = $this->withSession(['_token' => $token])->post(
        route('shopify.embedded.customers.candle-cash.adjust', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'direction' => 'add',
            'amount' => 5,
            'reason' => 'Manual adjustment with blocked sms',
        ]
    );

    $response->assertRedirect();
    $response->assertSessionHas('customer_detail_notice', function (array $notice): bool {
        return ($notice['style'] ?? null) === 'warning'
            && str_contains((string) ($notice['message'] ?? ''), 'Reward message not sent');
    });

    $balance = CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->first();
    expect((int) ($balance?->balance ?? 0))->toBe(15);
});

test('invalid candle cash adjustment is rejected', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $token = csrf_token();

    $response = $this->withSession(['_token' => $token])->post(
        route('shopify.embedded.customers.candle-cash.adjust', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'direction' => 'invalid',
            'amount' => 0,
            'reason' => '',
        ]
    );

    $response->assertSessionHasErrors(['direction', 'amount', 'reason']);
});

test('candle cash adjustment alias route resolves with embedded context', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $token = csrf_token();

    $response = $this->withSession(['_token' => $token])->post(
        route('shopify.app.customers.candle-cash.adjust', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'direction' => 'add',
            'amount' => 5,
            'reason' => 'Alias adjustment',
        ]
    );

    $response->assertRedirect();
});

test('customer identity app route does not require csrf token in embedded admin', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $response = $this->patch(
        route('shopify.app.customers.update', array_merge([
            'marketingProfile' => $profile->id,
        ], retailEmbeddedSignedQuery())),
        [
            'first_name' => 'Embedded',
            'last_name' => 'Updated',
            'email' => 'embedded.updated@example.com',
            'phone' => '+18646165468',
        ]
    );

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->not->toBeNull()
        ->and($location)->toContain('/shopify/app/customers/manage/' . $profile->id)
        ->and($location)->toContain('shop=modernforestry.myshopify.com')
        ->and($location)->toContain('host=admin-host-token')
        ->and($location)->toContain('embedded=1');

    $profile->refresh();

    expect($profile->first_name)->toBe('Embedded')
        ->and($profile->last_name)->toBe('Updated')
        ->and($profile->email)->toBe('embedded.updated@example.com')
        ->and($profile->phone)->toBe('+18646165468');
});

test('candle cash adjustment app route does not require csrf token in embedded admin', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    CandleCashBalance::query()->updateOrCreate(
        ['marketing_profile_id' => $profile->id],
        ['balance' => 25]
    );

    $response = $this->post(
        route('shopify.app.customers.candle-cash.adjust', array_merge([
            'marketingProfile' => $profile->id,
        ], retailEmbeddedSignedQuery())),
        [
            'direction' => 'add',
            'amount' => 10,
            'reason' => 'Embedded app adjustment',
        ]
    );

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->not->toBeNull()
        ->and($location)->toContain('/shopify/app/customers/manage/' . $profile->id)
        ->and($location)->toContain('shop=modernforestry.myshopify.com')
        ->and($location)->toContain('host=admin-host-token')
        ->and($location)->toContain('embedded=1');

    $balance = CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->first();
    expect((int) ($balance?->balance ?? 0))->toBe(35);
});

test('candle cash subtraction alias route updates balance without sending sms', function () {
    configureEmbeddedRetailStore();
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);

    $profile = seedEmbeddedCustomerDetailFixture();
    $profile->forceFill([
        'phone' => '555-222-3333',
        'normalized_phone' => '15552223333',
        'accepts_sms_marketing' => true,
    ])->save();

    CandleCashBalance::query()->updateOrCreate(
        ['marketing_profile_id' => $profile->id],
        ['balance' => 150]
    );

    startEmbeddedCustomersDetailSession($this);

    $response = $this->post(
        route('shopify.app.customers.candle-cash.adjust', array_merge([
            'marketingProfile' => $profile->id,
        ], retailEmbeddedSignedQuery()), false),
        [
            'direction' => 'subtract',
            'amount' => 150,
            'reason' => 'Customer balance correction',
        ]
    );

    $response->assertRedirect();

    $balance = CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->first();

    expect((int) ($balance?->balance ?? 0))->toBe(0)
        ->and(MarketingMessageDelivery::query()->where('marketing_profile_id', $profile->id)->count())->toBe(0);
});

test('embedded customer detail forms use helper-generated urls with Shopify query params', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $signature = retailEmbeddedSignedQuery();
    $response = $this->get(
        route('shopify.app.customers.detail', array_merge(['marketingProfile' => $profile->id], $signature))
    );

    $response->assertOk();
    $content = $response->getContent();

    $generator = new ShopifyEmbeddedCustomerActionUrlGenerator();
    $signedRequest = Request::create('/', 'GET', $signature);

    $expectedActions = [
        'customers.update',
        'customers.candle-cash.adjust',
        'customers.candle-cash.send',
        'customers.update-consent',
        'customers.message',
    ];

    foreach ($expectedActions as $routeName) {
        $expected = $generator->url($routeName, ['marketingProfile' => $profile->id], $signedRequest);
        $escaped = htmlspecialchars($expected, ENT_QUOTES, 'UTF-8');
        $this->assertStringContainsString('action="' . $escaped . '"', $content);
    }
});

test('embedded customer detail navigation links preserve Shopify query params', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $signature = retailEmbeddedSignedQuery();
    $response = $this->get(
        route('shopify.app.customers.detail', array_merge(['marketingProfile' => $profile->id], $signature))
    );

    $response->assertOk();
    $content = $response->getContent();
    $this->assertStringContainsString('href="/customers/manage?', $content);
    $this->assertStringContainsString('href="/customers/activity?', $content);
    $this->assertStringContainsString('href="/customers/questions?', $content);
    $this->assertStringContainsString('shop=modernforestry.myshopify.com', $content);
    $this->assertStringContainsString('host=admin-host-token', $content);
    $this->assertStringContainsString('embedded=1', $content);
    $this->assertStringContainsString('href="/?shop=', $content);
    $this->assertStringContainsString('href="/settings?shop=', $content);
    $this->assertStringContainsString('/marketing/customers/' . $profile->id, $content);
});

test('customer detail forms fall back to embedded routes without Shopify host', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $response = $this->get(route('shopify.embedded.customers.detail', ['marketingProfile' => $profile->id], false));

    $response->assertOk();
    $content = $response->getContent();

    $expectedActions = [
        route('shopify.embedded.customers.update', ['marketingProfile' => $profile->id], false),
        route('shopify.embedded.customers.candle-cash.adjust', ['marketingProfile' => $profile->id], false),
        route('shopify.embedded.customers.candle-cash.send', ['marketingProfile' => $profile->id], false),
        route('shopify.embedded.customers.update-consent', ['marketingProfile' => $profile->id], false),
        route('shopify.embedded.customers.message', ['marketingProfile' => $profile->id], false),
    ];

    foreach ($expectedActions as $action) {
        $this->assertStringContainsString('action="' . $action . '"', $content);
    }
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

    $response = $this->get(route('shopify.embedded.customers.detail', ['marketingProfile' => $profile->id], false));

    $response->assertOk()
        ->assertSeeText('Manual Adjustment')
        ->assertSeeText('Admin');
});

test('sms message send succeeds when consented', function () {
    configureEmbeddedRetailStore();
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);

    $profile = seedEmbeddedCustomerDetailFixture();
    $profile->forceFill([
        'phone' => '555-222-3333',
        'normalized_phone' => '15552223333',
        'accepts_sms_marketing' => true,
    ])->save();

    $user = User::factory()->create([
        'name' => 'Morgan Admin',
        'email' => 'morgan@example.com',
    ]);

    startEmbeddedCustomersDetailSession($this);

    $token = csrf_token();

    $response = $this->actingAs($user)->withSession(['_token' => $token])->post(
        route('shopify.embedded.customers.message', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'channel' => 'sms',
            'message' => 'Hello from embedded admin.',
        ]
    );

    $response->assertRedirect();

    $delivery = MarketingMessageDelivery::query()
        ->where('marketing_profile_id', $profile->id)
        ->latest('id')
        ->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery->channel)->toBe('sms')
        ->and((int) $delivery->created_by)->toBe($user->id);

    $detailResponse = $this->get(route('shopify.embedded.customers.detail', ['marketingProfile' => $profile->id], false));
    $detailResponse->assertOk()
        ->assertSeeText('SMS Message')
        ->assertSeeText('Morgan Admin');
});

test('sms message send is rejected when consent is missing', function () {
    configureEmbeddedRetailStore();
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);

    $profile = seedEmbeddedCustomerDetailFixture();
    $profile->forceFill([
        'phone' => '555-111-0000',
        'normalized_phone' => '15551110000',
        'accepts_sms_marketing' => false,
    ])->save();

    startEmbeddedCustomersDetailSession($this);

    $token = csrf_token();

    $response = $this->withSession(['_token' => $token])->post(
        route('shopify.embedded.customers.message', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'channel' => 'sms',
            'message' => 'Consent required test.',
        ]
    );

    $response->assertRedirect();

    expect(MarketingMessageDelivery::query()->where('marketing_profile_id', $profile->id)->count())->toBe(0);
});

test('invalid message input is rejected', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $token = csrf_token();

    $response = $this->withSession(['_token' => $token])->post(
        route('shopify.embedded.customers.message', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'channel' => 'email',
            'message' => '',
        ]
    );

    $response->assertSessionHasErrors(['channel', 'message']);
});

test('message send alias route resolves with embedded context', function () {
    configureEmbeddedRetailStore();
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);

    $profile = seedEmbeddedCustomerDetailFixture();
    $profile->forceFill([
        'phone' => '555-333-4444',
        'normalized_phone' => '15553334444',
        'accepts_sms_marketing' => true,
    ])->save();

    startEmbeddedCustomersDetailSession($this);

    $token = csrf_token();

    $response = $this->withSession(['_token' => $token])->post(
        route('shopify.app.customers.message', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'channel' => 'sms',
            'message' => 'Alias route send.',
        ]
    );

    $response->assertRedirect();
});

test('send candle cash succeeds and records gift transaction', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $user = User::factory()->create([
        'name' => 'Casey Admin',
        'email' => 'casey@example.com',
    ]);

    $token = csrf_token();

    $response = $this->actingAs($user)->withSession(['_token' => $token])->post(
        route('shopify.embedded.customers.candle-cash.send', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'amount' => 15,
            'reason' => 'Welcome gift',
        ]
    );

    $response->assertRedirect();

    $transaction = CandleCashTransaction::query()
        ->where('marketing_profile_id', $profile->id)
        ->orderByDesc('id')
        ->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->type)->toBe('gift')
        ->and((int) $transaction->points)->toBe(15)
        ->and($transaction->description)->toBe('Welcome gift')
        ->and((int) $transaction->source_id)->toBe($user->id);
    expect($transaction)->and($transaction->gift_intent)->toBeNull()
        ->and($transaction->gift_origin)->toBeNull()
        ->and($transaction->campaign_key)->toBeNull()
        ->and($transaction->notified_via)->toBe('none')
        ->and($transaction->notification_status)->toBe('skipped');

    $detailResponse = $this->get(route('shopify.embedded.customers.detail', ['marketingProfile' => $profile->id], false));
    $detailResponse->assertOk()
        ->assertSeeText('Candle Cash Sent')
        ->assertSeeText('Casey Admin');
});

test('send candle cash rejects invalid input', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $token = csrf_token();

    $response = $this->withSession(['_token' => $token])->post(
        route('shopify.embedded.customers.candle-cash.send', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'amount' => 0,
            'reason' => '',
        ]
    );

    $response->assertSessionHasErrors(['amount', 'reason']);
});

test('send candle cash alias route resolves with embedded context', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    startEmbeddedCustomersDetailSession($this);

    $token = csrf_token();

    $response = $this->withSession(['_token' => $token])->post(
        route('shopify.app.customers.candle-cash.send', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'amount' => 5,
            'reason' => 'Alias send',
        ]
    );

    $response->assertRedirect();
});

test('send candle cash records gift metadata and sms notification status', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    $profile->forceFill([
        'phone' => '555-222-1111',
        'normalized_phone' => '15552221111',
        'accepts_sms_marketing' => true,
    ])->save();

    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);

    startEmbeddedCustomersDetailSession($this);

    $user = User::factory()->create(['name' => 'Gift Admin']);
    $token = csrf_token();

    $response = $this->actingAs($user)->withSession(['_token' => $token])->post(
        route('shopify.embedded.customers.candle-cash.send', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'amount' => 12,
            'reason' => 'VIP thank you',
            'gift_intent' => 'vip',
            'gift_origin' => 'marketing',
            'campaign_key' => 'spring-royalty',
            'message' => 'Enjoy a little extra Candle Cash!',
        ]
    );

    $response->assertRedirect();

    $transaction = CandleCashTransaction::query()
        ->where('marketing_profile_id', $profile->id)
        ->latest('id')
        ->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->gift_intent)->toBe('vip')
        ->and($transaction->gift_origin)->toBe('marketing')
        ->and($transaction->campaign_key)->toBe('spring-royalty')
        ->and($transaction->notified_via)->toBe('sms')
        ->and($transaction->notification_status)->toBe('sent');
});

test('send candle cash continues even when optional message cannot send', function () {
    configureEmbeddedRetailStore();
    $profile = seedEmbeddedCustomerDetailFixture();
    $profile->forceFill([
        'phone' => '555-999-0000',
        'normalized_phone' => '15559990000',
        'accepts_sms_marketing' => false,
    ])->save();

    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);

    startEmbeddedCustomersDetailSession($this);

    $token = csrf_token();

    $response = $this->withSession(['_token' => $token])->post(
        route('shopify.embedded.customers.candle-cash.send', ['marketingProfile' => $profile->id], false),
        [
            '_token' => $token,
            'amount' => 8,
            'reason' => 'Send with optional message',
            'message' => 'Hello!',
        ]
    );

    $response->assertRedirect();

    $transaction = CandleCashTransaction::query()
        ->where('marketing_profile_id', $profile->id)
        ->orderByDesc('id')
        ->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->type)->toBe('gift')
        ->and((int) $transaction->points)->toBe(8)
        ->and($transaction->notified_via)->toBe('sms')
        ->and($transaction->notification_status)->toBe('failed');
});
