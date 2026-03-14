<?php

use App\Models\CandleCashReferral;
use App\Models\CandleCashTask;
use App\Models\CandleCashTaskCompletion;
use App\Models\CandleCashTaskEvent;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use App\Models\User;
use App\Services\Marketing\CandleCashOrderEventService;
use App\Services\Marketing\CandleCashReferralService;
use App\Services\Marketing\CandleCashTaskService;
use App\Services\Marketing\CandleCashVerificationService;
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
        ->assertSeeText('Pending events');
});

test('marketing manager can load candle cash management sections', function () {
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('marketing.candle-cash.tasks'))
        ->assertOk()
        ->assertSeeText('Task manager');

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

test('email signup reward is awarded automatically on verified opt in without revoking sms consent', function () {
    config()->set('marketing.shopify.signing_secret', 'stage10-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);

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

    $headers = stage10SignedHeaders(
        'POST',
        '/shopify/marketing/v1/consent/request',
        [],
        json_encode($payload),
        'stage10-secret'
    );

    $this->withHeaders($headers)
        ->postJson(route('marketing.shopify.v1.consent.request'), $payload)
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

    $payload = [
        'phone' => '5554441234',
        'first_name' => 'Text',
        'last_name' => 'Friend',
        'consent_sms' => true,
        'flow' => 'direct',
    ];

    $headers = stage10SignedHeaders(
        'POST',
        '/shopify/marketing/v1/consent/request',
        [],
        json_encode($payload),
        'stage10-secret'
    );

    $firstResponse = $this->withHeaders($headers)
        ->postJson(route('marketing.shopify.v1.consent.request'), $payload)
        ->assertOk()
        ->assertJsonPath('data.state', 'sms_confirmed')
        ->json();

    $this->withHeaders($headers)
        ->postJson(route('marketing.shopify.v1.consent.request'), $payload)
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
