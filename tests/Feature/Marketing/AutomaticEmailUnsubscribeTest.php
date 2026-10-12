<?php

use App\Models\MarketingConsentEvent;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Marketing\Email\TenantEmailDispatchService;
use App\Services\Marketing\MarketingConsentService;
use App\Services\Marketing\MarketingEmailUnsubscribeService;
use App\Services\Marketing\Messaging\TenantMessagingGateway;
use App\Services\Marketing\MessagingContactChannelStateService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    config(['app.url' => 'http://localhost']);
    $this->tenant = Tenant::create(['name' => 'Shop One', 'slug' => 'unsubscribe-shop']);
    $this->otherTenant = Tenant::create(['name' => 'Shop Two', 'slug' => 'unsubscribe-other']);
    $this->profile = MarketingProfile::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'subscriber@example.com', 'normalized_email' => 'subscriber@example.com', 'accepts_email_marketing' => true, 'accepts_sms_marketing' => true]);
    $this->duplicate = MarketingProfile::factory()->create(['tenant_id' => $this->tenant->id, 'email' => ' SUBSCRIBER@example.com ', 'normalized_email' => null, 'accepts_email_marketing' => true]);
    $this->other = MarketingProfile::factory()->create(['tenant_id' => $this->otherTenant->id, 'email' => 'subscriber@example.com', 'normalized_email' => 'subscriber@example.com', 'accepts_email_marketing' => true]);
    $this->url = app(MarketingEmailUnsubscribeService::class)->url($this->tenant->id, $this->profile->email);
});

