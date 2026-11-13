<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingCampaignVariant;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingGroup;
use App\Models\MarketingGroupMember;
use App\Models\MarketingProfile;
use App\Models\MarketingSendApproval;
use App\Models\Tenant;
use App\Models\TenantEmailSetting;
use App\Models\User;
use App\Services\Marketing\MarketingApprovalService;
use App\Services\Marketing\MarketingCampaignAudienceBuilder;
use App\Services\Marketing\MarketingCampaignEmailDesignService;
use App\Services\Marketing\MarketingEmailExecutionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['marketing.email.enabled' => true, 'marketing.email.dry_run' => false]);
    Http::preventStrayRequests();
    Http::fake(['https://api.sendgrid.com/*' => Http::response([], 202, ['X-Message-Id' => 'fictional-html-review'])]);
});

function campaignHtmlApprovalFixture(?string $html = '<html><body><h1>Fictional candle fair</h1></body></html>'): array
{
    $tenant = Tenant::create(['name' => 'Fictional candle maker', 'slug' => 'html-review-'.Str::lower(Str::random(10))]);
    TenantEmailSetting::create([
        'tenant_id' => $tenant->id, 'email_provider' => 'sendgrid', 'email_enabled' => true,
        'from_name' => 'Fictional candle maker', 'from_email' => 'hello@example.test', 'provider_status' => 'configured',
        'provider_config' => ['api_key' => 'SG.fictional-only', 'verified_sender_email' => 'hello@example.test'],
    ]);
    $actor = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $actor->tenants()->attach($tenant, ['role' => 'admin', 'membership_active' => true]);
    $campaign = MarketingCampaign::create([
        'tenant_id' => $tenant->id, 'name' => 'Fictional city invite', 'channel' => 'email', 'status' => 'draft',
        'message_html' => $html, 'message_subject' => 'Fictional candle fair this weekend',
    ]);
    MarketingCampaignVariant::create([
        'campaign_id' => $campaign->id, 'name' => 'Reviewed invitation', 'message_text' => 'Hi {{first_name}}, visit the fictional candle fair.', 'status' => 'draft',
    ]);
    $profile = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id, 'first_name' => 'Alex', 'email' => 'alex@example.test',
        'normalized_email' => 'alex@example.test', 'accepts_email_marketing' => true,
    ]);
    $group = MarketingGroup::create(['name' => 'Fictional reviewed audience']);
    MarketingGroupMember::create(['marketing_group_id' => $group->id, 'marketing_profile_id' => $profile->id]);
    $campaign->groups()->attach($group);
    app(MarketingCampaignAudienceBuilder::class)->prepareRecipients($campaign);
    $recipient = $campaign->recipients()->firstOrFail();

    return compact('tenant', 'actor', 'campaign', 'profile', 'recipient');
}

function approveCampaignHtmlFixture(array $fixture): MarketingCampaignRecipient
{
    return app(MarketingApprovalService::class)->approveRecipient($fixture['recipient'], $fixture['actor']->id, 'Reviewed fictional design and audience.');
}

function interruptCampaignHtmlAfterApprovalCheck(Closure $interrupt): void
{
    $service = app(MarketingCampaignEmailDesignService::class);
    $interrupted = false;
    $mock = Mockery::mock(MarketingCampaignEmailDesignService::class)->makePartial();
    $mock->shouldReceive('assertApproved')->andReturnUsing(function (...$arguments) use ($service, $interrupt, &$interrupted) {
        $service->assertApproved(...$arguments);
        if (! $interrupted) {
            $interrupted = true;
            $interrupt();
        }
    });
    app()->instance(MarketingCampaignEmailDesignService::class, $mock);
}

function assertCampaignHtmlBlocked(array $fixture, MarketingCampaignRecipient $recipient): void
{
    $result = app(MarketingEmailExecutionService::class)->sendRecipient($recipient, ['tenant_id' => $fixture['tenant']->id]);
    Http::assertNothingSent();
    expect($result['reason'])->toBe('email_design_changed')
        ->and(MarketingEmailDelivery::count())->toBe(0)
        ->and($recipient->fresh()->send_attempt_count)->toBe(0);
}

