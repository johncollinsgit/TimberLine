<?php

use App\Models\MarketingConsentEvent;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Marketing\CustomerOutreachDraft;
use App\Services\Marketing\CustomerOutreachPolicy;
use App\Services\Marketing\Email\Providers\SendGridEmailProvider;
use App\Services\Marketing\MarketingEmailExecutionService;
use App\Services\Marketing\MarketingEmailReadiness;
use App\Services\Marketing\MarketingSmsExecutionService;
use App\Services\Marketing\MarketingTemplateRenderer;
use App\Services\Marketing\MessageClickTrackingService;
use App\Services\Marketing\SendGridEmailService;
use App\Services\Marketing\TwilioProviderClient;
use App\Services\Marketing\TwilioSmsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-07T15:00:00Z'));
    config(['marketing.sms.enabled' => true, 'marketing.sms.dry_run' => false, 'marketing.twilio.enabled' => true,
        'marketing.twilio.account_sid' => 'AC'.str_repeat('a', 32), 'marketing.twilio.auth_token' => 'fictional-test-only',
        'marketing.twilio.messaging_service_sid' => 'MG'.str_repeat('b', 32),
        'marketing.customer_profile.email_dispatch_enabled' => true, 'marketing.customer_profile.sms_dispatch_enabled' => true]);
    Http::preventStrayRequests();
});

function retrySendGrid(array $message = []): array
{
    return app(SendGridEmailProvider::class)->sendEmail($message + ['to_email' => 'buyer@fictional.invalid', 'subject' => 'Fictional personal note', 'text' => 'Fictional note'],
        ['api_key' => 'fictional-test-only', 'from_email' => 'sender@fictional.invalid']);
}

function retryTwilio(array $options = []): array
{
    return app(TwilioProviderClient::class)->sendSms('+12025550123', 'Fictional personal note', $options);
}

function uncertainProviderTransport(string $outcome): object
{
    $calls = (object) ['requests' => []];
    Http::fake(function ($request) use ($outcome, $calls) {
        $calls->requests[] = $request;
        if ($outcome === 'timeout') {
            throw new ConnectionException('Fictional response lost after submission.');
        }

        return Http::response(['message' => 'Fictional provider uncertainty', 'code' => 30008, 'errors' => [['message' => 'Fictional uncertainty']]], (int) $outcome);
    });

    return $calls;
}

test('SendGrid message submission makes only one POST on uncertain transport outcome', function (string $outcome) {
    $calls = uncertainProviderTransport($outcome);
    expect(retrySendGrid()['success'])->toBeFalse();
    expect($calls->requests)->toHaveCount(1)->and($calls->requests[0]->method())->toBe('POST')
        ->and($calls->requests[0]->url())->toBe('https://api.sendgrid.com/v3/mail/send');
})->with(['timeout', '500', '503']);

test('Twilio message submission makes one POST and retains uncertainty independently of provider error codes', function (string $outcome) {
    $calls = uncertainProviderTransport($outcome);
    $result = retryTwilio();
    expect($result['success'])->toBeFalse()->and($result['status'])->toBe('unknown');
    expect($calls->requests)->toHaveCount(1);
})->with(['timeout', '500', '503']);

test('an uncertain MMS error cannot cause a second fallback SMS submission', function () {
    Http::fake(fn () => Http::response(['message' => 'MMS media service unavailable', 'code' => 21612], 503));
    $result = retryTwilio(['send_as_mms' => true]);
    expect($result['status'])->toBe('unknown');
    Http::assertSentCount(1);
});

test('a definitive MMS rejection without a message SID retains the existing explicit SMS fallback', function () {
    Http::fakeSequence()->push(['message' => 'MMS unsupported for destination', 'code' => 21612], 400)
        ->push(['sid' => 'SM'.str_repeat('c', 32), 'status' => 'queued'], 201);
    $result = retryTwilio(['send_as_mms' => true]);
    expect($result['success'])->toBeTrue()->and($result['delivery_mode'])->toBe('sms')->and($result['requested_delivery_mode'])->toBe('mms');
    Http::assertSentCount(2);
});

test('an MMS response with an original message SID cannot fall back to a new SMS', function () {
    Http::fake(fn () => Http::response(['message' => 'MMS media status requires review', 'code' => 21612, 'sid' => 'SM'.str_repeat('c', 32)], 400));
    expect(retryTwilio(['send_as_mms' => true])['success'])->toBeFalse();
    Http::assertSentCount(1);
});

