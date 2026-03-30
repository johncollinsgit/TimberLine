<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingCampaignVariant;
use App\Models\MarketingGroup;
use App\Models\MarketingGroupMember;
use App\Models\MarketingMessageTemplate;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\User;

test('messages hub surfaces only tenant-owned groups campaigns and templates', function () {
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $tenantA = Tenant::query()->create([
        'name' => 'Messages Tenant A',
        'slug' => 'messages-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Messages Tenant B',
        'slug' => 'messages-tenant-b',
    ]);
    $user->tenants()->syncWithoutDetaching([$tenantA->id]);

    $profileA = MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Tenant',
        'last_name' => 'A',
        'email' => 'messages-tenant-a@example.com',
        'normalized_email' => 'messages-tenant-a@example.com',
    ]);
    $profileB = MarketingProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'first_name' => 'Tenant',
        'last_name' => 'B',
        'email' => 'messages-tenant-b@example.com',
        'normalized_email' => 'messages-tenant-b@example.com',
    ]);

    $internalGroup = MarketingGroup::query()->create([
        'name' => 'VIP Text Crew',
        'description' => 'Manual SMS reachout group.',
        'is_internal' => true,
        'created_by' => $user->id,
    ]);
    MarketingGroupMember::query()->create([
        'marketing_group_id' => $internalGroup->id,
        'marketing_profile_id' => $profileA->id,
        'added_by' => $user->id,
    ]);

    MarketingGroup::query()->create([
        'name' => 'External Audience',
        'description' => 'Campaign-only group.',
        'is_internal' => false,
        'created_by' => $user->id,
    ]);
    $foreignGroup = MarketingGroup::query()->create([
        'name' => 'Tenant B Hidden Group',
        'description' => 'Foreign tenant group should stay hidden.',
        'is_internal' => true,
        'created_by' => $user->id,
    ]);
    MarketingGroupMember::query()->create([
        'marketing_group_id' => $foreignGroup->id,
        'marketing_profile_id' => $profileB->id,
        'added_by' => $user->id,
    ]);

    $campaign = MarketingCampaign::query()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Spring SMS Push',
        'status' => 'draft',
        'channel' => 'sms',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profileA->id,
        'channel' => 'sms',
        'status' => 'queued_for_approval',
    ]);
    $foreignCampaign = MarketingCampaign::query()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Foreign Tenant Push',
        'status' => 'draft',
        'channel' => 'sms',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    MarketingCampaignRecipient::query()->create([
        'campaign_id' => $foreignCampaign->id,
        'marketing_profile_id' => $profileB->id,
        'channel' => 'sms',
        'status' => 'queued_for_approval',
    ]);

    $template = MarketingMessageTemplate::query()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Manual Winback SMS',
        'channel' => 'sms',
        'objective' => 'winback',
        'template_text' => 'Hi {{first_name}}, come back soon.',
        'variables_json' => ['first_name'],
        'is_active' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    MarketingCampaignVariant::query()->create([
        'campaign_id' => $campaign->id,
        'template_id' => $template->id,
        'name' => 'Tenant A Variant',
        'message_text' => 'Tenant A message',
        'weight' => 100,
        'status' => 'active',
    ]);
    $foreignTemplate = MarketingMessageTemplate::query()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Foreign Template',
        'channel' => 'sms',
        'objective' => 'winback',
        'template_text' => 'Foreign tenant message',
        'variables_json' => ['first_name'],
        'is_active' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    MarketingCampaignVariant::query()->create([
        'campaign_id' => $foreignCampaign->id,
        'template_id' => $foreignTemplate->id,
        'name' => 'Tenant B Variant',
        'message_text' => 'Tenant B message',
        'weight' => 100,
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->get(route('marketing.messages'))
        ->assertOk()
        ->assertSeeText('Messages')
        ->assertSeeText('Quick Actions')
        ->assertSeeText('Create Group')
        ->assertSeeText('VIP Text Crew')
        ->assertSeeText('Manual Send')
        ->assertSeeText('Spring SMS Push')
        ->assertSeeText('Manual Winback SMS')
        ->assertSeeText('New SMS Template')
        ->assertDontSeeText('Tenant B Hidden Group')
        ->assertDontSeeText('Foreign Tenant Push')
        ->assertDontSeeText('Foreign Template');
});

test('messages hub fails closed when tenant context cannot be proven', function () {
    Tenant::query()->create([
        'name' => 'Messages Tenant Required',
        'slug' => 'messages-tenant-required',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('marketing.messages'))
        ->assertForbidden();
});
