<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReferral;
use App\Models\CandleCashReward;
use App\Models\CandleCashTask;
use App\Models\CandleCashTaskCompletion;
use App\Models\CandleCashTaskEvent;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Tenant;
use App\Support\Marketing\CandleCashMeasurement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('preview reports deterministic eligible mapping without mutating rows', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    [$oldProfile, $targetProfile] = seedLegacyTargetPair($tenant->id, 'retail:100001', 'preview');

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $oldProfile->id,
        'type' => 'earn',
        'candle_cash_delta' => 30,
        'legacy_points_origin' => true,
        'legacy_points_value' => 100,
        'source' => 'growave_activity',
        'source_id' => 'legacy:preview:1',
        'description' => 'Legacy import',
    ]);
    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $targetProfile->id,
        'type' => 'earn',
        'candle_cash_delta' => 5,
        'source' => 'candle_cash_task',
        'source_id' => 'target:preview:1',
        'description' => 'Program earn',
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $oldProfile->id,
        'balance' => 30,
    ]);
    CandleCashBalance::query()->create([
        'marketing_profile_id' => $targetProfile->id,
        'balance' => 5,
    ]);

    $this->artisan('marketing:rehome-legacy-growave-candle-cash', [
        '--tenant-id' => $tenant->id,
        '--store' => 'retail',
        '--sample' => 1,
    ])
        ->expectsOutput('mode=preview')
        ->expectsOutput('eligible_pairs=1')
        ->expectsOutput('rows_moved_transactions=0')
        ->assertExitCode(0);

    expect(CandleCashTransaction::query()->where('marketing_profile_id', $oldProfile->id)->count())->toBe(1)
        ->and(CandleCashTransaction::query()->where('marketing_profile_id', $targetProfile->id)->count())->toBe(1)
        ->and(CandleCashBalance::query()->where('marketing_profile_id', $oldProfile->id)->exists())->toBeTrue()
        ->and(CandleCashBalance::query()->where('marketing_profile_id', $targetProfile->id)->exists())->toBeTrue();
});

test('apply moves candle cash rows onto canonical tenant profile and recomputes balances', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    [$oldProfile, $targetProfile] = seedLegacyTargetPair($tenant->id, 'retail:100002', 'apply');

    $legacyTx = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $oldProfile->id,
        'type' => 'earn',
        'candle_cash_delta' => 30,
        'legacy_points_origin' => true,
        'legacy_points_value' => 100,
        'source' => 'growave_activity',
        'source_id' => 'legacy:apply:1',
        'description' => 'Legacy import',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $targetProfile->id,
        'type' => 'earn',
        'candle_cash_delta' => 2,
        'source' => 'candle_cash_task',
        'source_id' => 'target:apply:existing',
        'description' => 'Program earn',
    ]);

    $reward = CandleCashReward::query()->create([
        'name' => 'Test Reward',
        'candle_cash_cost' => 10,
        'reward_type' => 'coupon',
        'reward_value' => '10USD',
        'is_active' => true,
    ]);

    CandleCashRedemption::query()->create([
        'marketing_profile_id' => $oldProfile->id,
        'reward_id' => $reward->id,
        'candle_cash_spent' => 10,
        'platform' => 'shopify',
        'status' => 'issued',
        'redemption_code' => 'CC-TEST-APPLY-001',
        'issued_at' => now(),
    ]);

    $task = CandleCashTask::query()->create([
        'handle' => 'legacy-rehome-apply-task',
        'title' => 'Legacy rehome task',
        'reward_amount' => 1,
        'enabled' => true,
        'display_order' => 1,
        'task_type' => 'manual_submission',
    ]);

    $completion = CandleCashTaskCompletion::query()->create([
        'candle_cash_task_id' => $task->id,
        'marketing_profile_id' => $oldProfile->id,
        'status' => 'approved',
        'reward_amount' => 1,
        'reward_candle_cash' => 1,
        'candle_cash_transaction_id' => $legacyTx->id,
    ]);

    CandleCashTaskEvent::query()->create([
        'candle_cash_task_id' => $task->id,
        'marketing_profile_id' => $oldProfile->id,
        'candle_cash_task_completion_id' => $completion->id,
        'verification_mode' => 'manual_review_fallback',
        'source_event_key' => 'legacy-rehome-apply-task-event-1',
        'status' => 'awarded',
        'reward_awarded' => true,
    ]);

    CandleCashReferral::query()->create([
        'referrer_marketing_profile_id' => $oldProfile->id,
        'referral_code' => 'REF-APPLY-001',
        'status' => 'captured',
        'referrer_reward_status' => 'pending',
        'referred_reward_status' => 'pending',
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $oldProfile->id,
        'balance' => 30,
    ]);
    CandleCashBalance::query()->create([
        'marketing_profile_id' => $targetProfile->id,
        'balance' => 2,
    ]);

    $this->artisan('marketing:rehome-legacy-growave-candle-cash', [
        '--tenant-id' => $tenant->id,
        '--store' => 'retail',
        '--apply' => true,
    ])
        ->expectsOutput('mode=apply')
        ->expectsOutput('eligible_pairs=1')
        ->expectsOutput('rows_moved_transactions=1')
        ->expectsOutput('rows_moved_redemptions=1')
        ->expectsOutput('rows_moved_task_completions=1')
        ->expectsOutput('rows_moved_task_events=1')
        ->expectsOutput('rows_moved_referrals_referrer=1')
        ->expectsOutput('reconciled=yes')
        ->assertExitCode(0);

    expect(CandleCashTransaction::query()->where('marketing_profile_id', $oldProfile->id)->count())->toBe(0)
        ->and(CandleCashTransaction::query()->where('marketing_profile_id', $targetProfile->id)->count())->toBe(2)
        ->and(CandleCashRedemption::query()->where('marketing_profile_id', $oldProfile->id)->count())->toBe(0)
        ->and(CandleCashRedemption::query()->where('marketing_profile_id', $targetProfile->id)->count())->toBe(1)
        ->and(CandleCashTaskCompletion::query()->where('marketing_profile_id', $oldProfile->id)->count())->toBe(0)
        ->and(CandleCashTaskCompletion::query()->where('marketing_profile_id', $targetProfile->id)->count())->toBe(1)
        ->and(CandleCashTaskEvent::query()->where('marketing_profile_id', $oldProfile->id)->count())->toBe(0)
        ->and(CandleCashTaskEvent::query()->where('marketing_profile_id', $targetProfile->id)->count())->toBe(1)
        ->and(CandleCashReferral::query()->where('referrer_marketing_profile_id', $oldProfile->id)->count())->toBe(0)
        ->and(CandleCashReferral::query()->where('referrer_marketing_profile_id', $targetProfile->id)->count())->toBe(1)
        ->and(CandleCashBalance::query()->where('marketing_profile_id', $oldProfile->id)->exists())->toBeFalse();

    $targetLedger = CandleCashMeasurement::normalizeStoredAmount(
        CandleCashTransaction::query()->where('marketing_profile_id', $targetProfile->id)->sum('candle_cash_delta')
    );
    $targetBalance = CandleCashBalance::query()->where('marketing_profile_id', $targetProfile->id)->value('balance');

    expect(CandleCashMeasurement::normalizeStoredAmount($targetBalance))->toBe($targetLedger)
        ->and(CandleCashTransaction::query()
            ->where('marketing_profile_id', $targetProfile->id)
            ->where('source_id', 'target:apply:existing')
            ->exists())->toBeTrue();
});

