<?php

use App\Models\EventInstance;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingCampaignVariant;
use App\Models\MarketingEventSourceMapping;
use App\Models\MarketingMessageTemplate;
use App\Models\MarketingOrderEventAttribution;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingSegment;
use App\Models\SquareOrder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('pass4 remediation command assigns only provable unresolved ownership and quarantines ambiguous rows', function () {
    $fixtures = mt4cPass4SeedUnresolvedOwnershipFixtures();

    $this->artisan('marketing:remediate-authoring-ownership', [
        '--show-rows' => true,
    ])->expectsOutputToContain('mode=dry-run')
        ->expectsOutputToContain('entity=campaigns')
        ->expectsOutputToContain('entity=mappings')
        ->expectsOutputToContain('entity=attributions')
        ->assertExitCode(0);

    expect(DB::table('marketing_campaigns')->where('id', $fixtures['campaign_provable'])->value('tenant_id'))->toBeNull()
        ->and(DB::table('marketing_segments')->where('id', $fixtures['segment_provable'])->value('tenant_id'))->toBeNull()
        ->and(DB::table('marketing_message_templates')->where('id', $fixtures['template_provable'])->value('tenant_id'))->toBeNull()
        ->and(DB::table('marketing_event_source_mappings')->where('id', $fixtures['mapping_provable_source'])->value('tenant_id'))->toBeNull()
        ->and(DB::table('marketing_order_event_attributions')->where('id', $fixtures['attribution_provable'])->value('tenant_id'))->toBeNull();

    $this->artisan('marketing:remediate-authoring-ownership', [
        '--apply' => true,
    ])->expectsOutputToContain('mode=apply')
        ->expectsOutputToContain('totals')
        ->assertExitCode(0);

    expect((int) DB::table('marketing_campaigns')->where('id', $fixtures['campaign_provable'])->value('tenant_id'))
        ->toBe((int) $fixtures['tenant_a']->id)
        ->and(DB::table('marketing_campaigns')->where('id', $fixtures['campaign_ambiguous'])->value('tenant_id'))->toBeNull()
        ->and(DB::table('marketing_campaigns')->where('id', $fixtures['campaign_unprovable'])->value('tenant_id'))->toBeNull();

    expect((int) DB::table('marketing_segments')->where('id', $fixtures['segment_provable'])->value('tenant_id'))
        ->toBe((int) $fixtures['tenant_a']->id)
        ->and(DB::table('marketing_segments')->where('id', $fixtures['segment_ambiguous'])->value('tenant_id'))->toBeNull()
        ->and(DB::table('marketing_segments')->where('id', $fixtures['segment_unprovable'])->value('tenant_id'))->toBeNull();

    expect((int) DB::table('marketing_message_templates')->where('id', $fixtures['template_provable'])->value('tenant_id'))
        ->toBe((int) $fixtures['tenant_a']->id)
        ->and(DB::table('marketing_message_templates')->where('id', $fixtures['template_ambiguous'])->value('tenant_id'))->toBeNull()
        ->and(DB::table('marketing_message_templates')->where('id', $fixtures['template_unprovable'])->value('tenant_id'))->toBeNull();

    expect((int) DB::table('marketing_event_source_mappings')->where('id', $fixtures['mapping_provable_source'])->value('tenant_id'))
        ->toBe((int) $fixtures['tenant_a']->id)
        ->and((int) DB::table('marketing_event_source_mappings')->where('id', $fixtures['mapping_provable_tax'])->value('tenant_id'))->toBe((int) $fixtures['tenant_a']->id)
        ->and(DB::table('marketing_event_source_mappings')->where('id', $fixtures['mapping_ambiguous'])->value('tenant_id'))->toBeNull()
        ->and(DB::table('marketing_event_source_mappings')->where('id', $fixtures['mapping_unprovable'])->value('tenant_id'))->toBeNull()
        ->and(DB::table('marketing_event_source_mappings')->where('id', $fixtures['mapping_unsupported'])->value('tenant_id'))->toBeNull();

    expect((int) DB::table('marketing_order_event_attributions')->where('id', $fixtures['attribution_provable'])->value('tenant_id'))
        ->toBe((int) $fixtures['tenant_a']->id)
        ->and(DB::table('marketing_order_event_attributions')->where('id', $fixtures['attribution_ambiguous'])->value('tenant_id'))->toBeNull()
        ->and(DB::table('marketing_order_event_attributions')->where('id', $fixtures['attribution_unprovable'])->value('tenant_id'))->toBeNull()
        ->and(DB::table('marketing_order_event_attributions')->where('id', $fixtures['attribution_unsupported'])->value('tenant_id'))->toBeNull();
});

