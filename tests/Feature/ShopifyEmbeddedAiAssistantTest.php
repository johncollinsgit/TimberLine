<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignVariant;
use App\Models\MarketingMessageJob;
use App\Models\MarketingProfile;
use App\Models\MarketingRecommendation;
use App\Models\MarketingSendApproval;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use App\Services\Tenancy\TenantModuleAccessResolver;

beforeEach(function (): void {
    $this->withoutVite();
    app()->forgetInstance(TenantModuleAccessResolver::class);
});

test('assistant start page renders welcome, status strip, actions, and help content when ai access is enabled', function () {
    $tenant = Tenant::query()->create([
        'name' => 'AI Enabled Tenant',
        'slug' => 'ai-enabled-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $response = $this->get(route('shopify.app.assistant.start', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('AI Assistant')
        ->assertSeeText('Start Here')
        ->assertSeeText('Top Opportunities')
        ->assertSeeText('Draft Campaigns')
        ->assertSeeText('Setup')
        ->assertSeeText('Activity')
        ->assertSeeText('Welcome to AI Assistant')
        ->assertSeeText('Current Status')
        ->assertSeeText('Ready')
        ->assertSeeText('Needs Setup')
        ->assertSeeText('Locked')
        ->assertSeeText('Coming Soon')
        ->assertSeeText('Next Best Click')
        ->assertSeeText('What This Helps With')
        ->assertViewHas('pageSubnav', function (array $subnav): bool {
            $keys = collect($subnav)->pluck('key')->values()->all();

            return $keys === ['start', 'opportunities', 'setup'];
        })
        ->assertViewHas('startHere', function (array $payload): bool {
            $statusStrip = array_values((array) ($payload['status_strip'] ?? []));
            $actions = array_values((array) ($payload['actions'] ?? []));

            return count($statusStrip) === 4
                && count($actions) <= 3
                && collect($statusStrip)->pluck('label')->values()->all() === ['Ready', 'Needs Setup', 'Locked', 'Coming Soon'];
        });
});

test('assistant pages are tenant and tier aware for locked tenants', function () {
    $tenant = Tenant::query()->create([
        'name' => 'AI Locked Tenant',
        'slug' => 'ai-locked-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.assistant.start', retailEmbeddedSignedQuery()))
        ->assertStatus(403)
        ->assertSeeText('Welcome to AI Assistant')
        ->assertSeeText('Start Here is available with upgrade for this tenant right now.')
        ->assertSeeText('Review plans and module access')
        ->assertSeeText('Current Status')
        ->assertViewHas('startHere', function (array $payload): bool {
            $statusStrip = collect(array_values((array) ($payload['status_strip'] ?? [])));
            $locked = (int) ($statusStrip->firstWhere('label', 'Locked')['count'] ?? 0);

            return $locked >= 1;
        });

    $this->get(route('shopify.app.assistant.opportunities', retailEmbeddedSignedQuery()))
        ->assertStatus(403)
        ->assertSeeText('Best Opportunities Right Now')
        ->assertViewHas('topOpportunities', function (array $payload): bool {
            $lockedCta = (array) ($payload['locked_cta'] ?? []);

            return filled($lockedCta['label'] ?? null)
                && filled($lockedCta['href'] ?? null);
        });

    $this->get(route('shopify.app.assistant.drafts', retailEmbeddedSignedQuery()))
        ->assertStatus(403)
        ->assertSeeText('Draft Campaigns')
        ->assertViewHas('draftCampaigns', function (array $payload): bool {
            $lockedCta = (array) ($payload['locked_cta'] ?? []);

            return filled($lockedCta['label'] ?? null)
                && filled($lockedCta['href'] ?? null);
        });

    $this->get(route('shopify.app.assistant.setup', retailEmbeddedSignedQuery()))
        ->assertStatus(403)
        ->assertSeeText('Setup')
        ->assertSeeText('Review plans and module access')
        ->assertDontSeeText('Setup Checklist');
});

test('assistant tier access matrix gates surfaces by plan for non-alpha tenants', function () {
    $starter = Tenant::query()->create([
        'name' => 'Starter Tier Tenant',
        'slug' => 'starter-tier-tenant',
    ]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $starter->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    $growth = Tenant::query()->create([
        'name' => 'Growth Tier Tenant',
        'slug' => 'growth-tier-tenant',
    ]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $growth->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    $pro = Tenant::query()->create([
        'name' => 'Pro Tier Tenant',
        'slug' => 'pro-tier-tenant',
    ]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $pro->id,
        'plan_key' => 'pro',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    $assertMatrix = function (Tenant $tenant, array $expected): void {
        configureEmbeddedRetailStore($tenant->id);

        foreach ($expected as $routeName => $expectedStatus) {
            $this->get(route($routeName, retailEmbeddedSignedQuery()))
                ->assertStatus($expectedStatus);
        }
    };

    $assertMatrix($starter, [
        'shopify.app.assistant.start' => 403,
        'shopify.app.assistant.opportunities' => 403,
        'shopify.app.assistant.setup' => 403,
        'shopify.app.assistant.drafts' => 403,
        'shopify.app.assistant.activity' => 403,
    ]);
    $assertMatrix($growth, [
        'shopify.app.assistant.start' => 200,
        'shopify.app.assistant.opportunities' => 200,
        'shopify.app.assistant.setup' => 200,
        'shopify.app.assistant.drafts' => 403,
        'shopify.app.assistant.activity' => 403,
    ]);
    $assertMatrix($pro, [
        'shopify.app.assistant.start' => 200,
        'shopify.app.assistant.opportunities' => 200,
        'shopify.app.assistant.setup' => 200,
        'shopify.app.assistant.drafts' => 200,
        'shopify.app.assistant.activity' => 200,
    ]);
});

test('modern forestry alpha override matrix unlocks all ai surfaces regardless of plan', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    foreach ([
        'shopify.app.assistant.start',
        'shopify.app.assistant.opportunities',
        'shopify.app.assistant.setup',
        'shopify.app.assistant.drafts',
        'shopify.app.assistant.activity',
    ] as $routeName) {
        $this->get(route($routeName, retailEmbeddedSignedQuery()))
            ->assertOk();
    }
});

test('assistant draft mutation routes fail closed when draft surface is locked by tier', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Growth Draft Lock Tenant',
        'slug' => 'growth-draft-lock-tenant',
    ]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Casey',
        'last_name' => 'Customer',
        'email' => 'casey@example.com',
        'normalized_email' => 'casey@example.com',
        'accepts_email_marketing' => true,
    ]);
    $recommendation = MarketingRecommendation::query()->create([
        'type' => 'send_suggestion',
        'campaign_id' => null,
        'marketing_profile_id' => $profile->id,
        'title' => 'Follow up with recent buyers',
        'summary' => 'Growth tier should not create drafts from this route.',
        'status' => 'pending',
        'confidence' => 0.77,
        'created_by_system' => true,
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->post(route('shopify.app.assistant.drafts.create') . '?' . http_build_query(retailEmbeddedSignedQuery()), [
        'context_token' => retailEmbeddedContextToken(),
        'recommendation_id' => $recommendation->id,
    ])->assertRedirect()->assertSessionHas('status_error');

    expect(MarketingCampaign::query()
        ->where('tenant_id', $tenant->id)
        ->where('source_label', 'ai_assistant_draft')
        ->count())->toBe(0);
});

test('landlord entitlement override effects apply to ai draft access for starter tenants', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Starter Override Tenant',
        'slug' => 'starter-override-tenant',
    ]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    TenantModuleEntitlement::query()->updateOrCreate(
        [
            'tenant_id' => $tenant->id,
            'module_key' => 'ai',
        ],
        [
            'availability_status' => 'available',
            'enabled_status' => 'enabled',
            'billing_status' => 'included_in_plan',
            'entitlement_source' => 'override',
            'price_source' => 'override',
        ]
    );
    app()->forgetInstance(TenantModuleAccessResolver::class);

    $this->get(route('shopify.app.assistant.drafts', retailEmbeddedSignedQuery()))
        ->assertOk();

    TenantModuleEntitlement::query()->updateOrCreate(
        [
            'tenant_id' => $tenant->id,
            'module_key' => 'ai',
        ],
        [
            'availability_status' => 'available',
            'enabled_status' => 'disabled',
            'billing_status' => 'included_in_plan',
            'entitlement_source' => 'override',
            'price_source' => 'override',
        ]
    );
    app()->forgetInstance(TenantModuleAccessResolver::class);

    $this->get(route('shopify.app.assistant.drafts', retailEmbeddedSignedQuery()))
        ->assertStatus(403);
});

