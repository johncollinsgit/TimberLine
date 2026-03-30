<?php

use App\Models\BirthdayRewardIssuance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingStorefrontEvent;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Services\Marketing\BirthdayRewardActivationService;
use App\Services\Marketing\MarketingStorefrontEventLogger;

test('maintenance commands fail closed without tenant context in mt2c', function () {
    $this->artisan('marketing:backfill-attribution-source-meta', [
        '--chunk' => 50,
    ])->assertExitCode(1);

    $this->artisan('marketing:scan-unresolved-marketing-issues', [
        '--limit' => 10,
    ])->assertExitCode(1);
});

test('storefront link repair is tenant scoped and does not cross-link other tenants', function () {
    $tenantA = Tenant::query()->create(['name' => 'Repair Tenant A', 'slug' => 'repair-tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Repair Tenant B', 'slug' => 'repair-tenant-b']);

    $profileA = MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Repair',
        'email' => 'repair-a@example.com',
    ]);
    $profileB = MarketingProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'first_name' => 'Repair',
        'email' => 'repair-b@example.com',
    ]);

    MarketingProfileLink::query()->create([
        'tenant_id' => $tenantA->id,
        'marketing_profile_id' => $profileA->id,
        'source_type' => 'order',
        'source_id' => '1001',
        'source_meta' => ['source' => 'tenant_a'],
    ]);
    MarketingProfileLink::query()->create([
        'tenant_id' => $tenantB->id,
        'marketing_profile_id' => $profileB->id,
        'source_type' => 'order',
        'source_id' => '1001',
        'source_meta' => ['source' => 'tenant_b'],
    ]);

    $eventA = MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenantA->id,
        'event_type' => 'repair_needed',
        'status' => 'pending',
        'source_type' => 'order',
        'source_id' => '1001',
        'occurred_at' => now(),
        'resolution_status' => 'open',
    ]);
    $eventB = MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenantB->id,
        'event_type' => 'repair_needed',
        'status' => 'pending',
        'source_type' => 'order',
        'source_id' => '1001',
        'occurred_at' => now(),
        'resolution_status' => 'open',
    ]);

    $this->artisan('marketing:repair-storefront-links', [
        '--tenant-id' => $tenantA->id,
        '--limit' => 100,
    ])->assertExitCode(0);

    expect((int) ($eventA->fresh()->marketing_profile_id ?? 0))->toBe($profileA->id)
        ->and($eventB->fresh()->marketing_profile_id)->toBeNull();
});

test('unresolved issue scan stays tenant scoped', function () {
    $tenantA = Tenant::query()->create(['name' => 'Scan Tenant A', 'slug' => 'scan-tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Scan Tenant B', 'slug' => 'scan-tenant-b']);

    $profileA = MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Scan',
        'email' => 'scan-a@example.com',
    ]);
    $profileB = MarketingProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'first_name' => 'Scan',
        'email' => 'scan-b@example.com',
    ]);

    $reward = CandleCashReward::query()->create([
        'name' => 'MT2C Test Reward',
        'description' => 'Tenant scoped test reward',
        'candle_cash_cost' => 50,
        'reward_type' => 'coupon',
        'reward_value' => '5USD',
        'is_active' => true,
    ]);

    CandleCashRedemption::query()->create([
        'marketing_profile_id' => $profileA->id,
        'reward_id' => $reward->id,
        'candle_cash_spent' => 50,
        'platform' => 'shopify',
        'redemption_code' => 'SCAN-A-001',
        'status' => 'issued',
        'issued_at' => now()->subHour(),
    ]);
    CandleCashRedemption::query()->create([
        'marketing_profile_id' => $profileB->id,
        'reward_id' => $reward->id,
        'candle_cash_spent' => 50,
        'platform' => 'shopify',
        'redemption_code' => 'SCAN-B-001',
        'status' => 'issued',
        'issued_at' => now()->subHour(),
    ]);

    $this->artisan('marketing:scan-unresolved-marketing-issues', [
        '--tenant-id' => $tenantA->id,
        '--limit' => 100,
    ])
        ->expectsOutputToContain('issued_scanned=1')
        ->assertExitCode(0);

    expect(MarketingStorefrontEvent::query()
        ->where('event_type', 'redemption_reconciliation_pending')
        ->where('tenant_id', $tenantA->id)
        ->count())->toBe(1)
        ->and(MarketingStorefrontEvent::query()
            ->where('event_type', 'redemption_reconciliation_pending')
            ->where('tenant_id', $tenantB->id)
            ->count())->toBe(0);
});

test('birthday activation fails closed when resolved store is not tenant-owned', function () {
    $tenantA = Tenant::query()->create(['name' => 'Birthday Tenant A', 'slug' => 'birthday-tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Birthday Tenant B', 'slug' => 'birthday-tenant-b']);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Birthday',
        'email' => 'birthday-tenant-a@example.com',
    ]);
    $birthdayProfile = CustomerBirthdayProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'birth_month' => (int) now()->month,
        'birth_day' => (int) now()->day,
        'source' => 'test',
        'source_captured_at' => now(),
    ]);
    $issuance = BirthdayRewardIssuance::query()->create([
        'customer_birthday_profile_id' => $birthdayProfile->id,
        'marketing_profile_id' => $profile->id,
        'cycle_year' => (int) now()->year,
        'reward_type' => 'discount_code',
        'reward_name' => 'Birthday Reward',
        'status' => 'issued',
        'reward_value' => 10,
        'reward_code' => 'MT2C-BDAY-001',
        'claim_window_starts_at' => now()->subDay(),
        'claim_window_ends_at' => now()->addDays(7),
        'issued_at' => now()->subHour(),
    ]);

    MarketingProfileLink::query()->create([
        'tenant_id' => $tenantA->id,
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:12345',
        'source_meta' => ['store' => 'retail'],
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => $tenantB->id,
        'store_key' => 'retail',
        'shop_domain' => 'retail.example.myshopify.com',
        'access_token' => 'token-other-tenant',
        'installed_at' => now(),
    ]);

    $result = app(BirthdayRewardActivationService::class)->activate($issuance);

    expect((bool) ($result['ok'] ?? true))->toBeFalse()
        ->and((string) ($result['error'] ?? ''))->toBe('missing_shopify_store')
        ->and((string) ($issuance->fresh()->discount_sync_status ?? ''))->toBe('failed');
});

test('storefront event dedupe keys are isolated by tenant when context is present', function () {
    $tenantA = Tenant::query()->create(['name' => 'Logger Tenant A', 'slug' => 'logger-tenant-a']);
    $tenantB = Tenant::query()->create(['name' => 'Logger Tenant B', 'slug' => 'logger-tenant-b']);
    $logger = app(MarketingStorefrontEventLogger::class);

    $logger->log('reward_event', [
        'tenant_id' => $tenantA->id,
        'status' => 'ok',
        'dedupe_key' => 'same-request-key',
        'source_surface' => 'test',
    ]);
    $logger->log('reward_event', [
        'tenant_id' => $tenantB->id,
        'status' => 'ok',
        'dedupe_key' => 'same-request-key',
        'source_surface' => 'test',
    ]);

    expect(MarketingStorefrontEvent::query()
        ->where('event_type', 'reward_event')
        ->where('request_key', 'same-request-key')
        ->count())->toBe(2);
});
