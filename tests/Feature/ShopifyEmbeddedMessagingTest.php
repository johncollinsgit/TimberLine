<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\MarketingEmailDelivery;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageGroup;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Marketing\SendGridEmailService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('entitlements.default_plan', 'growth');
});

function shopifyMessagingApiHeaders(array $headers = []): array
{
    return array_merge([
        'Authorization' => 'Bearer '.retailShopifySessionToken(),
    ], $headers);
}

function shopifyMessagingGrantEntitlement(Tenant $tenant): void
{
    TenantModuleEntitlement::query()->updateOrCreate(
        [
            'tenant_id' => $tenant->id,
            'module_key' => 'messaging',
        ],
        [
            'availability_status' => 'available',
            'enabled_status' => 'enabled',
            'billing_status' => 'add_on_comped',
            'price_override_cents' => 0,
            'currency' => 'USD',
            'entitlement_source' => 'test',
            'price_source' => 'test',
        ]
    );
}

/**
 * @param  array<string,mixed>  $overrides
 */
function shopifyMessagingProfile(?int $tenantId, array $overrides = []): MarketingProfile
{
    $email = strtolower('profile-'.Str::random(8).'@example.com');
    $defaults = [
        'tenant_id' => $tenantId,
        'first_name' => 'Profile',
        'last_name' => 'Tester',
        'email' => $email,
        'normalized_email' => $email,
        'phone' => '5552223344',
        'normalized_phone' => '5552223344',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ];

    $payload = array_merge($defaults, $overrides);

    if (! array_key_exists('normalized_email', $overrides)) {
        $payload['normalized_email'] = is_string($payload['email'] ?? null)
            ? strtolower((string) $payload['email'])
            : null;
    }

    if (! array_key_exists('normalized_phone', $overrides)) {
        $payload['normalized_phone'] = is_string($payload['phone'] ?? null)
            ? preg_replace('/\D+/', '', (string) $payload['phone'])
            : null;
    }

    return MarketingProfile::query()->create($payload);
}

function runModernForestryMessagingDefaultSeedMigration(): void
{
    $migration = require base_path('database/migrations/2026_04_03_091000_seed_modern_forestry_messaging_entitlement.php');

    if (is_object($migration) && method_exists($migration, 'up')) {
        $migration->up();
    }
}

test('messaging workspace is hidden and locked for non-enabled tenant mappings', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Messaging Locked Tenant',
        'slug' => 'messaging-locked-tenant',
    ]);
    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('home', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertDontSee('href="/shopify/app/messaging?shop=', false);

    $this->get(route('shopify.app.messaging', retailEmbeddedSignedQuery()))
        ->assertStatus(403)
        ->assertSeeText('Messaging is locked');

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.bootstrap'))
        ->assertStatus(403)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'messaging_module_locked');
});

test('messaging nav and workspace load when entitlement is enabled', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Messaging Enabled Tenant',
        'slug' => 'messaging-enabled-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('home', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSee('href="/shopify/app/messaging?shop=', false);

    $this->get(route('shopify.app.messaging', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Messages Workspace')
        ->assertSeeText('Audience Groups')
        ->assertSeeText('Send to group')
        ->assertSee('id="messages-group-editor" hidden', false);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.bootstrap'))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonStructure([
            'ok',
            'data' => [
                'groups' => ['saved', 'auto'],
            ],
        ])
        ->assertJsonMissingPath('data.history')
        ->assertJsonMissingPath('data.all_subscribed_summary');
});

test('modern forestry default seed migration enables messaging entitlement', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    runModernForestryMessagingDefaultSeedMigration();

    $entitlement = TenantModuleEntitlement::query()
        ->where('tenant_id', $tenant->id)
        ->where('module_key', 'messaging')
        ->first();

    expect($entitlement)->not->toBeNull()
        ->and($entitlement?->enabled_status)->toBe('enabled')
        ->and($entitlement?->billing_status)->toBe('add_on_comped')
        ->and((int) ($entitlement?->price_override_cents ?? -1))->toBe(0)
        ->and((string) ($entitlement?->entitlement_source ?? ''))->toBe('modern_forestry_default');

    $module = app(TenantModuleAccessResolver::class)->module($tenant->id, 'messaging');

    expect((bool) ($module['has_access'] ?? false))->toBeTrue();
});

