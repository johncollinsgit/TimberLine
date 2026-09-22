<?php

use App\Http\Middleware\VerifyMarketingStorefrontRequest;
use App\Mail\TenantRewardsWishlistWeeklySummaryMail;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileWishlistItem;
use App\Models\MarketingStorefrontEvent;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Services\Marketing\TenantRewardsWishlistWeeklySummaryService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;

test('weekly rewards and wishlist summary is tenant scoped and reports usage and failures', function (): void {
    $this->travelTo(now()->parse('2026-09-22 12:00:00'));

    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $otherTenant = Tenant::query()->create(['name' => 'Other Shop', 'slug' => 'other-shop']);
    $profile = MarketingProfile::factory()->create(['tenant_id' => $tenant->id]);
    $otherProfile = MarketingProfile::factory()->create(['tenant_id' => $otherTenant->id]);
    $reward = CandleCashReward::query()->firstOrFail();

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'candle_cash_delta' => 25,
        'source' => 'order',
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);
    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $otherProfile->id,
        'type' => 'earn',
        'candle_cash_delta' => 99,
        'source' => 'order',
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    CandleCashRedemption::query()->create([
        'marketing_profile_id' => $profile->id,
        'reward_id' => $reward->id,
        'candle_cash_spent' => 10,
        'platform' => 'shopify',
        'redemption_code' => 'WEEKLY-ACTIVE',
        'status' => 'issued',
        'issued_at' => now()->subDays(2),
        'expires_at' => now()->addDays(10),
    ]);
    CandleCashRedemption::query()->create([
        'marketing_profile_id' => $profile->id,
        'reward_id' => $reward->id,
        'candle_cash_spent' => 10,
        'platform' => 'shopify',
        'redemption_code' => 'WEEKLY-USED',
        'status' => 'redeemed',
        'issued_at' => now()->subDays(3),
        'redeemed_at' => now()->subDay(),
    ]);
    CandleCashRedemption::query()->create([
        'marketing_profile_id' => $otherProfile->id,
        'reward_id' => $reward->id,
        'candle_cash_spent' => 50,
        'platform' => 'shopify',
        'redemption_code' => 'OTHER-TENANT',
        'status' => 'redeemed',
        'issued_at' => now()->subDay(),
        'redeemed_at' => now()->subDay(),
    ]);

    foreach ([
        ['widget_candle_cash_status_lookup', 'ok', null, null],
        ['reward_view', 'ok', null, null],
        ['reward_apply_click', 'ok', 'birthday', null],
        ['reward_apply_success', 'ok', 'birthday', null],
        ['reward_apply_click', 'ok', 'candle_cash', null],
        ['reward_apply_failure', 'error', 'candle_cash', 'discount_not_applied'],
        ['reward_status_fallback_rendered', 'error', 'surface', 'timeout'],
        ['widget_wishlist_add', 'ok', null, null],
        ['widget_wishlist_remove', 'ok', null, null],
        ['widget_wishlist_add', 'error', null, 'identity_missing'],
    ] as $index => [$eventType, $status, $rewardKind, $issueType]) {
        MarketingStorefrontEvent::query()->create([
            'tenant_id' => $tenant->id,
            'event_type' => $eventType,
            'status' => $status,
            'issue_type' => $issueType,
            'marketing_profile_id' => $profile->id,
            'request_key' => 'weekly-'.$index,
            'meta' => $rewardKind ? ['reward_kind' => $rewardKind] : null,
            'occurred_at' => now()->subHours($index + 1),
        ]);
    }

    MarketingStorefrontEvent::query()->create([
        'tenant_id' => $otherTenant->id,
        'event_type' => 'reward_apply_success',
        'status' => 'ok',
        'marketing_profile_id' => $otherProfile->id,
        'meta' => ['reward_kind' => 'candle_cash'],
        'occurred_at' => now()->subHour(),
    ]);

    MarketingProfileWishlistItem::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'provider' => 'backstage',
        'integration' => 'native',
        'store_key' => 'retail',
        'product_id' => 'product-1',
        'product_title' => 'Forest Candle',
        'status' => 'active',
        'added_at' => now()->subDay(),
        'last_added_at' => now()->subDay(),
    ]);
    MarketingProfileWishlistItem::query()->create([
        'tenant_id' => $otherTenant->id,
        'marketing_profile_id' => $otherProfile->id,
        'provider' => 'backstage',
        'integration' => 'native',
        'store_key' => 'retail',
        'product_id' => 'other-product',
        'product_title' => 'Other Product',
        'status' => 'active',
        'added_at' => now()->subDay(),
        'last_added_at' => now()->subDay(),
    ]);

    $report = app(TenantRewardsWishlistWeeklySummaryService::class)
        ->reportSnapshot($tenant, now(), 7);

    expect(data_get($report, 'health.status'))->toBe('needs_attention')
        ->and(data_get($report, 'health.issue_count'))->toBe(3)
        ->and(data_get($report, 'health.issues.0.count'))->toBe(1)
        ->and(data_get($report, 'candle_cash.apply_attempts'))->toBe(2)
        ->and(data_get($report, 'candle_cash.apply_successes'))->toBe(1)
        ->and(data_get($report, 'candle_cash.apply_success_rate'))->toBe(50.0)
        ->and(data_get($report, 'candle_cash.birthday_apply_successes'))->toBe(1)
        ->and(data_get($report, 'candle_cash.candle_cash_apply_successes'))->toBe(0)
        ->and(data_get($report, 'candle_cash.fallback_renders'))->toBe(1)
        ->and(data_get($report, 'candle_cash.earned.amount'))->toBe(25.0)
        ->and(data_get($report, 'candle_cash.issued.count'))->toBe(2)
        ->and(data_get($report, 'candle_cash.redeemed.count'))->toBe(1)
        ->and(data_get($report, 'candle_cash.currently_active_codes.count'))->toBe(1)
        ->and(data_get($report, 'wishlist.adds'))->toBe(1)
        ->and(data_get($report, 'wishlist.removals'))->toBe(1)
        ->and(data_get($report, 'wishlist.errors'))->toBe(1)
        ->and(data_get($report, 'wishlist.current_active_items'))->toBe(1)
        ->and(data_get($report, 'wishlist.top_saved_products.0.title'))->toBe('Forest Candle');
});