test('normal audience preparation and approval bind the HTML delivered through the email executor', function () {
    $fixture = campaignHtmlApprovalFixture();
    $hash = hash('sha256', $fixture['campaign']->message_html);
    expect(data_get($fixture['recipient']->recommendation_snapshot, 'email_design.html_sha256'))->toBe($hash)
        ->and($fixture['recipient']->status)->toBe('queued_for_approval');
    $recipient = approveCampaignHtmlFixture($fixture);

    $result = app(MarketingEmailExecutionService::class)->sendRecipient($recipient, ['tenant_id' => $fixture['tenant']->id]);

    expect($result['outcome'])->toBe('sent')
        ->and(data_get($recipient->fresh()->recommendation_snapshot, 'email_design_approval.html_sha256'))->toBe($hash)
        ->and(MarketingSendApproval::where('campaign_recipient_id', $recipient->id)->count())->toBe(1);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains(collect($request['content'])->firstWhere('type', 'text/html')['value'], '<h1>Fictional candle fair</h1>'));
});

test('HTML added changed or removed after approval requires a new review before provider delivery', function (string $change) {
    $fixture = campaignHtmlApprovalFixture($change === 'added' ? null : '<html><body>Original design</body></html>');
    $recipient = approveCampaignHtmlFixture($fixture);
    $fixture['campaign']->update(['message_html' => $change === 'removed' ? null : '<html><body>New design</body></html>']);

    assertCampaignHtmlBlocked($fixture, $recipient);
})->with(['added', 'changed', 'removed']);

test('an old plaintext approval cannot acquire an HTML body without preparing and approving the design', function () {
    $fixture = campaignHtmlApprovalFixture(null);
    $recipient = $fixture['recipient'];
    $recipient->update(['status' => 'approved', 'approved_by' => $fixture['actor']->id, 'approved_at' => now(), 'recommendation_snapshot' => []]);
    $fixture['campaign']->update(['message_html' => '<html><body>New art requires review</body></html>']);

    assertCampaignHtmlBlocked($fixture, $recipient);
});

test('added changed or removed HTML cannot be approved before the new design is prepared', function (string $change) {
    $fixture = campaignHtmlApprovalFixture($change === 'added' ? null : '<html><body>Original design</body></html>');
    $fixture['campaign']->update(['message_html' => $change === 'removed' ? null : '<html><body>Unprepared revision</body></html>']);

    expect(fn () => approveCampaignHtmlFixture($fixture))->toThrow(ValidationException::class)
        ->and(MarketingSendApproval::count())->toBe(0)
        ->and($fixture['recipient']->fresh()->approved_at)->toBeNull();
    Http::assertNothingSent();
})->with(['added', 'changed', 'removed']);

test('an HTML status flag without the normal design approval pin is requeued by preparation', function () {
    $fixture = campaignHtmlApprovalFixture();
    $fixture['recipient']->update(['status' => 'approved', 'approved_by' => $fixture['actor']->id, 'approved_at' => now()]);

    app(MarketingCampaignAudienceBuilder::class)->prepareRecipients($fixture['campaign']);

    expect($fixture['recipient']->fresh()->status)->toBe('queued_for_approval')
        ->and($fixture['recipient']->fresh()->approved_at)->toBeNull()
        ->and(MarketingSendApproval::count())->toBe(0);
    Http::assertNothingSent();
});

test('same-second replacement of prepared HTML cannot reuse the prior approval hash', function () {
    $fixture = campaignHtmlApprovalFixture();
    $recipient = approveCampaignHtmlFixture($fixture);
    $html = '<html><body>Replacement made in the same second</body></html>';
    $fixture['campaign']->update(['message_html' => $html]);
    $snapshot = $recipient->recommendation_snapshot;
    $snapshot['email_design']['html_sha256'] = hash('sha256', $html);
    $recipient->update(['recommendation_snapshot' => $snapshot]);

    assertCampaignHtmlBlocked($fixture, $recipient);
});

test('removing both campaign and prepared HTML cannot silently reuse a prior HTML approval for plaintext', function () {
    $fixture = campaignHtmlApprovalFixture();
    $recipient = approveCampaignHtmlFixture($fixture);
    $fixture['campaign']->update(['message_html' => null]);
    $snapshot = $recipient->recommendation_snapshot;
    unset($snapshot['email_design']);
    $recipient->update(['recommendation_snapshot' => $snapshot]);

    assertCampaignHtmlBlocked($fixture, $recipient);
});

