<?php

use App\Models\CandleCashReferral;
use App\Models\CandleCashReward;
use App\Models\CandleCashTask;
use App\Models\CandleCashTaskCompletion;
use App\Models\CandleCashTaskEvent;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingSetting;
use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Services\Marketing\CandleCashOrderEventService;
use App\Services\Marketing\CandleCashReferralService;
use App\Services\Marketing\CandleCashTaskService;
use App\Services\Marketing\CandleCashVerificationService;
use Carbon\Carbon;
use Illuminate\Support\Str;

test('marketing manager can load candle cash dashboard via stable base route name', function () {
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('marketing.candle-cash'))
        ->assertOk()
        ->assertSeeText('Candle Cash')
        ->assertSeeText('Rewards')
        ->assertSeeText('This page is split into Tasks and Status so it is easier to separate what you manage from what is currently live.')
        ->assertSeeText('Tasks')
        ->assertSeeText('Status')
        ->assertSeeText('Ways to Earn')
        ->assertSeeText('Ways to Redeem');
});

test('marketing manager can load candle cash management sections', function () {
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('marketing.candle-cash.tasks'))
        ->assertOk()
        ->assertSeeText('Ways to Earn');

    $this->actingAs($user)
        ->get(route('marketing.candle-cash.redeem'))
        ->assertOk()
        ->assertSeeText('Ways to Redeem');

    $this->actingAs($user)
        ->get(route('marketing.candle-cash.queue'))
        ->assertOk()
        ->assertSeeText('All events');

    $this->actingAs($user)
        ->get(route('marketing.candle-cash.customers'))
        ->assertOk()
        ->assertSeeText('Pick a customer from the left');

    $this->actingAs($user)
        ->get(route('marketing.candle-cash.referrals'))
        ->assertOk()
        ->assertSeeText('All referrals');

    $this->actingAs($user)
        ->get(route('marketing.candle-cash.settings'))
        ->assertOk()
        ->assertSeeText('Verification hooks');
});

test('queue shows manual google review proof details and the live review link for approval', function () {
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    MarketingSetting::query()->updateOrCreate(
        ['key' => 'candle_cash_integration_config'],
        [
            'value' => [
                'google_review_enabled' => true,
                'google_review_url' => 'https://g.page/r/CTucm4R1-wmOEAI/review',
            ],
            'description' => 'queue review proof test config',
        ]
    );

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Queue',
        'last_name' => 'Reviewer',
        'email' => 'queue-reviewer@example.com',
        'normalized_email' => 'queue-reviewer@example.com',
    ]);

    app(CandleCashTaskService::class)->submitCustomerTask($profile, 'google-review', [
        'proof_url' => 'https://example.com/review-proof',
        'proof_text' => 'Posted as Queue Reviewer on April 7.',
    ], [
        'source_type' => 'shopify_widget_task',
        'source_id' => 'google-review:manual-window:2026-04-06',
        'source_event_key' => 'google-review:profile:' . $profile->id . ':manual-window:2026-04-06',
        'request_key' => 'queue-google-review-proof',
        'effective_verification_mode' => 'manual_review_fallback',
        'effective_auto_award' => false,
        'effective_requires_manual_approval' => true,
        'effective_requires_customer_submission' => true,
        'effective_proof_text_required' => true,
    ]);

    $this->actingAs($user)
        ->get(route('marketing.candle-cash.queue'))
        ->assertOk()
        ->assertSeeText('Temporary manual verification')
        ->assertSeeText('Posted as Queue Reviewer on April 7.')
        ->assertSeeText('Open submitted proof link')
        ->assertSeeText('Open live Google review page');
});