test('pass4 unresolved quarantine stays fail-closed in tenant surfaces after remediation', function () {
    $fixtures = mt4cPass4SeedUnresolvedOwnershipFixtures();
    $user = mt4cPass4MarketingManagerForTenant($fixtures['tenant_a']);

    $this->artisan('marketing:remediate-authoring-ownership', [
        '--apply' => true,
    ])->assertExitCode(0);

    $this->actingAs($user)
        ->get(route('marketing.campaigns'))
        ->assertOk()
        ->assertSeeText('Pass4 Provable Campaign')
        ->assertDontSeeText('Pass4 Ambiguous Campaign')
        ->assertDontSeeText('Pass4 Unprovable Campaign');

    $this->actingAs($user)
        ->get(route('marketing.campaigns.show', $fixtures['campaign_ambiguous']))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('marketing.providers-integrations'))
        ->assertOk()
        ->assertSeeText('pass4-source-a')
        ->assertSeeText('pass4-tax-a')
        ->assertDontSeeText('Pass4 Unsupported Mapping');

    $this->actingAs($user)
        ->get(route('marketing.providers-integrations.mappings.edit', $fixtures['mapping_ambiguous']))
        ->assertNotFound();
});

test('customer detail excludes foreign-tenant attribution rows for shared square order ids', function () {
    $tenantA = Tenant::query()->create([
        'name' => 'MT4C Pass4 Attribution Tenant A',
        'slug' => 'mt4c-pass4-attribution-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'MT4C Pass4 Attribution Tenant B',
        'slug' => 'mt4c-pass4-attribution-b',
    ]);
    $user = mt4cPass4MarketingManagerForTenant($tenantA);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Attribution',
        'last_name' => 'Owner',
        'phone' => '+15550009888',
        'normalized_phone' => '+15550009888',
    ]);

    $sharedOrderId = 'pass4-shared-detail-order';
    SquareOrder::query()->create([
        'tenant_id' => $tenantA->id,
        'square_order_id' => $sharedOrderId,
        'source_name' => 'Tenant A Shared Source',
    ]);
    SquareOrder::query()->create([
        'tenant_id' => $tenantB->id,
        'square_order_id' => $sharedOrderId,
        'source_name' => 'Tenant B Shared Source',
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'square_order',
        'source_id' => $sharedOrderId,
    ]);

    $eventA = EventInstance::query()->create(['title' => 'Pass4 Tenant A Event']);
    $eventB = EventInstance::query()->create(['title' => 'Pass4 Tenant B Event']);

    MarketingOrderEventAttribution::query()->create([
        'tenant_id' => $tenantA->id,
        'source_type' => 'square_order',
        'source_id' => $sharedOrderId,
        'event_instance_id' => $eventA->id,
        'attribution_method' => 'mapping:square_source_name',
    ]);
    MarketingOrderEventAttribution::query()->create([
        'tenant_id' => $tenantB->id,
        'source_type' => 'square_order',
        'source_id' => $sharedOrderId,
        'event_instance_id' => $eventB->id,
        'attribution_method' => 'mapping:square_source_name',
    ]);

    $this->actingAs($user)
        ->get(route('marketing.customers.show', $profile))
        ->assertOk()
        ->assertSeeText('Pass4 Tenant A Event')
        ->assertDontSeeText('Pass4 Tenant B Event');
});

/**
 * @return array<string,mixed>
 */