test('assistant navigation hides locked and coming-soon child pages from growth tenants', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Growth Navigation Tenant',
        'slug' => 'growth-navigation-tenant',
    ]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.assistant.start', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertViewHas('pageSubnav', function (array $subnav): bool {
            return collect($subnav)->pluck('key')->values()->all() === ['start', 'opportunities', 'setup'];
        })
        ->assertViewHas('appNavigation', function (array $nav): bool {
            $documents = collect((array) ($nav['commandSearchDocuments'] ?? []))
                ->pluck('id')
                ->values()
                ->all();

            return in_array('page:assistant.start', $documents, true)
                && in_array('page:assistant.opportunities', $documents, true)
                && in_array('page:assistant.setup', $documents, true)
                && ! in_array('page:assistant.drafts', $documents, true)
                && ! in_array('page:assistant.activity', $documents, true);
        });
});

test('assistant activity page renders recent tenant-scoped plain-english history', function () {
    $tenant = Tenant::query()->create([
        'name' => 'AI Activity Tenant',
        'slug' => 'ai-activity-tenant',
    ]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'pro',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Riley',
        'last_name' => 'Buyer',
        'email' => 'riley@example.com',
        'normalized_email' => 'riley@example.com',
        'accepts_email_marketing' => true,
    ]);

    $campaign = MarketingCampaign::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Spring Follow-up Draft',
        'status' => 'ready_for_review',
        'channel' => 'sms',
        'source_label' => 'ai_assistant_draft',
        'message_body' => 'Thanks for your recent order. We saved a weekend offer for you.',
    ]);

    $recommendation = MarketingRecommendation::query()->create([
        'type' => 'segment_opportunity',
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'title' => 'Bring back past customers',
        'summary' => 'Recent customers are a good fit for a return offer.',
        'status' => 'pending',
        'confidence' => 0.91,
        'created_by_system' => true,
    ]);

    MarketingSendApproval::query()->create([
        'recommendation_id' => $recommendation->id,
        'approval_type' => 'manual_review',
        'status' => 'approved',
        'approved_at' => now(),
        'notes' => 'Approved by operator.',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.assistant.activity', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Activity')
        ->assertSeeText('Recent Activity')
        ->assertSeeText('Opportunity surfaced')
        ->assertSeeText('Draft created')
        ->assertSeeText('Approved by your team')
        ->assertSeeText('Draft status changed')
        ->assertViewHas('activityFeed', function (array $payload): bool {
            $items = array_values((array) ($payload['items'] ?? []));
            $labels = collect($items)->pluck('event_label')->filter()->values()->all();

            return count($items) >= 4
                && in_array('Opportunity surfaced', $labels, true)
                && in_array('Draft created', $labels, true)
                && in_array('Approved by your team', $labels, true)
                && in_array('Draft status changed', $labels, true);
        });
});