test('preparation clears a prior HTML approval even if its prepared design was already removed', function () {
    $fixture = campaignHtmlApprovalFixture();
    $recipient = approveCampaignHtmlFixture($fixture);
    $fixture['campaign']->update(['message_html' => null]);
    $snapshot = $recipient->recommendation_snapshot;
    unset($snapshot['email_design']);
    $recipient->update(['recommendation_snapshot' => $snapshot]);

    expect(fn () => app(MarketingApprovalService::class)->approveRecipient($recipient, $fixture['actor']->id))->toThrow(ValidationException::class);
    app(MarketingCampaignAudienceBuilder::class)->prepareRecipients($fixture['campaign']);

    expect($recipient->fresh()->status)->toBe('queued_for_approval')
        ->and($recipient->fresh()->approved_at)->toBeNull()
        ->and(data_get($recipient->fresh()->recommendation_snapshot, 'email_design_approval'))->toBeNull()
        ->and(MarketingSendApproval::where('campaign_recipient_id', $recipient->id)->count())->toBe(1);
    Http::assertNothingSent();
});

test('unchanged preparation retains HTML approval and all existing consent and design audit fields', function () {
    $fixture = campaignHtmlApprovalFixture();
    $recipient = approveCampaignHtmlFixture($fixture);
    $approvedAt = $recipient->approved_at;
    $snapshot = $recipient->recommendation_snapshot;
    $snapshot['consent_source_audit'] = ['basis' => 'fictional reviewed source'];
    $snapshot['email_design']['media_asset_ids'] = [101, 102];
    $snapshot['email_design']['reviewed_at'] = now()->toISOString();
    $snapshot['email_subject'] = 'Retained subject';
    $recipient->update(['recommendation_snapshot' => $snapshot]);

    app(MarketingCampaignAudienceBuilder::class)->prepareRecipients($fixture['campaign']);

    $recipient->refresh();
    expect($recipient->status)->toBe('approved')
        ->and($recipient->approved_at->equalTo($approvedAt))->toBeTrue()
        ->and(data_get($recipient->recommendation_snapshot, 'consent_source_audit.basis'))->toBe('fictional reviewed source')
        ->and(data_get($recipient->recommendation_snapshot, 'email_design.media_asset_ids'))->toBe([101, 102])
        ->and(data_get($recipient->recommendation_snapshot, 'email_design.reviewed_at'))->toBe($snapshot['email_design']['reviewed_at'])
        ->and($recipient->recommendation_snapshot['email_subject'])->toBe('Retained subject')
        ->and(data_get($recipient->recommendation_snapshot, 'email_design_approval.html_sha256'))->toBe(hash('sha256', $fixture['campaign']->message_html));
    Http::assertNothingSent();
});

test('repreparing changed added or removed HTML clears only the current approval and normal reapproval restores delivery', function (string $change) {
    $fixture = campaignHtmlApprovalFixture($change === 'added' ? null : '<html><body>Original design</body></html>');
    $recipient = approveCampaignHtmlFixture($fixture);
    $html = $change === 'removed' ? null : '<html><body>Reviewed replacement</body></html>';
    $fixture['campaign']->update(['message_html' => $html]);

    app(MarketingCampaignAudienceBuilder::class)->prepareRecipients($fixture['campaign']);

    $recipient->refresh();
    expect($recipient->status)->toBe('queued_for_approval')
        ->and($recipient->approved_by)->toBeNull()
        ->and($recipient->approved_at)->toBeNull()
        ->and(data_get($recipient->recommendation_snapshot, 'email_design_approval'))->toBeNull()
        ->and(data_get($recipient->recommendation_snapshot, 'email_design.html_sha256'))->toBe($html !== null ? hash('sha256', $html) : null)
        ->and(MarketingSendApproval::where('campaign_recipient_id', $recipient->id)->count())->toBe(1);
    Http::assertNothingSent();
    $recipient = approveCampaignHtmlFixture($fixture);
    $result = app(MarketingEmailExecutionService::class)->sendRecipient($recipient, ['tenant_id' => $fixture['tenant']->id]);

    expect($result['outcome'])->toBe('sent')
        ->and(MarketingSendApproval::where('campaign_recipient_id', $recipient->id)->count())->toBe(2);
    Http::assertSentCount(1);
})->with(['added', 'changed', 'removed']);

test('repreparing an attempted HTML recipient never requeues or overwrites its original design snapshot', function (string $status) {
    $fixture = campaignHtmlApprovalFixture();
    $recipient = approveCampaignHtmlFixture($fixture);
    $originalSnapshot = $recipient->recommendation_snapshot;
    $recipient->update(['status' => $status, 'send_attempt_count' => 1, 'sent_at' => $status === 'sent' ? now() : null]);
    $fixture['campaign']->update(['message_html' => '<html><body>Later revision</body></html>']);

    app(MarketingCampaignAudienceBuilder::class)->prepareRecipients($fixture['campaign']);

    expect($recipient->fresh()->status)->toBe($status)
        ->and($recipient->fresh()->send_attempt_count)->toBe(1)
        ->and($recipient->fresh()->recommendation_snapshot)->toBe($originalSnapshot);
    Http::assertNothingSent();
})->with(['sent', 'failed', 'undelivered']);

