<?php

use App\Models\LandlordOperatorAction;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Marketing\CustomerOutreachDraft;
use App\Services\Marketing\CustomerOutreachPolicy;
use App\Services\Marketing\MarketingEmailExecutionService;
use App\Services\Marketing\MarketingEmailReadiness;
use App\Services\Marketing\MarketingTemplateRenderer;
use App\Services\Marketing\SendGridEmailService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function personalEmailFixture(): array
{
    $tenant = Tenant::create(['name' => 'Fictional email merchant', 'slug' => 'email-admission-'.Str::lower(Str::random(10))]);
    $owner = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $tenant->users()->attach($owner, ['role' => 'owner', 'membership_active' => true]);
    TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'campaigns', 'availability_status' => 'available',
        'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test']);
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Fictional <customer>',
        'email' => 'fictional-email@example.test', 'normalized_email' => 'fictional-email@example.test', 'accepts_email_marketing' => true]);
    $policies = app(CustomerOutreachPolicy::class);
    $rules = $policies->editor($tenant, $owner)['defaults'];
    $rules['delivery_history_verified_from'] = ['email' => '2026-01-01T00:00:00Z', 'sms' => '2026-01-01T00:00:00Z'];
    $rules['coverage_review_note'] = 'Fictional existing-delivery histories verified for source tests.';
    $policies->revise($tenant, $owner, ['request_id' => (string) Str::uuid(), 'expected_version' => 0,
        'publish' => true, 'reason' => 'Fictional reviewed individual outreach limits.', 'rules' => $rules]);
    $campaign = app(CustomerOutreachDraft::class)->save($tenant, $owner, $profile, [
        'request_id' => (string) Str::uuid(), 'channel' => 'email', 'subject' => 'Personal {{first_name}} note',
        'body' => 'Hi {{first_name}}, thanks for visiting.', 'heading' => 'For {{first_name}}',
        'button_label' => 'Visit our shop', 'button_url' => 'https://example.test/shop',
    ]);
    $recipient = $campaign->recipients()->firstOrFail();
    $recipient->update(['status' => 'approved', 'approved_by' => $owner->id, 'approved_at' => now()]);

    return [$tenant, $owner, $profile, $campaign, $recipient];
}

function personalEmailReadiness(?callable $beforeAdmission = null): void
{
    $readiness = \Mockery::mock(MarketingEmailReadiness::class);
    app()->instance(MarketingEmailReadiness::class, $readiness);
    $readiness->shouldReceive('providerContextForDelivery')->once()
        ->andReturnUsing(function (?int $tenantId) use ($beforeAdmission): array {
            if ($beforeAdmission) {
                $beforeAdmission();
            }

            return ['tenant_id' => $tenantId, 'provider' => 'sendgrid', 'readiness_status' => 'ready',
                'config_status' => 'configured', 'resolution_source' => 'fictional_test', 'using_fallback_config' => false];
        });
    $renderer = \Mockery::mock(MarketingTemplateRenderer::class);
    app()->instance(MarketingTemplateRenderer::class, $renderer);
    $renderer->shouldReceive('renderCampaignMessage')->andReturnUsing(fn ($campaign, $body) => $body);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-07T15:00:00Z'));
    config(['marketing.customer_profile.email_dispatch_enabled' => true, 'marketing.customer_profile.sms_dispatch_enabled' => true]);
    Http::preventStrayRequests();
});

test('closed personal email release gate preserves the approved draft without a sending row or provider call', function () {
    [$tenant, $owner, $profile, $campaign, $recipient] = personalEmailFixture();
    config(['marketing.customer_profile.email_dispatch_enabled' => false]);
    personalEmailReadiness();
    $this->mock(SendGridEmailService::class)->shouldNotReceive('sendEmail');
    $result = app(MarketingEmailExecutionService::class)->sendRecipient($recipient, ['tenant_id' => $tenant->id]);
    expect($result['outcome'])->toBe('skipped')->and($result['reason_codes'])->toContain('outreach_email_safety_review_required')
        ->and($recipient->fresh()->status)->toBe('approved')->and($recipient->fresh()->send_attempt_count)->toBe(0)
        ->and(MarketingEmailDelivery::count())->toBe(0)
        ->and(LandlordOperatorAction::where('action_type', 'customer_outreach_dispatch_decision')->first()->status)->toBe('blocked');
    Http::assertNothingSent();
});