test('assistant activity page stays tenant-isolated for activity details', function () {
    $tenantA = Tenant::query()->create([
        'name' => 'Activity Tenant A',
        'slug' => 'activity-tenant-a',
    ]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'plan_key' => 'pro',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Activity Tenant B',
        'slug' => 'activity-tenant-b',
    ]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'plan_key' => 'pro',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    $profileA = MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Alex',
        'last_name' => 'TenantA',
        'email' => 'alex-a@example.com',
        'normalized_email' => 'alex-a@example.com',
    ]);
    $profileB = MarketingProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'first_name' => 'Blair',
        'last_name' => 'TenantB',
        'email' => 'blair-b@example.com',
        'normalized_email' => 'blair-b@example.com',
    ]);

    MarketingRecommendation::query()->create([
        'type' => 'segment_opportunity',
        'marketing_profile_id' => $profileA->id,
        'title' => 'Tenant A opportunity',
        'summary' => 'Visible only for tenant A.',
        'status' => 'pending',
        'created_by_system' => true,
    ]);
    MarketingRecommendation::query()->create([
        'type' => 'segment_opportunity',
        'marketing_profile_id' => $profileB->id,
        'title' => 'Tenant B private opportunity',
        'summary' => 'Must not be visible for tenant A.',
        'status' => 'pending',
        'created_by_system' => true,
    ]);

    configureEmbeddedRetailStore($tenantA->id);

    $this->get(route('shopify.app.assistant.activity', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Tenant A opportunity')
        ->assertDontSeeText('Tenant B private opportunity')
        ->assertViewHas('activityFeed', function (array $payload): bool {
            $titles = collect((array) ($payload['items'] ?? []))
                ->pluck('title')
                ->filter()
                ->values()
                ->all();

            return in_array('Tenant A opportunity', $titles, true)
                && ! in_array('Tenant B private opportunity', $titles, true);
        });
});

