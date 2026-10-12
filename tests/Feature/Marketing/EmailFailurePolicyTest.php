<?php

use App\Models\MarketingEmailDelivery;
use App\Models\MarketingProfile;
use App\Models\MessagingContactChannelState;
use App\Models\Tenant;
use App\Services\Marketing\MarketingEmailFailurePolicy;
use App\Services\Marketing\MarketingEmailUnsubscribeService;

test('email failure handling distinguishes recipient problems from temporary and sender failures', function (array $event, ?string $status, string $category): void {
    $result = app(MarketingEmailFailurePolicy::class)->classify($event);
    expect($result['email_status'])->toBe($status)->and($result['category'])->toBe($category);
})->with([
    'hard bounce' => [['event' => 'bounce', 'type' => 'bounce', 'status' => '5.0.0'], 'bounced', 'hard_bounce'],
    'unknown user' => [['event' => 'bounce', 'status' => '5.1.1', 'reason' => '550 NoSuchUser'], 'bounced', 'invalid_recipient'],
    'disabled mailbox' => [['event' => 'bounce', 'status' => '5.2.1'], 'bounced', 'invalid_recipient'],
    'invalid classification' => [['event' => 'bounce', 'type' => 'blocked', 'bounce_classification' => 'Invalid Address'], 'bounced', 'invalid_recipient'],
    'provider prior bounce' => [['event' => 'dropped', 'reason' => 'Bounced Address'], 'bounced', 'previous_hard_bounce'],
    'invalid drop' => [['event' => 'dropped', 'reason' => 'Invalid'], 'bounced', 'invalid_recipient'],
    'provider unsubscribe' => [['event' => 'dropped', 'reason' => 'Unsubscribed Address'], 'unsubscribed', 'unsubscribe'],
    'provider spam report' => [['event' => 'dropped', 'reason' => 'Spam Reporting Address'], 'suppressed', 'spam_complaint'],
    'spam complaint' => [['event' => 'spamreport'], 'suppressed', 'spam_complaint'],
    'unsubscribe' => [['event' => 'group_unsubscribe'], 'unsubscribed', 'unsubscribe'],
    'deferred' => [['event' => 'deferred', 'status' => '4.2.2'], null, 'temporary'],
    'mailbox full' => [['event' => 'bounce', 'type' => 'bounce', 'status' => '5.2.2'], null, 'temporary'],
    'temporary block' => [['event' => 'bounce', 'type' => 'blocked', 'reason' => 'Try again later'], null, 'temporary'],
    'reputation' => [['event' => 'bounce', 'type' => 'bounce', 'status' => '5.7.1'], null, 'sender_or_provider'],
    'sender failure' => [['event' => 'dropped', 'reason' => 'Sender identity not verified'], null, 'sender_or_provider'],
    'invalid header' => [['event' => 'dropped', 'reason' => 'Invalid SMTPAPI header'], null, 'sender_or_provider'],
    'content blocked' => [['event' => 'dropped', 'reason' => 'Spam Content'], null, 'sender_or_provider'],
    'package limit' => [['event' => 'dropped', 'reason' => 'Recipient List over Package Quota'], null, 'sender_or_provider'],
    'rate limit' => [['event' => 'send_failure', 'error_code' => 'rate_limited', 'error_message' => 'Rate limit reached'], null, 'temporary'],
    'unknown failure' => [['event' => 'dropped'], null, 'needs_review'],
]);

test('signed event ingestion stops hard bounced duplicate addresses without changing another tenant or SMS', function (): void {
    $tenant = Tenant::create(['name' => 'Forestry', 'slug' => 'failure-forestry']);
    $other = Tenant::create(['name' => 'Other', 'slug' => 'failure-other']);
    $profiles = MarketingProfile::factory()->count(2)->create(['tenant_id' => $tenant->id, 'email' => 'failure@example.com', 'normalized_email' => 'failure@example.com', 'accepts_email_marketing' => true, 'accepts_sms_marketing' => true]);
    $otherProfile = MarketingProfile::factory()->create(['tenant_id' => $other->id, 'email' => 'failure@example.com', 'accepts_email_marketing' => true]);
    $delivery = MarketingEmailDelivery::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profiles[0]->id, 'email' => 'failure@example.com', 'campaign_type' => 'birthday', 'provider' => 'sendgrid', 'status' => 'sent', 'sent_at' => now()]);
    // SendGrid merges custom args into the top level of its real webhook payload.
    $event = ['event' => 'bounce', 'type' => 'bounce', 'status' => '5.1.1', 'reason' => '550 User unknown', 'email' => $delivery->email, 'marketing_email_delivery_id' => (string) $delivery->id, 'tenant_id' => (string) $tenant->id, 'timestamp' => now()->timestamp, 'sg_event_id' => 'hard-bounce-1'];
    $this->postJson(route('marketing.webhooks.sendgrid-events'), [$event])->assertOk()->assertJson(['matched' => 1, 'updated' => 1]);
    $this->postJson(route('marketing.webhooks.sendgrid-events'), [$event])->assertOk()->assertJson(['duplicates' => 1]);
    foreach ($profiles as $profile) {
        expect($profile->fresh()->accepts_email_marketing)->toBeFalse()->and($profile->fresh()->accepts_sms_marketing)->toBeTrue();
    }
    expect($otherProfile->fresh()->accepts_email_marketing)->toBeTrue()
        ->and(app(MarketingEmailUnsubscribeService::class)->suppressed($tenant->id, $delivery->email))->toBeTrue()
        ->and(data_get($delivery->fresh()->metadata, 'failure_policy.category'))->toBe('invalid_recipient')
        ->and(data_get($delivery->fresh()->raw_payload, 'events.0.reason'))->toBe('550 User unknown');
});