test('weekly summary command sends the tenant report to the requested inbox', function (): void {
    Mail::fake();
    Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);

    $this->artisan('marketing:send-weekly-rewards-wishlist-summary', [
        '--tenant' => 'modern-forestry',
        '--email' => 'info@theforestrystudio.com',
        '--days' => 7,
    ])->assertSuccessful();

    Mail::assertSent(TenantRewardsWishlistWeeklySummaryMail::class, function (TenantRewardsWishlistWeeklySummaryMail $mail): bool {
        $html = $mail->render();

        return $mail->hasTo('info@theforestrystudio.com')
            && data_get($mail->report, 'tenant.slug') === 'modern-forestry'
            && data_get($mail->report, 'health.status') === 'no_activity'
            && str_contains($html, 'No activity observed')
            && str_contains($html, 'No customer names');
    });
});

test('weekly summary is scheduled for modern forestry on Monday morning', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($scheduled): bool => str_contains(
            (string) $scheduled->command,
            'marketing:send-weekly-rewards-wishlist-summary'
        ));

    expect($event)->not->toBeNull()
        ->and((string) $event->command)->toContain("--tenant='modern-forestry'")
        ->and((string) $event->command)->toContain("--email='info@theforestrystudio.com'")
        ->and($event->expression)->toBe('30 8 * * 1')
        ->and($event->timezone)->toBe('America/New_York')
        ->and($event->runInBackground)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});

test('reward fallback telemetry is accepted and stored as an error', function (): void {
    $this->withoutMiddleware(VerifyMarketingStorefrontRequest::class);

    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $profile = MarketingProfile::factory()->create(['tenant_id' => $tenant->id]);
    ShopifyStore::query()->create([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'modernforestry.example.myshopify.com',
        'access_token' => 'test-token',
        'installed_at' => now(),
    ]);

    $this->postJson(route('marketing.shopify.v1.rewards.event'), [
        'store_key' => 'retail',
        'marketing_profile_id' => $profile->id,
        'event_type' => 'reward_status_fallback_rendered',
        'request_key' => 'fallback-cart-timeout',
        'reward_kind' => 'surface',
        'surface' => 'cart',
        'state' => 'unknown_customer',
        'status_error_code' => 'timeout',
        'fallback_access_mode' => 'pending_status',
    ])->assertOk()
        ->assertJsonPath('data.event_type', 'reward_status_fallback_rendered');

    $event = MarketingStorefrontEvent::query()
        ->where('event_type', 'reward_status_fallback_rendered')
        ->firstOrFail();

    expect($event->status)->toBe('error')
        ->and($event->issue_type)->toBe('timeout')
        ->and(data_get($event->meta, 'fallback_access_mode'))->toBe('pending_status');
});