test('marketing manager can update an existing candle cash redeem rule from backstage', function () {
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $reward = CandleCashReward::query()->where('name', 'Free wax melt')->firstOrFail();

    $this->actingAs($user)
        ->from(route('marketing.candle-cash.redeem'))
        ->patch(route('marketing.candle-cash.redeem.update', $reward), [
            'title' => 'Free Wax Melt Duo',
            'description' => 'Updated from the Backstage rewards page.',
            'candle_cash_cost' => 3,
            'reward_value' => 'wax_melt_duo',
            'enabled' => '0',
        ])
        ->assertRedirect(route('marketing.candle-cash.redeem'));

    $reward->refresh();

    expect((string) $reward->name)->toBe('Free Wax Melt Duo')
        ->and((string) $reward->description)->toBe('Updated from the Backstage rewards page.')
        ->and((int) $reward->candle_cash_cost)->toBe(3)
        ->and((string) $reward->reward_value)->toBe('wax_melt_duo')
        ->and((bool) $reward->is_active)->toBeFalse();
});

test('non candle club members are blocked from candle club voting reward', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Ava',
        'email' => 'ava@example.com',
        'normalized_email' => 'ava@example.com',
    ]);

    $result = app(CandleCashTaskService::class)->submitCustomerTask($profile, 'candle-club-vote', [], [
        'source_type' => 'shopify_widget_task',
        'source_id' => 'candle-club-vote',
        'request_key' => 'task-vote-1',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['error'])->toBe('not_eligible');

    $task = CandleCashTask::query()->where('handle', 'candle-club-vote')->firstOrFail();

    $this->assertDatabaseHas('candle_cash_task_completions', [
        'marketing_profile_id' => $profile->id,
        'candle_cash_task_id' => $task->id,
        'status' => 'blocked',
        'blocked_reason' => 'not_eligible',
    ]);

    $this->assertDatabaseHas('marketing_storefront_events', [
        'event_type' => 'candle_cash_task_blocked',
        'marketing_profile_id' => $profile->id,
        'issue_type' => 'not_eligible',
        'source_id' => 'candle-club-vote',
    ]);
});

test('system triggered tasks cannot be submitted manually', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Theo',
        'email' => 'theo@example.com',
        'normalized_email' => 'theo@example.com',
    ]);

    $result = app(CandleCashTaskService::class)->submitCustomerTask($profile, 'second-order', [], [
        'source_type' => 'shopify_widget_task',
        'source_id' => 'second-order',
        'request_key' => 'task-second-order-1',
    ]);

    expect($result['ok'])->toBeFalse();
    expect($result['error'])->toBe('auto_verified_task');

    $task = CandleCashTask::query()->where('handle', 'second-order')->firstOrFail();

    $this->assertDatabaseHas('candle_cash_task_completions', [
        'marketing_profile_id' => $profile->id,
        'candle_cash_task_id' => $task->id,
        'status' => 'blocked',
        'blocked_reason' => 'auto_verified_task',
    ]);
});

test('second order reward is awarded only once for the same linked order', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Mina',
        'email' => 'mina@example.com',
        'normalized_email' => 'mina@example.com',
    ]);

    $firstOrder = Order::query()->create([
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 1001,
        'shopify_customer_id' => 'cust-100',
        'order_number' => '#1001',
        'email' => $profile->email,
    ]);

    $secondOrder = Order::query()->create([
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 1002,
        'shopify_customer_id' => 'cust-100',
        'order_number' => '#1002',
        'email' => $profile->email,
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'order',
        'source_id' => (string) $firstOrder->id,
        'match_method' => 'email',
        'confidence' => 1,
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'order',
        'source_id' => (string) $secondOrder->id,
        'match_method' => 'email',
        'confidence' => 1,
    ]);

    $service = app(CandleCashOrderEventService::class);
    $service->handle($secondOrder, []);
    $service->handle($secondOrder, []);

    $task = CandleCashTask::query()->where('handle', 'second-order')->firstOrFail();

    expect(CandleCashTaskCompletion::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('candle_cash_task_id', $task->id)
        ->where('status', 'awarded')
        ->count())->toBe(1);

    expect(CandleCashTransaction::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('source', 'candle_cash_task')
        ->where('description', 'Place a second order reward')
        ->count())->toBe(1);
});

