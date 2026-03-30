<?php

use App\Models\CandleCashTransaction;
use App\Models\MarketingAutomationEvent;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Marketing\CandleCashLifecycleService;
use Carbon\CarbonImmutable;

function makeLifecycleTenant(string $slug): Tenant
{
    return Tenant::query()->create([
        'name' => 'Tenant ' . strtoupper($slug),
        'slug' => $slug,
    ]);
}

function makeLifecycleProfile(Tenant $tenant, array $overrides = []): MarketingProfile
{
    return MarketingProfile::query()->create(array_merge([
        'tenant_id' => $tenant->id,
        'first_name' => 'Candle',
        'last_name' => 'Customer',
        'email' => strtolower($tenant->slug) . '.customer@example.com',
        'normalized_email' => strtolower($tenant->slug) . '.customer@example.com',
        'phone' => '+15555555555',
        'normalized_phone' => '+15555555555',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => true,
    ], $overrides));
}

function seedEarnedCandleCash(MarketingProfile $profile, int $points, CarbonImmutable $createdAt): void
{
    $transaction = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'points' => $points,
        'source' => 'order',
        'source_id' => 'order-' . $profile->id,
        'description' => 'Program-earned Candle Cash',
    ]);

    $transaction->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ])->saveQuietly();
}

test('customer with earned candle cash and no redemption qualifies for reminder lifecycle trigger', function (): void {
    $tenant = makeLifecycleTenant('alpha');
    $profile = makeLifecycleProfile($tenant);
    seedEarnedCandleCash($profile, 300, CarbonImmutable::now()->subDays(21));

    $preview = app(CandleCashLifecycleService::class)->preview([
        'tenant_id' => $tenant->id,
        'trigger' => CandleCashLifecycleService::TRIGGER_REMINDER,
        'channel' => 'email',
    ]);

    expect((int) data_get($preview, 'summary.qualified_count'))->toBe(1)
        ->and(collect((array) data_get($preview, 'rows'))->pluck('marketing_profile_id')->all())
        ->toContain($profile->id);
});

test('customer with no outstanding earned candle cash does not qualify', function (): void {
    $tenant = makeLifecycleTenant('beta');
    $profile = makeLifecycleProfile($tenant);
    $earnedAt = CarbonImmutable::now()->subDays(20);
    seedEarnedCandleCash($profile, 300, $earnedAt);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'redeem',
        'points' => -300,
        'source' => 'reward',
        'source_id' => 'redeem-' . $profile->id,
        'description' => 'Redeemed Candle Cash',
        'created_at' => $earnedAt->addDays(2),
        'updated_at' => $earnedAt->addDays(2),
    ]);

    $preview = app(CandleCashLifecycleService::class)->preview([
        'tenant_id' => $tenant->id,
        'trigger' => CandleCashLifecycleService::TRIGGER_REMINDER,
        'channel' => 'email',
    ]);

    expect((int) data_get($preview, 'summary.qualified_count'))->toBe(0);
});

test('cooldown suppresses repeated reminder eligibility', function (): void {
    $tenant = makeLifecycleTenant('gamma');
    $profile = makeLifecycleProfile($tenant);
    seedEarnedCandleCash($profile, 300, CarbonImmutable::now()->subDays(20));

    MarketingAutomationEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'trigger_key' => CandleCashLifecycleService::TRIGGER_REMINDER,
        'channel' => 'email',
        'status' => 'queued_intent',
        'occurred_at' => CarbonImmutable::now()->subDays(2),
        'context' => ['source' => 'test'],
    ]);

    $preview = app(CandleCashLifecycleService::class)->preview([
        'tenant_id' => $tenant->id,
        'trigger' => CandleCashLifecycleService::TRIGGER_REMINDER,
        'channel' => 'email',
    ]);

    expect((int) data_get($preview, 'summary.qualified_count'))->toBe(0)
        ->and((int) data_get($preview, 'summary.excluded_reasons.cooldown_active'))->toBeGreaterThanOrEqual(1);
});

