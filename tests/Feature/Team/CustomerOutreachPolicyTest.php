<?php

use App\Models\LandlordOperatorAction;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\MarketingSetting;
use App\Models\MessagingContactChannelState;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\CustomerOutreachExplanation;
use App\Services\Marketing\CustomerOutreachPolicy;
use App\Services\Marketing\CustomerPreferenceService;
use App\Services\Marketing\MarketingSmsExecutionService;
use App\Services\Marketing\MarketingTemplateRenderer;
use App\Services\Marketing\MessageClickTrackingService;
use App\Services\Marketing\TwilioSmsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/CustomerProfileJson.php';

function outreachWorkspace(): array
{
    $tenant = Tenant::create(['name' => 'Fictional outreach merchant', 'slug' => 'outreach-'.Str::random(10)]);
    $owner = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $tenant->users()->attach($owner->id, ['role' => 'owner', 'membership_active' => true]);
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Fictional customer',
        'email' => 'fictional@example.test', 'normalized_email' => 'fictional@example.test',
        'phone' => '+15552223344', 'normalized_phone' => '+15552223344',
        'accepts_email_marketing' => true, 'accepts_sms_marketing' => true]);

    return [$tenant, $owner, $profile];
}

function outreachRevision(Tenant $tenant, User $owner, array $overrides = [], bool $publish = true): array
{
    $service = app(CustomerOutreachPolicy::class);
    $editor = $service->editor($tenant, $owner);
    $rules = array_replace($editor['defaults'], ['timezone' => 'America/New_York',
        'delivery_history_verified_from' => ['email' => '2026-01-01T00:00:00Z', 'sms' => '2026-01-01T00:00:00Z'],
        'coverage_review_note' => 'Fictional retained-ledger coverage reviewed for both channels.'], $overrides);

    return $service->revise($tenant, $owner, ['request_id' => (string) Str::uuid(), 'expected_version' => $editor['version'],
        'publish' => $publish, 'reason' => 'Fictional owner reviewed appropriate limits.', 'rules' => $rules]);
}

function outreachEmail(MarketingProfile $profile, array $overrides = []): MarketingEmailDelivery
{
    return MarketingEmailDelivery::create(array_replace(['tenant_id' => $profile->tenant_id, 'marketing_profile_id' => $profile->id,
        'provider' => 'sendgrid', 'email' => $profile->email, 'status' => 'sent', 'sent_at' => now()->subHour(),
        'source_label' => 'marketing_campaign', 'created_at' => now()->subHours(2)], $overrides));
}

function outreachSms(MarketingProfile $profile, array $overrides = []): MarketingMessageDelivery
{
    return MarketingMessageDelivery::create(array_replace(['tenant_id' => $profile->tenant_id, 'marketing_profile_id' => $profile->id,
        'provider' => 'twilio', 'to_phone' => $profile->phone, 'channel' => 'sms', 'send_status' => 'sent',
        'rendered_message' => 'Fictional promo', 'sent_at' => now()->subHour(), 'source_label' => 'marketing_campaign',
        'created_at' => now()->subHours(2)], $overrides));
}