test('assistant activity page keeps locked tenants on a clean fail-closed state', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Activity Locked Tenant',
        'slug' => 'activity-locked-tenant',
    ]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.assistant.activity', retailEmbeddedSignedQuery()))
        ->assertStatus(403)
        ->assertSeeText('Activity is available with upgrade for this tenant right now.')
        ->assertSeeText('Review plans and module access')
        ->assertDontSeeText('Recent Activity');
});

test('assistant activity page paginates older history and defaults to recent items', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Activity Pagination Tenant',
        'slug' => 'activity-pagination-tenant',
    ]);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'pro',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Morgan',
        'last_name' => 'History',
        'email' => 'morgan-history@example.com',
        'normalized_email' => 'morgan-history@example.com',
    ]);

    for ($index = 1; $index <= 14; $index++) {
        $recommendation = MarketingRecommendation::query()->create([
            'type' => 'segment_opportunity',
            'marketing_profile_id' => $profile->id,
            'title' => 'Activity opportunity '.$index,
            'summary' => 'Recent item '.$index,
            'status' => 'pending',
            'created_by_system' => true,
        ]);

        $recommendation->forceFill([
            'created_at' => now()->subMinutes($index),
            'updated_at' => now()->subMinutes($index),
        ])->saveQuietly();
    }

    configureEmbeddedRetailStore($tenant->id);

    $firstPage = $this->get(route('shopify.app.assistant.activity', retailEmbeddedSignedQuery()));
    $firstPage->assertOk()
        ->assertViewHas('activityFeed', function (array $payload): bool {
            $items = array_values((array) ($payload['items'] ?? []));
            $pagination = (array) ($payload['pagination'] ?? []);

            return count($items) === 10
                && (int) ($pagination['per_page'] ?? 0) === 10
                && (int) ($pagination['total'] ?? 0) >= 14
                && (bool) ($pagination['has_pages'] ?? false) === true
                && filled($pagination['next_url'] ?? null);
        });

    $this->get(route('shopify.app.assistant.activity', array_merge(retailEmbeddedSignedQuery(), ['activity_page' => 2])))
        ->assertOk()
        ->assertViewHas('activityFeed', function (array $payload): bool {
            $items = array_values((array) ($payload['items'] ?? []));
            $pagination = (array) ($payload['pagination'] ?? []);

            return (int) ($pagination['current_page'] ?? 0) === 2
                && count($items) >= 1
                && count($items) <= 10;
        });
});