test('default behavior excludes wholesale-touched legacy profiles', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    [$oldProfile, $targetProfile] = seedLegacyTargetPair($tenant->id, 'retail:100003', 'wholesale');

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $oldProfile->id,
        'tenant_id' => null,
        'source_type' => 'shopify_customer',
        'source_id' => 'wholesale:900003',
        'match_method' => 'test',
        'confidence' => 1.00,
    ]);

    CustomerExternalProfile::query()->create([
        'tenant_id' => null,
        'marketing_profile_id' => $oldProfile->id,
        'provider' => 'shopify',
        'integration' => 'shopify_customer',
        'store_key' => 'wholesale',
        'external_customer_id' => '900003',
        'email' => 'wholesale.signal@example.com',
        'normalized_email' => 'wholesale.signal@example.com',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $oldProfile->id,
        'type' => 'earn',
        'candle_cash_delta' => 9,
        'legacy_points_origin' => true,
        'legacy_points_value' => 30,
        'source' => 'growave_activity',
        'source_id' => 'legacy:wholesale:1',
        'description' => 'Legacy import',
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $oldProfile->id,
        'balance' => 9,
    ]);
    CandleCashBalance::query()->create([
        'marketing_profile_id' => $targetProfile->id,
        'balance' => 0,
    ]);

    $this->artisan('marketing:rehome-legacy-growave-candle-cash', [
        '--tenant-id' => $tenant->id,
        '--store' => 'retail',
    ])
        ->expectsOutput('excluded_wholesale_profiles=1')
        ->expectsOutput('eligible_pairs=0')
        ->assertExitCode(0);

    $this->artisan('marketing:rehome-legacy-growave-candle-cash', [
        '--tenant-id' => $tenant->id,
        '--store' => 'retail',
        '--include-wholesale' => true,
    ])
        ->expectsOutput('excluded_wholesale_profiles=0')
        ->expectsOutput('eligible_pairs=1')
        ->assertExitCode(0);
});

