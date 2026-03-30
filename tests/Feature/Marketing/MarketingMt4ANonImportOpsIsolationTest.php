<?php

use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingStorefrontEvent;
use App\Models\SquareOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\CandleCashService;

test('operations reconciliation is tenant-scoped and foreign issue resolution fails closed', function () {
    $tenantA = Tenant::query()->create(['name' => 'Ops Tenant A', 'slug' => 'ops-tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Ops Tenant B', 'slug' => 'ops-tenant-b']);

    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $admin->tenants()->attach($tenantA->id, ['role' => 'admin']);

    $profileA = MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Ops',
        'email' => 'ops-a@example.com',
        'normalized_email' => 'ops-a@example.com',
    ]);
    $profileB = MarketingProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'first_name' => 'Ops',
        'email' => 'ops-b@example.com',
        'normalized_email' => 'ops-b@example.com',
    ]);

    $reward = mt4aActiveReward();
    app(CandleCashService::class)->addPoints($profileA, 300, 'earn', 'admin', 'seed-a', 'seed');
    app(CandleCashService::class)->addPoints($profileB, 300, 'earn', 'admin', 'seed-b', 'seed');

    $issuedA = app(CandleCashService::class)->redeemReward($profileA, $reward, 'shopify');
    $issuedB = app(CandleCashService::class)->redeemReward($profileB, $reward, 'shopify');
    $redemptionA = CandleCashRedemption::query()->findOrFail((int) ($issuedA['redemption_id'] ?? 0));
    $redemptionB = CandleCashRedemption::query()->findOrFail((int) ($issuedB['redemption_id'] ?? 0));

    MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenantA->id,
        'event_type' => 'widget_redeem_request',
        'status' => 'error',
        'issue_type' => 'tenant_a_issue',
        'source_surface' => 'shopify_widget',
        'endpoint' => '/shopify/marketing/rewards/redeem',
        'marketing_profile_id' => $profileA->id,
        'candle_cash_redemption_id' => $redemptionA->id,
        'resolution_status' => 'open',
        'occurred_at' => now(),
    ]);
    $foreignEvent = MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenantB->id,
        'event_type' => 'widget_redeem_request',
        'status' => 'error',
        'issue_type' => 'tenant_b_issue',
        'source_surface' => 'shopify_widget',
        'endpoint' => '/shopify/marketing/rewards/redeem',
        'marketing_profile_id' => $profileB->id,
        'candle_cash_redemption_id' => $redemptionB->id,
        'resolution_status' => 'open',
        'occurred_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.operations.reconciliation', ['status' => 'open']))
        ->assertOk()
        ->assertSeeText('tenant_a_issue')
        ->assertDontSeeText('tenant_b_issue');

    $this->actingAs($admin)
        ->post(route('marketing.operations.reconciliation.issues.resolve', $foreignEvent), [
            'resolution_status' => 'resolved',
            'notes' => 'cross tenant should fail',
        ])
        ->assertNotFound();
});

test('operations mark redeemed fails closed for cross-tenant redemption route-model ids', function () {
    $tenantA = Tenant::query()->create(['name' => 'Ops Mark Tenant A', 'slug' => 'ops-mark-tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Ops Mark Tenant B', 'slug' => 'ops-mark-tenant-b']);

    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $admin->tenants()->attach($tenantA->id, ['role' => 'admin']);

    $profileB = MarketingProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'first_name' => 'Foreign Redemption',
        'email' => 'foreign-redemption@example.com',
        'normalized_email' => 'foreign-redemption@example.com',
    ]);

    $reward = mt4aActiveReward();
    app(CandleCashService::class)->addPoints($profileB, 300, 'earn', 'admin', 'seed-b', 'seed');
    $issuedB = app(CandleCashService::class)->redeemReward($profileB, $reward, 'square');
    $redemptionB = CandleCashRedemption::query()->findOrFail((int) ($issuedB['redemption_id'] ?? 0));

    $this->actingAs($admin)
        ->post(route('marketing.operations.reconciliation.redemptions.mark-redeemed', $redemptionB), [
            'platform' => 'square',
            'external_order_source' => 'square_manual',
            'external_order_id' => 'MT4A-CROSS-001',
            'notes' => 'cross tenant should fail',
        ])
        ->assertNotFound();

    expect((string) $redemptionB->fresh()->status)->toBe('issued');
});

