<?php

use App\Models\MarketingConsentEvent;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\MarketingSendApproval;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Marketing\CustomerOutreachDraft;
use App\Services\Marketing\CustomerOutreachExplanation;
use App\Services\Marketing\MarketingEmailExecutionService;
use App\Services\Marketing\MarketingSmsExecutionService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    $this->tenant = Tenant::create(['name' => 'Fictional review shop', 'slug' => 'review-'.Str::lower(Str::random(8)), 'status' => 'active']);
    $this->actor = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $this->actor->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    $this->profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Fictional', 'email' => 'review@example.invalid', 'normalized_email' => 'review@example.invalid',
        'phone' => '8645550101', 'normalized_phone' => '8645550101', 'accepts_email_marketing' => false, 'accepts_sms_marketing' => false]);
    TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'campaigns', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test']);
    $this->campaign = app(CustomerOutreachDraft::class)->save($this->tenant, $this->actor, $this->profile, ['request_id' => (string) Str::uuid(), 'channel' => 'email', 'subject' => 'A note for {{first_name}}', 'body' => 'Hello {{first_name}}.', 'heading' => 'Your note']);
    $this->url = fn ($profile = null, $campaign = null) => route('customers.outreach.review', ['tenant' => $this->tenant->slug, 'profile' => ($profile ?? $this->profile)->id, 'campaign' => ($campaign ?? $this->campaign)->id]);
    $this->actingAs($this->actor);
});

function customerReviewInput($test, string $action = 'approve'): array
{
    $review = $test->getJson(($test->url)())->assertOk()->json('review');

    return ['action' => $action, 'request_id' => (string) Str::uuid(), 'reviewed_content_hash' => $review['content_hash'], $action === 'approve' ? 'confirm_approval' : 'confirm_send' => true];
}

function customerReviewAllowHandoff(): void
{
    $real = app(CustomerOutreachExplanation::class);
    $fake = Mockery::mock($real)->makePartial();
    $fake->shouldReceive('dispatchDecision')->andReturnUsing(fn ($campaign, $profile) => ['tenant_id' => $profile->tenant_id, 'profile_id' => $profile->id, 'allowed' => true, 'reason_codes' => []]);
    app()->instance(CustomerOutreachExplanation::class, $fake);
}

