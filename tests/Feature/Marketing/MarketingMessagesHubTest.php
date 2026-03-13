<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingGroup;
use App\Models\MarketingMessageTemplate;
use App\Models\User;

test('messages hub surfaces groups direct send campaigns and templates', function () {
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $internalGroup = MarketingGroup::query()->create([
        'name' => 'VIP Text Crew',
        'description' => 'Manual SMS reachout group.',
        'is_internal' => true,
        'created_by' => $user->id,
    ]);

    MarketingGroup::query()->create([
        'name' => 'External Audience',
        'description' => 'Campaign-only group.',
        'is_internal' => false,
        'created_by' => $user->id,
    ]);

    MarketingCampaign::query()->create([
        'name' => 'Spring SMS Push',
        'status' => 'draft',
        'channel' => 'sms',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    MarketingMessageTemplate::query()->create([
        'name' => 'Manual Winback SMS',
        'channel' => 'sms',
        'objective' => 'winback',
        'template_text' => 'Hi {{first_name}}, come back soon.',
        'variables_json' => ['first_name'],
        'is_active' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
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
        ->assertSeeText('New SMS Template');
});
