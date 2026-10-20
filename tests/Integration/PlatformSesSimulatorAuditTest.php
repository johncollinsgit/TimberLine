<?php

use App\Http\Controllers\Marketing\SesWebhookController;
use App\Models\PlatformEmailDelivery;
use App\Models\PlatformEmailMessage;
use App\Services\Mail\PlatformSesOutbox;
use App\Services\Mail\PlatformSesSimulatorAudit;
use App\Services\Marketing\Email\SesDeliveryEvents;
use App\Services\Marketing\Email\SesSnsVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['cache.default' => 'array', 'ses_migration.simulator_fixture_enabled' => false]);
    Cache::flush();
});

function approvedPlatformAuditFixture(string $case = 'success'): array
{
    $recipient = $case.'@simulator.amazonses.com';
    $message = PlatformEmailMessage::create([
        'group_key' => hash('sha256', 'explicit:ses-platform-simulator-20261020-'.$case),
        'payload_hash' => hash('sha256', 'approved non-customer fixture'), 'mime' => 'approved non-customer fixture',
        'tenant_id' => null, 'from_email' => 'no-reply@theeverbranch.com', 'configuration_set' => 'everbranch-platform',
    ]);
    $delivery = PlatformEmailDelivery::create([
        'platform_email_message_id' => $message->id, 'recipient_hash' => app(PlatformSesOutbox::class)->recipientHash($recipient),
        'recipient' => $recipient, 'state' => 'submitting', 'submitted_at' => now(), 'provider_cost_micros' => 1000,
    ]);
    $event = ['eventType' => 'Delivery', 'mail' => ['messageId' => 'audit-fixture-provider-receipt',
        'source' => 'Everbranch <no-reply@theeverbranch.com>', 'destination' => [$recipient],
        'tags' => ['everbranch_lane' => ['platform'], 'platform_delivery_id' => [(string) $delivery->id],
            'ses:configuration-set' => ['everbranch-platform']]]];

    return [$delivery, $event];
}

