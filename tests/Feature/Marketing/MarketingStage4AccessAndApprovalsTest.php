<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingProfile;
use App\Models\MarketingRecommendation;
use App\Models\MarketingSendApproval;
use App\Models\User;

test('admin and marketing manager can access stage 4 marketing pages', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $marketingManager = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    foreach ([$admin, $marketingManager] as $user) {
        $this->actingAs($user)
            ->get(route('marketing.messages'))
            ->assertOk()
            ->assertSeeText('Messages');

        $this->actingAs($user)
            ->get(route('marketing.segments'))
            ->assertOk()
            ->assertSeeText('Segments');

        $this->actingAs($user)
            ->get(route('marketing.campaigns'))
            ->assertOk()
            ->assertSeeText('Campaigns');

        $this->actingAs($user)
            ->get(route('marketing.message-templates'))
            ->assertOk()
            ->assertSeeText('Templates');

        $this->actingAs($user)
            ->get(route('marketing.recommendations'))
            ->assertOk()
            ->assertSeeText('Suggestions');
    }
});

test('unauthorized roles cannot access stage 4 marketing pages', function () {
    $manager = User::factory()->create([
        'role' => 'manager',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get(route('marketing.messages'))
        ->assertForbidden();
    $this->actingAs($manager)
        ->get(route('marketing.segments'))
        ->assertForbidden();
    $this->actingAs($manager)
        ->get(route('marketing.campaigns'))
        ->assertForbidden();
    $this->actingAs($manager)
        ->get(route('marketing.message-templates'))
        ->assertForbidden();
    $this->actingAs($manager)
        ->get(route('marketing.recommendations'))
        ->assertForbidden();
});

test('admin and marketing manager can approve and reject queued recipients', function () {
    $campaign = MarketingCampaign::query()->create([
        'name' => 'Approvals Campaign',
        'status' => 'draft',
        'channel' => 'sms',
    ]);

    $approveProfile = MarketingProfile::query()->create([
        'first_name' => 'Approve',
    ]);
    $rejectProfile = MarketingProfile::query()->create([
        'first_name' => 'Reject',
    ]);

    $toApprove = MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $approveProfile->id,
        'channel' => 'sms',
        'status' => 'queued_for_approval',
    ]);
    $toReject = MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $rejectProfile->id,
        'channel' => 'sms',
        'status' => 'queued_for_approval',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $marketingManager = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post(route('marketing.campaigns.recipients.approve', [$campaign, $toApprove]), [
            'notes' => 'Looks good.',
        ])
        ->assertRedirect(route('marketing.campaigns.show', $campaign));

    $this->actingAs($marketingManager)
        ->post(route('marketing.campaigns.recipients.reject', [$campaign, $toReject]), [
            'notes' => 'Hold for now.',
        ])
        ->assertRedirect(route('marketing.campaigns.show', $campaign));

    expect($toApprove->fresh()->status)->toBe('approved')
        ->and($toReject->fresh()->status)->toBe('rejected')
        ->and(MarketingSendApproval::query()
            ->where('campaign_recipient_id', $toApprove->id)
            ->where('status', 'approved')
            ->exists())->toBeTrue()
        ->and(MarketingSendApproval::query()
            ->where('campaign_recipient_id', $toReject->id)
            ->where('status', 'rejected')
            ->exists())->toBeTrue();
});

test('unauthorized role cannot approve queued recipients', function () {
    $campaign = MarketingCampaign::query()->create([
        'name' => 'Unauthorized Campaign',
        'status' => 'draft',
        'channel' => 'sms',
    ]);
    $profile = MarketingProfile::query()->create(['first_name' => 'Nope']);
    $recipient = MarketingCampaignRecipient::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'channel' => 'sms',
        'status' => 'queued_for_approval',
    ]);

    $manager = User::factory()->create([
        'role' => 'manager',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($manager)
        ->post(route('marketing.campaigns.recipients.approve', [$campaign, $recipient]))
        ->assertForbidden();
});

test('recommendation review actions are accessible to marketing roles and logged', function () {
    $campaign = MarketingCampaign::query()->create([
        'name' => 'Recommendation Campaign',
        'status' => 'draft',
        'channel' => 'sms',
    ]);
    $profile = MarketingProfile::query()->create(['first_name' => 'Rec']);

    $recommendation = MarketingRecommendation::query()->create([
        'type' => 'copy_improvement',
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'title' => 'Add urgency line',
        'summary' => 'Current copy is long.',
        'status' => 'pending',
        'details_json' => ['reason' => 'single_variant'],
        'created_by_system' => true,
    ]);

    $marketingManager = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($marketingManager)
        ->post(route('marketing.recommendations.approve', $recommendation), [
            'notes' => 'Approved for next iteration.',
        ])
        ->assertRedirect(route('marketing.recommendations'));

    expect($recommendation->fresh()->status)->toBe('approved')
        ->and(MarketingSendApproval::query()
            ->where('recommendation_id', $recommendation->id)
            ->where('status', 'approved')
            ->exists())->toBeTrue();
});