test('qualifying referral order awards both sides once', function () {
    $referrer = MarketingProfile::query()->create([
        'first_name' => 'Rae',
        'email' => 'rae@example.com',
        'normalized_email' => 'rae@example.com',
    ]);

    $referred = MarketingProfile::query()->create([
        'first_name' => 'June',
        'email' => 'june@example.com',
        'normalized_email' => 'june@example.com',
    ]);

    $order = Order::query()->create([
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 2001,
        'shopify_customer_id' => 'cust-200',
        'order_number' => '#2001',
        'email' => $referred->email,
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $referred->id,
        'source_type' => 'order',
        'source_id' => (string) $order->id,
        'match_method' => 'email',
        'confidence' => 1,
    ]);

    $referralCode = app(CandleCashReferralService::class)->referralCodeForProfile($referrer);
    $service = app(CandleCashOrderEventService::class);

    $service->handle($order, ['referral_code' => $referralCode]);
    $service->handle($order, ['referral_code' => $referralCode]);

    expect(CandleCashReferral::query()->count())->toBe(1);

    $referral = CandleCashReferral::query()->firstOrFail();
    expect($referral->status)->toBe('qualified');
    expect($referral->referrer_marketing_profile_id)->toBe($referrer->id);
    expect($referral->referred_marketing_profile_id)->toBe($referred->id);

    $task = CandleCashTask::query()->where('handle', 'refer-a-friend')->firstOrFail();
    $friendTask = CandleCashTask::query()->where('handle', 'referred-friend-bonus')->firstOrFail();

    expect(CandleCashTaskCompletion::query()
        ->where('marketing_profile_id', $referrer->id)
        ->where('candle_cash_task_id', $task->id)
        ->where('status', 'awarded')
        ->count())->toBe(1);

    expect(CandleCashTaskCompletion::query()
        ->where('marketing_profile_id', $referred->id)
        ->where('candle_cash_task_id', $friendTask->id)
        ->where('status', 'awarded')
        ->count())->toBe(1);
});

test('eligible candle club members can earn the vote reward once per campaign', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Cora',
        'email' => 'cora@example.com',
        'normalized_email' => 'cora@example.com',
        'source_channels' => ['candle_club'],
    ]);

    $service = app(CandleCashVerificationService::class);

    $first = $service->recordCandleClubVote($profile, 'spring-2026');
    $second = $service->recordCandleClubVote($profile, 'spring-2026');

    expect($first['ok'])->toBeTrue()
        ->and($first['state'])->toBe('awarded')
        ->and($second['ok'])->toBeTrue()
        ->and($second['state'])->toBe('awarded');

    $task = CandleCashTask::query()->where('handle', 'candle-club-vote')->firstOrFail();

    expect(CandleCashTaskCompletion::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('candle_cash_task_id', $task->id)
        ->where('status', 'awarded')
        ->count())->toBe(1);

    expect(CandleCashTaskEvent::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('candle_cash_task_id', $task->id)
        ->where('source_event_key', 'candle-club-vote:profile:' . $profile->id . ':campaign:spring-2026')
        ->value('duplicate_hits'))->toBe(1);
});

test('candle club membership is recognized from the live subscription product order lines', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Juniper',
        'email' => 'juniper@example.com',
        'normalized_email' => 'juniper@example.com',
    ]);

    $order = Order::query()->create([
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 4012,
        'shopify_customer_id' => 'cust-club-4012',
        'order_number' => '#4012',
        'email' => $profile->email,
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'order',
        'source_id' => (string) $order->id,
        'match_method' => 'email',
        'confidence' => 1,
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'shopify_product_id' => 998877,
        'shopify_variant_id' => 554433,
        'quantity' => 1,
        'ordered_qty' => 1,
        'currency_code' => 'USD',
        'unit_price' => 34.00,
        'line_subtotal' => 34.00,
        'line_total' => 34.00,
        'raw_title' => 'Modern Forestry Candle Club 16oz Subscription with Gifts',
        'raw_variant' => 'Monthly 16oz membership',
    ]);

    $eligibilityService = app(\App\Services\Marketing\CandleCashTaskEligibilityService::class);

    expect($eligibilityService->membershipStatusForProfile($profile))->toBe('active_candle_club_member');

    app(CandleCashOrderEventService::class)->handle($order, []);

    $task = CandleCashTask::query()->where('handle', 'candle-club-join')->firstOrFail();

    expect(CandleCashTaskCompletion::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('candle_cash_task_id', $task->id)
        ->where('status', 'awarded')
        ->count())->toBe(1);
});