test('messaging customer search is tenant-scoped and returns contactability fields', function () {
    $tenantA = Tenant::query()->create([
        'name' => 'Search Tenant A',
        'slug' => 'search-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Search Tenant B',
        'slug' => 'search-tenant-b',
    ]);

    shopifyMessagingGrantEntitlement($tenantA);
    shopifyMessagingGrantEntitlement($tenantB);
    configureEmbeddedRetailStore($tenantA->id);

    $profileA = shopifyMessagingProfile($tenantA->id, [
        'first_name' => 'John',
        'last_name' => 'Collins',
        'email' => 'john.collins+a@example.com',
        'normalized_email' => 'john.collins+a@example.com',
        'phone' => '555-123-4568',
        'normalized_phone' => '5551234568',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    $profileB = shopifyMessagingProfile($tenantB->id, [
        'first_name' => 'John',
        'last_name' => 'Collins',
        'email' => 'john.collins+b@example.com',
        'normalized_email' => 'john.collins+b@example.com',
        'phone' => '555-999-1122',
        'normalized_phone' => '5559991122',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    $response = $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.customers.search', ['q' => 'john']));

    $response->assertOk()
        ->assertJsonPath('ok', true);

    $rows = collect((array) $response->json('data'));
    $ids = $rows->pluck('id')->map(fn ($value): int => (int) $value)->all();

    expect($ids)->toContain($profileA->id)
        ->not->toContain($profileB->id);

    $selected = $rows->first(fn (array $row): bool => (int) ($row['id'] ?? 0) === $profileA->id);
    expect((bool) ($selected['sms_contactable'] ?? false))->toBeTrue()
        ->and((bool) ($selected['email_contactable'] ?? false))->toBeTrue();
});

test('messaging group creation and update persist tenant-scoped memberships', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Messaging Group Tenant',
        'slug' => 'messaging-group-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $first = shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Taylor',
        'last_name' => 'One',
    ]);
    $second = shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Taylor',
        'last_name' => 'Two',
    ]);

    $createResponse = $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.groups.create'), [
            'name' => 'VIP Follow-up',
            'description' => 'Primary outreach customers',
            'member_profile_ids' => [$first->id, $second->id],
        ]);

    $createResponse->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Group saved.')
        ->assertJsonPath('data.members_count', 2);

    $groupId = (int) $createResponse->json('data.id');
    expect($groupId)->toBeGreaterThan(0);

    $this->assertDatabaseHas('marketing_message_groups', [
        'id' => $groupId,
        'tenant_id' => $tenant->id,
        'name' => 'VIP Follow-up',
        'is_system' => 0,
    ]);

    expect(DB::table('marketing_message_group_members')
        ->where('marketing_message_group_id', $groupId)
        ->count())->toBe(2);

    $updateResponse = $this->withHeaders(shopifyMessagingApiHeaders())
        ->patchJson(route('shopify.app.api.messaging.groups.update', ['group' => $groupId]), [
            'name' => 'VIP Follow-up Updated',
            'description' => 'Updated description',
            'member_profile_ids' => [$first->id],
        ]);

    $updateResponse->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Group updated.')
        ->assertJsonPath('data.members_count', 1);

    expect(DB::table('marketing_message_group_members')
        ->where('marketing_message_group_id', $groupId)
        ->count())->toBe(1);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.groups'))
        ->assertOk()
        ->assertJsonPath('ok', true);
});

