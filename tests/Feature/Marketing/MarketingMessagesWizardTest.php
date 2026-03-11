<?php

use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageGroup;
use App\Models\MarketingProfile;
use App\Models\MarketingShortLink;
use App\Models\User;

beforeEach(function () {
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.sms.dry_run', false);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.twilio.account_sid', 'AC_TEST');
    config()->set('marketing.twilio.auth_token', 'AUTH_TEST');
    config()->set('marketing.twilio.messaging_service_sid', 'MG_TEST');
    config()->set('marketing.twilio.verify_signature', false);
    config()->set('services.twilio.account_sid', 'AC_TEST');
    config()->set('services.twilio.auth_token', 'AUTH_TEST');
    config()->set('services.twilio.messaging_service_sid', 'MG_TEST');
});

test('admin and marketing manager can access messaging wizard and delivery log', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $marketingManager = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $manager = User::factory()->create([
        'role' => 'manager',
        'email_verified_at' => now(),
    ]);

    foreach ([$admin, $marketingManager] as $user) {
        $this->actingAs($user)
            ->get(route('marketing.messages.send'))
            ->assertOk()
            ->assertSeeText('Send a text');

        $this->actingAs($user)
            ->get(route('marketing.messages.deliveries'))
            ->assertOk()
            ->assertSeeText('Direct Message Deliveries');
    }

    $this->actingAs($manager)
        ->get(route('marketing.messages.send'))
        ->assertForbidden();
});

test('single-customer wizard flow creates direct sms delivery records', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Avery',
        'last_name' => 'Shopper',
        'email' => 'avery@example.com',
        'normalized_email' => 'avery@example.com',
        'phone' => '+15555550100',
        'normalized_phone' => '+15555550100',
        'accepts_sms_marketing' => true,
        'source_channels' => ['shopify'],
    ]);

    $this->actingAs($user)
        ->post(route('marketing.messages.save-audience'), [
            'audience_kind' => 'person',
            'selected_profile_id' => $profile->id,
        ])
        ->assertRedirect(route('marketing.messages.send'));

    $this->actingAs($user)
        ->post(route('marketing.messages.save-message'), [
            'message_text' => 'Hello from Backstage wizard.',
        ])
        ->assertRedirect(route('marketing.messages.send'));

    $response = $this->actingAs($user)
        ->post(route('marketing.messages.execute'), [
            'confirm_send' => '1',
            'dry_run' => '1',
        ]);

    $response->assertRedirect();

    $delivery = MarketingMessageDelivery::query()
        ->whereNull('campaign_id')
        ->where('marketing_profile_id', $profile->id)
        ->latest('id')
        ->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery->channel)->toBe('sms')
        ->and($delivery->provider)->toBe('twilio')
        ->and($delivery->provider_message_id)->toStartWith('DRYRUN-')
        ->and($delivery->send_status)->toBe('sent')
        ->and((string) data_get($delivery->provider_payload, 'source_label'))->toBe('direct_message_wizard')
        ->and((string) data_get($delivery->provider_payload, 'batch_id'))->not->toBe('');
});

test('custom group audience can be saved as reusable group', function () {
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $profileA = MarketingProfile::query()->create([
        'first_name' => 'Group',
        'last_name' => 'One',
        'email' => 'group.one@example.com',
        'normalized_email' => 'group.one@example.com',
        'phone' => '+15555550111',
        'normalized_phone' => '+15555550111',
        'accepts_sms_marketing' => true,
    ]);
    $profileB = MarketingProfile::query()->create([
        'first_name' => 'Group',
        'last_name' => 'Two',
        'email' => 'group.two@example.com',
        'normalized_email' => 'group.two@example.com',
        'phone' => '+15555550112',
        'normalized_phone' => '+15555550112',
        'accepts_sms_marketing' => true,
    ]);

    $this->actingAs($user)
        ->post(route('marketing.messages.save-audience'), [
            'audience_kind' => 'group',
            'group_mode' => 'custom',
            'selected_profile_ids' => [$profileA->id, $profileB->id],
            'group_name' => 'VIP Spring Group',
            'group_description' => 'Reusable campaign audience',
            'save_reusable_group' => '1',
        ])
        ->assertRedirect(route('marketing.messages.send'));

    $group = MarketingMessageGroup::query()->where('name', 'VIP Spring Group')->first();

    expect($group)->not->toBeNull()
        ->and($group->channel)->toBe('sms')
        ->and($group->is_reusable)->toBeTrue()
        ->and($group->members()->count())->toBe(2);
});