test('ambiguous old-to-target mapping fails closed in preview', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $oldProfile = MarketingProfile::query()->create([
        'tenant_id' => null,
        'email' => 'ambiguous.old@example.com',
        'normalized_email' => 'ambiguous.old@example.com',
    ]);

    $targetA = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'ambiguous.target.a@example.com',
        'normalized_email' => 'ambiguous.target.a@example.com',
    ]);
    $targetB = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'ambiguous.target.b@example.com',
        'normalized_email' => 'ambiguous.target.b@example.com',
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $oldProfile->id,
        'tenant_id' => null,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:200001',
        'match_method' => 'test',
        'confidence' => 1.00,
    ]);
    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $oldProfile->id,
        'tenant_id' => null,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:200002',
        'match_method' => 'test',
        'confidence' => 1.00,
    ]);
    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $targetA->id,
        'tenant_id' => $tenant->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:200001',
        'match_method' => 'test',
        'confidence' => 1.00,
    ]);
    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $targetB->id,
        'tenant_id' => $tenant->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:200002',
        'match_method' => 'test',
        'confidence' => 1.00,
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $oldProfile->id,
        'type' => 'earn',
        'candle_cash_delta' => 12,
        'legacy_points_origin' => true,
        'legacy_points_value' => 40,
        'source' => 'growave_activity',
        'source_id' => 'legacy:ambiguous:1',
        'description' => 'Legacy import',
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $oldProfile->id,
        'balance' => 12,
    ]);

    $this->artisan('marketing:rehome-legacy-growave-candle-cash', [
        '--tenant-id' => $tenant->id,
        '--store' => 'retail',
    ])
        ->expectsOutput('ambiguous_old_profiles=1')
        ->expectsOutput('eligible_pairs=0')
        ->assertExitCode(1);

    expect(CandleCashTransaction::query()->where('marketing_profile_id', $oldProfile->id)->count())->toBe(1);
});

test('apply preserves existing target transactions while adding moved legacy ledger', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    [$oldProfile, $targetProfile] = seedLegacyTargetPair($tenant->id, 'retail:100004', 'preserve');

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $oldProfile->id,
        'type' => 'earn',
        'candle_cash_delta' => 18,
        'legacy_points_origin' => true,
        'legacy_points_value' => 60,
        'source' => 'growave_activity',
        'source_id' => 'legacy:preserve:1',
        'description' => 'Legacy import',
    ]);
    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $targetProfile->id,
        'type' => 'earn',
        'candle_cash_delta' => 7,
        'source' => 'shopify_embedded_admin',
        'source_id' => 'target:preserve:1',
        'description' => 'Existing target adjustment',
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $oldProfile->id,
        'balance' => 18,
    ]);
    CandleCashBalance::query()->create([
        'marketing_profile_id' => $targetProfile->id,
        'balance' => 7,
    ]);

    $this->artisan('marketing:rehome-legacy-growave-candle-cash', [
        '--tenant-id' => $tenant->id,
        '--store' => 'retail',
        '--apply' => true,
    ])->assertExitCode(0);

    expect(CandleCashTransaction::query()->where('marketing_profile_id', $targetProfile->id)->count())->toBe(2)
        ->and(CandleCashTransaction::query()
            ->where('marketing_profile_id', $targetProfile->id)
            ->where('source_id', 'target:preserve:1')
            ->exists())->toBeTrue()
        ->and(CandleCashTransaction::query()
            ->where('marketing_profile_id', $targetProfile->id)
            ->where('source_id', 'legacy:preserve:1')
            ->exists())->toBeTrue();

    $targetLedger = CandleCashMeasurement::normalizeStoredAmount(
        CandleCashTransaction::query()->where('marketing_profile_id', $targetProfile->id)->sum('candle_cash_delta')
    );
    $targetBalance = CandleCashMeasurement::normalizeStoredAmount(
        CandleCashBalance::query()->where('marketing_profile_id', $targetProfile->id)->value('balance')
    );

    expect($targetBalance)->toBe($targetLedger)
        ->and(CandleCashBalance::query()->where('marketing_profile_id', $oldProfile->id)->exists())->toBeFalse();
});

/**
 * @return array{0:MarketingProfile,1:MarketingProfile}
 */
function seedLegacyTargetPair(int $tenantId, string $sourceId, string $tag): array
{
    $oldProfile = MarketingProfile::query()->create([
        'tenant_id' => null,
        'email' => "legacy.{$tag}@example.com",
        'normalized_email' => "legacy.{$tag}@example.com",
    ]);
    $targetProfile = MarketingProfile::query()->create([
        'tenant_id' => $tenantId,
        'email' => "target.{$tag}@example.com",
        'normalized_email' => "target.{$tag}@example.com",
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $oldProfile->id,
        'tenant_id' => null,
        'source_type' => 'shopify_customer',
        'source_id' => $sourceId,
        'match_method' => 'test',
        'confidence' => 1.00,
    ]);
    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $targetProfile->id,
        'tenant_id' => $tenantId,
        'source_type' => 'shopify_customer',
        'source_id' => $sourceId,
        'match_method' => 'test',
        'confidence' => 1.00,
    ]);

    return [$oldProfile, $targetProfile];
}