test('legacy plaintext without design metadata retains normal approval and delivery behavior', function () {
    $fixture = campaignHtmlApprovalFixture(null);
    $fixture['recipient']->update(['recommendation_snapshot' => []]);
    $recipient = approveCampaignHtmlFixture($fixture);

    expect(data_get($recipient->recommendation_snapshot, 'email_design_approval'))->toBe(['html_sha256' => null]);
    $result = app(MarketingEmailExecutionService::class)->sendRecipient($recipient, ['tenant_id' => $fixture['tenant']->id]);

    expect($result['outcome'])->toBe('sent');
    Http::assertSentCount(1);
    expect(MarketingEmailDelivery::firstOrFail()->metadata['content_format'])->toBe('text');
});

test('malformed email review hashes cannot be approved or dispatched', function () {
    $fixture = campaignHtmlApprovalFixture();
    $recipient = approveCampaignHtmlFixture($fixture);
    $snapshot = $recipient->recommendation_snapshot;
    $snapshot['email_design'] = ['html_sha256' => 'invalid'];
    $recipient->update(['recommendation_snapshot' => $snapshot]);

    expect(fn () => app(MarketingApprovalService::class)->approveRecipient($recipient, $fixture['actor']->id))->toThrow(ValidationException::class);
    assertCampaignHtmlBlocked($fixture, $recipient);
});

test('the approval assertion refreshes a cached campaign before the executor composes its HTML', function () {
    $fixture = campaignHtmlApprovalFixture();
    $cachedCampaign = $fixture['campaign'];
    $recipient = approveCampaignHtmlFixture($fixture);
    $html = '<html><body>Currently approved artwork</body></html>';
    MarketingCampaign::whereKey($cachedCampaign->id)->update(['message_html' => $html]);
    $currentCampaign = $cachedCampaign->fresh();
    app(MarketingCampaignAudienceBuilder::class)->prepareRecipients($currentCampaign);
    $recipient = approveCampaignHtmlFixture($fixture);

    app(MarketingCampaignEmailDesignService::class)->assertApproved($cachedCampaign, $recipient);

    expect($cachedCampaign->message_html)->toBe($html);
    Http::assertNothingSent();
});