test('personal email hands the exact frozen escaped HTML and text to the existing mocked sender once', function () {
    [$tenant, $owner, $profile, $campaign, $recipient] = personalEmailFixture();
    personalEmailReadiness();
    $this->mock(SendGridEmailService::class)->shouldReceive('sendEmail')->once()
        ->withArgs(function ($email, $subject, $body, $options) use ($tenant, $profile, $campaign): bool {
            expect($email)->toBe($profile->email)->and($subject)->toBe($campaign->message_subject)
                ->and($body)->toBe($campaign->message_body)->and($options['html_body'])->toBe($campaign->message_html)
                ->and($options['html_body'])->toContain('Fictional &lt;customer&gt;', 'https://example.test/shop')
                ->and($options['html_body'])->not->toContain('Fictional <customer>')
                ->and($options['tenant_id'])->toBe($tenant->id)->and($options['is_marketing'])->toBeTrue();

            return true;
        })->andReturn(['success' => true, 'provider' => 'sendgrid', 'message_id' => 'fictional-email-accepted-1',
            'status' => 'sent', 'payload' => [], 'dry_run' => false]);
    $result = app(MarketingEmailExecutionService::class)->sendRecipient($recipient, ['tenant_id' => $tenant->id]);
    $delivery = MarketingEmailDelivery::firstOrFail();
    expect($result['outcome'])->toBe('sent')->and($delivery->status)->toBe('sent')
        ->and($delivery->metadata['outreach_decision_audit_id'])->toBeInt()
        ->and($delivery->metadata['dry_run'])->toBeFalse()->and($recipient->fresh()->send_attempt_count)->toBe(1);
    Http::assertNothingSent();
});

test('personal email rechecks consent recipient approval and content after initial rendering', function (string $change) {
    [$tenant, $owner, $profile, $campaign, $recipient] = personalEmailFixture();
    personalEmailReadiness(function () use ($change, $profile, $campaign, $recipient) {
        match ($change) {
            'consent' => $profile->update(['accepts_email_marketing' => false, 'email_opted_out_at' => now()]),
            'recipient' => $recipient->update(['status' => 'rejected']),
            'html' => $campaign->update(['message_html' => $campaign->message_html.'<p>Changed after review</p>']),
            'valid_html_hash' => $campaign->update(['message_html' => $campaign->message_html.'<p>Rehashed after review</p>',
                'target_snapshot' => [...$campaign->target_snapshot, 'render_hash' => hash('sha256', json_encode([
                    $campaign->message_subject, $campaign->message_body, $campaign->message_html.'<p>Rehashed after review</p>',
                ], JSON_THROW_ON_ERROR))]]),
        };
    });
    $this->mock(SendGridEmailService::class)->shouldNotReceive('sendEmail');
    $result = app(MarketingEmailExecutionService::class)->sendRecipient($recipient, ['tenant_id' => $tenant->id]);
    $expected = ['consent' => 'email_not_consented_or_missing', 'recipient' => 'outreach_recipient_not_sendable',
        'html' => 'outreach_prepared_content_changed', 'valid_html_hash' => 'outreach_original_content_changed_or_missing'];
    expect($result['outcome'])->toBe('skipped')->and($result['reason_codes'])->toContain($expected[$change])
        ->and(MarketingEmailDelivery::count())->toBe(0);
    Http::assertNothingSent();
})->with(['consent', 'recipient', 'html', 'valid_html_hash']);

test('a pending existing SMS claim blocks personal email admission before the provider', function () {
    [$tenant, $owner, $profile, $campaign, $recipient] = personalEmailFixture();
    personalEmailReadiness(function () use ($tenant, $profile) {
        MarketingMessageDelivery::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'channel' => 'sms',
            'provider' => 'twilio', 'send_status' => 'sending', 'rendered_message' => 'Fictional pending prior claim']);
    });
    $this->mock(SendGridEmailService::class)->shouldNotReceive('sendEmail');
    $result = app(MarketingEmailExecutionService::class)->sendRecipient($recipient, ['tenant_id' => $tenant->id]);
    expect($result['outcome'])->toBe('skipped')->and($result['reason_codes'])->toContain('outreach_delivery_outcome_unresolved')
        ->and(MarketingEmailDelivery::count())->toBe(0);
    Http::assertNothingSent();
});