function outreachPrepared(MarketingProfile $profile, string $channel = 'email', array $variables = ['first_name' => 'Fictional private name']): MarketingCampaign
{
    $campaign = MarketingCampaign::create(['tenant_id' => $profile->tenant_id, 'name' => 'Fictional individual draft',
        'slug' => 'fictional-draft-'.Str::uuid(), 'status' => 'draft', 'channel' => $channel, 'objective' => 'retention',
        'source_label' => 'customer_profile', 'message_body' => 'Fictional private message',
        'target_snapshot' => ['profile_id' => $profile->id,
            'text_hash' => hash('sha256', json_encode([$profile->tenant_id, $profile->id, $channel, 'Fictional private message'], JSON_THROW_ON_ERROR))]]);
    $decision = app(CustomerOutreachPolicy::class)->decision($profile, $channel);
    $explanation = app(CustomerOutreachExplanation::class)->build($profile, [
        'coverage' => 'explicitly_linked_records_only', 'has_more' => true, 'issues' => [], 'confirmed_purchase_count' => 1,
        'behavior' => ['partial' => true, 'missing_item_purchase_count' => 1, 'units_without_reviewed_scent' => 2, 'units_without_product_identity' => 1],
        'purchases' => [['id' => 'order:fictional-1', 'source' => 'order', 'source_id' => 'fictional-1',
            'confirmed' => true, 'occurred_at' => now()->subMonth()->toIso8601String()]],
    ], $variables, $decision);
    $auditId = app(CustomerOutreachExplanation::class)->recordPreparation($campaign, $profile, $explanation, null);
    $campaign->update(['target_snapshot' => [...$campaign->target_snapshot, 'explanation' => $explanation, 'preparation_audit_id' => $auditId]]);

    return $campaign->fresh();
}

function outreachApprovedSms(MarketingProfile $profile): MarketingCampaignRecipient
{
    $campaign = outreachPrepared($profile, 'sms');
    $campaign->update(['store_key' => 'fictional-shop']);
    $variant = $campaign->variants()->create(['name' => 'Fictional frozen variant', 'variant_key' => 'personal_note',
        'message_text' => $campaign->message_body, 'weight' => 100, 'is_control' => true, 'status' => 'draft']);

    return $campaign->recipients()->create(['marketing_profile_id' => $profile->id, 'variant_id' => $variant->id,
        'channel' => 'sms', 'status' => 'approved']);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-07T15:00:00Z'));
    config(['marketing.customer_profile.sms_dispatch_enabled' => true, 'marketing.customer_profile.email_dispatch_enabled' => true]);
    Http::preventStrayRequests();
});

test('reviewing a tenant policy cannot enable personalized SMS through the independently closed transport gate', function () {
    [$tenant, $owner, $profile] = outreachWorkspace();
    outreachRevision($tenant, $owner);
    config(['marketing.customer_profile.sms_dispatch_enabled' => false]);
    $decision = app(CustomerOutreachPolicy::class)->decision($profile, 'sms');
    expect($decision['allowed'])->toBeFalse()->and($decision['reason_codes'])->toContain('outreach_sms_safety_review_required')
        ->and($decision['policy']['status'])->toBe('published');
    Http::assertNothingSent();
});

test('reviewing a tenant policy cannot enable personalized email through the independently closed transport gate', function () {
    [$tenant, $owner, $profile] = outreachWorkspace();
    outreachRevision($tenant, $owner);
    config(['marketing.customer_profile.email_dispatch_enabled' => false]);
    $decision = app(CustomerOutreachPolicy::class)->decision($profile, 'email');
    expect($decision['allowed'])->toBeFalse()->and($decision['reason_codes'])->toContain('outreach_email_safety_review_required')
        ->and($decision['policy']['status'])->toBe('published');
    Http::assertNothingSent();
});