function mt4cPass4SeedUnresolvedOwnershipFixtures(): array
{
    $tenantA = Tenant::query()->create([
        'name' => 'MT4C Pass4 Tenant A',
        'slug' => 'mt4c-pass4-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'MT4C Pass4 Tenant B',
        'slug' => 'mt4c-pass4-tenant-b',
    ]);

    $profileA = MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Pass4',
        'last_name' => 'Owner A',
        'phone' => '+15550009001',
        'normalized_phone' => '+15550009001',
        'accepts_sms_marketing' => true,
    ]);
    $profileB = MarketingProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'first_name' => 'Pass4',
        'last_name' => 'Owner B',
        'phone' => '+15550009002',
        'normalized_phone' => '+15550009002',
        'accepts_sms_marketing' => true,
    ]);

    $campaignProvable = MarketingCampaign::query()->create([
        'name' => 'Pass4 Provable Campaign',
        'status' => 'draft',
        'channel' => 'sms',
    ]);
    MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaignProvable->id,
        'marketing_profile_id' => $profileA->id,
        'channel' => 'sms',
        'status' => 'queued_for_approval',
    ]);

    $campaignAmbiguous = MarketingCampaign::query()->create([
        'name' => 'Pass4 Ambiguous Campaign',
        'status' => 'draft',
        'channel' => 'sms',
    ]);
    MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaignAmbiguous->id,
        'marketing_profile_id' => $profileA->id,
        'channel' => 'sms',
        'status' => 'queued_for_approval',
    ]);
    MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaignAmbiguous->id,
        'marketing_profile_id' => $profileB->id,
        'channel' => 'sms',
        'status' => 'queued_for_approval',
    ]);

    $campaignUnprovable = MarketingCampaign::query()->create([
        'name' => 'Pass4 Unprovable Campaign',
        'status' => 'draft',
        'channel' => 'sms',
    ]);

    $segmentProvable = MarketingSegment::query()->create([
        'name' => 'Pass4 Segment Provable',
        'status' => 'active',
        'rules_json' => ['logic' => 'and', 'conditions' => [], 'groups' => []],
    ]);
    $segmentAmbiguous = MarketingSegment::query()->create([
        'name' => 'Pass4 Segment Ambiguous',
        'status' => 'active',
        'rules_json' => ['logic' => 'and', 'conditions' => [], 'groups' => []],
    ]);
    $segmentUnprovable = MarketingSegment::query()->create([
        'name' => 'Pass4 Segment Unprovable',
        'status' => 'active',
        'rules_json' => ['logic' => 'and', 'conditions' => [], 'groups' => []],
    ]);

    $tenantCampaignA = MarketingCampaign::query()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Pass4 Tenant A Segment Bridge',
        'status' => 'draft',
        'channel' => 'sms',
        'segment_id' => $segmentProvable->id,
    ]);
    MarketingCampaign::query()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Pass4 Tenant A Ambiguous Segment Bridge',
        'status' => 'draft',
        'channel' => 'sms',
        'segment_id' => $segmentAmbiguous->id,
    ]);
    $tenantCampaignB = MarketingCampaign::query()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Pass4 Tenant B Ambiguous Segment Bridge',
        'status' => 'draft',
        'channel' => 'sms',
        'segment_id' => $segmentAmbiguous->id,
    ]);

    $templateProvable = MarketingMessageTemplate::query()->create([
        'name' => 'Pass4 Template Provable',
        'channel' => 'sms',
        'template_text' => 'Template A',
        'is_active' => true,
    ]);
    $templateAmbiguous = MarketingMessageTemplate::query()->create([
        'name' => 'Pass4 Template Ambiguous',
        'channel' => 'sms',
        'template_text' => 'Template Ambiguous',
        'is_active' => true,
    ]);
    $templateUnprovable = MarketingMessageTemplate::query()->create([
        'name' => 'Pass4 Template Unprovable',
        'channel' => 'sms',
        'template_text' => 'Template Unprovable',
        'is_active' => true,
    ]);

    MarketingCampaignVariant::query()->create([
        'campaign_id' => $tenantCampaignA->id,
        'template_id' => $templateProvable->id,
        'name' => 'Pass4 Variant Provable',
        'variant_key' => 'P4P',
        'message_text' => 'Variant P',
        'weight' => 100,
        'is_control' => true,
        'status' => 'active',
    ]);
    MarketingCampaignVariant::query()->create([
        'campaign_id' => $tenantCampaignA->id,
        'template_id' => $templateAmbiguous->id,
        'name' => 'Pass4 Variant Ambiguous A',
        'variant_key' => 'P4A',
        'message_text' => 'Variant A',
        'weight' => 100,
        'is_control' => true,
        'status' => 'active',
    ]);
    MarketingCampaignVariant::query()->create([
        'campaign_id' => $tenantCampaignB->id,
        'template_id' => $templateAmbiguous->id,
        'name' => 'Pass4 Variant Ambiguous B',
        'variant_key' => 'P4B',
        'message_text' => 'Variant B',
        'weight' => 100,
        'is_control' => true,
        'status' => 'active',
    ]);

    $orderProvable = SquareOrder::query()->create([
        'tenant_id' => $tenantA->id,
        'square_order_id' => 'pass4-order-a',
        'source_name' => 'Pass4 Source A',
        'raw_tax_names' => ['Pass4 Tax A'],
    ]);
    SquareOrder::query()->create([
        'tenant_id' => $tenantA->id,
        'square_order_id' => 'pass4-order-shared-a',
        'source_name' => 'Pass4 Shared Source',
        'raw_tax_names' => ['Pass4 Shared Tax'],
    ]);
    SquareOrder::query()->create([
        'tenant_id' => $tenantB->id,
        'square_order_id' => 'pass4-order-shared-b',
        'source_name' => 'Pass4 Shared Source',
        'raw_tax_names' => ['Pass4 Shared Tax'],
    ]);
    SquareOrder::query()->create([
        'tenant_id' => $tenantA->id,
        'square_order_id' => 'pass4-ambiguous-source',
        'source_name' => 'pass4-ambiguous-attribution',
    ]);
    SquareOrder::query()->create([
        'tenant_id' => $tenantB->id,
        'square_order_id' => 'pass4-ambiguous-source',
        'source_name' => 'pass4-ambiguous-attribution',
    ]);

    $mappingProvableSource = MarketingEventSourceMapping::query()->create([
        'source_system' => 'square_source_name',
        'raw_value' => 'Pass4 Source A',
        'normalized_value' => 'pass4-source-a',
        'is_active' => true,
    ]);
    $mappingProvableTax = MarketingEventSourceMapping::query()->create([
        'source_system' => 'square_tax_name',
        'raw_value' => 'Pass4 Tax A',
        'normalized_value' => 'pass4-tax-a',
        'is_active' => true,
    ]);
    $mappingAmbiguous = MarketingEventSourceMapping::query()->create([
        'source_system' => 'square_source_name',
        'raw_value' => 'Pass4 Shared Source',
        'normalized_value' => 'pass4-shared-source',
        'is_active' => true,
    ]);
    $mappingUnprovable = MarketingEventSourceMapping::query()->create([
        'source_system' => 'square_source_name',
        'raw_value' => 'Pass4 Unknown Source',
        'normalized_value' => 'pass4-unknown-source',
        'is_active' => true,
    ]);
    $mappingUnsupported = MarketingEventSourceMapping::query()->create([
        'source_system' => 'legacy_manual_source',
        'raw_value' => 'Pass4 Unsupported Mapping',
        'normalized_value' => 'pass4-unsupported-mapping',
        'is_active' => true,
    ]);

    $event = EventInstance::query()->create([
        'title' => 'MT4C Pass4 Event',
    ]);

    $attributionProvable = MarketingOrderEventAttribution::query()->create([
        'source_type' => 'square_order',
        'source_id' => $orderProvable->square_order_id,
        'event_instance_id' => $event->id,
        'attribution_method' => 'legacy',
    ]);
    $attributionAmbiguous = MarketingOrderEventAttribution::query()->create([
        'source_type' => 'square_order',
        'source_id' => 'pass4-ambiguous-source',
        'event_instance_id' => $event->id,
        'attribution_method' => 'legacy',
    ]);
    $attributionUnprovable = MarketingOrderEventAttribution::query()->create([
        'source_type' => 'square_order',
        'source_id' => 'pass4-missing-source',
        'event_instance_id' => $event->id,
        'attribution_method' => 'legacy',
    ]);
    $attributionUnsupported = MarketingOrderEventAttribution::query()->create([
        'source_type' => 'shopify_order',
        'source_id' => 'pass4-shopify-source',
        'event_instance_id' => $event->id,
        'attribution_method' => 'legacy',
    ]);

    return [
        'tenant_a' => $tenantA,
        'tenant_b' => $tenantB,
        'campaign_provable' => (int) $campaignProvable->id,
        'campaign_ambiguous' => (int) $campaignAmbiguous->id,
        'campaign_unprovable' => (int) $campaignUnprovable->id,
        'segment_provable' => (int) $segmentProvable->id,
        'segment_ambiguous' => (int) $segmentAmbiguous->id,
        'segment_unprovable' => (int) $segmentUnprovable->id,
        'template_provable' => (int) $templateProvable->id,
        'template_ambiguous' => (int) $templateAmbiguous->id,
        'template_unprovable' => (int) $templateUnprovable->id,
        'mapping_provable_source' => (int) $mappingProvableSource->id,
        'mapping_provable_tax' => (int) $mappingProvableTax->id,
        'mapping_ambiguous' => (int) $mappingAmbiguous->id,
        'mapping_unprovable' => (int) $mappingUnprovable->id,
        'mapping_unsupported' => (int) $mappingUnsupported->id,
        'attribution_provable' => (int) $attributionProvable->id,
        'attribution_ambiguous' => (int) $attributionAmbiguous->id,
        'attribution_unprovable' => (int) $attributionUnprovable->id,
        'attribution_unsupported' => (int) $attributionUnsupported->id,
    ];
}

function mt4cPass4MarketingManagerForTenant(Tenant $tenant): User
{
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $user->tenants()->syncWithoutDetaching([$tenant->id]);

    return $user;
}
