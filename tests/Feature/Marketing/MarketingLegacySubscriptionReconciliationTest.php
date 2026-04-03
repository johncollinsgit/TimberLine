<?php

use App\Models\CandleCashTask;
use App\Models\CandleCashTaskCompletion;
use App\Models\CandleCashTaskEvent;
use App\Models\CandleCashTransaction;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Marketing\LegacyImportSubscriptionReconciliationService;
use App\Services\Marketing\MarketingConsentIncentiveService;
use App\Services\Marketing\MarketingConsentService;

test('yotpo imported subscribed customer is reconciled to canonical email and sms without issuing rewards', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Legacy Reconcile Tenant Yotpo',
        'slug' => 'legacy-reconcile-tenant-yotpo',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'legacy.yotpo@example.com',
        'normalized_email' => 'legacy.yotpo@example.com',
        'phone' => '+15551005468',
        'normalized_phone' => '5551005468',
        'accepts_email_marketing' => false,
        'accepts_sms_marketing' => false,
        'email_opted_out_at' => now()->subMonths(2),
        'sms_opted_out_at' => now()->subMonths(2),
    ]);

    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'channel' => 'email',
        'event_type' => 'imported',
        'source_type' => 'yotpo_contacts_import',
        'source_id' => 'yotpo-email:legacy.yotpo@example.com',
        'occurred_at' => now()->subMonths(5),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'channel' => 'sms',
        'event_type' => 'imported',
        'source_type' => 'yotpo_contacts_import',
        'source_id' => 'yotpo-phone:5551005468',
        'occurred_at' => now()->subMonths(5),
    ]);

    $beforeTransactions = CandleCashTransaction::query()->count();
    $beforeTaskEvents = CandleCashTaskEvent::query()->count();
    $beforeCompletions = CandleCashTaskCompletion::query()->count();

    $summary = app(LegacyImportSubscriptionReconciliationService::class)->reconcile([
        'tenant_id' => $tenant->id,
    ]);

    $profile->refresh();

    expect($profile->accepts_email_marketing)->toBeTrue()
        ->and($profile->accepts_sms_marketing)->toBeTrue()
        ->and($profile->email_opted_out_at)->toBeNull()
        ->and($profile->sms_opted_out_at)->toBeNull()
        ->and((int) ($summary['reconciled_profiles'] ?? 0))->toBe(1)
        ->and((int) ($summary['reconciled_email'] ?? 0))->toBe(1)
        ->and((int) ($summary['reconciled_sms'] ?? 0))->toBe(1)
        ->and((int) ($summary['reward_paths_suppressed'] ?? 0))->toBe(1)
        ->and(CandleCashTransaction::query()->count())->toBe($beforeTransactions)
        ->and(CandleCashTaskEvent::query()->count())->toBe($beforeTaskEvents)
        ->and(CandleCashTaskCompletion::query()->count())->toBe($beforeCompletions)
        ->and(MarketingConsentEvent::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'legacy_import_reconciliation')
            ->where('event_type', 'imported')
            ->whereIn('channel', ['email', 'sms'])
            ->count())->toBe(2);
});

test('square imported subscribed customer is reconciled and reruns are idempotent', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Legacy Reconcile Tenant Square',
        'slug' => 'legacy-reconcile-tenant-square',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'legacy.square@example.com',
        'normalized_email' => 'legacy.square@example.com',
        'phone' => '+15551112222',
        'normalized_phone' => '5551112222',
        'accepts_email_marketing' => false,
        'accepts_sms_marketing' => false,
    ]);

    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'channel' => 'email',
        'event_type' => 'imported',
        'source_type' => 'square_marketing_import',
        'source_id' => 'sq-marketing-email-1',
        'occurred_at' => now()->subMonths(4),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'channel' => 'sms',
        'event_type' => 'imported',
        'source_type' => 'square_marketing_import',
        'source_id' => 'sq-marketing-sms-1',
        'occurred_at' => now()->subMonths(4),
    ]);

    $service = app(LegacyImportSubscriptionReconciliationService::class);

    $first = $service->reconcile([
        'tenant_id' => $tenant->id,
    ]);

    $profile->refresh();
    $eventCountAfterFirst = MarketingConsentEvent::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('source_type', 'legacy_import_reconciliation')
        ->count();

    $second = $service->reconcile([
        'tenant_id' => $tenant->id,
    ]);

    $eventCountAfterSecond = MarketingConsentEvent::query()
        ->where('marketing_profile_id', $profile->id)
        ->where('source_type', 'legacy_import_reconciliation')
        ->count();

    expect($profile->accepts_email_marketing)->toBeTrue()
        ->and($profile->accepts_sms_marketing)->toBeTrue()
        ->and((int) ($first['reconciled_profiles'] ?? 0))->toBe(1)
        ->and((int) ($second['reconciled_profiles'] ?? 0))->toBe(0)
        ->and($eventCountAfterFirst)->toBe(2)
        ->and($eventCountAfterSecond)->toBe(2);
});