test('new personalized outreach blocks legacy Square inferred consent until current contact permission is proven', function () {
    [$tenant, $owner, $profile] = outreachWorkspace();
    outreachRevision($tenant, $owner);
    $alias = MarketingProfile::create(['tenant_id' => $tenant->id, 'merged_into_profile_id' => $profile->id]);
    $event = ['tenant_id' => $tenant->id, 'marketing_profile_id' => $alias->id, 'channel' => 'email'];
    MarketingConsentEvent::create($event + ['event_type' => 'confirmed', 'source_type' => 'verified_customer_choice',
        'details' => ['email' => $profile->email], 'occurred_at' => now()->subDays(3)]);
    MarketingConsentEvent::create($event + ['event_type' => 'revoked', 'source_type' => 'customer_choice', 'occurred_at' => now()->subDays(2)]);
    MarketingConsentEvent::create($event + ['event_type' => 'imported', 'source_type' => 'square_customer_sync', 'occurred_at' => now()->subDay()]);
    $decision = app(CustomerOutreachPolicy::class)->decision($profile, 'email');
    expect($decision['allowed'])->toBeFalse()->and($decision['reason_codes'])->toContain('outreach_square_consent_requires_review')
        ->and($profile->fresh()->accepts_email_marketing)->toBeTrue();
    MarketingConsentEvent::create($event + ['event_type' => 'confirmed', 'source_type' => 'verified_customer_choice',
        'details' => ['email' => 'different-contact@example.test'], 'occurred_at' => now()]);
    expect(app(CustomerOutreachPolicy::class)->decision($profile, 'email')['reason_codes'])->toContain('outreach_square_consent_requires_review');
    MarketingConsentEvent::create($event + ['event_type' => 'confirmed', 'source_type' => 'verified_customer_choice',
        'details' => ['email' => $profile->email], 'occurred_at' => now()]);
    expect(app(CustomerOutreachPolicy::class)->decision($profile, 'email')['allowed'])->toBeTrue();
    Http::assertNothingSent();
});

test('publication requires a reason and explicit retained-history evidence for both channels', function () {
    [$tenant, $owner] = outreachWorkspace();
    $service = app(CustomerOutreachPolicy::class);
    $input = ['request_id' => (string) Str::uuid(), 'expected_version' => 0, 'publish' => true,
        'reason' => 'Fictional owner review.', 'rules' => $service->editor($tenant, $owner)['defaults']];
    expect(fn () => $service->revise($tenant, $owner, $input))->toThrow(\Illuminate\Validation\ValidationException::class)
        ->and($service->policySnapshot($tenant->id)['status'])->toBe('unreviewed')
        ->and(LandlordOperatorAction::where('action_type', 'customer_outreach_policy_revision')->count())->toBe(0);
});

test('unreviewed tenant limits never inherit a global policy or turn unknown history into zero deliveries', function () {
    [$tenant, $owner, $profile] = outreachWorkspace();
    MarketingSetting::create(['key' => CustomerOutreachPolicy::KEY, 'value' => ['status' => 'published', 'version' => 100]]);
    $result = app(CustomerOutreachPolicy::class)->decision($profile, 'email');
    expect($result['allowed'])->toBeFalse()->and($result['policy']['version'])->toBe(0)
        ->and($result['reason_codes'])->toContain('outreach_policy_unreviewed', 'outreach_email_history_unavailable', 'outreach_sms_history_unavailable')
        ->and($result['delivery_evidence']['daily_count'])->toBeNull()->and($result['delivery_evidence']['observed_daily_count'])->toBe(0);
    outreachRevision($tenant, $owner, [], false);
    expect(app(CustomerOutreachPolicy::class)->policySnapshot($tenant->id)['status'])->toBe('unreviewed');
    Http::assertNothingSent();
});

test('only active tenant owners and admins revise limits with immutable audited replay and stale edit rejection', function () {
    [$tenant, $owner] = outreachWorkspace();
    [$other, $foreign] = outreachWorkspace();
    $service = app(CustomerOutreachPolicy::class);
    expect(fn () => $service->editor($tenant, $foreign))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $input = ['request_id' => (string) Str::uuid(), 'expected_version' => 0, 'publish' => false,
        'reason' => 'Fictional tenant-specific draft review.', 'rules' => $service->editor($tenant, $owner)['defaults']];
    $first = $service->revise($tenant, $owner, $input);
    expect(customerProfileJsonValue($service->revise($tenant, $owner, $input)))->toBe(customerProfileJsonValue($first))
        ->and(LandlordOperatorAction::where('action_type', 'customer_outreach_policy_revision')->count())->toBe(1);
    expect(fn () => $service->revise($tenant, $owner, array_replace($input, ['reason' => 'Changed content under the same request.'])))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $service->revise($tenant, $owner, array_replace($input, ['request_id' => (string) Str::uuid()])))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    outreachRevision($tenant, $owner, ['daily_cap' => 2]);
    expect($service->policySnapshot($tenant->id)['version'])->toBe(2)->and($service->policySnapshot($other->id)['version'])->toBe(0);
    $entry = LandlordOperatorAction::where('action_type', 'customer_outreach_policy_revision')->first();
    expect(customerProfileJsonValue($entry->result))->toBe(customerProfileJsonValue($first))->and(fn () => $entry->update(['status' => 'changed']))->toThrow(\RuntimeException::class);
});