test('tenant owner with global member role reviews and approves content without sending or granting consent', function () {
    $this->get(($this->url)())->assertOk()->assertSeeText('Review personal email draft')->assertSeeText('Approval reviews content')
        ->assertSee('sandbox=""', false)->assertDontSee('Send approved message');
    $command = customerReviewInput($this);
    $this->postJson(($this->url)(), $command)->assertOk()->assertJsonPath('action_result.outcome', 'approved')->assertJsonPath('review.recipient_status', 'approved');
    $this->postJson(($this->url)(), $command)->assertOk()->assertJsonPath('action_result.replayed', true);
    expect(MarketingSendApproval::count())->toBe(1)->and(MarketingEmailDelivery::count())->toBe(0)->and(MarketingMessageDelivery::count())->toBe(0)
        ->and(MarketingConsentEvent::count())->toBe(0)->and($this->profile->fresh()->accepts_email_marketing)->toBeFalse();
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('review rejects other tenant profile campaign source or nonmanager and preserves immutable content', function () {
    $foreignTenant = Tenant::create(['name' => 'Foreign review', 'slug' => 'foreign-review']);
    $foreign = MarketingProfile::create(['tenant_id' => $foreignTenant->id]);
    $this->getJson(($this->url)($foreign))->assertNotFound();
    $other = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    $this->getJson(($this->url)($other))->assertNotFound();
    $command = customerReviewInput($this);
    $this->campaign->update(['message_html' => '<p>Unreviewed replacement</p>']);
    $this->postJson(($this->url)(), $command)->assertConflict();
    expect(MarketingSendApproval::count())->toBe(0);
    $this->campaign->update(['source_label' => 'other_campaign']);
    $this->getJson(($this->url)())->assertNotFound();
    $member = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $member->tenants()->attach($this->tenant, ['role' => 'member', 'membership_active' => true]);
    $this->actingAs($member)->getJson(($this->url)())->assertForbidden();
});

test('review actions require explicit attestation and reject invalid commands before any approval or send', function () {
    $input = customerReviewInput($this);
    unset($input['confirm_approval']);
    $this->postJson(($this->url)(), $input)->assertUnprocessable()->assertJsonValidationErrors('confirm_approval');
    $send = customerReviewInput($this, 'send');
    unset($send['confirm_send']);
    $this->postJson(($this->url)(), $send)->assertUnprocessable()->assertJsonValidationErrors('confirm_send');
    $this->postJson(($this->url)(), [...$input, 'action' => 'force_retry'])->assertUnprocessable();
    expect(MarketingSendApproval::count())->toBe(0)->and(MarketingEmailDelivery::count())->toBe(0)->and(MarketingConsentEvent::count())->toBe(0);
});

test('default-off dispatch blockers prevent even an explicitly confirmed send from reaching a sender', function () {
    $this->postJson(($this->url)(), customerReviewInput($this))->assertOk();
    $sender = Mockery::mock(MarketingEmailExecutionService::class);
    $sender->shouldNotReceive('sendRecipient');
    app()->instance(MarketingEmailExecutionService::class, $sender);
    $input = customerReviewInput($this, 'send');
    $this->postJson(($this->url)(), $input)->assertOk()->assertJsonPath('action_result.outcome', 'blocked');
    $this->postJson(($this->url)(), $input)->assertOk()->assertJsonPath('action_result.replayed', true);
    expect(MarketingEmailDelivery::count())->toBe(0)->and(MarketingMessageDelivery::count())->toBe(0);
    Http::assertNothingSent();
});

test('explicit send hands off once to existing sender and replays preserve original unknown delivery', function () {
    $this->postJson(($this->url)(), customerReviewInput($this))->assertOk();
    customerReviewAllowHandoff();
    $sender = Mockery::mock(MarketingEmailExecutionService::class);
    $sender->shouldReceive('sendRecipient')->once()->andReturnUsing(function ($recipient, $options) {
        expect($options['tenant_id'])->toBe($this->tenant->id)->and($options['actor_id'])->toBe($this->actor->id)->and($options)->not->toHaveKey('force_retry');
        $delivery = MarketingEmailDelivery::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile->id, 'marketing_campaign_recipient_id' => $recipient->id,
            'email' => $this->profile->email, 'provider' => 'fixture', 'status' => 'unknown']);
        $recipient->update(['status' => 'sending', 'send_attempt_count' => 1]);

        return ['outcome' => 'unknown', 'reason' => 'provider_outcome_unconfirmed', 'delivery_id' => $delivery->id, 'dry_run' => false];
    });
    app()->instance(MarketingEmailExecutionService::class, $sender);
    $input = customerReviewInput($this, 'send');
    $this->postJson(($this->url)(), $input)->assertOk()->assertJsonPath('action_result.outcome', 'unknown')->assertJsonPath('review.send_handoff_unconfirmed', true);
    $this->postJson(($this->url)(), $input)->assertOk()->assertJsonPath('action_result.outcome', 'unknown')->assertJsonPath('action_result.replayed', true);
    $this->postJson(($this->url)(), [...$input, 'request_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('action_result.outcome', 'blocked');
    expect(MarketingEmailDelivery::count())->toBe(1)->and($this->campaign->recipients()->first()->status)->toBe('sending')->and(MarketingSendApproval::count())->toBe(1);
    Http::assertNothingSent();
});

test('interrupted handoff leaves a durable unknown reservation and cannot substitute another attempt', function () {
    $this->postJson(($this->url)(), customerReviewInput($this))->assertOk();
    customerReviewAllowHandoff();
    $sender = Mockery::mock(MarketingEmailExecutionService::class);
    $sender->shouldReceive('sendRecipient')->once()->andThrow(new RuntimeException('Fixture process interruption'));
    app()->instance(MarketingEmailExecutionService::class, $sender);
    $input = customerReviewInput($this, 'send');
    $this->postJson(($this->url)(), $input)->assertOk()->assertJsonPath('action_result.outcome', 'unknown');
    $this->postJson(($this->url)(), $input)->assertOk()->assertJsonPath('action_result.replayed', true);
    $this->postJson(($this->url)(), [...$input, 'request_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('action_result.outcome', 'blocked');
    expect(MarketingEmailDelivery::count())->toBe(0)->and($this->campaign->recipients()->first()->status)->toBe('approved');
});

test('approval cannot reset rejected sent sending failed or existing-delivery states', function () {
    foreach (['rejected', 'sent', 'sending', 'failed'] as $status) {
        $recipient = $this->campaign->recipients()->first();
        $recipient->update(['status' => $status]);
        $this->postJson(($this->url)(), customerReviewInput($this))->assertConflict();
        expect($recipient->fresh()->status)->toBe($status);
    }
    expect(MarketingSendApproval::count())->toBe(0);
});

test('review requires exactly one original recipient and variant and unchanged preparation evidence', function () {
    $input = customerReviewInput($this);
    $snapshot = $this->campaign->target_snapshot;
    $snapshot['explanation']['why_prepared'] = 'Changed after review';
    $this->campaign->update(['target_snapshot' => $snapshot]);
    $this->postJson(($this->url)(), $input)->assertConflict();
    $this->campaign->variants()->create(['name' => 'Extra', 'variant_key' => 'extra', 'message_text' => 'Other', 'status' => 'draft']);
    $this->getJson(($this->url)())->assertConflict();
});

test('web review commands retain CSRF protection outside the testing bypass', function () {
    $input = customerReviewInput($this);
    $request = Request::create(($this->url)(), 'POST', $input);
    $request->setLaravelSession(app('session.store'));
    $csrf = new class(app(), app('encrypter')) extends ValidateCsrfToken
    {
        protected function runningUnitTests()
        {
            return false;
        }
    };
    expect(fn () => $csrf->handle($request, fn () => response('should not execute')))->toThrow(\Illuminate\Session\TokenMismatchException::class);
    expect(MarketingSendApproval::count())->toBe(0)->and(MarketingEmailDelivery::count())->toBe(0);
});

test('review UUID cannot be reused for a different action and approval never clears an earlier rejection', function () {
    $approve = customerReviewInput($this);
    $this->postJson(($this->url)(), $approve)->assertOk();
    $this->postJson(($this->url)(), [...$approve, 'action' => 'send', 'confirm_send' => true])->assertConflict();
    $recipient = $this->campaign->recipients()->first();
    app(\App\Services\Marketing\MarketingApprovalService::class)->rejectRecipient($recipient, $this->actor->id, 'Fictional prior rejection');
    // A later unrelated status refresh cannot erase the original rejected approval authority.
    $recipient->update(['status' => 'skipped']);
    $this->postJson(($this->url)(), customerReviewInput($this))->assertConflict();
    expect($recipient->fresh()->status)->toBe('skipped')->and(MarketingSendApproval::count())->toBe(2);
});

test('SMS review uses the existing SMS sender once and preserves its original accepted delivery state', function () {
    $this->campaign = app(CustomerOutreachDraft::class)->save($this->tenant, $this->actor, $this->profile, ['request_id' => (string) Str::uuid(), 'channel' => 'sms', 'body' => 'Fictional reviewed text.']);
    $this->postJson(($this->url)(), customerReviewInput($this))->assertOk();
    customerReviewAllowHandoff();
    $sms = Mockery::mock(MarketingSmsExecutionService::class);
    $sms->shouldReceive('sendRecipient')->once()->andReturnUsing(function ($recipient, $options) {
        expect($options['tenant_id'])->toBe($this->tenant->id)->and($options)->not->toHaveKey('force_retry');
        $delivery = MarketingMessageDelivery::create(['tenant_id' => $this->tenant->id, 'campaign_id' => $this->campaign->id, 'campaign_recipient_id' => $recipient->id,
            'marketing_profile_id' => $this->profile->id, 'channel' => 'sms', 'provider' => 'fixture', 'to_phone' => $this->profile->phone, 'rendered_message' => 'Fictional reviewed text.', 'send_status' => 'sent', 'sent_at' => now()]);
        $recipient->update(['status' => 'sent', 'send_attempt_count' => 1]);

        return ['outcome' => 'sent', 'delivery_id' => $delivery->id, 'dry_run' => false];
    });
    app()->instance(MarketingSmsExecutionService::class, $sms);
    $email = Mockery::mock(MarketingEmailExecutionService::class);
    $email->shouldNotReceive('sendRecipient');
    app()->instance(MarketingEmailExecutionService::class, $email);
    $command = customerReviewInput($this, 'send');
    $this->postJson(($this->url)(), $command)->assertOk()->assertJsonPath('action_result.outcome', 'sent')->assertJsonPath('review.deliveries.0.status', 'sent');
    $this->postJson(($this->url)(), $command)->assertOk()->assertJsonPath('action_result.replayed', true);
    $this->postJson(($this->url)(), [...$command, 'request_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('action_result.outcome', 'blocked');
    expect(MarketingMessageDelivery::count())->toBe(1)->and(MarketingConsentEvent::count())->toBe(0)->and($this->profile->fresh()->accepts_sms_marketing)->toBeFalse();
    Http::assertNothingSent();
});

test('review mutations recheck membership and reject an extra recipient without source selection', function () {
    $command = customerReviewInput($this);
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    $this->postJson(($this->url)(), $command)->assertForbidden();
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => true]);
    $other = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    $this->campaign->recipients()->create(['marketing_profile_id' => $other->id, 'variant_id' => $this->campaign->variants()->first()->id, 'channel' => 'email', 'status' => 'draft']);
    $this->postJson(($this->url)(), [...$command, 'recipient_id' => $this->campaign->recipients()->first()->id])->assertConflict();
    expect(MarketingSendApproval::count())->toBe(0)->and(MarketingEmailDelivery::count())->toBe(0);
});

test('preference edits after preparation require a new draft instead of approving stale personalization', function () {
    $command = customerReviewInput($this);
    $preferences = app(\App\Services\Marketing\CustomerPreferenceService::class);
    $preferences->updateForStaff($this->profile, $this->tenant->id, $this->actor, ['request_id' => (string) Str::uuid(), 'expected_version' => $preferences->forStaff($this->profile, $this->tenant->id)['version'],
        'customer_confirmed' => true, 'changes' => ['favorite_scents' => ['Fictional changed declared scent']]]);
    $this->postJson(($this->url)(), $command)->assertConflict();
    $this->getJson(($this->url)())->assertOk()->assertJsonPath('review.can_approve', false);
    expect(MarketingSendApproval::count())->toBe(0)->and(MarketingEmailDelivery::count())->toBe(0)->and(MarketingConsentEvent::count())->toBe(0);
});

test('rehashed edited content cannot acquire approval against a different immutable preparation', function () {
    $editedHtml = $this->campaign->message_html.'<p>Edited after preparation</p>';
    $this->campaign->update(['message_html' => $editedHtml, 'target_snapshot' => [...$this->campaign->target_snapshot,
        'render_hash' => hash('sha256', json_encode([$this->campaign->message_subject, $this->campaign->message_body, $editedHtml], JSON_THROW_ON_ERROR))]]);
    $review = $this->getJson(($this->url)())->assertOk()->assertJsonPath('review.can_approve', false)->json('review');
    expect($review['integrity_blockers'])->toContain('outreach_original_content_changed_or_missing');
    $this->postJson(($this->url)(), ['action' => 'approve', 'request_id' => (string) Str::uuid(),
        'reviewed_content_hash' => $review['content_hash'], 'confirm_approval' => true])->assertConflict();
    expect(MarketingSendApproval::count())->toBe(0)->and(MarketingEmailDelivery::count())->toBe(0);
    Http::assertNothingSent();
});
