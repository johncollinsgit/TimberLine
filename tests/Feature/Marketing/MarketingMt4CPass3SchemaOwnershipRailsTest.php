<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingEventSourceMapping;
use App\Models\MarketingGroup;
use App\Models\MarketingGroupMember;
use App\Models\MarketingMessageTemplate;
use App\Models\MarketingProfile;
use App\Models\MarketingSegment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config()->set('marketing.square.enabled', true);
    config()->set('marketing.square.sync_customers_enabled', false);
    config()->set('marketing.square.sync_orders_enabled', false);
    config()->set('marketing.square.sync_payments_enabled', false);
});

test('segment and template authoring writes tenant-owned rows in strict mode', function () {
    [$tenantA, $tenantB] = mt4cPass3Tenants();
    $user = mt4cPass3MarketingManagerForTenant($tenantA);

    $this->actingAs($user)
        ->get(route('marketing.segments.create'))
        ->assertOk();

    $this->actingAs($user)
        ->post(route('marketing.segments.store'), [
            'name' => 'Tenant A Segment',
            'description' => 'Owned by tenant A',
            'status' => 'active',
            'channel_scope' => 'sms',
            'rule_logic' => 'and',
            'conditions' => [
                ['field' => 'has_sms_consent', 'operator' => 'eq', 'value' => true],
            ],
        ])
        ->assertRedirect();

    $createdSegment = MarketingSegment::query()->where('name', 'Tenant A Segment')->firstOrFail();
    expect((int) $createdSegment->tenant_id)->toBe($tenantA->id);

    $this->actingAs($user)
        ->get(route('marketing.message-templates.create'))
        ->assertOk();

    $this->actingAs($user)
        ->post(route('marketing.message-templates.store'), [
            'name' => 'Tenant A Template',
            'channel' => 'sms',
            'objective' => 'winback',
            'tone' => 'friendly',
            'template_text' => 'Hi {{first_name}}, welcome back!',
            'variables_raw' => 'first_name',
            'is_active' => 1,
        ])
        ->assertRedirect();

    $createdTemplate = MarketingMessageTemplate::query()->where('name', 'Tenant A Template')->firstOrFail();
    expect((int) $createdTemplate->tenant_id)->toBe($tenantA->id);

    MarketingSegment::query()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Segment',
        'status' => 'active',
        'rules_json' => ['logic' => 'and', 'conditions' => [], 'groups' => []],
    ]);
    MarketingMessageTemplate::query()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Template',
        'channel' => 'sms',
        'template_text' => 'Tenant B',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('marketing.segments'))
        ->assertOk()
        ->assertSeeText('Tenant A Segment')
        ->assertDontSeeText('Tenant B Segment');

    $this->actingAs($user)
        ->get(route('marketing.message-templates'))
        ->assertOk()
        ->assertSeeText('Tenant A Template')
        ->assertDontSeeText('Tenant B Template');
});

test('segment duplication remains tenant-bound and foreign segment duplication is blocked', function () {
    [$tenantA, $tenantB] = mt4cPass3Tenants();
    $user = mt4cPass3MarketingManagerForTenant($tenantA);

    $ownedSegment = MarketingSegment::query()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Owned Segment',
        'status' => 'active',
        'rules_json' => ['logic' => 'and', 'conditions' => [], 'groups' => []],
    ]);
    $foreignSegment = MarketingSegment::query()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Foreign Segment',
        'status' => 'active',
        'rules_json' => ['logic' => 'and', 'conditions' => [], 'groups' => []],
    ]);

    $this->actingAs($user)
        ->post(route('marketing.segments.duplicate', $ownedSegment))
        ->assertRedirect();

    $duplicate = MarketingSegment::query()
        ->where('name', 'like', 'Owned Segment (Copy)%')
        ->latest('id')
        ->firstOrFail();

    expect((int) $duplicate->tenant_id)->toBe($tenantA->id);

    $this->actingAs($user)
        ->post(route('marketing.segments.duplicate', $foreignSegment))
        ->assertNotFound();
});