test('legacy reconciliation does not overwrite channels with a more recent opt out', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Legacy Reconcile Tenant Opt Out',
        'slug' => 'legacy-reconcile-tenant-opt-out',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'legacy.optout@example.com',
        'normalized_email' => 'legacy.optout@example.com',
        'phone' => '+15553334444',
        'normalized_phone' => '5553334444',
        'accepts_email_marketing' => false,
        'accepts_sms_marketing' => false,
    ]);

    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'channel' => 'email',
        'event_type' => 'imported',
        'source_type' => 'square_customer_sync',
        'source_id' => 'sq-customer-legacy-email',
        'occurred_at' => now()->subMonths(3),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'channel' => 'sms',
        'event_type' => 'imported',
        'source_type' => 'square_customer_sync',
        'source_id' => 'sq-customer-legacy-sms',
        'occurred_at' => now()->subMonths(3),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'channel' => 'sms',
        'event_type' => 'opted_out',
        'source_type' => 'shopify_widget_optin',
        'source_id' => 'recent-optout',
        'occurred_at' => now()->subWeek(),
    ]);

    $summary = app(LegacyImportSubscriptionReconciliationService::class)->reconcile([
        'tenant_id' => $tenant->id,
    ]);

    $profile->refresh();

    expect($profile->accepts_email_marketing)->toBeTrue()
        ->and($profile->accepts_sms_marketing)->toBeFalse()
        ->and((int) ($summary['reconciled_profiles'] ?? 0))->toBe(1)
        ->and((int) ($summary['reconciled_email'] ?? 0))->toBe(1)
        ->and((int) ($summary['reconciled_sms'] ?? 0))->toBe(0)
        ->and((int) ($summary['skipped_recent_opt_out'] ?? 0))->toBe(1);
});

test('new opt-ins still award sms consent rewards through normal path', function (): void {
    config()->set('marketing.candle_cash_consent_bonus.sms', 9);
    CandleCashTask::query()->where('handle', 'sms-signup')->update(['enabled' => false]);

    $tenant = Tenant::query()->create([
        'name' => 'Fresh Opt In Tenant',
        'slug' => 'fresh-opt-in-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'phone' => '+15554445555',
        'normalized_phone' => '5554445555',
        'accepts_sms_marketing' => false,
    ]);

    app(MarketingConsentService::class)->setSmsConsent($profile, true, [
        'source_type' => 'shopify_widget_optin',
        'source_id' => 'fresh-optin:1',
        'tenant_id' => $tenant->id,
    ]);

    $result = app(MarketingConsentIncentiveService::class)->awardSmsConsentBonusOnce(
        profile: $profile->fresh(),
        sourceId: 'fresh-optin:1',
        description: 'Fresh opt-in reward check'
    );

    expect((bool) ($result['awarded'] ?? false))->toBeTrue()
        ->and((int) ($result['candle_cash'] ?? 0))->toBe(9)
        ->and(CandleCashTransaction::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('source', 'consent')
            ->where('source_id', 'fresh-optin:1')
            ->where('candle_cash_delta', '>', 0)
            ->exists())->toBeTrue();
});

test('legacy subscription reconciliation command is tenant-scoped and fails closed without tenant id', function (): void {
    $this->artisan('marketing:reconcile-legacy-subscriptions')
        ->expectsOutputToContain('Missing required --tenant-id')
        ->assertExitCode(1);
});
