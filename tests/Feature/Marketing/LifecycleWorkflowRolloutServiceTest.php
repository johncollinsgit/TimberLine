<?php

use App\Models\MarketingAutomationEvent;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Tenant;
use App\Services\Marketing\LifecycleWorkflowRolloutService;
use Carbon\CarbonImmutable;

it('audits lifecycle workflow readiness with launchable and blocked phases', function (): void {
    $now = CarbonImmutable::parse('2026-04-20 12:00:00');
    Carbon\Carbon::setTestNow($now);

    $tenant = Tenant::query()->create([
        'name' => 'Workflow Audit Tenant',
        'slug' => 'workflow-audit-tenant',
    ]);

    $welcomeProfile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Welcome',
        'last_name' => 'Prospect',
        'email' => 'welcome@example.com',
        'normalized_email' => 'welcome@example.com',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => false,
    ]);

    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $welcomeProfile->id,
        'channel' => 'email',
        'event_type' => 'opted_in',
        'source_type' => 'test',
        'source_id' => 'welcome-optin',
        'occurred_at' => $now->subDays(2),
    ]);

    $repeatProfile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Repeat',
        'last_name' => 'Buyer',
        'email' => 'repeat@example.com',
        'normalized_email' => 'repeat@example.com',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => true,
    ]);

    $recentFirstOrderProfile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'First',
        'last_name' => 'Buyer',
        'email' => 'first@example.com',
        'normalized_email' => 'first@example.com',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => true,
    ]);

    $oldOrderA = Order::query()->create([
        'tenant_id' => $tenant->id,
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => '1001',
        'order_number' => '#1001',
        'ordered_at' => $now->subDays(120),
        'total_price' => 88.50,
    ]);
    $oldOrderB = Order::query()->create([
        'tenant_id' => $tenant->id,
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => '1002',
        'order_number' => '#1002',
        'ordered_at' => $now->subDays(92),
        'total_price' => 122.10,
    ]);
    $recentOrder = Order::query()->create([
        'tenant_id' => $tenant->id,
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => '1003',
        'order_number' => '#1003',
        'ordered_at' => $now->subDays(5),
        'total_price' => 64.00,
    ]);

    foreach ([
        [$repeatProfile, $oldOrderA],
        [$repeatProfile, $oldOrderB],
        [$recentFirstOrderProfile, $recentOrder],
    ] as [$profile, $order]) {
        MarketingProfileLink::query()->create([
            'tenant_id' => $tenant->id,
            'marketing_profile_id' => $profile->id,
            'source_type' => 'order',
            'source_id' => (string) $order->id,
            'match_method' => 'test_seed',
            'confidence' => 1.0,
        ]);
    }

    OrderLine::query()->create([
        'order_id' => $recentOrder->id,
        'raw_title' => 'Spring Candle Bundle',
        'sku' => 'BUNDLE-01',
        'quantity' => 1,
    ]);

    $audit = app(LifecycleWorkflowRolloutService::class)->audit($tenant->id, 'retail');
    $workflows = collect((array) ($audit['workflows'] ?? []));

    expect((string) data_get($workflows, 'welcome.status'))->toBe('can_ship_now')
        ->and((int) data_get($workflows, 'welcome.eligible_now'))->toBeGreaterThan(0)
        ->and((string) data_get($workflows, 'winback.status'))->toBe('can_ship_now')
        ->and((int) data_get($workflows, 'winback.eligible_now'))->toBeGreaterThan(0)
        ->and((string) data_get($workflows, 'post_purchase_cross_sell.status'))->toBe('can_ship_now')
        ->and((int) data_get($workflows, 'post_purchase_cross_sell.eligible_now'))->toBeGreaterThan(0)
        ->and((string) data_get($workflows, 'wishlist_triggered_offer.status'))->toBe('can_ship_now')
        ->and((string) data_get($workflows, 'cart_abandonment.status'))->toBe('needs_small_build')
        ->and((string) data_get($workflows, 'checkout_abandonment.status'))->toBe('needs_small_build');
});

it('stages welcome workflow with suppression and approval queue output', function (): void {
    $now = CarbonImmutable::parse('2026-04-20 13:00:00');
    Carbon\Carbon::setTestNow($now);

    $tenant = Tenant::query()->create([
        'name' => 'Workflow Stage Tenant',
        'slug' => 'workflow-stage-tenant',
    ]);

    $eligible = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Eligible',
        'last_name' => 'Welcome',
        'email' => 'eligible@example.com',
        'normalized_email' => 'eligible@example.com',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => false,
    ]);

    $suppressed = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Suppressed',
        'last_name' => 'Buyer',
        'email' => 'suppressed@example.com',
        'normalized_email' => 'suppressed@example.com',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => false,
    ]);

    foreach ([$eligible, $suppressed] as $profile) {
        MarketingConsentEvent::query()->create([
            'tenant_id' => $tenant->id,
            'marketing_profile_id' => $profile->id,
            'channel' => 'email',
            'event_type' => 'opted_in',
            'source_type' => 'test',
            'source_id' => 'seed-' . $profile->id,
            'occurred_at' => $now->subDays(1),
        ]);
    }

    $suppressedOrder = Order::query()->create([
        'tenant_id' => $tenant->id,
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => '2301',
        'order_number' => '#2301',
        'ordered_at' => $now->subDays(8),
        'total_price' => 70.00,
    ]);

    MarketingProfileLink::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $suppressed->id,
        'source_type' => 'order',
        'source_id' => (string) $suppressedOrder->id,
        'match_method' => 'test_seed',
        'confidence' => 1.0,
    ]);

    $result = app(LifecycleWorkflowRolloutService::class)->stageWorkflow(
        workflowKey: LifecycleWorkflowRolloutService::WORKFLOW_WELCOME,
        tenantId: $tenant->id,
        storeKey: 'retail',
        options: ['limit' => 100]
    );

    expect((string) ($result['status'] ?? ''))->toBe('ok')
        ->and((int) ($result['queued_for_approval'] ?? 0))->toBe(1)
        ->and((int) ($result['suppressed'] ?? 0))->toBeGreaterThanOrEqual(1);

    $queuedRecipient = MarketingCampaignRecipient::query()
        ->where('campaign_id', (int) $result['campaign_id'])
        ->where('marketing_profile_id', $eligible->id)
        ->first();

    expect($queuedRecipient)->not->toBeNull()
        ->and((string) $queuedRecipient->status)->toBe('queued_for_approval');

    $suppressedEvent = MarketingAutomationEvent::query()
        ->forTenantId($tenant->id)
        ->where('marketing_profile_id', $suppressed->id)
        ->where('trigger_key', LifecycleWorkflowRolloutService::WORKFLOW_WELCOME)
        ->where('status', 'suppressed')
        ->first();

    expect($suppressedEvent)->not->toBeNull()
        ->and((string) $suppressedEvent->reason)->toContain('recent_purchase');
});
