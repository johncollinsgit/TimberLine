<?php

use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Marketing\CandleCashService;

test('backfill redemption finalizations command repairs sync-failed cancellations by tenant and remains idempotent', function () {
    $tenantA = Tenant::query()->create([
        'name' => 'Backfill Tenant A',
        'slug' => 'backfill-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Backfill Tenant B',
        'slug' => 'backfill-tenant-b',
    ]);

    $profileA = MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'BackfillA',
        'email' => 'backfill-a@example.com',
        'normalized_email' => 'backfill-a@example.com',
    ]);
    $profileB = MarketingProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'first_name' => 'BackfillB',
        'email' => 'backfill-b@example.com',
        'normalized_email' => 'backfill-b@example.com',
    ]);

    $service = app(CandleCashService::class);
    $service->addPoints($profileA, 500, 'earn', 'admin', 'seed-a', 'seed');
    $service->addPoints($profileB, 500, 'earn', 'admin', 'seed-b', 'seed');

    $reward = CandleCashReward::query()->where('is_active', true)->orderBy('candle_cash_cost')->firstOrFail();

    $issuedA = $service->redeemReward($profileA, $reward, 'shopify');
    $issuedB = $service->redeemReward($profileB, $reward, 'shopify');

    $redemptionA = CandleCashRedemption::query()->findOrFail((int) ($issuedA['redemption_id'] ?? 0));
    $redemptionB = CandleCashRedemption::query()->findOrFail((int) ($issuedB['redemption_id'] ?? 0));

    $service->cancelIssuedRedemptionAndRestoreBalance($redemptionA);
    $service->cancelIssuedRedemptionAndRestoreBalance($redemptionB);

    $codeA = (string) ($issuedA['code'] ?? '');
    $codeB = (string) ($issuedB['code'] ?? '');

    $orderA = Order::query()->create([
        'tenant_id' => $tenantA->id,
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 920001,
        'order_number' => '#920001',
        'status' => 'complete',
        'discount_total' => $service->amountFromPoints((int) $redemptionA->candle_cash_spent),
        'internal_notes' => $codeA,
        'ordered_at' => now()->subHour(),
    ]);
    $orderB = Order::query()->create([
        'tenant_id' => $tenantB->id,
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 920002,
        'order_number' => '#920002',
        'status' => 'complete',
        'discount_total' => $service->amountFromPoints((int) $redemptionB->candle_cash_spent),
        'internal_notes' => $codeB,
        'ordered_at' => now()->subHour(),
    ]);

    MarketingProfileLink::query()->create([
        'tenant_id' => $tenantA->id,
        'marketing_profile_id' => $profileA->id,
        'source_type' => 'order',
        'source_id' => (string) $orderA->id,
    ]);
    MarketingProfileLink::query()->create([
        'tenant_id' => $tenantB->id,
        'marketing_profile_id' => $profileB->id,
        'source_type' => 'order',
        'source_id' => (string) $orderB->id,
    ]);

    $this->artisan('marketing:backfill-redemption-finalizations', [
        '--tenant-id' => $tenantA->id,
        '--chunk' => 100,
    ])->assertExitCode(0);

    expect((string) $redemptionA->fresh()->status)->toBe('redeemed')
        ->and((string) $redemptionA->fresh()->external_order_id)->toBe((string) $orderA->id)
        ->and((string) $redemptionB->fresh()->status)->toBe('canceled');

    $this->artisan('marketing:backfill-redemption-finalizations', [
        '--chunk' => 100,
    ])->assertExitCode(0);

    expect((string) $redemptionB->fresh()->status)->toBe('redeemed')
        ->and((string) $redemptionB->fresh()->external_order_id)->toBe((string) $orderB->id);

    $this->artisan('marketing:backfill-redemption-finalizations', [
        '--chunk' => 100,
    ])->assertExitCode(0);

    $reversalCountA = CandleCashTransaction::query()
        ->where('marketing_profile_id', $profileA->id)
        ->where('source', 'reward_reconciliation')
        ->where('source_id', (string) $redemptionA->id)
        ->where('type', 'adjustment')
        ->where('candle_cash_delta', -(int) $redemptionA->candle_cash_spent)
        ->count();

    $reversalCountB = CandleCashTransaction::query()
        ->where('marketing_profile_id', $profileB->id)
        ->where('source', 'reward_reconciliation')
        ->where('source_id', (string) $redemptionB->id)
        ->where('type', 'adjustment')
        ->where('candle_cash_delta', -(int) $redemptionB->candle_cash_spent)
        ->count();

    expect($reversalCountA)->toBe(1)
        ->and($reversalCountB)->toBe(1);
});