test('fresh approval lookup cannot cross a changed campaign owner or recipient identity', function (string $change) {
    $fixture = campaignHtmlApprovalFixture();
    $recipient = approveCampaignHtmlFixture($fixture);
    if ($change === 'tenant') {
        $otherTenant = Tenant::create(['name' => 'Other fictional maker', 'slug' => 'other-html-'.Str::lower(Str::random(10))]);
        MarketingCampaign::whereKey($fixture['campaign']->id)->update(['tenant_id' => $otherTenant->id]);
    } else {
        $otherProfile = MarketingProfile::factory()->create(['tenant_id' => $fixture['tenant']->id]);
        MarketingCampaignRecipient::whereKey($recipient->id)->update(['marketing_profile_id' => $otherProfile->id]);
    }

    expect(fn () => app(MarketingCampaignEmailDesignService::class)->assertApproved($fixture['campaign'], $recipient))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with(['tenant', 'profile']);

test('the email claim preserves concurrent design review rejection identity and claim state', function (string $change) {
    $fixture = campaignHtmlApprovalFixture(str_starts_with($change, 'added') ? null : '<html><body>Original reviewed design</body></html>');
    $recipient = approveCampaignHtmlFixture($fixture);
    $expectedState = null;
    $fields = ['status', 'marketing_profile_id', 'recommendation_snapshot', 'approved_by', 'approved_at', 'send_attempt_count'];
    interruptCampaignHtmlAfterApprovalCheck(function () use ($fixture, $recipient, $change, $fields, &$expectedState) {
        if (in_array($change, ['changed', 'removed', 'added', 'added_prepared', 'reprepared', 'reapproved'], true)) {
            MarketingCampaign::whereKey($fixture['campaign']->id)->update(['message_html' => $change === 'removed' ? null : '<html><body>Concurrent replacement artwork</body></html>']);
            if (in_array($change, ['added_prepared', 'reprepared', 'reapproved'], true)) {
                app(MarketingCampaignAudienceBuilder::class)->prepareRecipients($fixture['campaign']->fresh());
            }
            if ($change === 'reapproved') {
                app(MarketingApprovalService::class)->approveRecipient($recipient->fresh(), $fixture['actor']->id, 'Reviewed concurrent artwork.');
            }
        } elseif ($change === 'rejected') {
            app(MarketingApprovalService::class)->rejectRecipient($recipient->fresh(), $fixture['actor']->id, 'Do not send.');
        } elseif ($change === 'profile') {
            $otherProfile = MarketingProfile::factory()->create(['tenant_id' => $fixture['tenant']->id]);
            MarketingCampaignRecipient::whereKey($recipient->id)->update(['marketing_profile_id' => $otherProfile->id]);
        } elseif ($change === 'tenant') {
            $otherTenant = Tenant::create(['name' => 'Other fictional maker', 'slug' => 'claim-other-'.Str::lower(Str::random(10))]);
            MarketingCampaign::whereKey($fixture['campaign']->id)->update(['tenant_id' => $otherTenant->id]);
        } else {
            MarketingCampaignRecipient::whereKey($recipient->id)->update(['status' => $change]);
        }
        $expectedState = $recipient->fresh()->only($fields);
    });

    $result = app(MarketingEmailExecutionService::class)->sendRecipient($recipient, ['tenant_id' => $fixture['tenant']->id]);

    Http::assertNothingSent();
    expect($result['reason'])->toBe('email_claim_changed')
        ->and($recipient->fresh()->only($fields))->toEqual($expectedState)
        ->and($recipient->fresh()->send_attempt_count)->toBe(0)
        ->and(MarketingEmailDelivery::count())->toBe(0);
})->with(['changed', 'removed', 'added', 'added_prepared', 'reprepared', 'reapproved', 'rejected', 'profile', 'tenant', 'sending', 'sent']);

test('competing email workers can complete only one reviewed recipient delivery', function () {
    $fixture = campaignHtmlApprovalFixture();
    $recipient = approveCampaignHtmlFixture($fixture);
    $winningResult = null;
    interruptCampaignHtmlAfterApprovalCheck(function () use ($fixture, $recipient, &$winningResult) {
        $winningResult = app(MarketingEmailExecutionService::class)->sendRecipient($recipient->fresh(), ['tenant_id' => $fixture['tenant']->id]);
    });

    $losingResult = app(MarketingEmailExecutionService::class)->sendRecipient($recipient, ['tenant_id' => $fixture['tenant']->id]);

    expect($winningResult['outcome'])->toBe('sent')
        ->and($losingResult['reason'])->toBe('email_claim_changed')
        ->and($recipient->fresh()->status)->toBe('sent')
        ->and($recipient->fresh()->send_attempt_count)->toBe(1)
        ->and(MarketingEmailDelivery::count())->toBe(1);
    Http::assertSentCount(1);
});

test('a null-tenant legacy plaintext campaign can claim using its canonical profile tenant', function () {
    $fixture = campaignHtmlApprovalFixture(null);
    MarketingCampaign::whereKey($fixture['campaign']->id)->update(['tenant_id' => null]);
    $recipient = approveCampaignHtmlFixture($fixture);

    $result = app(MarketingEmailExecutionService::class)->sendRecipient($recipient, ['tenant_id' => $fixture['tenant']->id]);

    expect($result['outcome'])->toBe('sent')
        ->and(MarketingEmailDelivery::firstOrFail()->tenant_id)->toBe($fixture['tenant']->id);
    Http::assertSentCount(1);
});

test('an explicit retry can claim a failed email with its unchanged HTML approval', function () {
    $fixture = campaignHtmlApprovalFixture();
    $recipient = approveCampaignHtmlFixture($fixture);
    $recipient->update(['status' => 'failed', 'send_attempt_count' => 1]);
    $executor = app(MarketingEmailExecutionService::class);

    expect($executor->sendRecipient($recipient, ['tenant_id' => $fixture['tenant']->id])['reason'])->toBe('status_not_sendable');
    Http::assertNothingSent();
    $result = $executor->retryRecipient($recipient, ['tenant_id' => $fixture['tenant']->id]);

    expect($result['outcome'])->toBe('sent')->and($recipient->fresh()->send_attempt_count)->toBe(2);
    Http::assertSentCount(1);
});
