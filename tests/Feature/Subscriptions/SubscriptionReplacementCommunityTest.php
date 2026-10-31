<?php

use App\Models\MarketingProfile;
use App\Models\SubscriptionMemberVote;
use App\Services\Mobile\ModernForestryMobileAccountService;
use App\Services\Mobile\ModernForestryMobileCustomerSession;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use App\Services\Subscriptions\SubscriptionModuleService;
use App\Services\Subscriptions\SubscriptionReplacementCommunityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Subscriptions\BraintreeScenario;

beforeEach(function () {
    Mail::fake();
    Queue::fake();
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 15)->setTime(10, 0)->utc());
    $this->s = new BraintreeScenario;
    $this->s->store->update(['store_key' => 'retail']);
    $this->s->activate();
    $this->profile = MarketingProfile::factory()->create(['tenant_id' => $this->s->tenant->id]);
    $this->session = new ModernForestryMobileCustomerSession($this->profile, 'fictional-authenticated-token', [
        'source_type' => 'shopify_customer', 'shopify_customer_id' => '456',
    ]);
    $this->legacy = DB::table('subscription_contracts')->insertGetId(['tenant_id' => $this->s->tenant->id, 'marketing_profile_id' => $this->profile->id,
        'shopify_subscription_contract_gid' => 'gid://shopify/SubscriptionContract/1234', 'recharge_subscription_id' => '789',
        'shopify_customer_gid' => 'gid://shopify/Customer/456', 'is_candle_club' => true, 'status' => 'cancelled', 'created_at' => now(), 'updated_at' => now()]);
    $this->poll = DB::table('subscription_polls')->insertGetId(['tenant_id' => $this->s->tenant->id, 'poll_type' => SubscriptionModuleService::CANDLE_CLUB_TYPE,
        'title' => 'Next month', 'status' => 'open', 'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(), 'share_token' => Str::random(40), 'created_at' => now(), 'updated_at' => now()]);
    $this->option = DB::table('subscription_poll_options')->insertGetId(['tenant_id' => $this->s->tenant->id, 'subscription_poll_id' => $this->poll,
        'label' => 'Forest Rain', 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $this->scent = DB::table('subscription_candle_club_monthly_scents')->insertGetId(['tenant_id' => $this->s->tenant->id, 'year' => 2027, 'month' => 1,
        'title' => 'Winter Forest', 'photo_url' => 'https://images.example.test/winter.jpg', 'created_at' => now(), 'updated_at' => now()]);
    $this->account = app(ModernForestryMobileAccountService::class);
});

afterEach(function () {
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

function replacementCommunityVote($case, array $override = []): array
{
    return $case->account->candleClubAction($case->session, $override + ['action' => 'vote_for_next_month', 'poll_id' => $case->poll, 'option_id' => $case->option]);
}

function retainedClubVote($case): void
{
    DB::table('subscription_votes')->insert(['tenant_id' => $case->s->tenant->id, 'subscription_poll_id' => $case->poll, 'subscription_poll_option_id' => $case->option,
        'subscription_contract_id' => $case->legacy, 'marketing_profile_id' => $case->profile->id, 'shopify_subscription_contract_gid' => 'gid://shopify/SubscriptionContract/1234',
        'shopify_customer_gid' => 'gid://shopify/Customer/456', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);
}

test('activated membership keeps its poll monthly scents and private comments after source cancellation without provider reads', function () {
    $before = Http::recorded()->count();
    $payload = $this->account->candleClub($this->session);
    expect($payload['eligible'])->toBeTrue()->and($payload['actions']['can_vote'])->toBeTrue()
        ->and($payload['active_poll']['id'])->toBe($this->poll)->and($payload['monthly_scents'][0]['title'])->toBe('Winter Forest');
    $request = ['request_id' => (string) Str::uuid(), 'rating' => 4, 'body' => 'Warm and woody.'];
    $first = $this->account->candleClubScentFeedback($this->session, $this->scent, $request);
    $retry = $this->account->candleClubScentFeedback($this->session, $this->scent, $request);
    $saved = DB::table('subscription_candle_club_scent_feedback')->first();
    expect($first['ok'])->toBeTrue()->and($retry['id'])->toBe($first['id'])->and($saved->subscription_contract_id)->toBeNull()
        ->and($saved->status)->toBe('pending')->and($saved->visibility)->toBe('candle_club')
        ->and(json_decode($saved->metadata, true)['billing_agreement_id'])->toBe($this->s->agreement->id)
        ->and(DB::table('subscription_contracts')->where('id', $this->legacy)->value('status'))->toBe('cancelled')
        ->and(Http::recorded()->count())->toBe($before)->and($this->s->sales)->toBe([]);
});

test('one neutral membership ballot appears in shared totals history and already voted state', function () {
    $result = replacementCommunityVote($this);
    $retry = replacementCommunityVote($this);
    expect($result['status'])->toBe('vote_recorded')->and($retry['status'])->toBe('already_voted')
        ->and($result['candle_club']['active_poll']['already_voted'])->toBeTrue()
        ->and($result['candle_club']['vote_history'][0]['option_label'])->toBe('Forest Rain')
        ->and(SubscriptionMemberVote::count())->toBe(1)->and(DB::table('subscription_votes')->count())->toBe(0)
        ->and(app(SubscriptionModuleService::class)->pollPayload($this->poll)['options'][0]['votes'])->toBe(1);
    $vote = SubscriptionMemberVote::first();
    expect(DB::table('subscription_member_votes')->value('evidence'))->not->toContain('Forest Rain', 'gid://shopify/Customer/456');
    expect(fn () => $vote->update(['subscription_poll_option_id' => 99]))->toThrow(LogicException::class);
});

test('a retained exact-source ballot survives cutover and cannot be cast again in the new membership lane', function () {
    retainedClubVote($this);
    $payload = $this->account->candleClub($this->session);
    expect($payload['active_poll']['already_voted'])->toBeTrue()->and($payload['vote_history'][0]['option_label'])->toBe('Forest Rain')
        ->and(replacementCommunityVote($this)['status'])->toBe('already_voted')->and(SubscriptionMemberVote::count())->toBe(0)
        ->and(app(SubscriptionModuleService::class)->pollPayload($this->poll)['options'][0]['votes'])->toBe(1);
});

test('profile or email matches cannot borrow another source membership history', function () {
    retainedClubVote($this);
    DB::table('subscription_contracts')->where('id', $this->legacy)->update(['shopify_customer_gid' => 'gid://shopify/Customer/999']);
    $payload = $this->account->candleClub($this->session);
    expect($payload['vote_history'])->toBe([])->and($payload['active_poll']['already_voted'])->toBeFalse();
    expect(replacementCommunityVote($this)['status'])->toBe('vote_recorded');
});

test('an unmapped previous ballot requires identity review instead of another vote', function () {
    retainedClubVote($this);
    DB::table('subscription_contracts')->where('id', $this->legacy)->update(['recharge_subscription_id' => null]);
    expect(replacementCommunityVote($this)['status'])->toBe('membership_review_required')->and(SubscriptionMemberVote::count())->toBe(0);
});

test('paused cancelled or frozen membership cannot create a new vote or scent comment', function (string $kind) {
    $row = $this->s->agreement->fresh();
    if ($kind === 'freeze') {
        config()->set('subscriptions.replacement_servicing_enabled', false);
    } else {
        app(SubscriptionBillingAgreementService::class)->change($row->tenant_id, $this->s->actor, $row->id, $kind, (string) Str::uuid(), $row->revision);
    }
    expect(replacementCommunityVote($this)['status'])->toBe('not_eligible')
        ->and($this->account->candleClubScentFeedback($this->session, $this->scent, ['body' => 'Cannot write', 'rating' => 5])['status'])->toBe('not_eligible')
        ->and(SubscriptionMemberVote::count())->toBe(0)->and(DB::table('subscription_candle_club_scent_feedback')->count())->toBe(0);
})->with(['pause', 'cancel', 'freeze']);

test('a stale active mirror cannot provide a second ballot or comments for a converted membership', function () {
    DB::table('subscription_contracts')->where('id', $this->legacy)->update(['status' => 'active']);
    expect(app(SubscriptionReplacementCommunityService::class)->convertedContract($this->s->tenant->id, DB::table('subscription_contracts')->find($this->legacy)))->toBeTrue();
    expect(app(SubscriptionModuleService::class)->submitCandleClubScentFeedback($this->profile, $this->scent, ['body' => 'Wrong lane'])['status'])->toBe('not_eligible');
    expect(replacementCommunityVote($this)['status'])->toBe('vote_recorded');
});

test('a reviewed payment agreement alone cannot grant Candle Club benefits', function () {
    $this->s->store->update(['store_key' => 'earlier-fixture-store']);
    $s = new BraintreeScenario(candleClub: false);
    $s->store->update(['store_key' => 'retail']);
    $s->activate();
    $profile = MarketingProfile::factory()->create(['tenant_id' => $s->tenant->id]);
    $session = new ModernForestryMobileCustomerSession($profile, 'fictional', ['source_type' => 'shopify_customer', 'shopify_customer_id' => '456']);
    expect($this->account->candleClub($session)['eligible'])->toBeFalse()
        ->and($this->account->candleClubAction($session, ['action' => 'vote', 'poll_id' => $this->poll, 'option_id' => $this->option,
            'membership_program' => 'candle_club', 'tenant_id' => $this->s->tenant->id])['status'])->toBe('not_eligible')
        ->and(SubscriptionMemberVote::count())->toBe(0);
});

test('a changed profile for the same verified customer cannot acquire another membership ballot', function () {
    replacementCommunityVote($this);
    $profile = MarketingProfile::factory()->create(['tenant_id' => $this->s->tenant->id]);
    $this->session = new ModernForestryMobileCustomerSession($profile, 'fictional', ['source_type' => 'shopify_customer', 'shopify_customer_id' => '456']);
    expect(replacementCommunityVote($this)['status'])->toBe('already_voted')->and(SubscriptionMemberVote::count())->toBe(1);
});

test('poll timing and tenant option ownership are rechecked before accepting a member ballot', function (string $defect) {
    if ($defect === 'closed') {
        DB::table('subscription_polls')->where('id', $this->poll)->update(['status' => 'closed']);
    } elseif ($defect === 'future') {
        DB::table('subscription_polls')->where('id', $this->poll)->update(['opens_at' => now()->addDay()]);
    } elseif ($defect === 'expired') {
        DB::table('subscription_polls')->where('id', $this->poll)->update(['closes_at' => now()->subMinute()]);
    } elseif ($defect === 'option_tenant') {
        $other = \App\Models\Tenant::create(['name' => 'Other voting merchant', 'slug' => 'other-voting-merchant']);
        DB::table('subscription_poll_options')->where('id', $this->option)->update(['tenant_id' => $other->id]);
    } else {
        $other = DB::table('subscription_polls')->insertGetId(['tenant_id' => $this->s->tenant->id, 'poll_type' => SubscriptionModuleService::CANDLE_CLUB_TYPE,
            'title' => 'Other ballot', 'status' => 'closed', 'share_token' => Str::random(40)]);
        DB::table('subscription_poll_options')->where('id', $this->option)->update(['subscription_poll_id' => $other]);
    }
    expect(replacementCommunityVote($this)['ok'])->toBeFalse()->and(SubscriptionMemberVote::count())->toBe(0);
})->with(['closed', 'future', 'expired', 'option_tenant', 'option_poll']);

test('the existing native action endpoint records only the authenticated member and no supplied billing identity', function () {
    $sessions = Mockery::mock(\App\Services\Mobile\ModernForestryMobileCustomerSessionService::class);
    $sessions->shouldReceive('resolveFromRequest')->andReturn($this->session);
    app()->instance(\App\Services\Mobile\ModernForestryMobileCustomerSessionService::class, $sessions);
    $before = Http::recorded()->count();
    $this->postJson('/api/mobile/v1/modern-forestry/account/candle-club/action', ['action' => 'vote_for_next_month', 'poll_id' => $this->poll, 'option_id' => $this->option,
        'tenant_id' => 999, 'member_id' => 999, 'agreement_id' => 999, 'marketing_profile_id' => 999])->assertOk()->assertJsonPath('data.status', 'vote_recorded');
    $vote = SubscriptionMemberVote::first();
    expect($vote->tenant_id)->toBe($this->s->tenant->id)->and($vote->member_id)->toBe($this->s->agreement->member_id)
        ->and($vote->marketing_profile_id)->toBe($this->profile->id)->and(Http::recorded()->count())->toBe($before);
});

test('a scent note remains one accepted record when its author crosses from the original mirror to Everbranch', function () {
    $request = ['request_id' => (string) Str::uuid(), 'body' => 'My earlier scent note.', 'rating' => 5];
    $id = DB::table('subscription_candle_club_scent_feedback')->insertGetId(['tenant_id' => $this->s->tenant->id,
        'subscription_candle_club_monthly_scent_id' => $this->scent, 'subscription_contract_id' => $this->legacy, 'marketing_profile_id' => $this->profile->id,
        'rating' => 5, 'body' => $request['body'], 'visibility' => 'candle_club', 'status' => 'pending',
        'metadata' => json_encode(['request_id' => $request['request_id']]), 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
    expect($this->account->candleClubScentFeedback($this->session, $this->scent, $request)['id'])->toBe($id)
        ->and(DB::table('subscription_candle_club_scent_feedback')->count())->toBe(1)
        ->and((int) DB::table('subscription_candle_club_scent_feedback')->value('subscription_contract_id'))->toBe($this->legacy);
});

test('a merged profile cannot borrow another customers accepted scent-note request', function () {
    $request = ['request_id' => (string) Str::uuid(), 'body' => 'Another source customer note.', 'rating' => 5];
    DB::table('subscription_contracts')->where('id', $this->legacy)->update(['shopify_customer_gid' => 'gid://shopify/Customer/999']);
    DB::table('subscription_candle_club_scent_feedback')->insert(['tenant_id' => $this->s->tenant->id,
        'subscription_candle_club_monthly_scent_id' => $this->scent, 'subscription_contract_id' => $this->legacy, 'marketing_profile_id' => $this->profile->id,
        'rating' => 5, 'body' => $request['body'], 'visibility' => 'candle_club', 'status' => 'pending',
        'metadata' => json_encode(['request_id' => $request['request_id']]), 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
    expect($this->account->candleClubScentFeedback($this->session, $this->scent, $request)['status'])->toBe('request_conflict')
        ->and(DB::table('subscription_candle_club_scent_feedback')->count())->toBe(1);
});