test('cross-channel caps include accepted legacy campaigns and explicit aliases but isolate another tenant', function () {
    [$tenant, $owner, $profile] = outreachWorkspace();
    [$other, $foreign, $foreignProfile] = outreachWorkspace();
    outreachRevision($tenant, $owner);
    outreachSms($foreignProfile);
    outreachEmail($profile, ['tenant_id' => $other->id]); // Malformed cross-tenant reference remains excluded.
    expect(app(CustomerOutreachPolicy::class)->decision($profile, 'email')['allowed'])->toBeTrue();
    $alias = MarketingProfile::create(['tenant_id' => $tenant->id, 'merged_into_profile_id' => $profile->id]);
    outreachSms($alias);
    $decision = app(CustomerOutreachPolicy::class)->decision($profile, 'email');
    expect($decision['allowed'])->toBeFalse()->and($decision['reason_codes'])->toContain('outreach_daily_cap', 'outreach_cross_channel_cooldown')
        ->and($decision['delivery_evidence']['daily_count'])->toBe(1)->and($decision['delivery_evidence']['records'][0]['profile_id'])->toBe($alias->id);
});

test('failed and skipped attempts and transactional receipts do not consume promotional caps', function () {
    [$tenant, $owner, $profile] = outreachWorkspace();
    outreachRevision($tenant, $owner);
    outreachEmail($profile, ['status' => 'failed', 'sent_at' => null]);
    outreachSms($profile, ['send_status' => 'skipped', 'sent_at' => null]);
    outreachEmail($profile, ['source_label' => 'pos_receipt', 'campaign_type' => 'receipt']);
    outreachSms($profile, ['provider_payload' => ['message_type' => 'transactional']]);
    outreachEmail($profile, ['raw_payload' => ['payload' => ['dry_run' => true]]]);
    $result = app(CustomerOutreachPolicy::class)->decision($profile, 'email');
    expect($result['allowed'])->toBeTrue()->and($result['delivery_evidence']['daily_count'])->toBe(0)
        ->and($result['delivery_evidence']['transactional_receipts_excluded'])->toBe(2);
    outreachEmail($profile, ['status' => 'failed']); // Accepted first, then a downstream failure still represents outreach.
    expect(app(CustomerOutreachPolicy::class)->decision($profile, 'sms')['reason_codes'])->toContain('outreach_daily_cap');
});

test('an unresolved existing attempt blocks both channels even when its outcome predates the cap window', function (string $channel, string $status) {
    [$tenant, $owner, $profile] = outreachWorkspace();
    outreachRevision($tenant, $owner);
    if ($channel === 'email') {
        outreachEmail($profile, ['status' => $status, 'sent_at' => null, 'created_at' => now()->subMonth()]);
    } else {
        outreachSms($profile, ['send_status' => $status, 'sent_at' => null, 'created_at' => now()->subMonth()]);
    }
    expect(app(CustomerOutreachPolicy::class)->decision($profile, $channel === 'email' ? 'sms' : 'email')['reason_codes'])
        ->toContain('outreach_delivery_outcome_unresolved');
})->with([['email', 'unknown'], ['email', 'sending'], ['sms', 'queued'], ['sms', 'sending']]);