test('operations storefront debug lookup is tenant-scoped', function () {
    $tenantA = Tenant::query()->create(['name' => 'Ops Debug Tenant A', 'slug' => 'ops-debug-tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Ops Debug Tenant B', 'slug' => 'ops-debug-tenant-b']);

    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $admin->tenants()->attach($tenantA->id, ['role' => 'admin']);

    $profileB = MarketingProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'first_name' => 'Foreign Debug',
        'email' => 'foreign-debug@example.com',
        'normalized_email' => 'foreign-debug@example.com',
    ]);

    $this->actingAs($admin)
        ->getJson(route('marketing.operations.storefront-redemption-debug', ['email' => $profileB->email]))
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'profile_not_found');
});

test('reconcile redemptions command fails closed without tenant id in mt4a', function () {
    $this->artisan('marketing:reconcile-redemptions', [
        '--source' => 'all',
        '--limit' => 10,
    ])->assertExitCode(1);
});

test('reconcile redemptions command scopes square scans to tenant owned orders', function () {
    $tenantA = Tenant::query()->create(['name' => 'Ops Reconcile Tenant A', 'slug' => 'ops-reconcile-tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Ops Reconcile Tenant B', 'slug' => 'ops-reconcile-tenant-b']);

    $profileA = MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Square A',
        'email' => 'square-a@example.com',
        'normalized_email' => 'square-a@example.com',
    ]);
    $profileB = MarketingProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'first_name' => 'Square B',
        'email' => 'square-b@example.com',
        'normalized_email' => 'square-b@example.com',
    ]);

    $reward = mt4aActiveReward();
    app(CandleCashService::class)->addPoints($profileA, 400, 'earn', 'admin', 'seed-a', 'seed');
    app(CandleCashService::class)->addPoints($profileB, 400, 'earn', 'admin', 'seed-b', 'seed');

    $issuedA = app(CandleCashService::class)->redeemReward($profileA, $reward, 'square');
    $issuedB = app(CandleCashService::class)->redeemReward($profileB, $reward, 'square');
    $redemptionA = CandleCashRedemption::query()->findOrFail((int) ($issuedA['redemption_id'] ?? 0));
    $redemptionB = CandleCashRedemption::query()->findOrFail((int) ($issuedB['redemption_id'] ?? 0));

    SquareOrder::query()->create([
        'tenant_id' => $tenantA->id,
        'square_order_id' => 'mt4a-square-a-1001',
        'source_name' => 'Order ' . (string) ($issuedA['code'] ?? ''),
        'raw_payload' => ['code' => (string) ($issuedA['code'] ?? '')],
        'raw_tax_names' => [],
        'synced_at' => now(),
    ]);
    SquareOrder::query()->create([
        'tenant_id' => $tenantB->id,
        'square_order_id' => 'mt4a-square-b-1001',
        'source_name' => 'Order ' . (string) ($issuedB['code'] ?? ''),
        'raw_payload' => ['code' => (string) ($issuedB['code'] ?? '')],
        'raw_tax_names' => [],
        'synced_at' => now(),
    ]);

    MarketingProfileLink::query()->create([
        'tenant_id' => $tenantA->id,
        'marketing_profile_id' => $profileA->id,
        'source_type' => 'square_order',
        'source_id' => 'mt4a-square-a-1001',
    ]);
    MarketingProfileLink::query()->create([
        'tenant_id' => $tenantB->id,
        'marketing_profile_id' => $profileB->id,
        'source_type' => 'square_order',
        'source_id' => 'mt4a-square-b-1001',
    ]);

    $this->artisan('marketing:reconcile-redemptions', [
        '--source' => 'square',
        '--tenant-id' => $tenantA->id,
        '--limit' => 25,
    ])->expectsOutputToContain('orders_scanned=1')
        ->assertSuccessful();

    expect((string) $redemptionA->fresh()->status)->toBe('redeemed')
        ->and((string) $redemptionB->fresh()->status)->toBe('issued');
});

function mt4aActiveReward(): CandleCashReward
{
    return CandleCashReward::query()->where('is_active', true)->orderBy('candle_cash_cost')->first()
        ?? CandleCashReward::query()->create([
            'name' => 'MT4A Reward',
            'description' => 'MT4A test reward',
            'candle_cash_cost' => 100,
            'reward_type' => 'coupon',
            'reward_value' => '5USD',
            'is_active' => true,
        ]);
}