test('candle club membership is recognized from synced external vip tier signals', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Ivy',
        'email' => 'ivy@example.com',
        'normalized_email' => 'ivy@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'shopify',
        'store_key' => 'retail',
        'external_customer_id' => 'shopify-ivy-1',
        'email' => $profile->email,
        'normalized_email' => $profile->normalized_email,
        'vip_tier' => 'Candle Club',
        'source_channels' => ['shopify', 'candle_club'],
        'synced_at' => now(),
    ]);

    $eligibilityService = app(\App\Services\Marketing\CandleCashTaskEligibilityService::class);

    expect($eligibilityService->membershipStatusForProfile($profile))->toBe('active_candle_club_member');
});

test('email signup reward is awarded automatically on verified opt in without revoking sms consent', function () {
    config()->set('marketing.shopify.signing_secret', 'stage10-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage10-retail-client');

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Eden',
        'email' => 'eden.' . Str::lower(Str::random(6)) . '@example.com',
        'normalized_email' => null,
        'accepts_sms_marketing' => true,
    ]);

    $profile->forceFill([
        'normalized_email' => Str::lower($profile->email),
    ])->save();

    $payload = [
        'email' => $profile->email,
        'first_name' => 'Eden',
        'consent_email' => true,
        'flow' => 'direct',
    ];

    $query = ['shop' => 'timberline.example.myshopify.com'];
    $headers = stage10SignedHeaders(
        'POST',
        '/shopify/marketing/v1/consent/request',
        $query,
        json_encode($payload),
        'stage10-secret'
    );

    $this->withHeaders($headers)
        ->postJson(route('marketing.shopify.v1.consent.request', $query), $payload)
        ->assertOk()
        ->assertJsonPath('data.accepts_email_marketing', true)
        ->assertJsonPath('data.accepts_sms_marketing', true);

    $task = CandleCashTask::query()->where('handle', 'email-signup')->firstOrFail();

    expect(MarketingProfile::query()->findOrFail($profile->id)->accepts_sms_marketing)->toBeTrue()
        ->and(CandleCashTaskCompletion::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('candle_cash_task_id', $task->id)
            ->where('status', 'awarded')
            ->count())->toBe(1);
});

test('sms signup reward is awarded automatically once for the same verified opt in event', function () {
    config()->set('marketing.shopify.signing_secret', 'stage10-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);
    config()->set('services.shopify.stores.retail.shop', 'timberline.example.myshopify.com');
    config()->set('services.shopify.stores.retail.client_id', 'stage10-retail-client');

    $payload = [
        'phone' => '5554441234',
        'first_name' => 'Text',
        'last_name' => 'Friend',
        'consent_sms' => true,
        'flow' => 'direct',
    ];

    $query = ['shop' => 'timberline.example.myshopify.com'];
    $headers = stage10SignedHeaders(
        'POST',
        '/shopify/marketing/v1/consent/request',
        $query,
        json_encode($payload),
        'stage10-secret'
    );

    $firstResponse = $this->withHeaders($headers)
        ->postJson(route('marketing.shopify.v1.consent.request', $query), $payload)
        ->assertOk()
        ->assertJsonPath('data.state', 'sms_confirmed')
        ->json();

    $this->withHeaders($headers)
        ->postJson(route('marketing.shopify.v1.consent.request', $query), $payload)
        ->assertOk()
        ->assertJsonPath('data.state', 'sms_confirmed');

    $profileId = (int) data_get($firstResponse, 'data.profile_id', 0);
    $task = CandleCashTask::query()->where('handle', 'sms-signup')->firstOrFail();

    expect($profileId)->toBeGreaterThan(0)
        ->and(CandleCashTaskCompletion::query()
            ->where('marketing_profile_id', $profileId)
            ->where('candle_cash_task_id', $task->id)
            ->where('status', 'awarded')
            ->count())->toBe(1);
});

test('duplicate google review events cannot award twice', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Greta',
        'email' => 'greta@example.com',
        'normalized_email' => 'greta@example.com',
    ]);

    $service = app(CandleCashVerificationService::class);

    $first = $service->awardGoogleReview($profile, 'google-review-123', ['rating' => 5]);
    $second = $service->awardGoogleReview($profile, 'google-review-123', ['rating' => 5]);

    expect($first['ok'])->toBeTrue()
        ->and($first['state'])->toBe('awarded')
        ->and($second['ok'])->toBeTrue()
        ->and($second['state'])->toBe('awarded');

    $task = CandleCashTask::query()->where('handle', 'google-review')->firstOrFail();

    expect(CandleCashTaskCompletion::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('candle_cash_task_id', $task->id)
        ->where('status', 'awarded')
        ->count())->toBe(1);

    expect(CandleCashTaskEvent::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('candle_cash_task_id', $task->id)
        ->where('source_event_key', 'google-review:google-review-123')
        ->value('duplicate_hits'))->toBe(1);
});