test('footer GET is harmless and one click POST opts out duplicates without login cookies or cross tenant effects', function (): void {
    $this->get($this->url)->assertOk()->assertSee('Stop marketing emails?');
    expect($this->profile->fresh()->accepts_email_marketing)->toBeTrue();
    $this->post($this->url, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertDontSee('sign-in')->assertHeader('Cache-Control');
    expect($this->profile->fresh()->accepts_email_marketing)->toBeFalse()
        ->and($this->duplicate->fresh()->accepts_email_marketing)->toBeFalse()
        ->and($this->other->fresh()->accepts_email_marketing)->toBeTrue()
        ->and($this->profile->fresh()->accepts_sms_marketing)->toBeTrue()
        ->and(MarketingConsentEvent::where('event_type', 'unsubscribed')->count())->toBe(2);
    $this->post($this->url, ['List-Unsubscribe' => 'One-Click'])->assertOk();
    expect(MarketingConsentEvent::where('event_type', 'unsubscribed')->count())->toBe(2);
});

test('browser confirmation opts out and invalid tokens cannot select a tenant or email', function (): void {
    $this->post($this->url, ['confirm' => '1'])->assertOk()->assertSee("You're unsubscribed.", false);
    $this->post('/email/unsubscribe?token=forged', ['List-Unsubscribe' => 'One-Click', 'tenant_id' => $this->otherTenant->id, 'email' => $this->other->email])->assertNotFound();
    expect($this->other->fresh()->accepts_email_marketing)->toBeTrue();
});

test('imports and reconciliation cannot resurrect a suppressed email but explicit reconsent can', function (): void {
    app(MessagingContactChannelStateService::class)->markEmailStatus($this->tenant->id, $this->profile, $this->profile->email, 'unsubscribed', 'test');
    $newDuplicate = MarketingProfile::factory()->create(['tenant_id' => $this->tenant->id, 'email' => $this->profile->email, 'normalized_email' => $this->profile->email, 'accepts_email_marketing' => true]);
    app(MarketingConsentService::class)->applyToProfile($newDuplicate, ['accepts_email_marketing' => true], ['legacy_import_reconciliation' => true]);
    expect($newDuplicate->fresh()->accepts_email_marketing)->toBeFalse();
    app(MarketingConsentService::class)->setEmailConsent($this->profile->fresh(), true);
    expect(app(MarketingEmailUnsubscribeService::class)->suppressed($this->tenant->id, $this->profile->email))->toBeFalse();
});

test('both sending boundaries block suppressed addresses before contacting providers', function (): void {
    Http::fake();
    app(MessagingContactChannelStateService::class)->markEmailStatus($this->tenant->id, $this->profile, $this->profile->email, 'unsubscribed', 'test');
    foreach ([TenantMessagingGateway::class, TenantEmailDispatchService::class] as $class) {
        $service = app($class);
        $result = $class === TenantMessagingGateway::class
            ? $service->sendEmail($this->tenant->id, $this->profile->email, 'Birthday', 'Body', ['campaign_type' => 'birthday'])
            : $service->sendEmail($this->profile->email, 'Birthday', 'Body', ['tenant_id' => $this->tenant->id, 'campaign_type' => 'birthday']);
        expect($result['error_code'])->toBe('email_unsubscribed');
    }
    Http::assertNothingSent();
});

test('prepared marketing messages contain working html plaintext and one click headers', function (): void {
    [$text, $options] = app(MarketingEmailUnsubscribeService::class)->prepare($this->tenant->id, $this->profile->email, 'Gift', ['html_body' => '<html><body><a href="mailto:info@theforestrystudio.com?subject=Unsubscribe">Unsubscribe</a></body></html>']);
    expect($text)->toContain('Unsubscribe: http://localhost/email/unsubscribe?token=')
        ->and($options['html_body'])->not->toContain('mailto:')
        ->and($options['headers']['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click');
    $target = trim($options['headers']['List-Unsubscribe'], '<>');
    $this->post($target, ['List-Unsubscribe' => 'One-Click'])->assertOk();
});

test('an existing historical optout suppresses a subscribed duplicate before the first new unsubscribe click', function (): void {
    $this->profile->update(['accepts_email_marketing' => false, 'email_opted_out_at' => now()->subYear()]);
    expect(app(MarketingEmailUnsubscribeService::class)->suppressed($this->tenant->id, $this->duplicate->email))->toBeTrue()
        ->and(app(MarketingEmailUnsubscribeService::class)->suppressed($this->otherTenant->id, $this->other->email))->toBeFalse();
});

test('inbound webhook fails closed without its secret and quoted unsubscribe footers do not opt out normal replies', function (): void {
    config(['marketing.messaging.responses.sendgrid_inbound_token' => null]);
    $this->postJson('/webhooks/sendgrid/inbound', ['from' => $this->profile->email, 'subject' => 'Unsubscribe'])->assertStatus(503);
    $classifier = new ReflectionMethod(\App\Services\Marketing\SendGridInboundEmailService::class, 'classify');
    $service = app(\App\Services\Marketing\SendGridInboundEmailService::class);
    expect($classifier->invoke($service, 'Re: Birthday gift', "Thanks for the gift!\nOn Monday, Modern Forestry wrote:\nUnsubscribe", [])['message_type'])->toBe('normal')
        ->and($classifier->invoke($service, 'Unsubscribe', '', [])['message_type'])->toBe('unsubscribe');
});

test('old email optout survives address changes without suppressing the new address or changing SMS', function (): void {
    $this->duplicate->delete();
    $states = app(MessagingContactChannelStateService::class);
    $states->markEmailStatus($this->tenant->id, $this->profile, $this->profile->email, 'subscribed', 'test');
    $states->markSmsSubscribed($this->tenant->id, $this->profile, '+15555550101', 'test');
    $this->profile->update(['email' => 'new@example.com', 'normalized_email' => 'new@example.com']);
    $states->markEmailStatus($this->tenant->id, $this->profile, 'subscriber@example.com', 'unsubscribed', 'old_message');
    expect($this->profile->fresh()->accepts_email_marketing)->toBeTrue()
        ->and($states->resolveEmailStatus($this->tenant->id, $this->profile, 'new@example.com'))->toBe('subscribed')
        ->and($states->resolveSmsStatus($this->tenant->id, $this->profile, '+15555550101'))->toBe('subscribed');
    $states->markEmailStatus($this->tenant->id, $this->profile, 'new@example.com', 'subscribed', 'new_consent');
    expect(app(MarketingEmailUnsubscribeService::class)->suppressed($this->tenant->id, 'subscriber@example.com'))->toBeTrue()
        ->and(app(MarketingEmailUnsubscribeService::class)->suppressed($this->tenant->id, 'new@example.com'))->toBeFalse();
});
