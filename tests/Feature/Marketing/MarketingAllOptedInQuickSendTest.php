<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.sms.dry_run', false);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.twilio.account_sid', 'AC_TEST');
    config()->set('marketing.twilio.auth_token', 'AUTH_TEST');
    config()->set('marketing.twilio.messaging_service_sid', 'MG_TEST');
    config()->set('marketing.twilio.verify_signature', false);
    config()->set('marketing.email.enabled', true);
    config()->set('marketing.email.dry_run', false);
    config()->set('marketing.email.from_email', 'marketing@example.com');
    config()->set('marketing.email.from_name', 'Timberline');
    config()->set('services.sendgrid.api_key', 'SG_TEST');
});

function seedOptedInAudience(): void
{
    MarketingProfile::query()->create([
        'first_name' => 'Both',
        'email' => 'both@example.com',
        'normalized_email' => 'both@example.com',
        'phone' => '5551112222',
        'normalized_phone' => '+15551112222',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ]);

    MarketingProfile::query()->create([
        'first_name' => 'Sms',
        'phone' => '5553334444',
        'normalized_phone' => '+15553334444',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => false,
    ]);

    MarketingProfile::query()->create([
        'first_name' => 'Email',
        'email' => 'email@example.com',
        'normalized_email' => 'email@example.com',
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => true,
    ]);

    MarketingProfile::query()->create([
        'first_name' => 'Nope',
        'email' => 'nope@example.com',
        'normalized_email' => 'nope@example.com',
        'phone' => '5556667777',
        'normalized_phone' => '+15556667777',
        'accepts_sms_marketing' => false,
        'accepts_email_marketing' => false,
    ]);
}

test('dashboard and quick send page expose the simplified all opted-in workflow', function () {
    seedOptedInAudience();

    $user = User::factory()->create([
        'role' => 'admin',
        'email' => 'operator@example.com',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Send Message to All Opted-In Customers')
        ->assertSeeText('Quick send to all SMS/email subscribers');

    $this->actingAs($user)
        ->get(route('marketing.send.all-opted-in'))
        ->assertOk()
        ->assertSeeText('Send to All Opted-In')
        ->assertSeeText('2')
        ->assertSeeText('3')
        ->assertSeeText('SMS only')
        ->assertSeeText('Both SMS + Email');
});

test('test send reuses provider plumbing without creating campaigns', function () {
    seedOptedInAudience();

    Http::fake([
        'https://api.twilio.com/*' => Http::response([
            'sid' => 'SM_TEST_QUICK',
            'status' => 'sent',
        ], 201),
        'https://api.sendgrid.com/*' => Http::response([], 202, ['X-Message-Id' => 'SG_TEST_QUICK']),
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'name' => 'Operator Person',
        'email' => 'operator@example.com',
        'email_verified_at' => now(),
    ]);
    $campaignBaseline = MarketingCampaign::query()->count();
    $recipientBaseline = MarketingCampaignRecipient::query()->count();

    $response = $this->actingAs($user)
        ->from(route('marketing.send.all-opted-in'))
        ->post(route('marketing.send.all-opted-in.submit'), [
            'intent' => 'test',
            'channel' => 'both',
            'sms_body' => 'Hi {{first_name}}, this is a preview.',
            'email_subject' => 'Preview {{first_name}}',
            'email_body' => 'Email body for {{first_name}}',
            'cta_link' => 'https://example.com/offers/spring',
            'test_phone' => '(555) 222-9999',
            'test_email' => 'operator@example.com',
        ]);

    $response->assertRedirect(route('marketing.send.all-opted-in'));
    $response->assertSessionHas('quick_send_all_opted_in_test_result');

    expect(MarketingCampaign::query()->count())->toBe($campaignBaseline)
        ->and(MarketingCampaignRecipient::query()->count())->toBe($recipientBaseline);

    Http::assertSentCount(2);
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'twilio.com')) {
            return false;
        }

        return str_contains((string) $request['Body'], 'https://example.com/offers/spring');
    });
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'sendgrid.com')) {
            return false;
        }

        $payload = $request->data();

        return data_get($payload, 'personalizations.0.subject') === 'Preview Operator';
    });
});

test('final send creates hidden campaigns, sends recipients, and blocks double submit', function () {
    seedOptedInAudience();

    Http::fake([
        'https://api.twilio.com/*' => Http::sequence()
            ->push([
                'sid' => 'SM_FINAL_QUICK_1',
                'status' => 'sent',
            ], 201)
            ->push([
                'sid' => 'SM_FINAL_QUICK_2',
                'status' => 'sent',
            ], 201),
        'https://api.sendgrid.com/*' => Http::response([], 202, ['X-Message-Id' => 'SG_FINAL_QUICK']),
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'email' => 'operator@example.com',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)->get(route('marketing.send.all-opted-in'))->assertOk();
    $token = session('marketing.send.all_opted_in.confirmation_token');
    $campaignBaseline = MarketingCampaign::query()->count();
    $recipientBaseline = MarketingCampaignRecipient::query()->count();
    $smsBaseline = MarketingMessageDelivery::query()->count();
    $emailBaseline = MarketingEmailDelivery::query()->count();

    $payload = [
        'intent' => 'send',
        'channel' => 'both',
        'sms_body' => 'Quick SMS for {{first_name}}',
        'email_subject' => 'Big News {{first_name}}',
        'email_body' => 'Quick email for {{first_name}}',
        'cta_link' => 'https://example.com/join',
        'confirm_send' => '1',
        'confirmation_token' => $token,
    ];

    $response = $this->actingAs($user)
        ->from(route('marketing.send.all-opted-in'))
        ->post(route('marketing.send.all-opted-in.submit'), $payload);

    $response->assertRedirect(route('marketing.send.all-opted-in'));
    $response->assertSessionHas('quick_send_all_opted_in_send_result');

    expect(MarketingCampaign::query()->count())->toBe($campaignBaseline + 2)
        ->and(MarketingCampaignRecipient::query()->count())->toBe($recipientBaseline + 4)
        ->and(MarketingMessageDelivery::query()->count())->toBe($smsBaseline + 2)
        ->and(MarketingEmailDelivery::query()->count())->toBe($emailBaseline + 2);

    $emailRecipient = MarketingCampaignRecipient::query()->where('channel', 'email')->firstOrFail();
    expect(data_get($emailRecipient->recommendation_snapshot, 'email_subject'))->toBe('Big News {{first_name}}');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'sendgrid.com')) {
            return false;
        }

        return data_get($request->data(), 'personalizations.0.subject') === 'Big News Both';
    });

    $retry = $this->actingAs($user)
        ->from(route('marketing.send.all-opted-in'))
        ->post(route('marketing.send.all-opted-in.submit'), $payload);

    $retry->assertRedirect(route('marketing.send.all-opted-in'));
    $retry->assertSessionHasErrors('confirm_send');

    expect(MarketingCampaign::query()->count())->toBe($campaignBaseline + 2);
});