test('the outgoing personal HTML must match the immutable content validated under the admission lock', function () {
    [$tenant, $owner, $profile, $campaign, $recipient] = personalEmailFixture();
    $originalHtml = $campaign->message_html;
    $originalSnapshot = $campaign->target_snapshot;
    $editedHtml = $originalHtml.'<p>Unapproved earlier payload</p>';
    $campaign->update(['message_html' => $editedHtml, 'target_snapshot' => [...$originalSnapshot,
        'render_hash' => hash('sha256', json_encode([$campaign->message_subject, $campaign->message_body, $editedHtml], JSON_THROW_ON_ERROR))]]);
    personalEmailReadiness(fn () => $campaign->update(['message_html' => $originalHtml, 'target_snapshot' => $originalSnapshot]));
    $this->mock(SendGridEmailService::class)->shouldNotReceive('sendEmail');
    $result = app(MarketingEmailExecutionService::class)->sendRecipient($recipient, ['tenant_id' => $tenant->id]);
    expect($result['outcome'])->toBe('skipped')->and($result['reason_codes'])->toContain('outreach_prepared_content_changed')
        ->and(MarketingEmailDelivery::count())->toBe(0)->and($recipient->fresh()->status)->toBe('approved');
    Http::assertNothingSent();
});

test('personal email retains unknown acceptance in its original ledger and cannot retry it as a failed recipient', function (array $response) {
    [$tenant, $owner, $profile, $campaign, $recipient] = personalEmailFixture();
    personalEmailReadiness();
    $this->mock(SendGridEmailService::class)->shouldReceive('sendEmail')->once()->andReturn($response);
    $service = app(MarketingEmailExecutionService::class);
    $result = $service->sendApprovedForCampaign($campaign, ['tenant_id' => $tenant->id]);
    expect($result['unknown'])->toBe(1)->and($result['failed'])->toBe(0)->and($result['skipped'])->toBe(0)
        ->and(MarketingEmailDelivery::first()->status)->toBe('unknown')
        ->and(MarketingEmailDelivery::first()->sent_at)->toBeNull()->and($recipient->fresh()->status)->toBe('sending')
        ->and(app(CustomerOutreachPolicy::class)->decision($profile, 'sms')['reason_codes'])->toContain('outreach_delivery_outcome_unresolved');
    expect($service->sendRecipient($recipient->fresh(), ['tenant_id' => $tenant->id, 'force_retry' => true])['reason'])->toBe('status_not_sendable')
        ->and(MarketingEmailDelivery::count())->toBe(1);
    Http::assertNothingSent();
})->with([
    [['success' => false, 'provider' => 'sendgrid', 'message_id' => null, 'status' => 'failed', 'error_code' => 'provider_unreachable', 'retryable' => true, 'payload' => [], 'dry_run' => false]],
    [['success' => true, 'provider' => 'sendgrid', 'message_id' => null, 'status' => 'sent', 'payload' => [], 'dry_run' => false]],
]);

test('a provider-reported email simulation never becomes unknown acceptance or consumes a promotional cap', function () {
    [$tenant, $owner, $profile, $campaign, $recipient] = personalEmailFixture();
    personalEmailReadiness();
    $this->mock(SendGridEmailService::class)->shouldReceive('sendEmail')->once()->andReturn([
        'success' => true, 'provider' => 'ses_tenant', 'message_id' => null, 'status' => 'sent', 'payload' => [], 'dry_run' => true,
    ]);
    $result = app(MarketingEmailExecutionService::class)->sendRecipient($recipient, ['tenant_id' => $tenant->id]);
    expect($result['dry_run'])->toBeTrue()->and($result['outcome'])->not->toBe('unknown')
        ->and(MarketingEmailDelivery::first()->metadata['dry_run'])->toBeTrue()
        ->and(app(CustomerOutreachPolicy::class)->decision($profile, 'email')['allowed'])->toBeTrue();
    Http::assertNothingSent();
});