test('SendGrid read-only account verification retains its successful GET retry', function () {
    Http::fakeSequence()->push([], 503)->push(['type' => 'paid'], 200);
    $result = app(SendGridEmailProvider::class)->validateConfiguration(['api_key' => 'fictional-test-only',
        'from_email' => 'sender@fictional.invalid', 'perform_live_check' => true]);
    expect($result['valid'])->toBeTrue();
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

test('SendGrid read-only verification also retries a connection failure without submitting mail', function () {
    $calls = (object) ['count' => 0];
    Http::fake(function ($request) use ($calls) {
        expect($request->method())->toBe('GET');
        if (++$calls->count === 1) {
            throw new ConnectionException('Fictional temporary read failure.');
        }

        return Http::response(['type' => 'paid'], 200);
    });
    expect(app(SendGridEmailProvider::class)->validateConfiguration(['api_key' => 'fictional-test-only', 'from_email' => 'sender@fictional.invalid'])['valid'])->toBeTrue();
    expect($calls->count)->toBe(2);
});

test('rate-limited message submissions do not automatically issue another POST', function () {
    Http::fake(fn () => Http::response(['code' => 20429, 'message' => 'Fictional rejected rate limit'], 429));
    expect(retrySendGrid()['success'])->toBeFalse()->and(retryTwilio()['success'])->toBeFalse();
    Http::assertSentCount(2);
});

function postRetryRecipient(string $channel): array
{
    $tenant = Tenant::create(['name' => 'Fictional transport merchant', 'slug' => 'transport-'.Str::lower(Str::random(10))]);
    $owner = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $tenant->users()->attach($owner, ['role' => 'owner', 'membership_active' => true]);
    TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'campaigns', 'availability_status' => 'available',
        'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'fictional_test']);
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Fictional buyer',
        'email' => 'buyer@fictional.invalid', 'normalized_email' => 'buyer@fictional.invalid', 'phone' => '+12025550123', 'normalized_phone' => '+12025550123',
        'accepts_email_marketing' => true, 'accepts_sms_marketing' => true]);
    $policy = app(CustomerOutreachPolicy::class);
    $rules = $policy->editor($tenant, $owner)['defaults'];
    $rules['delivery_history_verified_from'] = ['email' => '2026-01-01T00:00:00Z', 'sms' => '2026-01-01T00:00:00Z'];
    $rules['coverage_review_note'] = 'Fictional retained ledgers checked for this transport test.';
    $policy->revise($tenant, $owner, ['request_id' => (string) Str::uuid(), 'expected_version' => 0, 'publish' => true,
        'reason' => 'Fictional owner reviewed source-test limits.', 'rules' => $rules]);
    $campaign = app(CustomerOutreachDraft::class)->save($tenant, $owner, $profile, ['request_id' => (string) Str::uuid(), 'channel' => $channel,
        'subject' => 'Fictional personal note', 'body' => 'Fictional private message']);
    if ($channel === 'sms') {
        $campaign->update(['store_key' => 'fictional-shop']);
    }
    $recipient = $campaign->recipients()->firstOrFail();
    $recipient->update(['status' => 'approved', 'approved_by' => $owner->id, 'approved_at' => now()]);

    return [$tenant, $profile, $recipient];
}

test('an original personal delivery journal prevents resubmission after real provider transport uncertainty', function (string $channel, string $outcome) {
    [$tenant, $profile, $recipient] = postRetryRecipient($channel);
    $this->mock(MarketingTemplateRenderer::class)->shouldReceive('renderCampaignMessage')->andReturnUsing(fn ($campaign, $body) => $body);
    if ($channel === 'email') {
        $this->mock(MarketingEmailReadiness::class)->shouldReceive('providerContextForDelivery')->once()->andReturn([
            'tenant_id' => $tenant->id, 'provider' => 'sendgrid', 'readiness_status' => 'ready', 'config_status' => 'configured', 'resolution_source' => 'fictional_test']);
        // Keep routing/configuration faked; exercise the actual provider request and existing delivery journal.
        $this->mock(SendGridEmailService::class)->shouldReceive('sendEmail')->once()->andReturnUsing(fn ($to, $subject, $body, $options) => retrySendGrid([
            'to_email' => $to, 'subject' => $subject, 'text' => $body, 'html' => $options['html_body'] ?? null,
            'custom_args' => $options['custom_args'] ?? [], 'metadata' => $options['metadata'] ?? []]));
        $sender = app(MarketingEmailExecutionService::class);
    } else {
        $this->mock(MessageClickTrackingService::class)->shouldReceive('decorateSmsMessageForDelivery')->once()->andReturn(['message' => 'Fictional private message', 'links' => []]);
        $this->mock(TwilioSmsService::class)->shouldReceive('sendSms')->once()->andReturnUsing(fn ($to, $body, $options) => app(TwilioProviderClient::class)->sendSms($to, $body, $options));
        $sender = app(MarketingSmsExecutionService::class);
    }
    $calls = uncertainProviderTransport($outcome);
    $result = $sender->sendRecipient($recipient, ['tenant_id' => $tenant->id]);
    $this->assertSame('unknown', $result['outcome'], json_encode($result));
    expect($recipient->fresh()->status)->toBe('sending');
    $sender->sendRecipient($recipient->fresh(), ['tenant_id' => $tenant->id]);
    $sender->retryRecipient($recipient->fresh(), ['tenant_id' => $tenant->id]);
    expect($calls->requests)->toHaveCount(1);
    if ($channel === 'email') {
        expect($calls->requests[0]['personalizations'][0]['custom_args']['marketing_email_delivery_id'])->toBe((string) MarketingEmailDelivery::sole()->id);
    } else {
        expect($calls->requests[0]['Body'])->toBe('Fictional private message')->and($calls->requests[0]['StatusCallback'])->not->toBeEmpty();
    }
    expect($recipient->fresh()->send_attempt_count)->toBe(1)->and(MarketingConsentEvent::count())->toBe(0)
        ->and(MarketingEmailDelivery::count() + MarketingMessageDelivery::count())->toBe(1)
        ->and(app(CustomerOutreachPolicy::class)->decision($profile, $channel === 'email' ? 'sms' : 'email')['reason_codes'])->toContain('outreach_delivery_outcome_unresolved');
})->with([['email', 'timeout'], ['email', '503'], ['sms', 'timeout'], ['sms', '503']]);
