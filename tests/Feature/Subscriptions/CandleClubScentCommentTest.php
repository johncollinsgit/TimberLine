<?php

use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Subscriptions\SubscriptionModuleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../ShopifyEmbeddedTestHelpers.php';
require_once __DIR__.'/../../Support/SubscriptionFoundationHelpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Fictional candle merchant', 'slug' => 'scent-comments']);
    subscriptionFoundationFixture($this, $this->tenant);
    $this->profile = MarketingProfile::factory()->create(['tenant_id' => $this->tenant->id]);
    DB::table('subscription_contracts')->insert([
        'tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile->id,
        'shopify_subscription_contract_gid' => 'gid://shopify/SubscriptionContract/3',
        'status' => 'active', 'is_candle_club' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->scent = DB::table('subscription_candle_club_monthly_scents')->insertGetId([
        'tenant_id' => $this->tenant->id, 'month' => 10, 'year' => 2026, 'title' => 'Fictional Forest',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->service = app(SubscriptionModuleService::class);
});

test('a retried scent comment keeps one private pending review and cannot rewrite it', function () {
    $payload = ['request_id' => (string) Str::uuid(), 'rating' => 4, 'body' => 'Soft woods, with a lovely warm finish.', 'source' => 'ios_app'];
    $first = $this->service->submitCandleClubScentFeedback($this->profile, $this->scent, $payload);
    $retry = $this->service->submitCandleClubScentFeedback($this->profile, $this->scent, $payload);
    $conflict = $this->service->submitCandleClubScentFeedback($this->profile, $this->scent, array_replace($payload, ['body' => 'Changed after sending.']));
    expect($first['ok'])->toBeTrue()->and($retry['id'])->toBe($first['id'])
        ->and($conflict['status'])->toBe('request_conflict')
        ->and(DB::table('subscription_candle_club_scent_feedback')->count())->toBe(1);
    $saved = DB::table('subscription_candle_club_scent_feedback')->first();
    expect($saved->body)->toBe($payload['body'])->and($saved->status)->toBe('pending')->and($saved->visibility)->toBe('candle_club')
        ->and(DB::table('marketing_review_histories')->count())->toBe(0);
    Http::assertNothingSent();
});

test('scent comments require current membership and a scent in the same tenant', function () {
    $foreign = Tenant::create(['name' => 'Another merchant', 'slug' => 'foreign-scents']);
    $scent = DB::table('subscription_candle_club_monthly_scents')->insertGetId([
        'tenant_id' => $foreign->id, 'month' => 10, 'year' => 2026, 'title' => 'Private foreign scent',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $data = ['rating' => 5, 'body' => 'My thoughts'];
    expect($this->service->submitCandleClubScentFeedback($this->profile, $scent, $data)['status'])->toBe('scent_not_found');
    DB::table('subscription_contracts')->where('tenant_id', $this->tenant->id)->update(['status' => 'cancelled']);
    expect($this->service->submitCandleClubScentFeedback($this->profile, $this->scent, $data)['status'])->toBe('not_eligible')
        ->and(DB::table('subscription_candle_club_scent_feedback')->count())->toBe(0);
});

test('a blank scent note cannot become a successful review', function () {
    expect($this->service->submitCandleClubScentFeedback($this->profile, $this->scent, ['rating' => 5, 'body' => " \n "])['status'])
        ->toBe('missing_review_body')->and(DB::table('subscription_candle_club_scent_feedback')->count())->toBe(0);
});

test('the mobile comment endpoint uses the authenticated profile and ignores supplied ownership', function () {
    $session = new \App\Services\Mobile\ModernForestryMobileCustomerSession($this->profile, 'fictional', ['source_type' => 'shopify_customer', 'shopify_customer_id' => '456']);
    $sessions = Mockery::mock(\App\Services\Mobile\ModernForestryMobileCustomerSessionService::class);
    $sessions->shouldReceive('resolveFromRequest')->andReturn($session);
    $this->app->instance(\App\Services\Mobile\ModernForestryMobileCustomerSessionService::class, $sessions);
    $this->postJson('/api/mobile/v1/modern-forestry/account/candle-club/monthly-scents/'.$this->scent.'/feedback', [
        'request_id' => (string) Str::uuid(), 'rating' => 5, 'body' => 'A soft, cozy scent.',
        'marketing_profile_id' => 999, 'tenant_id' => 999,
    ])->assertOk()->assertJsonPath('data.ok', true);
    $saved = DB::table('subscription_candle_club_scent_feedback')->first();
    expect((int) $saved->marketing_profile_id)->toBe($this->profile->id)->and((int) $saved->tenant_id)->toBe($this->tenant->id);
});