test('messaging group endpoints enforce tenant boundaries', function () {
    $tenantA = Tenant::query()->create([
        'name' => 'Messaging Tenant A',
        'slug' => 'messaging-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Messaging Tenant B',
        'slug' => 'messaging-tenant-b',
    ]);
    shopifyMessagingGrantEntitlement($tenantA);
    shopifyMessagingGrantEntitlement($tenantB);

    configureEmbeddedRetailStore($tenantA->id);
    $profileA = shopifyMessagingProfile($tenantA->id, [
        'first_name' => 'Scope',
        'last_name' => 'Owner',
    ]);

    $createResponse = $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.groups.create'), [
            'name' => 'Tenant A Group',
            'member_profile_ids' => [$profileA->id],
        ]);

    $createResponse->assertOk()->assertJsonPath('ok', true);
    $groupId = (int) $createResponse->json('data.id');
    expect($groupId)->toBeGreaterThan(0);

    configureEmbeddedRetailStore($tenantB->id);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.groups.detail', ['group' => $groupId]))
        ->assertStatus(404)
        ->assertJsonPath('ok', false);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->patchJson(route('shopify.app.api.messaging.groups.update', ['group' => $groupId]), [
            'name' => 'Blocked update',
            'member_profile_ids' => [$profileA->id],
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Group could not be updated.')
        ->assertJsonValidationErrors(['group_id']);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.send.group'), [
            'target_type' => 'saved',
            'group_id' => $groupId,
            'channel' => 'sms',
            'body' => 'Cross-tenant send should be blocked.',
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Message could not be sent.')
        ->assertJsonValidationErrors(['group_id']);
});

test('all subscribed summary follows consent plus channel eligibility rules', function () {
    $tenant = Tenant::query()->create([
        'name' => 'All Subscribed Tenant',
        'slug' => 'all-subscribed-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Sms',
        'last_name' => 'Only',
        'email' => null,
        'normalized_email' => null,
        'phone' => '5551111001',
        'normalized_phone' => '5551111001',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => false,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Email',
        'last_name' => 'Only',
        'email' => 'email-only@example.com',
        'normalized_email' => 'email-only@example.com',
        'phone' => null,
        'normalized_phone' => null,
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => true,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Both',
        'last_name' => 'Eligible',
        'email' => 'both@example.com',
        'normalized_email' => 'both@example.com',
        'phone' => '5551111003',
        'normalized_phone' => '5551111003',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'first_name' => 'No',
        'last_name' => 'Contact',
        'email' => null,
        'normalized_email' => null,
        'phone' => null,
        'normalized_phone' => null,
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'first_name' => 'No',
        'last_name' => 'Consent',
        'email' => 'noconsent@example.com',
        'normalized_email' => 'noconsent@example.com',
        'phone' => '5551111005',
        'normalized_phone' => '5551111005',
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    $legacySms = shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Legacy',
        'last_name' => 'Sms',
        'email' => null,
        'normalized_email' => null,
        'phone' => '5551111006',
        'normalized_phone' => '5551111006',
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    $legacyEmail = shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Legacy',
        'last_name' => 'Email',
        'email' => 'legacy-email@example.com',
        'normalized_email' => 'legacy-email@example.com',
        'phone' => null,
        'normalized_phone' => null,
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);

    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacySms->id,
        'channel' => 'sms',
        'event_type' => 'imported',
        'source_type' => 'square_marketing_import',
        'source_id' => 'legacy-sms-import',
        'occurred_at' => now()->subMonths(3),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacyEmail->id,
        'channel' => 'email',
        'event_type' => 'imported',
        'source_type' => 'yotpo_contacts_import',
        'source_id' => 'legacy-email-import',
        'occurred_at' => now()->subMonths(4),
    ]);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.bootstrap'))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.groups.auto.0.key', 'all_subscribed');

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.audience.summary'))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.all_subscribed_summary.sms', 3)
        ->assertJsonPath('data.all_subscribed_summary.email', 3)
        ->assertJsonPath('data.all_subscribed_summary.overlap', 1)
        ->assertJsonPath('data.all_subscribed_summary.unique', 5)
        ->assertJsonStructure([
            'ok',
            'data' => [
                'all_subscribed_summary' => ['sms', 'email', 'overlap', 'unique'],
                'diagnostics' => [
                    'sms' => ['displayed_audience_count', 'query_candidate_count', 'effective_consent_count', 'resolved_sendable_count'],
                    'email' => ['displayed_audience_count', 'query_candidate_count', 'effective_consent_count', 'resolved_sendable_count'],
                ],
            ],
        ]);
});