test('campaign authoring writes tenant-owned rows and blocks foreign segment selection', function () {
    [$tenantA, $tenantB] = mt4cPass3Tenants();
    $user = mt4cPass3MarketingManagerForTenant($tenantA);

    $segmentA = MarketingSegment::query()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Tenant A Campaign Segment',
        'status' => 'active',
        'rules_json' => ['logic' => 'and', 'conditions' => [], 'groups' => []],
    ]);
    $segmentB = MarketingSegment::query()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Campaign Segment',
        'status' => 'active',
        'rules_json' => ['logic' => 'and', 'conditions' => [], 'groups' => []],
    ]);

    $tenantProfile = MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Campaign',
        'last_name' => 'Owner',
        'phone' => '+15550001111',
        'normalized_phone' => '+15550001111',
        'accepts_sms_marketing' => true,
    ]);
    $group = MarketingGroup::query()->create([
        'name' => 'Tenant A Group',
        'created_by' => $user->id,
    ]);
    MarketingGroupMember::query()->create([
        'marketing_group_id' => $group->id,
        'marketing_profile_id' => $tenantProfile->id,
        'added_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->post(route('marketing.campaigns.store'), [
            'name' => 'Tenant A Campaign',
            'status' => 'draft',
            'channel' => 'sms',
            'segment_id' => $segmentA->id,
            'group_ids' => [$group->id],
            'objective' => 'winback',
            'attribution_window_days' => 7,
        ])
        ->assertRedirect();

    $campaign = MarketingCampaign::query()->where('name', 'Tenant A Campaign')->firstOrFail();
    expect((int) $campaign->tenant_id)->toBe($tenantA->id);

    $this->actingAs($user)
        ->from(route('marketing.campaigns.create'))
        ->post(route('marketing.campaigns.store'), [
            'name' => 'Tenant A Invalid Campaign',
            'status' => 'draft',
            'channel' => 'sms',
            'segment_id' => $segmentB->id,
            'group_ids' => [$group->id],
            'objective' => 'winback',
            'attribution_window_days' => 7,
        ])
        ->assertRedirect(route('marketing.campaigns.create'))
        ->assertSessionHasErrors(['segment_id']);
});

test('event source mappings are tenant-scoped and unresolved legacy rows stay fail-closed', function () {
    [$tenantA, $tenantB] = mt4cPass3Tenants();
    $user = mt4cPass3MarketingManagerForTenant($tenantA);

    $owned = MarketingEventSourceMapping::query()->create([
        'tenant_id' => $tenantA->id,
        'source_system' => 'square_tax_name',
        'raw_value' => 'tenant-a-tax',
        'normalized_value' => 'tenant-a-tax',
        'is_active' => true,
    ]);
    $foreign = MarketingEventSourceMapping::query()->create([
        'tenant_id' => $tenantB->id,
        'source_system' => 'square_tax_name',
        'raw_value' => 'tenant-b-tax',
        'normalized_value' => 'tenant-b-tax',
        'is_active' => true,
    ]);
    $unresolved = MarketingEventSourceMapping::query()->create([
        'source_system' => 'square_tax_name',
        'raw_value' => 'unresolved-tax',
        'normalized_value' => 'unresolved-tax',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('marketing.providers-integrations'))
        ->assertOk()
        ->assertSeeText('tenant-a-tax')
        ->assertDontSeeText('tenant-b-tax')
        ->assertDontSeeText('unresolved-tax');

    $this->actingAs($user)
        ->post(route('marketing.providers-integrations.mappings.store'), [
            'source_system' => 'square_tax_name',
            'raw_value' => 'tenant-a-new-tax',
            'normalized_value' => 'tenant-a-new-tax',
            'is_active' => true,
        ])
        ->assertRedirect(route('marketing.providers-integrations'));

    $newMappingTenantId = MarketingEventSourceMapping::query()
        ->where('raw_value', 'tenant-a-new-tax')
        ->value('tenant_id');
    expect((int) $newMappingTenantId)->toBe($tenantA->id);

    $this->actingAs($user)
        ->get(route('marketing.providers-integrations.mappings.edit', $owned))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('marketing.providers-integrations.mappings.edit', $foreign))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('marketing.providers-integrations.mappings.edit', $unresolved))
        ->assertNotFound();
});

