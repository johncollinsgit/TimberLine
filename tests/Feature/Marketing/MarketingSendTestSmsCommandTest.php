<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.sms.dry_run', false);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.twilio.account_sid', 'AC_TEST');
    config()->set('marketing.twilio.auth_token', 'AUTH_TEST');
    config()->set('marketing.twilio.messaging_service_sid', null);
    config()->set('marketing.twilio.from_number', null);
});

test('marketing send test sms command reports sender path and twilio sid', function () {
    config()->set('marketing.twilio.senders', [
        [
            'key' => 'toll_free',
            'label' => 'Toll-free',
            'type' => 'toll_free',
            'status' => 'active',
            'enabled' => true,
            'default' => true,
            'messaging_service_sid' => 'MG_TOLL_FREE',
        ],
        [
            'key' => 'local',
            'label' => 'Local',
            'type' => 'local',
            'status' => 'active',
            'enabled' => true,
            'from_number' => '+15554443333',
        ],
    ]);

    Http::fake([
        'https://api.twilio.com/*' => Http::response([
            'sid' => 'SM_COMMAND_1',
            'status' => 'sent',
        ], 201),
    ]);

    $this->artisan('marketing:send-test-sms', [
        'to' => '+15552229988',
        'message' => "We're on a mission from God",
        '--sender' => 'local',
    ])
        ->expectsOutputToContain('sender_key=local')
        ->expectsOutputToContain('sender_path=from_number')
        ->expectsOutputToContain('twilio_message_sid=SM_COMMAND_1')
        ->assertExitCode(0);
});

test('marketing send test sms command blocks live send when global dry run is enabled', function () {
    config()->set('marketing.sms.dry_run', true);

    Http::fake();

    $this->artisan('marketing:send-test-sms', [
        'to' => '+15552229988',
        'message' => 'Blocked send',
    ])
        ->expectsOutputToContain('MARKETING_SMS_DRY_RUN=true blocks live sends.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});