test('assistant opportunities page renders explainable recommendation cards with top-five pagination', function () {
    $tenant = Tenant::query()->create([
        'name' => 'AI Opportunities Tenant',
        'slug' => 'ai-opportunities-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    $campaign = MarketingCampaign::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Spring Follow-up Campaign',
        'status' => 'draft',
        'channel' => 'sms',
        'objective' => 'event_followup',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Taylor',
        'last_name' => 'Buyer',
        'email' => 'taylor@example.com',
        'normalized_email' => 'taylor@example.com',
        'accepts_email_marketing' => true,
    ]);

    $recommendations = [
        ['type' => 'segment_opportunity', 'title' => 'Bring back past customers', 'summary' => 'Recent buyer activity supports a targeted winback list.', 'confidence' => 0.92, 'details_json' => ['estimated_profiles' => 24]],
        ['type' => 'send_suggestion', 'title' => 'Follow up with recent buyers', 'summary' => 'Recent buyers have not seen a follow-up touch yet.', 'confidence' => 0.83, 'details_json' => ['event_context' => 'Flowertown']],
        ['type' => 'copy_improvement', 'title' => 'Review a draft campaign', 'summary' => 'Current copy can be tightened for better conversion.', 'confidence' => 0.76, 'details_json' => ['suggestion' => 'Test a shorter draft message with stronger offer framing.']],
        ['type' => 'timing_suggestion', 'title' => 'Promote a seasonal scent', 'summary' => 'Timing signals suggest better response in the afternoon.', 'confidence' => 0.70, 'details_json' => ['recommended_daypart' => 'afternoon', 'recommended_hour' => 14]],
        ['type' => 'channel_suggestion', 'title' => 'Clean up missing setup before sending', 'summary' => 'Channel readiness is incomplete for a full rollout.', 'confidence' => 0.66, 'details_json' => ['segment_name' => 'Email Consented / No SMS Consent']],
        ['type' => 'channel_suggestion', 'title' => 'Lower priority cleanup', 'summary' => 'Lower-confidence setup cleanup recommendation.', 'confidence' => 0.41, 'details_json' => ['estimated_profiles' => 6]],
    ];

    foreach ($recommendations as $row) {
        MarketingRecommendation::query()->create([
            'type' => $row['type'],
            'campaign_id' => $campaign->id,
            'marketing_profile_id' => $profile->id,
            'title' => $row['title'],
            'summary' => $row['summary'],
            'details_json' => $row['details_json'],
            'status' => 'pending',
            'confidence' => $row['confidence'],
            'created_by_system' => true,
        ]);
    }

    MarketingRecommendation::query()->create([
        'type' => 'segment_opportunity',
        'title' => 'Foreign tenant recommendation',
        'summary' => 'Should not leak into this tenant.',
        'status' => 'pending',
        'confidence' => 0.99,
        'created_by_system' => true,
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.assistant.opportunities', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Best Opportunities Right Now')
        ->assertSeeText('Top Opportunities')
        ->assertSeeText('Bring back past customers')
        ->assertSeeText('Follow up with recent buyers')
        ->assertSeeText('Priority: High priority')
        ->assertSeeText('Priority: Medium priority')
        ->assertSeeText('Based on roughly 24 matching customer records.')
        ->assertDontSeeText('Lower priority cleanup')
        ->assertDontSeeText('Foreign tenant recommendation')
        ->assertViewHas('topOpportunities', function (array $payload): bool {
            $rows = array_values((array) ($payload['opportunities'] ?? []));
            $pagination = (array) ($payload['pagination'] ?? []);
            $first = is_array($rows[0] ?? null) ? (array) $rows[0] : [];

            return count($rows) === 5
                && ($first['title'] ?? null) === 'Bring back past customers'
                && ($first['priority'] ?? null) === 'High priority'
                && filled($first['action_label'] ?? null)
                && filled($first['action_href'] ?? null)
                && (int) ($pagination['total'] ?? 0) === 6
                && (bool) ($pagination['has_pages'] ?? false) === true
                && filled($pagination['next_url'] ?? null);
        });
});

test('assistant opportunities page shows a clean empty state when no recommendations exist', function () {
    $tenant = Tenant::query()->create([
        'name' => 'AI Empty Opportunities Tenant',
        'slug' => 'ai-empty-opportunities-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.assistant.opportunities', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('No top opportunities yet')
        ->assertSeeText('Open Setup')
        ->assertViewHas('topOpportunities', function (array $payload): bool {
            $rows = array_values((array) ($payload['opportunities'] ?? []));
            $empty = (array) ($payload['empty_state'] ?? []);

            return $rows === []
                && ($empty['label'] ?? null) === 'Open Setup'
                && filled($empty['href'] ?? null);
        });
});

test('assistant draft campaigns page renders focused draft review and recommendation actions', function () {
    $tenant = Tenant::query()->create([
        'name' => 'AI Draft Surface Tenant',
        'slug' => 'ai-draft-surface-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'pro',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    $campaign = MarketingCampaign::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Past Customers Spring Follow-up',
        'status' => 'draft',
        'channel' => 'sms',
        'source_label' => 'ai_assistant_draft',
        'message_body' => 'We miss you at the studio. Come back this weekend for a seasonal reward.',
        'target_snapshot' => [
            'audience_label' => 'Past customers from spring events',
            'why_this_was_suggested' => 'Recent spring buyers have not received a follow-up.',
        ],
    ]);

    MarketingCampaignVariant::query()->create([
        'campaign_id' => $campaign->id,
        'name' => 'Primary Draft',
        'message_text' => 'We miss you at the studio. Come back this weekend for a seasonal reward.',
        'status' => 'draft',
        'is_control' => true,
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Dana',
        'last_name' => 'Shopper',
        'email' => 'dana@example.com',
        'normalized_email' => 'dana@example.com',
        'accepts_email_marketing' => true,
    ]);

    MarketingRecommendation::query()->create([
        'type' => 'segment_opportunity',
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'title' => 'Bring back past customers',
        'summary' => 'Bring back spring event shoppers who have gone quiet.',
        'details_json' => [
            'candidate_segment' => 'Past customers from spring events',
            'suggested_message' => 'We saved your favorites. Stop by this week for an early reward.',
        ],
        'status' => 'pending',
        'confidence' => 0.89,
        'created_by_system' => true,
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.assistant.drafts', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Draft Campaigns')
        ->assertSeeText('Review Draft')
        ->assertSeeText('Why this was suggested')
        ->assertSeeText('Audience')
        ->assertSeeText('Message')
        ->assertSeeText('Next Step')
        ->assertSeeText('Create Draft')
        ->assertViewHas('draftCampaigns', function (array $payload): bool {
            $drafts = array_values((array) ($payload['drafts'] ?? []));
            $selectedDraft = is_array($payload['selected_draft'] ?? null) ? (array) $payload['selected_draft'] : [];
            $recommendations = array_values((array) ($payload['recommendations'] ?? []));

            return count($drafts) >= 1
                && ($drafts[0]['title'] ?? null) === 'Past Customers Spring Follow-up'
                && ($selectedDraft['status_label'] ?? null) === 'Draft Ready'
                && count($recommendations) >= 1;
        });
});

test('assistant draft creation and editing stay approval-safe with no autonomous send path', function () {
    $tenant = Tenant::query()->create([
        'name' => 'AI Draft Create Tenant',
        'slug' => 'ai-draft-create-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'pro',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Blake',
        'last_name' => 'Customer',
        'email' => 'blake@example.com',
        'normalized_email' => 'blake@example.com',
        'accepts_email_marketing' => true,
    ]);

    $recommendation = MarketingRecommendation::query()->create([
        'type' => 'send_suggestion',
        'campaign_id' => null,
        'marketing_profile_id' => $profile->id,
        'title' => 'Follow up with recent buyers',
        'summary' => 'Recent buyers are ready for a short re-engagement message.',
        'details_json' => [
            'objective' => 'event_followup',
            'candidate_segment' => 'Recent spring buyers',
            'suggested_message' => 'Thanks for your last order. Want first look access to this week’s seasonal scent?',
            'suggested_channel' => 'sms',
        ],
        'status' => 'pending',
        'confidence' => 0.82,
        'created_by_system' => true,
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->post(route('shopify.app.assistant.drafts.create') . '?' . http_build_query(retailEmbeddedSignedQuery()), [
        'context_token' => retailEmbeddedContextToken(),
        'recommendation_id' => $recommendation->id,
    ])->assertRedirect();

    $draftCampaign = MarketingCampaign::query()
        ->where('tenant_id', $tenant->id)
        ->where('source_label', 'ai_assistant_draft')
        ->latest('id')
        ->first();

    expect($draftCampaign)->not->toBeNull()
        ->and((string) $draftCampaign->status)->toBe('draft')
        ->and((string) data_get($draftCampaign->target_snapshot, 'recommendation_type'))->toBe('send_suggestion')
        ->and((int) data_get($draftCampaign->target_snapshot, 'recommendation_id'))->toBe((int) $recommendation->id);

    $this->post(route('shopify.app.assistant.drafts.update', ['campaign' => $draftCampaign->id]) . '?' . http_build_query(retailEmbeddedSignedQuery()), [
        'context_token' => retailEmbeddedContextToken(),
        'name' => 'Recent Buyers Follow-up Draft',
        'audience' => 'Recent spring buyers',
        'message' => 'Manual review update: send this only after checking setup and timing.',
    ])->assertRedirect();

    $draftCampaign->refresh();
    $variant = MarketingCampaignVariant::query()->where('campaign_id', $draftCampaign->id)->latest('id')->first();

    expect((string) $draftCampaign->status)->toBe('draft')
        ->and((string) $draftCampaign->name)->toBe('Recent Buyers Follow-up Draft')
        ->and((string) $draftCampaign->message_body)->toContain('Manual review update')
        ->and((string) data_get($draftCampaign->target_snapshot, 'audience_label'))->toBe('Recent spring buyers')
        ->and($variant)->not->toBeNull()
        ->and((string) $variant->status)->toBe('draft')
        ->and((string) $variant->message_text)->toContain('Manual review update')
        ->and((string) $recommendation->fresh()->status)->toBe('pending')
        ->and(MarketingSendApproval::query()->where('recommendation_id', $recommendation->id)->count())->toBe(0)
        ->and(MarketingMessageJob::query()->where('campaign_id', $draftCampaign->id)->count())->toBe(0);
});

test('assistant setup page composes checklist items and mixed state rendering for non-alpha eligible tenants', function () {
    $tenant = Tenant::query()->create([
        'name' => 'AI Setup Tenant',
        'slug' => 'ai-setup-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'growth',
        'operating_mode' => 'shopify',
        'source' => 'test',
    ]);

    TenantModuleState::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'customers',
        'setup_status' => 'in_progress',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.assistant.setup', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Setup')
        ->assertSeeText('Setup Checklist')
        ->assertSeeText('Customer Data')
        ->assertSeeText('Email Ready')
        ->assertSeeText('Campaigns Ready')
        ->assertSeeText('Recommendations Ready')
        ->assertSeeText('Store Connected')
        ->assertSeeText('Review Needed')
        ->assertSeeText('Ready')
        ->assertSeeText('Needs Setup')
        ->assertSeeText('Locked')
        ->assertSeeText('Coming Soon')
        ->assertViewHas('setupChecklist', function (array $payload): bool {
            $rows = array_values((array) ($payload['checklist'] ?? []));
            $states = collect($rows)
                ->map(static fn (array $row): ?string => $row['state']['ui_state'] ?? null)
                ->filter()
                ->unique()
                ->values()
                ->all();

            return count($rows) === 6
                && in_array('active', $states, true)
                && in_array('setup_needed', $states, true)
                && in_array('locked', $states, true)
                && ! in_array('coming_soon', $states, true);
        });
});

test('modern forestry alpha bootstrap unlocks ai assistant on first request', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.assistant.start', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('AI Assistant')
        ->assertSeeText('Ready')
        ->assertSeeText('Welcome to AI Assistant')
        ->assertSeeText('Next Best Click')
        ->assertDontSeeText('Review plans and module access');

    $this->get(route('shopify.app.assistant.setup', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Setup')
        ->assertSeeText('Setup Checklist')
        ->assertDontSeeText('Review plans and module access')
        ->assertViewHas('setupChecklist', function (array $payload): bool {
            $rows = array_values((array) ($payload['checklist'] ?? []));

            return (bool) ($payload['assistant_enabled'] ?? false) === true
                && count($rows) === 6;
        });

    $this->get(route('shopify.app.assistant.drafts', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Draft Campaigns')
        ->assertSeeText('Review Draft')
        ->assertDontSeeText('Review plans and module access');

    $module = app(TenantModuleAccessResolver::class)->module($tenant->id, 'ai');

    expect($module['has_access'])->toBeTrue()
        ->and($module['ui_state'])->toBe('active')
        ->and($module['setup_status'])->toBe('configured');
});