test('tenant boundary is respected during lifecycle cohort evaluation', function (): void {
    $tenantA = makeLifecycleTenant('delta');
    $tenantB = makeLifecycleTenant('epsilon');

    $profileA = makeLifecycleProfile($tenantA, ['email' => 'delta@example.com', 'normalized_email' => 'delta@example.com']);
    $profileB = makeLifecycleProfile($tenantB, ['email' => 'epsilon@example.com', 'normalized_email' => 'epsilon@example.com']);

    seedEarnedCandleCash($profileA, 300, CarbonImmutable::now()->subDays(25));
    seedEarnedCandleCash($profileB, 300, CarbonImmutable::now()->subDays(25));

    $preview = app(CandleCashLifecycleService::class)->preview([
        'tenant_id' => $tenantA->id,
        'trigger' => CandleCashLifecycleService::TRIGGER_REMINDER,
        'channel' => 'email',
    ]);

    $profileIds = collect((array) data_get($preview, 'rows'))
        ->pluck('marketing_profile_id')
        ->map(fn ($value): int => (int) $value)
        ->all();

    expect($profileIds)->toContain($profileA->id)
        ->and($profileIds)->not->toContain($profileB->id);
});

test('non-consented customer is excluded from reminder channel', function (): void {
    $tenant = makeLifecycleTenant('zeta');
    $profile = makeLifecycleProfile($tenant, [
        'accepts_email_marketing' => false,
    ]);
    seedEarnedCandleCash($profile, 300, CarbonImmutable::now()->subDays(20));

    $preview = app(CandleCashLifecycleService::class)->preview([
        'tenant_id' => $tenant->id,
        'trigger' => CandleCashLifecycleService::TRIGGER_REMINDER,
        'channel' => 'email',
    ]);

    expect((int) data_get($preview, 'summary.qualified_count'))->toBe(0)
        ->and((int) data_get($preview, 'summary.excluded_reasons.not_contactable_for_channel'))->toBeGreaterThanOrEqual(1);
});

test('lapsed with value trigger uses store scoped order recency', function (): void {
    $tenant = makeLifecycleTenant('eta');
    $profile = makeLifecycleProfile($tenant, ['email' => 'lapsed@example.com', 'normalized_email' => 'lapsed@example.com']);
    seedEarnedCandleCash($profile, 300, CarbonImmutable::now()->subDays(35));

    MarketingProfileLink::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:9001',
        'match_method' => 'test',
        'confidence' => 1.0,
    ]);

    Order::factory()->create([
        'tenant_id' => $tenant->id,
        'shopify_store_key' => 'retail',
        'shopify_customer_id' => '9001',
        'ordered_at' => now()->subDays(80),
    ]);

    $preview = app(CandleCashLifecycleService::class)->preview([
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'trigger' => CandleCashLifecycleService::TRIGGER_LAPSED_WITH_VALUE,
        'channel' => 'email',
    ]);

    expect((int) data_get($preview, 'summary.qualified_count'))->toBe(1)
        ->and((int) data_get($preview, 'rows.0.marketing_profile_id'))->toBe($profile->id);
});

test('preview command lists eligible customers and records intents when requested', function (): void {
    $tenant = makeLifecycleTenant('theta');
    $profile = makeLifecycleProfile($tenant, ['email' => 'command@example.com', 'normalized_email' => 'command@example.com']);
    seedEarnedCandleCash($profile, 300, CarbonImmutable::now()->subDays(30));

    $this->artisan('marketing:candle-cash-lifecycle-preview', [
        '--tenant-id' => $tenant->id,
        '--trigger' => CandleCashLifecycleService::TRIGGER_REMINDER,
        '--channel' => 'email',
        '--record-intents' => true,
    ])
        ->expectsOutputToContain('qualified_count=1')
        ->expectsOutputToContain('intents_recorded=1')
        ->assertSuccessful();

    expect(MarketingAutomationEvent::query()
        ->where('tenant_id', $tenant->id)
        ->where('marketing_profile_id', $profile->id)
        ->where('trigger_key', CandleCashLifecycleService::TRIGGER_REMINDER)
        ->count())->toBe(1);
});

test('preview command fails closed when tenant context is missing', function (): void {
    $this->artisan('marketing:candle-cash-lifecycle-preview', [
        '--trigger' => CandleCashLifecycleService::TRIGGER_REMINDER,
        '--channel' => 'email',
    ])
        ->expectsOutputToContain('Missing required --tenant-id')
        ->assertExitCode(1);
});

test('record queued intents skips rows missing tenant context', function (): void {
    $rows = collect([
        [
            'marketing_profile_id' => 999,
            'tenant_id' => null,
            'trigger_key' => CandleCashLifecycleService::TRIGGER_REMINDER,
            'channel' => 'email',
            'outstanding_candle_cash' => 10,
        ],
    ]);

    $result = app(CandleCashLifecycleService::class)->recordQueuedIntents($rows);

    expect((int) ($result['recorded'] ?? 0))->toBe(0)
        ->and((int) ($result['skipped'] ?? 0))->toBe(1)
        ->and(MarketingAutomationEvent::query()->count())->toBe(0);
});