function platformAuditEnvelope(array $event, string $id = 'approved-platform-sns-fixture'): string
{
    return json_encode(['Type' => 'Notification', 'MessageId' => $id, 'Message' => json_encode($event, JSON_THROW_ON_ERROR),
        'TopicArn' => 'arn:aws:sns:us-east-1:894665396752:everbranch-ses-tenant-events', 'Timestamp' => now()->toIso8601String(),
        'SignatureVersion' => '2', 'Signature' => 'local-verifier-fixture-only',
        'SigningCertURL' => 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-fixture.pem'], JSON_THROW_ON_ERROR);
}

function callPlatformAuditWebhook(string $body, bool $valid = true): \Illuminate\Http\JsonResponse
{
    $request = Request::create('/webhooks/ses/events', 'POST', [], [], [], [], $body);
    $verifier = Mockery::mock(SesSnsVerifier::class);
    $verifier->shouldReceive('valid')->once()->andReturn($valid);
    $tenantEvents = Mockery::mock(SesDeliveryEvents::class);
    $tenantEvents->shouldNotReceive('handle');

    return app(SesWebhookController::class)->events($request, $verifier, $tenantEvents);
}

it('retains the exact verified simulator envelope encrypted and replays without another update', function (string $case): void {
    [$delivery, $event] = approvedPlatformAuditFixture($case);
    $body = platformAuditEnvelope($event);
    $key = PlatformSesSimulatorAudit::key('approved-platform-sns-fixture');
    $first = callPlatformAuditWebhook($body)->getData(true);
    $retained = Cache::get($key);
    expect($first)->toMatchArray(['ok' => true, 'matched' => 1, 'updated' => 1])
        ->and($retained)->toBeString()->not->toContain('simulator.amazonses.com', 'local-verifier-fixture-only')
        ->and(Crypt::decryptString($retained))->toBe($body)
        ->and(callPlatformAuditWebhook(Crypt::decryptString($retained))->getData(true))->toMatchArray(['matched' => 1, 'updated' => 0])
        ->and($delivery->fresh()->event_hashes)->toHaveCount(1)
        ->and(DB::table('tenant_messaging_ledger_entries')->count())->toBe(0);
})->with(['success', 'bounce', 'complaint']);

it('retains nothing before signature validation or when delivery context is unmatched', function (bool $valid): void {
    [$delivery, $event] = approvedPlatformAuditFixture();
    if ($valid) {
        data_set($event, 'mail.source', 'foreign@example.test');
    }
    $response = callPlatformAuditWebhook(platformAuditEnvelope($event), $valid);
    expect($response->getStatusCode())->toBe($valid ? 200 : 403)
        ->and(Cache::get(PlatformSesSimulatorAudit::key('approved-platform-sns-fixture')))->toBeNull()
        ->and($delivery->fresh()->event_hashes)->toBeNull();
})->with([true, false]);

it('does not retain ordinary platform mail sent to a simulator outside the approved logical intents', function (): void {
    [$delivery, $event] = approvedPlatformAuditFixture();
    DB::table('platform_email_messages')->where('id', $delivery->platform_email_message_id)
        ->update(['group_key' => hash('sha256', 'another-platform-intent')]);
    expect(callPlatformAuditWebhook(platformAuditEnvelope($event))->getData(true))->toMatchArray(['matched' => 1, 'updated' => 1])
        ->and(Cache::get(PlatformSesSimulatorAudit::key('approved-platform-sns-fixture')))->toBeNull();
});

it('requires already matched receipt evidence and the exact envelope before retention', function (): void {
    [$delivery, $event] = approvedPlatformAuditFixture();
    $audit = app(PlatformSesSimulatorAudit::class);
    $body = platformAuditEnvelope($event);
    expect($audit->remember($event, 'approved-platform-sns-fixture', $body))->toBeFalse();
    callPlatformAuditWebhook($body);
    $other = $event;
    data_set($other, 'mail.messageId', 'foreign-provider-receipt');
    expect($audit->remember($other, 'approved-platform-sns-fixture', platformAuditEnvelope($other)))->toBeFalse()
        ->and($audit->remember($event, 'different-sns-id', $body))->toBeFalse()
        ->and($audit->remember($event, 'approved-platform-sns-fixture', '{}'))->toBeFalse()
        ->and($audit->remember($event, 'approved-platform-sns-fixture', str_repeat('x', 262145)))->toBeFalse();
});

it('excludes owner-mailbox or foreign context even when an approved pilot record exists', function (): void {
    [$delivery, $event] = approvedPlatformAuditFixture();
    callPlatformAuditWebhook(platformAuditEnvelope($event));
    foreach (['mail.destination' => ['info@theeverbranch.com'], 'mail.tags.everbranch_lane' => ['tenant'],
        'mail.tags.ses:configuration-set' => ['everbranch-marketing'], 'mail.source' => 'foreign@example.test',
        'mail.tags.platform_delivery_id' => [(string) $delivery->id, '999']] as $path => $value) {
        $other = $event;
        data_set($other, $path, $value);
        expect(app(PlatformSesSimulatorAudit::class)->remember($other, 'approved-platform-sns-fixture', platformAuditEnvelope($other)))->toBeFalse();
    }
});

it('can retain a retried notification after cache failure without repeating the delivery update', function (): void {
    [$delivery, $event] = approvedPlatformAuditFixture();
    $body = platformAuditEnvelope($event);
    $cache = Cache::getFacadeRoot();
    Cache::shouldReceive('forever')->once()->andReturn(false);
    try {
        expect(fn () => callPlatformAuditWebhook($body))->toThrow(RuntimeException::class, 'could not be retained');
    } finally {
        Cache::swap($cache);
    }
    expect($delivery->fresh()->event_hashes)->toHaveCount(1)
        ->and(callPlatformAuditWebhook($body)->getData(true))->toMatchArray(['matched' => 1, 'updated' => 0])
        ->and(Crypt::decryptString(Cache::get(PlatformSesSimulatorAudit::key('approved-platform-sns-fixture'))))->toBe($body)
        ->and($delivery->fresh()->event_hashes)->toHaveCount(1);
});