test('manual phone audience creates profile and logs delivery on send', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('marketing.messages.save-audience'), [
            'audience_kind' => 'manual',
            'manual_phones' => '+1 (555) 777-1212',
        ])
        ->assertRedirect(route('marketing.messages.send'));

    $this->actingAs($user)
        ->post(route('marketing.messages.save-message'), [
            'message_text' => 'Manual audience message',
        ])
        ->assertRedirect(route('marketing.messages.send'));

    $this->actingAs($user)
        ->post(route('marketing.messages.execute'), [
            'confirm_send' => '1',
            'dry_run' => '1',
        ])
        ->assertRedirect();

    $profile = MarketingProfile::query()
        ->where('normalized_phone', '+15557771212')
        ->first();

    $delivery = MarketingMessageDelivery::query()
        ->whereNull('campaign_id')
        ->latest('id')
        ->first();

    expect($profile)->not->toBeNull()
        ->and($delivery)->not->toBeNull()
        ->and((int) $delivery->marketing_profile_id)->toBe((int) $profile->id)
        ->and($delivery->provider_message_id)->toStartWith('DRYRUN-');
});

test('customer autocomplete endpoint returns marketing profile matches', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    MarketingProfile::query()->create([
        'first_name' => 'Casey',
        'last_name' => 'Lookup',
        'email' => 'casey.lookup@example.com',
        'normalized_email' => 'casey.lookup@example.com',
        'phone' => '+15558889999',
        'normalized_phone' => '+15558889999',
        'accepts_sms_marketing' => true,
    ]);

    $this->actingAs($user)
        ->getJson(route('marketing.messages.search-customers', ['q' => 'casey']))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Casey Lookup')
        ->assertJsonPath('meta.has_profiles', true);
});

test('message save auto-shortens links and stores preview metadata', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Link',
        'last_name' => 'Test',
        'email' => 'link.test@example.com',
        'normalized_email' => 'link.test@example.com',
        'phone' => '+15550001122',
        'normalized_phone' => '+15550001122',
        'accepts_sms_marketing' => true,
    ]);

    $this->actingAs($user)
        ->post(route('marketing.messages.save-audience'), [
            'audience_kind' => 'person',
            'selected_profile_id' => $profile->id,
        ])
        ->assertRedirect(route('marketing.messages.send'));

    $response = $this->actingAs($user)
        ->post(route('marketing.messages.save-message'), [
            'message_text' => 'New drop alert: https://example.com/products/spring-launch?ref=campaign',
        ]);

    $response->assertRedirect(route('marketing.messages.send'))
        ->assertSessionHas('marketing.messages.wizard.shortened_links');

    $shortLink = MarketingShortLink::query()->first();

    expect($shortLink)->not->toBeNull()
        ->and($shortLink->destination_url)->toContain('https://example.com/products/spring-launch');
});

test('short links redirect to destination and increment usage count', function () {
    $link = MarketingShortLink::query()->create([
        'code' => 'abc1234',
        'destination_url' => 'https://example.com/product/forest',
        'url_hash' => hash('sha256', 'https://example.com/product/forest'),
    ]);

    $this->get(route('marketing.short-links.redirect', ['code' => $link->code]))
        ->assertRedirect('https://example.com/product/forest');

    expect($link->fresh()->usage_count)->toBe(1)
        ->and($link->fresh()->last_used_at)->not->toBeNull();
});