test('google review rewards only the first verified review in a seven day window', function () {
    Carbon::setTestNow('2026-04-01 09:00:00');

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Greta',
        'email' => 'greta-weekly@example.com',
        'normalized_email' => 'greta-weekly@example.com',
    ]);

    $service = app(CandleCashVerificationService::class);

    $first = $service->awardGoogleReview($profile, 'google-review-window-1', ['rating' => 5]);
    $second = $service->awardGoogleReview($profile, 'google-review-window-2', ['rating' => 5]);

    expect($first['ok'])->toBeTrue()
        ->and($first['state'])->toBe('awarded')
        ->and($second['ok'])->toBeFalse()
        ->and($second['state'])->toBe('completed')
        ->and($second['error'])->toBe('max_completions_reached');

    $task = CandleCashTask::query()->where('handle', 'google-review')->firstOrFail();

    expect(CandleCashTaskCompletion::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('candle_cash_task_id', $task->id)
        ->where('status', 'awarded')
        ->count())->toBe(1);

    Carbon::setTestNow('2026-04-09 09:00:01');

    $third = $service->awardGoogleReview($profile, 'google-review-window-3', ['rating' => 5]);

    expect($third['ok'])->toBeTrue()
        ->and($third['state'])->toBe('awarded');

    expect(CandleCashTaskCompletion::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('candle_cash_task_id', $task->id)
        ->where('status', 'awarded')
        ->count())->toBe(2);

    Carbon::setTestNow();
});

test('product review rewards only the first verified review in a seven day window', function () {
    Carbon::setTestNow('2026-04-01 12:00:00');

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Mila',
        'email' => 'mila-weekly@example.com',
        'normalized_email' => 'mila-weekly@example.com',
    ]);

    $service = app(CandleCashVerificationService::class);

    $first = $service->awardProductReview($profile, 'product-review-window-1', ['rating' => 5]);
    $second = $service->awardProductReview($profile, 'product-review-window-2', ['rating' => 4]);

    expect($first['ok'])->toBeTrue()
        ->and($first['state'])->toBe('awarded')
        ->and($second['ok'])->toBeFalse()
        ->and($second['state'])->toBe('completed')
        ->and($second['error'])->toBe('max_completions_reached');

    $task = CandleCashTask::query()->where('handle', 'product-review')->firstOrFail();

    expect(CandleCashTaskCompletion::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('candle_cash_task_id', $task->id)
        ->where('status', 'awarded')
        ->count())->toBe(1);

    Carbon::setTestNow('2026-04-09 12:00:01');

    $third = $service->awardProductReview($profile, 'product-review-window-3', ['rating' => 5]);

    expect($third['ok'])->toBeTrue()
        ->and($third['state'])->toBe('awarded');

    expect(CandleCashTaskCompletion::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('candle_cash_task_id', $task->id)
        ->where('status', 'awarded')
        ->count())->toBe(2);

    Carbon::setTestNow();
});