test('temporary bounce preserves consent and a later delivery receipt can recover its status', function (): void {
    $tenant = Tenant::create(['name' => 'Temporary', 'slug' => 'failure-temporary']);
    $profile = MarketingProfile::factory()->create(['tenant_id' => $tenant->id, 'accepts_email_marketing' => true]);
    $delivery = MarketingEmailDelivery::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'email' => $profile->email, 'campaign_type' => 'birthday', 'status' => 'sent']);
    $event = ['event' => 'bounce', 'type' => 'blocked', 'status' => '5.2.2', 'reason' => 'Mailbox full', 'marketing_email_delivery_id' => (string) $delivery->id, 'timestamp' => now()->timestamp];
    $this->postJson(route('marketing.webhooks.sendgrid-events'), [$event])->assertOk();
    expect($profile->fresh()->accepts_email_marketing)->toBeTrue()->and(app(MarketingEmailUnsubscribeService::class)->suppressed($tenant->id, $profile->email))->toBeFalse();
    $this->postJson(route('marketing.webhooks.sendgrid-events'), [[...$event, 'event' => 'delivered', 'timestamp' => now()->addMinute()->timestamp]])->assertOk();
    expect($delivery->fresh()->status)->toBe('delivered')->and($delivery->fresh()->delivered_at)->not->toBeNull();
});

test('hard bounce cannot replace an unsubscribe and dry runs do not alter consent', function (): void {
    $tenant = Tenant::create(['name' => 'Consent', 'slug' => 'failure-consent']);
    $profile = MarketingProfile::factory()->create(['tenant_id' => $tenant->id, 'accepts_email_marketing' => true]);
    $policy = app(MarketingEmailFailurePolicy::class);
    $result = ['success' => false, 'error_code' => 'invalid_recipient', 'error_message' => 'Invalid recipient'];
    $policy->applySendResult($tenant->id, $profile->email, $result, ['dry_run' => true]);
    expect($profile->fresh()->accepts_email_marketing)->toBeTrue();
    app(\App\Services\Marketing\MessagingContactChannelStateService::class)->markEmailStatus($tenant->id, $profile, $profile->email, 'unsubscribed', 'unsubscribe_link');
    $delivery = MarketingEmailDelivery::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'email' => $profile->email]);
    $policy->apply($delivery, ['event' => 'bounce', 'type' => 'bounce'], now());
    expect(MessagingContactChannelState::where('email', $profile->email)->value('email_status'))->toBe('unsubscribed');
});

test('event callbacks require a valid signature and a matching recipient before suppression', function (): void {
    $tenant = Tenant::create(['name' => 'Signed', 'slug' => 'failure-signed']);
    $profile = MarketingProfile::factory()->create(['tenant_id' => $tenant->id, 'accepts_email_marketing' => true]);
    $delivery = MarketingEmailDelivery::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'email' => $profile->email, 'status' => 'sent']);
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    config(['marketing.messaging.responses.verify_sendgrid_event_signature' => true, 'marketing.messaging.responses.sendgrid_event_public_key' => openssl_pkey_get_details($key)['key']]);
    $event = ['event' => 'bounce', 'type' => 'bounce', 'email' => 'different@example.com', 'marketing_email_delivery_id' => (string) $delivery->id, 'timestamp' => now()->timestamp];
    $body = json_encode([$event]);
    $timestamp = (string) now()->timestamp;
    $this->call('POST', route('marketing.webhooks.sendgrid-events'), [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertForbidden();
    openssl_sign($timestamp.$body, $signature, $key, OPENSSL_ALGO_SHA256);
    $this->call('POST', route('marketing.webhooks.sendgrid-events'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE' => base64_encode($signature), 'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP' => $timestamp], $body)->assertOk()->assertJson(['unmatched' => 1]);
    expect($profile->fresh()->accepts_email_marketing)->toBeTrue();
    $body = json_encode([[...$event, 'email' => $profile->email]]);
    openssl_sign($timestamp.$body, $signature, $key, OPENSSL_ALGO_SHA256);
    $this->call('POST', route('marketing.webhooks.sendgrid-events'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE' => base64_encode($signature), 'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP' => $timestamp], $body)->assertOk()->assertJson(['matched' => 1]);
    expect($profile->fresh()->accepts_email_marketing)->toBeFalse();
});