test('legacy subscribed auto groups are only exposed for modern forestry tenant', function () {
    $modernTenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    $otherTenant = Tenant::query()->create([
        'name' => 'Legacy Groups Hidden Tenant',
        'slug' => 'legacy-groups-hidden-tenant',
    ]);
    shopifyMessagingGrantEntitlement($modernTenant);
    shopifyMessagingGrantEntitlement($otherTenant);

    configureEmbeddedRetailStore($modernTenant->id);

    $modernResponse = $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.groups'));

    $modernResponse->assertOk()->assertJsonPath('ok', true);

    $modernAutoKeys = collect((array) $modernResponse->json('data.auto'))
        ->pluck('key')
        ->map(fn ($value): string => (string) $value)
        ->all();

    expect($modernAutoKeys)
        ->toContain('all_subscribed')
        ->toContain('legacy_sms_subscribed')
        ->toContain('legacy_email_subscribed');

    configureEmbeddedRetailStore($otherTenant->id);

    $otherResponse = $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.groups'));

    $otherResponse->assertOk()->assertJsonPath('ok', true);

    $otherAutoKeys = collect((array) $otherResponse->json('data.auto'))
        ->pluck('key')
        ->map(fn ($value): string => (string) $value)
        ->all();

    expect($otherAutoKeys)
        ->toContain('all_subscribed')
        ->not->toContain('legacy_sms_subscribed')
        ->not->toContain('legacy_email_subscribed');

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.preview.group'), [
            'target_type' => 'auto',
            'group_key' => 'legacy_sms_subscribed',
            'channel' => 'sms',
            'body' => 'Legacy preview should be tenant scoped.',
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('message', 'Preview could not be generated.')
        ->assertJsonValidationErrors(['group_key']);
});