test('daily boundaries follow the reviewed tenant timezone and rolling seven-day boundaries use elapsed UTC time', function () {
    [$tenant, $owner, $profile] = outreachWorkspace();
    outreachRevision($tenant, $owner, ['cross_channel_cooldown_minutes' => 0, 'email_cooldown_minutes' => 0, 'sms_cooldown_minutes' => 0]);
    $this->travelTo(CarbonImmutable::parse('2026-11-01T05:30:00Z')); // First 01:30 during DST fall-back.
    outreachEmail($profile, ['sent_at' => '2026-11-01T03:59:59Z', 'created_at' => '2026-11-01T03:00:00Z']);
    $service = app(CustomerOutreachPolicy::class);
    expect($service->decision($profile, 'sms')['delivery_evidence']['day_starts_at'])->toBe('2026-11-01T04:00:00+00:00')
        ->and($service->decision($profile, 'sms')['allowed'])->toBeTrue();
    outreachEmail($profile, ['sent_at' => '2026-11-01T04:00:00Z', 'created_at' => '2026-11-01T04:00:00Z']);
    expect($service->decision($profile, 'sms')['reason_codes'])->toContain('outreach_daily_cap');
    $this->travelTo(CarbonImmutable::parse('2026-11-08T04:00:00Z')); // Seven elapsed days, despite time-zone clock change.
    $result = $service->decision($profile, 'sms', CarbonImmutable::parse('2026-11-07T23:00:00-05:00'));
    expect($result['delivery_evidence']['rolling_7_days_starts_at'])->toBe('2026-11-01T04:00:00+00:00')
        ->and($result['delivery_evidence']['rolling_7_day_count'])->toBe(0);
});

test('cooldowns permit exactly the elapsed boundary and enforce channel caps independently', function () {
    [$tenant, $owner, $profile] = outreachWorkspace();
    outreachRevision($tenant, $owner, ['daily_cap' => 10, 'rolling_7_day_cap' => 20,
        'email_daily_cap' => 10, 'sms_daily_cap' => 0, 'cross_channel_cooldown_minutes' => 60,
        'email_cooldown_minutes' => 120, 'sms_cooldown_minutes' => 120]);
    outreachEmail($profile, ['sent_at' => now()->subMinutes(60)]);
    $result = app(CustomerOutreachPolicy::class)->decision($profile, 'email');
    expect($result['reason_codes'])->not->toContain('outreach_cross_channel_cooldown')
        ->and($result['reason_codes'])->toContain('outreach_email_cooldown');
    expect(app(CustomerOutreachPolicy::class)->decision($profile, 'sms')['reason_codes'])->toContain('outreach_sms_daily_cap');
    $this->travel(60)->minutes();
    expect(app(CustomerOutreachPolicy::class)->decision($profile, 'email')['allowed'])->toBeTrue();
});

test('missing delivery storage and late reviewed history remain unavailable rather than a claimed zero', function () {
    [$tenant, $owner, $profile] = outreachWorkspace();
    outreachRevision($tenant, $owner, ['delivery_history_verified_from' => ['email' => now()->subDay()->toIso8601String(), 'sms' => '2026-01-01T00:00:00Z']]);
    expect(app(CustomerOutreachPolicy::class)->decision($profile, 'email')['delivery_evidence']['daily_count'])->toBeNull();
    Schema::rename('marketing_message_deliveries', 'fictional_unavailable_sms_deliveries');
    try {
        $result = app(CustomerOutreachPolicy::class)->decision($profile, 'email');
        expect($result['reason_codes'])->toContain('outreach_sms_history_unavailable')->and($result['delivery_evidence']['daily_count'])->toBeNull();
    } finally {
        Schema::rename('fictional_unavailable_sms_deliveries', 'marketing_message_deliveries');
    }
});