test('unresolved legacy campaign, segment, and template rows are fail-closed in strict mode', function () {
    [$tenantA] = mt4cPass3Tenants();
    $user = mt4cPass3MarketingManagerForTenant($tenantA);

    $tenantProfile = MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Legacy',
        'last_name' => 'Owner',
        'phone' => '+15550002222',
        'normalized_phone' => '+15550002222',
        'accepts_sms_marketing' => true,
    ]);

    $legacyCampaign = MarketingCampaign::query()->create([
        'name' => 'Legacy Unresolved Campaign',
        'status' => 'draft',
        'channel' => 'sms',
    ]);

    MarketingCampaignRecipient::query()->create([
        'campaign_id' => $legacyCampaign->id,
        'marketing_profile_id' => $tenantProfile->id,
        'channel' => 'sms',
        'status' => 'queued_for_approval',
    ]);

    $legacySegment = MarketingSegment::query()->create([
        'name' => 'Legacy Unresolved Segment',
        'status' => 'active',
        'rules_json' => ['logic' => 'and', 'conditions' => [], 'groups' => []],
    ]);

    $legacyTemplate = MarketingMessageTemplate::query()->create([
        'name' => 'Legacy Unresolved Template',
        'channel' => 'sms',
        'template_text' => 'Legacy template text',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('marketing.campaigns'))
        ->assertOk()
        ->assertDontSeeText('Legacy Unresolved Campaign');

    $this->actingAs($user)
        ->get(route('marketing.campaigns.show', $legacyCampaign))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('marketing.segments'))
        ->assertOk()
        ->assertDontSeeText('Legacy Unresolved Segment');

    $this->actingAs($user)
        ->get(route('marketing.segments.edit', $legacySegment))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('marketing.message-templates'))
        ->assertOk()
        ->assertDontSeeText('Legacy Unresolved Template');

    $this->actingAs($user)
        ->get(route('marketing.message-templates.edit', $legacyTemplate))
        ->assertNotFound();
});

test('pass3 migration backfills provable legacy campaign, segment, and template ownership', function () {
    $migrationPath = 'database/migrations/2026_04_06_090000_add_tenant_ownership_to_marketing_authoring_tables.php';

    $this->artisan('migrate:rollback', [
        '--path' => $migrationPath,
    ])->assertExitCode(0);

    $tenant = Tenant::query()->create([
        'name' => 'MT4C Pass3 Backfill Tenant',
        'slug' => 'mt4c-pass3-backfill-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Backfill',
        'last_name' => 'Profile',
        'phone' => '+15550003333',
        'normalized_phone' => '+15550003333',
        'accepts_sms_marketing' => true,
    ]);

    $segment = MarketingSegment::query()->create([
        'name' => 'Backfill Segment',
        'status' => 'active',
        'rules_json' => ['logic' => 'and', 'conditions' => [], 'groups' => []],
    ]);

    $campaign = MarketingCampaign::query()->create([
        'name' => 'Backfill Campaign',
        'status' => 'draft',
        'channel' => 'sms',
        'segment_id' => $segment->id,
    ]);

    $template = MarketingMessageTemplate::query()->create([
        'name' => 'Backfill Template',
        'channel' => 'sms',
        'template_text' => 'Backfill template',
        'is_active' => true,
    ]);

    DB::table('marketing_campaign_variants')->insert([
        'campaign_id' => $campaign->id,
        'template_id' => $template->id,
        'name' => 'Backfill Variant',
        'variant_key' => 'BF',
        'message_text' => 'Backfill message',
        'weight' => 100,
        'is_control' => false,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'channel' => 'sms',
        'status' => 'queued_for_approval',
    ]);

    $this->artisan('migrate', [
        '--path' => $migrationPath,
    ])->assertExitCode(0);

    expect((int) DB::table('marketing_campaigns')->where('id', $campaign->id)->value('tenant_id'))->toBe($tenant->id)
        ->and((int) DB::table('marketing_segments')->where('id', $segment->id)->value('tenant_id'))->toBe($tenant->id)
        ->and((int) DB::table('marketing_message_templates')->where('id', $template->id)->value('tenant_id'))->toBe($tenant->id);
});

/**
 * @return array<int,Tenant>
 */
function mt4cPass3Tenants(): array
{
    $tenantA = Tenant::query()->create([
        'name' => 'MT4C Pass3 Tenant A',
        'slug' => 'mt4c-pass3-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'MT4C Pass3 Tenant B',
        'slug' => 'mt4c-pass3-tenant-b',
    ]);

    return [$tenantA, $tenantB];
}

function mt4cPass3MarketingManagerForTenant(Tenant $tenant): User
{
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $user->tenants()->syncWithoutDetaching([$tenant->id]);

    return $user;
}