test('modern forestry legacy auto group summaries count unique sendable imported recipients per channel', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $legacySmsOnly = shopifyMessagingProfile($tenant->id, [
        'email' => null,
        'normalized_email' => null,
        'phone' => '5557771001',
        'normalized_phone' => '5557771001',
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    $legacyEmailOnly = shopifyMessagingProfile($tenant->id, [
        'email' => 'legacy-email-only@example.com',
        'normalized_email' => 'legacy-email-only@example.com',
        'phone' => null,
        'normalized_phone' => null,
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    $legacyBoth = shopifyMessagingProfile($tenant->id, [
        'email' => 'legacy-both@example.com',
        'normalized_email' => 'legacy-both@example.com',
        'phone' => '5557771003',
        'normalized_phone' => '5557771003',
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    $legacySmsUnsendable = shopifyMessagingProfile($tenant->id, [
        'email' => 'legacy-unsendable-sms@example.com',
        'normalized_email' => 'legacy-unsendable-sms@example.com',
        'phone' => null,
        'normalized_phone' => null,
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    $legacyEmailUnsendable = shopifyMessagingProfile($tenant->id, [
        'email' => null,
        'normalized_email' => null,
        'phone' => '5557771005',
        'normalized_phone' => '5557771005',
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    $legacySmsOptedOut = shopifyMessagingProfile($tenant->id, [
        'email' => null,
        'normalized_email' => null,
        'phone' => '5557771006',
        'normalized_phone' => '5557771006',
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    $legacySmsReconciled = shopifyMessagingProfile($tenant->id, [
        'email' => null,
        'normalized_email' => null,
        'phone' => '5557771008',
        'normalized_phone' => '5557771008',
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    $legacyEmailReconciled = shopifyMessagingProfile($tenant->id, [
        'email' => 'legacy-email-reconciled@example.com',
        'normalized_email' => 'legacy-email-reconciled@example.com',
        'phone' => null,
        'normalized_phone' => null,
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'email' => 'canonical-only@example.com',
        'normalized_email' => 'canonical-only@example.com',
        'phone' => '5557771007',
        'normalized_phone' => '5557771007',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacySmsOnly->id,
        'channel' => 'sms',
        'event_type' => 'imported',
        'source_type' => 'square_marketing_import',
        'source_id' => 'legacy-sms-only-a',
        'occurred_at' => now()->subMonths(5),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacySmsOnly->id,
        'channel' => 'sms',
        'event_type' => 'imported',
        'source_type' => 'square_marketing_import',
        'source_id' => 'legacy-sms-only-b',
        'occurred_at' => now()->subMonths(4),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacyEmailOnly->id,
        'channel' => 'email',
        'event_type' => 'imported',
        'source_type' => 'yotpo_contacts_import',
        'source_id' => 'legacy-email-only-a',
        'occurred_at' => now()->subMonths(4),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacyEmailOnly->id,
        'channel' => 'email',
        'event_type' => 'imported',
        'source_type' => 'yotpo_contacts_import',
        'source_id' => 'legacy-email-only-b',
        'occurred_at' => now()->subMonths(3),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacyBoth->id,
        'channel' => 'sms',
        'event_type' => 'imported',
        'source_type' => 'square_customer_sync',
        'source_id' => 'legacy-both-sms',
        'occurred_at' => now()->subMonths(3),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacyBoth->id,
        'channel' => 'email',
        'event_type' => 'imported',
        'source_type' => 'square_customer_sync',
        'source_id' => 'legacy-both-email',
        'occurred_at' => now()->subMonths(3),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacySmsUnsendable->id,
        'channel' => 'sms',
        'event_type' => 'imported',
        'source_type' => 'square_marketing_import',
        'source_id' => 'legacy-unsendable-sms',
        'occurred_at' => now()->subMonths(2),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacyEmailUnsendable->id,
        'channel' => 'email',
        'event_type' => 'imported',
        'source_type' => 'yotpo_contacts_import',
        'source_id' => 'legacy-unsendable-email',
        'occurred_at' => now()->subMonths(2),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacySmsReconciled->id,
        'channel' => 'sms',
        'event_type' => 'imported',
        'source_type' => 'legacy_import_reconciliation',
        'source_id' => 'legacy-reconciled-sms',
        'occurred_at' => now()->subMonths(2),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacyEmailReconciled->id,
        'channel' => 'email',
        'event_type' => 'imported',
        'source_type' => 'growave_marketing_reconciliation_sync',
        'source_id' => 'legacy-reconciled-email',
        'occurred_at' => now()->subMonths(2),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacySmsOptedOut->id,
        'channel' => 'sms',
        'event_type' => 'imported',
        'source_type' => 'square_marketing_import',
        'source_id' => 'legacy-optout-import',
        'occurred_at' => now()->subMonths(3),
    ]);
    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacySmsOptedOut->id,
        'channel' => 'sms',
        'event_type' => 'opted_out',
        'source_type' => 'shopify_widget_optin',
        'source_id' => 'legacy-optout-latest',
        'occurred_at' => now()->subWeek(),
    ]);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.audience.summary'))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.group_summaries.legacy_sms_subscribed.sms', 3)
        ->assertJsonPath('data.group_summaries.legacy_sms_subscribed.email', 0)
        ->assertJsonPath('data.group_summaries.legacy_sms_subscribed.unique', 3)
        ->assertJsonPath('data.group_summaries.legacy_email_subscribed.sms', 0)
        ->assertJsonPath('data.group_summaries.legacy_email_subscribed.email', 3)
        ->assertJsonPath('data.group_summaries.legacy_email_subscribed.unique', 3)
        ->assertJsonPath('data.diagnostics.legacy_sms_subscribed.resolved_sendable_count', 3)
        ->assertJsonPath('data.diagnostics.legacy_email_subscribed.resolved_sendable_count', 3);
});

test('individual sms send uses twilio path and records delivery metadata', function () {
    $tenant = Tenant::query()->create([
        'name' => 'SMS Send Tenant',
        'slug' => 'sms-send-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);
    config()->set('marketing.twilio.messaging_service_sid', 'MG_TEST');

    $profile = shopifyMessagingProfile($tenant->id, [
        'phone' => '555-222-5468',
        'normalized_phone' => '5552225468',
        'accepts_sms_marketing' => true,
    ]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.send.individual'), [
            'profile_id' => $profile->id,
            'channel' => 'sms',
            'body' => 'To God be the Glory',
        ]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Message sent.')
        ->assertJsonPath('data.summary.sent', 1);

    $delivery = MarketingMessageDelivery::query()
        ->where('marketing_profile_id', $profile->id)
        ->latest('id')
        ->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery?->channel)->toBe('sms')
        ->and($delivery?->provider)->toBe('twilio')
        ->and((string) data_get($delivery?->provider_payload, 'source_label'))->toBe('shopify_embedded_messaging_individual')
        ->and((string) data_get($delivery?->provider_payload, 'batch_id'))->not->toBe('');
});

test('individual email send uses existing email pipeline and records delivery metadata', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Email Send Tenant',
        'slug' => 'email-send-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $profile = shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Jane',
        'last_name' => 'Mailer',
        'email' => 'jane.mailer@example.com',
        'normalized_email' => 'jane.mailer@example.com',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => false,
    ]);

    $sendGrid = \Mockery::mock(SendGridEmailService::class);
    $sendGrid->shouldReceive('sendEmail')
        ->once()
        ->withArgs(function (string $toEmail, string $subject, string $body, array $options) use ($profile, $tenant): bool {
            return $toEmail === 'jane.mailer@example.com'
                && $subject === 'Operational Message'
                && $body === 'To God be the Glory'
                && (int) ($options['tenant_id'] ?? 0) === $tenant->id
                && (int) ($options['customer_id'] ?? 0) === $profile->id
                && (string) ($options['campaign_type'] ?? '') === 'direct_message';
        })
        ->andReturn([
            'success' => true,
            'provider' => 'sendgrid',
            'message_id' => 'sg-msg-123',
            'status' => 'sent',
            'error_code' => null,
            'error_message' => null,
            'payload' => ['id' => 'sg-msg-123'],
            'dry_run' => false,
            'retryable' => false,
            'tenant_id' => $tenant->id,
        ]);
    app()->instance(SendGridEmailService::class, $sendGrid);

    $response = $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.send.individual'), [
            'profile_id' => $profile->id,
            'channel' => 'email',
            'subject' => 'Operational Message',
            'body' => 'To God be the Glory',
        ]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Message sent.')
        ->assertJsonPath('data.summary.sent', 1);

    $delivery = MarketingEmailDelivery::query()
        ->where('marketing_profile_id', $profile->id)
        ->latest('id')
        ->first();

    expect($delivery)->not->toBeNull()
        ->and((int) ($delivery?->tenant_id ?? 0))->toBe($tenant->id)
        ->and((string) ($delivery?->campaign_type ?? ''))->toBe('direct_message')
        ->and((string) ($delivery?->provider_message_id ?? ''))->toBe('sg-msg-123')
        ->and((string) data_get($delivery?->metadata, 'source_label'))->toBe('shopify_embedded_messaging_individual')
        ->and((string) data_get($delivery?->metadata, 'subject'))->toBe('Operational Message');
});

test('auto group send dispatches to all subscribed sms recipients only', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Auto Group Tenant',
        'slug' => 'auto-group-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', true);
    config()->set('marketing.twilio.messaging_service_sid', 'MG_TEST');

    shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Eligible',
        'last_name' => 'One',
        'phone' => '5554440001',
        'normalized_phone' => '5554440001',
        'accepts_sms_marketing' => true,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Eligible',
        'last_name' => 'Two',
        'phone' => '5554440002',
        'normalized_phone' => '5554440002',
        'accepts_sms_marketing' => true,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Ineligible',
        'last_name' => 'NoConsent',
        'phone' => '5554440003',
        'normalized_phone' => '5554440003',
        'accepts_sms_marketing' => false,
    ]);
    $legacyImported = shopifyMessagingProfile($tenant->id, [
        'first_name' => 'Legacy',
        'last_name' => 'Imported',
        'phone' => '5554440004',
        'normalized_phone' => '5554440004',
        'accepts_sms_marketing' => false,
    ]);

    MarketingConsentEvent::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $legacyImported->id,
        'channel' => 'sms',
        'event_type' => 'imported',
        'source_type' => 'square_marketing_import',
        'source_id' => 'legacy-auto-group-sms',
        'occurred_at' => now()->subMonths(2),
    ]);

    $response = $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.send.group'), [
            'target_type' => 'auto',
            'group_key' => 'all_subscribed',
            'channel' => 'sms',
            'body' => 'To God be the Glory',
        ]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Message sent.')
        ->assertJsonPath('data.summary.processed', 3)
        ->assertJsonPath('data.summary.sent', 3)
        ->assertJsonPath('data.target.type', 'auto')
        ->assertJsonPath('data.target.key', 'all_subscribed');

    $deliveries = MarketingMessageDelivery::query()
        ->where('channel', 'sms')
        ->get();

    expect($deliveries)->toHaveCount(3)
        ->and($deliveries->every(fn (MarketingMessageDelivery $delivery): bool => (string) data_get($delivery->provider_payload, 'source_label') === 'shopify_embedded_messaging_auto_group'))
        ->toBeTrue();
});