test('current explicit preferences and suppressions override a previously sendable decision', function () {
    [$tenant, $owner, $profile] = outreachWorkspace();
    outreachRevision($tenant, $owner);
    $preferences = app(CustomerPreferenceService::class);
    $version = $preferences->forStaff($profile, $tenant->id)['version'];
    $preferences->updateForStaff($profile, $tenant->id, $owner, ['request_id' => (string) Str::uuid(), 'expected_version' => $version,
        'customer_confirmed' => true, 'changes' => ['preferred_channel' => 'none']]);
    expect(app(CustomerOutreachPolicy::class)->decision($profile, 'email')['reason_codes'])->toContain('outreach_customer_prefers_no_marketing');
    MessagingContactChannelState::updateOrCreate(['tenant_id' => $tenant->id, 'phone' => $profile->phone],
        ['marketing_profile_id' => $profile->id, 'sms_status' => 'unsubscribed', 'sms_status_reason' => 'stop']);
    expect(app(CustomerOutreachPolicy::class)->decision($profile, 'sms')['reason_codes'])->toContain('sms_stop_suppressed');
});

test('preparation explanations replay unchanged after policy edits and retain honest partial-data quality', function () {
    [$tenant, $owner, $profile] = outreachWorkspace();
    outreachRevision($tenant, $owner);
    $campaign = outreachPrepared($profile);
    $explanations = app(CustomerOutreachExplanation::class);
    $original = $campaign->target_snapshot['explanation'];
    expect($explanations->dispatchDecision($campaign, $profile)['allowed'])->toBeTrue();
    outreachRevision($tenant, $owner, ['daily_cap' => 0]);
    $decision = $explanations->dispatchDecision($campaign->fresh(), $profile);
    expect($decision['reason_codes'])->toContain('outreach_daily_cap')->and($decision['policy']['version'])->toBe(2)
        ->and($decision['preparation_policy_version'])->toBe(1);
    $history = $explanations->history($profile);
    expect($history[0]['explanation'])->toBe($original)
        ->and($original['quality']['lifetime_history_complete'])->toBeFalse()
        ->and($original['quality']['lifetime_totals_supported'])->toBeFalse()
        ->and($original['quality']['issues'])->toContain('bounded_or_partial_purchase_history', 'source_freshness_not_established', 'purchase_item_details_missing', 'scent_mapping_missing_or_unreviewed', 'stable_product_identity_missing');
    $raw = json_encode($history, JSON_THROW_ON_ERROR);
    expect($raw)->not->toContain($profile->email, $profile->phone, 'Fictional private name', 'Fictional private message');
    expect($explanations->recordPreparation($campaign, $profile, $original, null))->toBe($history[0]['id']);
    expect(fn () => $explanations->recordPreparation($campaign, $profile, array_replace($original, ['why_prepared' => 'changed']), null))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    [$foreignTenant, $foreignOwner, $foreign] = outreachWorkspace();
    expect($explanations->history($foreign))->toBe([])
        ->and(fn () => $explanations->dispatchDecision($campaign, $foreign))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('missing or tampered preparation evidence blocks dispatch and old preparation requires a fresh reviewed draft', function () {
    [$tenant, $owner, $profile] = outreachWorkspace();
    outreachRevision($tenant, $owner);
    $campaign = outreachPrepared($profile);
    $service = app(CustomerOutreachExplanation::class);
    $snapshot = $campaign->target_snapshot;
    $campaign->update(['target_snapshot' => ['profile_id' => $profile->id]]);
    expect($service->dispatchDecision($campaign, $profile)['reason_codes'])->toContain('outreach_preparation_evidence_missing_or_changed');
    $tampered = $snapshot;
    $tampered['explanation']['preparation_decision']['allowed'] = false;
    $campaign->update(['target_snapshot' => $tampered]);
    expect($service->dispatchDecision($campaign, $profile)['reason_codes'])->toContain('outreach_preparation_evidence_missing_or_changed');
    $tampered['explanation']['prepared_at'] = 'malformed snapshot';
    $campaign->update(['target_snapshot' => $tampered]);
    expect($service->dispatchDecision($campaign, $profile)['reason_codes'])->toContain('outreach_preparation_evidence_missing_or_changed');
    $campaign->update(['target_snapshot' => $snapshot]);
    $this->travel(8)->days();
    expect($service->dispatchDecision($campaign, $profile)['reason_codes'])->toContain('outreach_preparation_stale');
    Http::assertNothingSent();
});

test('a preference change after preparation requires a new draft and keeps original explanation replay intact', function () {
    [$tenant, $owner, $profile] = outreachWorkspace();
    outreachRevision($tenant, $owner);
    $campaign = outreachPrepared($profile);
    $old = $campaign->target_snapshot['explanation'];
    $preferences = app(CustomerPreferenceService::class);
    $preferences->updateForStaff($profile, $tenant->id, $owner, ['request_id' => (string) Str::uuid(),
        'expected_version' => $preferences->forStaff($profile, $tenant->id)['version'], 'customer_confirmed' => true,
        'changes' => ['avoided_scents' => ['Fictional old scent']]]);
    expect(app(CustomerOutreachExplanation::class)->dispatchDecision($campaign, $profile)['reason_codes'])
        ->toContain('outreach_prepared_preferences_changed')
        ->and(app(CustomerOutreachExplanation::class)->history($profile)[0]['explanation'])->toBe($old);
});

test('a declared rewards communication decline blocks explicit rewards drafts while keeping ordinary marketing separate', function () {
    [$tenant, $owner, $profile] = outreachWorkspace();
    outreachRevision($tenant, $owner);
    $preferences = app(CustomerPreferenceService::class);
    $preferences->updateForStaff($profile, $tenant->id, $owner, ['request_id' => (string) Str::uuid(),
        'expected_version' => $preferences->forStaff($profile, $tenant->id)['version'], 'customer_confirmed' => true,
        'changes' => ['rewards_communications' => false]]);
    $ordinary = outreachPrepared($profile);
    expect(app(CustomerOutreachExplanation::class)->dispatchDecision($ordinary, $profile)['allowed'])->toBeTrue();
    $rewards = outreachPrepared($profile, 'email', ['rewards_balance' => '5']);
    expect(app(CustomerOutreachExplanation::class)->dispatchDecision($rewards, $profile)['reason_codes'])
        ->toContain('outreach_rewards_communications_declined');
    $ordinary->update(['objective' => 'reward_issuance']);
    expect(app(CustomerOutreachExplanation::class)->dispatchDecision($ordinary, $profile)['reason_codes'])
        ->toContain('outreach_rewards_communications_declined');
});

test('personal SMS blocked by the closed gate never creates a sending attempt or calls a provider', function () {
    [$tenant, $owner, $profile] = outreachWorkspace();
    outreachRevision($tenant, $owner);
    $recipient = outreachApprovedSms($profile);
    config(['marketing.customer_profile.sms_dispatch_enabled' => false]);
    $this->mock(MarketingTemplateRenderer::class)->shouldReceive('renderCampaignMessage')->andReturn('Fictional private message');
    $this->mock(TwilioSmsService::class)->shouldNotReceive('sendSms');
    $result = app(MarketingSmsExecutionService::class)->sendRecipient($recipient, ['tenant_id' => $tenant->id]);
    expect($result['reason'])->toBe('outreach_sms_safety_review_required')->and($recipient->fresh()->status)->toBe('skipped')
        ->and(MarketingMessageDelivery::count())->toBe(0)
        ->and(LandlordOperatorAction::where('action_type', 'customer_outreach_dispatch_decision')->first()->status)->toBe('blocked');
    Http::assertNothingSent();
});

test('personal SMS with an unknown mocked provider outcome preserves its original attempt and blocks the other channel', function (bool $success, string $status, ?string $errorCode) {
    [$tenant, $owner, $profile] = outreachWorkspace();
    outreachRevision($tenant, $owner);
    $recipient = outreachApprovedSms($profile);
    $this->mock(MarketingTemplateRenderer::class)->shouldReceive('renderCampaignMessage')->andReturn('Fictional private message');
    $this->mock(MessageClickTrackingService::class)->shouldReceive('decorateSmsMessageForDelivery')->once()
        ->andReturn(['message' => 'Fictional private message', 'links' => []]);
    $this->mock(TwilioSmsService::class)->shouldReceive('sendSms')->once()->andReturn([
        'success' => $success, 'status' => $status, 'error_code' => $errorCode, 'error_message' => 'Fictional transport uncertainty',
        'provider_message_id' => null, 'payload' => [], 'dry_run' => false,
    ]);
    $service = app(MarketingSmsExecutionService::class);
    $result = $service->sendApprovedForCampaign($recipient->campaign, ['tenant_id' => $tenant->id]);
    expect($result['unknown'])->toBe(1)->and($result['failed'])->toBe(0)->and($result['skipped'])->toBe(0)
        ->and($recipient->fresh()->status)->toBe('sending')
        ->and(MarketingMessageDelivery::first()->send_status)->toBe('unknown')
        ->and(MarketingMessageDelivery::first()->sent_at)->toBeNull()
        ->and(MarketingMessageDelivery::first()->failed_at)->toBeNull()
        ->and(app(CustomerOutreachPolicy::class)->decision($profile, 'email')['reason_codes'])->toContain('outreach_delivery_outcome_unresolved');
    expect($service->sendRecipient($recipient->fresh(), ['tenant_id' => $tenant->id, 'force_retry' => true])['reason'])->toBe('status_not_sendable')
        ->and(MarketingMessageDelivery::count())->toBe(1);
    Http::assertNothingSent();
})->with([[false, 'failed', 'exception'], [true, 'sent', null]]);

test('a provider-reported personal SMS simulation cannot consume a real promotional allowance', function () {
    [$tenant, $owner, $profile] = outreachWorkspace();
    outreachRevision($tenant, $owner);
    $recipient = outreachApprovedSms($profile);
    $this->mock(MarketingTemplateRenderer::class)->shouldReceive('renderCampaignMessage')->andReturn('Fictional private message');
    $this->mock(MessageClickTrackingService::class)->shouldReceive('decorateSmsMessageForDelivery')->once()
        ->andReturn(['message' => 'Fictional private message', 'links' => []]);
    $this->mock(TwilioSmsService::class)->shouldReceive('sendSms')->once()->andReturn([
        'success' => true, 'status' => 'sent', 'provider_message_id' => null, 'payload' => [], 'dry_run' => true,
    ]);
    $result = app(MarketingSmsExecutionService::class)->sendRecipient($recipient, ['tenant_id' => $tenant->id]);
    expect($result['dry_run'])->toBeTrue()->and(MarketingMessageDelivery::first()->provider_payload['dry_run'])->toBeTrue()
        ->and(app(CustomerOutreachPolicy::class)->decision($profile, 'email')['allowed'])->toBeTrue();
    Http::assertNothingSent();
});

test('an old profile record and missing source observations remain explicit freshness gaps', function () {
    [$tenant, $owner, $profile] = outreachWorkspace();
    outreachRevision($tenant, $owner);
    DB::table('marketing_profiles')->where('id', $profile->id)->update(['updated_at' => '2025-01-01 00:00:00']);
    $profile->refresh();
    $decision = app(CustomerOutreachPolicy::class)->decision($profile, 'email');
    $explanation = app(CustomerOutreachExplanation::class)->build($profile,
        ['behavior' => ['partial' => false], 'confirmed_purchase_count' => 0], [], $decision, ['last_purchase_date']);
    expect($explanation['profile_record_freshness'])->toBe('profile_record_stale')
        ->and($explanation['quality']['source_freshness'])->toBe('unknown')
        ->and($explanation['quality']['issues'])->toContain('profile_record_stale', 'source_freshness_not_established',
            'required_personalization_value_missing', 'no_confirmed_linked_purchases_observed')
        ->and($explanation['quality']['missing_variables'])->toBe(['last_purchase_date']);
});