test('group preview returns resolved recipient estimate before send and does not dispatch deliveries', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Preview Tenant',
        'slug' => 'preview-tenant',
    ]);
    shopifyMessagingGrantEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    shopifyMessagingProfile($tenant->id, [
        'phone' => '5556000001',
        'normalized_phone' => '5556000001',
        'accepts_sms_marketing' => true,
    ]);
    shopifyMessagingProfile($tenant->id, [
        'phone' => '5556000002',
        'normalized_phone' => '5556000002',
        'accepts_sms_marketing' => true,
    ]);

    $this->withHeaders(shopifyMessagingApiHeaders())
        ->postJson(route('shopify.app.api.messaging.preview.group'), [
            'target_type' => 'auto',
            'group_key' => 'all_subscribed',
            'channel' => 'sms',
            'body' => 'To God be the Glory',
        ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.target.key', 'all_subscribed')
        ->assertJsonPath('data.channel', 'sms')
        ->assertJsonPath('data.estimated_recipients', 2);

    expect(MarketingMessageDelivery::query()->count())->toBe(0)
        ->and(MarketingEmailDelivery::query()->count())->toBe(0);
});

test('groups list excludes system and cross-tenant groups', function () {
    $tenantA = Tenant::query()->create([
        'name' => 'Group List Tenant A',
        'slug' => 'group-list-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Group List Tenant B',
        'slug' => 'group-list-tenant-b',
    ]);
    shopifyMessagingGrantEntitlement($tenantA);
    shopifyMessagingGrantEntitlement($tenantB);

    $groupA = MarketingMessageGroup::query()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Tenant A Group',
        'channel' => 'multi',
        'is_reusable' => true,
        'is_system' => false,
        'system_key' => null,
    ]);
    MarketingMessageGroup::query()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Group',
        'channel' => 'multi',
        'is_reusable' => true,
        'is_system' => false,
        'system_key' => null,
    ]);
    MarketingMessageGroup::query()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'System Group',
        'channel' => 'multi',
        'is_reusable' => true,
        'is_system' => true,
        'system_key' => 'all_subscribed',
    ]);

    configureEmbeddedRetailStore($tenantA->id);

    $response = $this->withHeaders(shopifyMessagingApiHeaders())
        ->getJson(route('shopify.app.api.messaging.groups'));

    $response->assertOk()->assertJsonPath('ok', true);

    $saved = collect((array) $response->json('data.saved'));
    $savedIds = $saved->pluck('id')->map(fn ($value): int => (int) $value)->all();

    expect($savedIds)->toContain((int) $groupA->id)
        ->toHaveCount(1);
});
