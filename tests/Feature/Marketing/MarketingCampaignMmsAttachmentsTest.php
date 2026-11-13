<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingCampaignVariant;
use App\Models\MarketingGroup;
use App\Models\MarketingGroupMember;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageMediaAsset;
use App\Models\MarketingProfile;
use App\Models\MarketingSendApproval;
use App\Models\MarketingShortLink;
use App\Models\MessagingContactChannelState;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\MarketingApprovalService;
use App\Services\Marketing\MarketingCampaignAudienceBuilder;
use App\Services\Marketing\MarketingCampaignMmsMediaService;
use App\Services\Marketing\MarketingSmsExecutionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 11, 13)->setTimezone('America/New_York')->setTime(12, 0));
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.sms.dry_run', false);
    config()->set('marketing.sms.enforce_send_windows_in_tests', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.twilio.account_sid', 'AC_FICTIONAL_MMS');
    config()->set('marketing.twilio.auth_token', 'fictional-test-token');
    config()->set('marketing.twilio.messaging_service_sid', null);
    config()->set('marketing.twilio.from_number', '+15550001234');
    config()->set('marketing.twilio.senders', []);
    config()->set('features.tenant_messaging_platform', false);
    config()->set('filesystems.disks.public.url', 'https://assets.example.test/storage');
    Storage::fake('public', ['url' => 'https://assets.example.test/storage']);
    Http::preventStrayRequests();
    Http::fake(['https://api.twilio.com/*' => Http::response(['sid' => 'SM_FICTIONAL_MMS', 'status' => 'sent'], 201)]);
});

function campaignMmsAsset(MarketingCampaign $campaign): MarketingMessageMediaAsset
{
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jxJkAAAAASUVORK5CYII=');
    $path = 'messaging-media/tenant-'.$campaign->tenant_id.'/'.$campaign->store_key.'/sms/'.Str::random(12).'.png';
    Storage::disk('public')->put($path, $bytes);

    return MarketingMessageMediaAsset::query()->create([
        'tenant_id' => $campaign->tenant_id,
        'store_key' => $campaign->store_key,
        'channel' => 'sms',
        'disk' => 'public',
        'path' => $path,
        'public_url' => Storage::disk('public')->url($path),
        'original_name' => 'fictional-candle.png',
        'mime_type' => 'image/png',
        'size_bytes' => strlen($bytes),
        'width' => 1,
        'height' => 1,
        'metadata' => ['content_sha256' => hash('sha256', $bytes)],
    ]);
}

function campaignMmsSelection(MarketingMessageMediaAsset $asset): array
{
    return ['asset_id' => $asset->id, 'sha256' => $asset->metadata['content_sha256'], 'required' => true, 'prepared_at' => now()->subMinute()->toIso8601String()];
}

function campaignMmsFixture(bool $withMedia = true): array
{
    $tenant = Tenant::query()->create(['name' => 'Fictional candle maker', 'slug' => 'mms-'.Str::lower(Str::random(10))]);
    $store = 'retail-'.Str::lower(Str::random(8));
    ShopifyStore::query()->create(['tenant_id' => $tenant->id, 'store_key' => $store, 'shop_domain' => $store.'.myshopify.com', 'access_token' => 'fictional', 'installed_at' => now()]);
    $actor = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $actor->tenants()->attach($tenant, ['role' => 'admin', 'membership_active' => true]);
    $campaign = MarketingCampaign::query()->create(['tenant_id' => $tenant->id, 'store_key' => $store, 'name' => 'Fictional candle show', 'channel' => 'sms', 'status' => 'draft']);
    $profile = MarketingProfile::query()->create(['tenant_id' => $tenant->id, 'first_name' => 'Fictional', 'phone' => '+15551112222', 'normalized_phone' => '+15551112222', 'accepts_sms_marketing' => true]);
    MarketingCampaignVariant::query()->create(['campaign_id' => $campaign->id, 'name' => 'Reviewed text', 'message_text' => 'Fictional candle show this weekend. Reply STOP to opt out.', 'status' => 'draft']);
    $group = MarketingGroup::query()->create(['name' => 'Fictional reviewed audience']);
    MarketingGroupMember::query()->create(['marketing_group_id' => $group->id, 'marketing_profile_id' => $profile->id]);
    $campaign->groups()->attach($group);
    $asset = $withMedia ? campaignMmsAsset($campaign) : null;
    if ($asset) {
        $campaign->forceFill(['target_snapshot' => ['mms_media' => campaignMmsSelection($asset)]])->save();
    }
    app(MarketingCampaignAudienceBuilder::class)->prepareRecipients($campaign);
    $recipient = $campaign->recipients()->firstOrFail();

    return compact('tenant', 'actor', 'campaign', 'profile', 'asset', 'recipient');
}

function approveCampaignMmsFixture(array $fixture): MarketingCampaignRecipient
{
    return app(MarketingApprovalService::class)->approveRecipient($fixture['recipient'], $fixture['actor']->id, 'Reviewed fictional text and photo.');
}

function interruptCampaignMmsAfterValidation(Closure $interrupt): void
{
    $service = app(MarketingCampaignMmsMediaService::class);
    $interrupted = false;
    $mock = Mockery::mock(MarketingCampaignMmsMediaService::class)->makePartial();
    $mock->shouldReceive('forRecipient')->andReturnUsing(function (...$arguments) use ($service, $interrupt, &$interrupted) {
        $media = $service->forRecipient(...$arguments);
        if (! $interrupted) {
            $interrupted = true;
            $interrupt();
        }

        return $media;
    });
    app()->instance(MarketingCampaignMmsMediaService::class, $mock);
}

test('normal approval and SMS execution send one owned photo as MMS with an auditable asset snapshot', function () {
    $fixture = campaignMmsFixture();
    $recipient = approveCampaignMmsFixture($fixture);
    $beforeFlags = $fixture['profile']->only(['accepts_sms_marketing', 'sms_opted_out_at']);

    $result = app(MarketingSmsExecutionService::class)->sendApprovedForCampaign($fixture['campaign'], ['tenant_id' => $fixture['tenant']->id, 'actor_id' => $fixture['actor']->id, 'recipient_ids' => [$recipient->id], 'limit' => 1]);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['MediaUrl'] === $fixture['asset']->public_url && $request['SendAsMms'] === 'true'
        && $request['Body'] === 'Fictional candle show this weekend. Reply STOP to opt out.' && $request['To'] === '+15551112222');
    $delivery = MarketingMessageDelivery::query()->where('campaign_recipient_id', $recipient->id)->firstOrFail();
    expect($result['sent'])->toBe(1)
        ->and(data_get($delivery->provider_payload, 'mms_media.asset_id'))->toBe($fixture['asset']->id)
        ->and(data_get($delivery->provider_payload, 'mms_media.sha256'))->toBe($fixture['asset']->metadata['content_sha256'])
        ->and(data_get($delivery->provider_payload, 'requested_delivery_mode'))->toBe('mms')
        ->and(data_get($recipient->fresh()->recommendation_snapshot, 'mms_media_approval.asset_id'))->toBe($fixture['asset']->id)
        ->and(MarketingSendApproval::query()->where('campaign_recipient_id', $recipient->id)->count())->toBe(1)
        ->and($fixture['profile']->fresh()->only(array_keys($beforeFlags)))->toBe($beforeFlags);
});

test('campaigns without attachments still make the same plain SMS request', function () {
    $fixture = campaignMmsFixture(false);
    $recipient = approveCampaignMmsFixture($fixture);
    $result = app(MarketingSmsExecutionService::class)->sendRecipient($recipient);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => ! isset($request['MediaUrl']) && ! isset($request['SendAsMms']));
    expect($result['outcome'])->toBe('sent');
});

test('a changed recipient identity cannot reuse an approved photo recipient', function () {
    $fixture = campaignMmsFixture();
    $recipient = approveCampaignMmsFixture($fixture);
    $otherProfile = MarketingProfile::factory()->create(['tenant_id' => $fixture['tenant']->id]);
    MarketingCampaignRecipient::whereKey($recipient->id)->update(['marketing_profile_id' => $otherProfile->id]);

    $result = app(MarketingSmsExecutionService::class)->sendRecipient($recipient);

    expect($result['reason'])->toBe('mms_media_invalid_or_unapproved')
        ->and($recipient->fresh()->marketing_profile_id)->toBe($otherProfile->id)
        ->and(MarketingMessageDelivery::count())->toBe(0);
    Http::assertNothingSent();
});

test('a queued photo recipient is never implicitly approved by execution', function () {
    $fixture = campaignMmsFixture();
    $result = app(MarketingSmsExecutionService::class)->sendRecipient($fixture['recipient']);

    Http::assertNothingSent();
    expect($result['reason'])->toBe('status_not_sendable')
        ->and($fixture['recipient']->fresh()->approved_at)->toBeNull()
        ->and(MarketingSendApproval::query()->count())->toBe(0);
});

test('a status flag without an approval-bound photo snapshot cannot dispatch MMS', function () {
    $fixture = campaignMmsFixture();
    $fixture['recipient']->forceFill(['status' => 'approved', 'approved_by' => $fixture['actor']->id, 'approved_at' => now()])->save();
    $result = app(MarketingSmsExecutionService::class)->sendRecipient($fixture['recipient']);

    Http::assertNothingSent();
    expect($result['reason'])->toBe('mms_media_invalid_or_unapproved');
});

test('invalid or altered photo assets fail closed before any provider attempt', function (string $change) {
    $fixture = campaignMmsFixture();
    $recipient = approveCampaignMmsFixture($fixture);
    $asset = $fixture['asset'];
    match ($change) {
        'foreign_tenant' => $asset->forceFill(['tenant_id' => Tenant::query()->create(['name' => 'Other fictional maker', 'slug' => 'other-'.Str::random(8)])->id])->save(),
        'wrong_store' => $asset->forceFill(['store_key' => 'another-store'])->save(),
        'wrong_channel' => $asset->forceFill(['channel' => 'email'])->save(),
        'wrong_disk' => $asset->forceFill(['disk' => 'local'])->save(),
        'missing_file' => Storage::disk('public')->delete($asset->path),
        'oversize' => (function () use ($asset) {
            Storage::disk('public')->put($asset->path, str_repeat('x', MarketingCampaignMmsMediaService::MAX_BYTES + 1));
            $asset->forceFill(['size_bytes' => MarketingCampaignMmsMediaService::MAX_BYTES + 1])->save();
        })(),
        'changed_bytes' => (function () use ($asset) {
            Storage::disk('public')->append($asset->path, 'changed');
            $asset->forceFill(['size_bytes' => Storage::disk('public')->size($asset->path)])->save();
        })(),
        'wrong_mime' => $asset->forceFill(['mime_type' => 'image/webp'])->save(),
        'external_url' => $asset->forceFill(['public_url' => 'https://foreign.example.test/photo.png'])->save(),
        'bad_path' => $asset->forceFill(['path' => 'messaging-media/tenant-'.$asset->tenant_id.'/'.$asset->store_key.'/sms/../email/photo.png'])->save(),
    };

    $result = app(MarketingSmsExecutionService::class)->sendRecipient($recipient);
    Http::assertNothingSent();
    expect($result['reason'])->toBe('mms_media_invalid_or_unapproved')
        ->and(MarketingMessageDelivery::query()->count())->toBe(0)
        ->and($recipient->fresh()->send_attempt_count)->toBe(0);
})->with(['foreign_tenant', 'wrong_store', 'wrong_channel', 'wrong_disk', 'missing_file', 'oversize', 'changed_bytes', 'wrong_mime', 'external_url', 'bad_path']);

test('invalid owned media cannot be prepared or approved', function () {
    $fixture = campaignMmsFixture();
    $fixture['asset']->forceFill(['channel' => 'email'])->save();

    expect(fn () => app(MarketingCampaignAudienceBuilder::class)->prepareRecipients($fixture['campaign']))->toThrow(ValidationException::class)
        ->and(fn () => approveCampaignMmsFixture($fixture))->toThrow(ValidationException::class);
    Http::assertNothingSent();
    expect(MarketingSendApproval::query()->count())->toBe(0);
});

test('replacing both draft media selections cannot reuse an earlier approval even in the same second', function () {
    $fixture = campaignMmsFixture();
    $recipient = approveCampaignMmsFixture($fixture);
    $replacement = campaignMmsAsset($fixture['campaign']);
    $selection = campaignMmsSelection($replacement);
    $fixture['campaign']->forceFill(['target_snapshot' => ['mms_media' => $selection]])->save();
    $snapshot = $recipient->recommendation_snapshot;
    $snapshot['mms_media'] = $selection;
    $recipient->forceFill(['recommendation_snapshot' => $snapshot])->save();

    $result = app(MarketingSmsExecutionService::class)->sendRecipient($recipient);
    Http::assertNothingSent();
    expect($result['reason'])->toBe('mms_media_invalid_or_unapproved');
});

test('a required photo cannot silently disappear from a recipient or campaign', function (string $location) {
    $fixture = campaignMmsFixture();
    $recipient = approveCampaignMmsFixture($fixture);
    if ($location === 'recipient') {
        $snapshot = $recipient->recommendation_snapshot;
        unset($snapshot['mms_media']);
        $recipient->forceFill(['recommendation_snapshot' => $snapshot])->save();
    } elseif ($location === 'campaign') {
        $fixture['campaign']->forceFill(['target_snapshot' => []])->save();
    } else {
        $fixture['campaign']->forceFill(['target_snapshot' => []])->save();
        $snapshot = $recipient->recommendation_snapshot;
        unset($snapshot['mms_media']);
        $recipient->forceFill(['recommendation_snapshot' => $snapshot])->save();
    }

    $result = app(MarketingSmsExecutionService::class)->sendRecipient($recipient);
    Http::assertNothingSent();
    expect($result['reason'])->toBe('mms_media_invalid_or_unapproved');
})->with(['recipient', 'campaign', 'both']);

test('repreparing the same photo preserves its approval and changed photos require normal reapproval', function () {
    $fixture = campaignMmsFixture();
    $recipient = approveCampaignMmsFixture($fixture);
    $snapshot = $recipient->recommendation_snapshot;
    $snapshot['consent_source_audit'] = ['basis' => 'fictional reviewed source evidence'];
    $recipient->forceFill(['recommendation_snapshot' => $snapshot])->save();
    $builder = app(MarketingCampaignAudienceBuilder::class);
    $builder->prepareRecipients($fixture['campaign']);
    expect($recipient->fresh()->status)->toBe('approved')
        ->and(data_get($recipient->fresh()->recommendation_snapshot, 'mms_media_approval.asset_id'))->toBe($fixture['asset']->id)
        ->and(data_get($recipient->fresh()->recommendation_snapshot, 'consent_source_audit.basis'))->toBe('fictional reviewed source evidence');

    $replacement = campaignMmsAsset($fixture['campaign']);
    $fixture['campaign']->forceFill(['target_snapshot' => ['mms_media' => campaignMmsSelection($replacement)]])->save();
    $builder->prepareRecipients($fixture['campaign']);
    expect($recipient->fresh()->status)->toBe('queued_for_approval')
        ->and($recipient->fresh()->approved_at)->toBeNull()
        ->and(data_get($recipient->fresh()->recommendation_snapshot, 'mms_media_approval'))->toBeNull();
    Http::assertNothingSent();

    $recipient = app(MarketingApprovalService::class)->approveRecipient($recipient->fresh(), $fixture['actor']->id);
    $result = app(MarketingSmsExecutionService::class)->sendRecipient($recipient);
    expect($result['outcome'])->toBe('sent')
        ->and(MarketingSendApproval::query()->where('campaign_recipient_id', $recipient->id)->count())->toBe(2);
    Http::assertSent(fn ($request) => $request['MediaUrl'] === $replacement->public_url);
});

test('preparing an attempted photo recipient cannot requeue or overwrite its send snapshot', function () {
    $fixture = campaignMmsFixture();
    $recipient = approveCampaignMmsFixture($fixture);
    app(MarketingSmsExecutionService::class)->sendRecipient($recipient);
    $replacement = campaignMmsAsset($fixture['campaign']);
    $fixture['campaign']->forceFill(['target_snapshot' => ['mms_media' => campaignMmsSelection($replacement)]])->save();
    app(MarketingCampaignAudienceBuilder::class)->prepareRecipients($fixture['campaign']);

    expect($recipient->fresh()->status)->toBe('sent')
        ->and(data_get($recipient->fresh()->recommendation_snapshot, 'mms_media.asset_id'))->toBe($fixture['asset']->id);
    Http::assertSentCount(1);
});

test('photo sends keep current SMS consent suppression and time guards', function (string $block) {
    $fixture = campaignMmsFixture();
    $recipient = approveCampaignMmsFixture($fixture);
    if ($block === 'consent') {
        $fixture['profile']->forceFill(['accepts_sms_marketing' => false])->save();
    } elseif ($block === 'suppression') {
        MessagingContactChannelState::query()->create(['tenant_id' => $fixture['tenant']->id, 'marketing_profile_id' => $fixture['profile']->id, 'phone' => $fixture['profile']->normalized_phone, 'sms_status' => 'unsubscribed', 'sms_status_reason' => 'stop']);
    } elseif ($block === 'window') {
        $fixture['campaign']->forceFill(['send_window_json' => ['start' => '13:00', 'end' => '14:00', 'timezone' => 'America/New_York']])->save();
    } else {
        $fixture['campaign']->forceFill(['quiet_hours_override_json' => ['start' => '11:00', 'end' => '13:00', 'timezone' => 'America/New_York']])->save();
    }

    $result = app(MarketingSmsExecutionService::class)->sendRecipient($recipient->fresh());
    Http::assertNothingSent();
    expect($result['outcome'])->toBe('skipped')->and(MarketingMessageDelivery::query()->count())->toBe(0);
})->with(['consent', 'suppression', 'window', 'quiet']);

test('an MMS provider error never retries as a photo-less SMS', function () {
    $fixture = campaignMmsFixture();
    $recipient = approveCampaignMmsFixture($fixture);
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['https://api.twilio.com/*' => Http::response(['code' => 21612, 'message' => 'MMS unsupported for destination'], 400)]);

    $result = app(MarketingSmsExecutionService::class)->sendRecipient($recipient);
    // Preserve the provider client's existing two transport attempts. Neither may
    // switch to plain SMS or drop the photo after the MMS rejection.
    Http::assertSentCount(2);
    expect(Http::recorded()->every(fn ($pair) => $pair[0]['MediaUrl'] === $fixture['asset']->public_url && $pair[0]['SendAsMms'] === 'true'))->toBeTrue()
        ->and($result['outcome'])->toBe('failed')
        ->and(MarketingMessageDelivery::query()->count())->toBe(1)
        ->and($recipient->fresh()->send_attempt_count)->toBe(1);
});

test('an explicit retry keeps the same approved photo while an ordinary resend stays blocked', function () {
    $fixture = campaignMmsFixture();
    $recipient = approveCampaignMmsFixture($fixture);
    $recipient->forceFill(['status' => 'failed', 'send_attempt_count' => 1])->save();
    $executor = app(MarketingSmsExecutionService::class);

    expect($executor->sendRecipient($recipient)['reason'])->toBe('status_not_sendable');
    Http::assertNothingSent();
    $result = $executor->retryRecipient($recipient);

    expect($result['outcome'])->toBe('sent')->and($recipient->fresh()->send_attempt_count)->toBe(2);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['MediaUrl'] === $fixture['asset']->public_url && $request['SendAsMms'] === 'true');
});

test('a photo message exceeding the MMS text limit fails before any provider attempt', function () {
    $fixture = campaignMmsFixture();
    $recipient = approveCampaignMmsFixture($fixture);
    $recipient->variant()->update(['message_text' => str_repeat('x', 1601)]);

    $result = app(MarketingSmsExecutionService::class)->sendRecipient($recipient->fresh());

    Http::assertNothingSent();
    expect($result['reason'])->toBe('mms_media_invalid_or_unapproved')
        ->and($recipient->fresh()->send_attempt_count)->toBe(0);
});

test('tracking cannot expand a photo message beyond the MMS limit or retain a provisional send', function () {
    $fixture = campaignMmsFixture();
    $recipient = approveCampaignMmsFixture($fixture);
    config()->set('marketing.links.enabled', true);
    config()->set('marketing.links.base_url', 'https://tracking.example.test');
    $recipient->variant()->update(['message_text' => str_repeat('x', 1500).' https://a.co']);

    $result = app(MarketingSmsExecutionService::class)->sendRecipient($recipient->fresh());

    Http::assertNothingSent();
    expect($result['reason'])->toBe('mms_media_invalid_or_unapproved')
        ->and($recipient->fresh()->send_attempt_count)->toBe(0)
        ->and(MarketingMessageDelivery::query()->count())->toBe(0)
        ->and(MarketingShortLink::query()->count())->toBe(0);
});

test('a fractional-second photo revision can be approved normally in that same database second', function () {
    $this->travelTo(now()->setMicrosecond(500000));
    $fixture = campaignMmsFixture();
    $selection = campaignMmsSelection($fixture['asset']);
    $selection['prepared_at'] = now()->toISOString();
    $fixture['campaign']->forceFill(['target_snapshot' => ['mms_media' => $selection]])->save();
    app(MarketingCampaignAudienceBuilder::class)->prepareRecipients($fixture['campaign']);
    $recipient = approveCampaignMmsFixture($fixture);

    $result = app(MarketingSmsExecutionService::class)->sendRecipient($recipient);

    expect($result['outcome'])->toBe('sent');
    Http::assertSentCount(1);
});

test('the MMS claim cannot overwrite a concurrent preparation rejection identity change or other claim', function (string $change) {
    $fixture = campaignMmsFixture();
    $recipient = approveCampaignMmsFixture($fixture);
    $expectedState = null;
    $fields = ['status', 'marketing_profile_id', 'recommendation_snapshot', 'approved_by', 'approved_at', 'send_attempt_count'];
    interruptCampaignMmsAfterValidation(function () use ($fixture, $recipient, $change, $fields, &$expectedState) {
        if (in_array($change, ['reprepared', 'reapproved'], true)) {
            $replacement = campaignMmsAsset($fixture['campaign']);
            $fixture['campaign']->update(['target_snapshot' => ['mms_media' => campaignMmsSelection($replacement)]]);
            app(MarketingCampaignAudienceBuilder::class)->prepareRecipients($fixture['campaign']);
            if ($change === 'reapproved') {
                app(MarketingApprovalService::class)->approveRecipient($recipient->fresh(), $fixture['actor']->id, 'Reviewed replacement photo.');
            }
        } elseif ($change === 'rejected') {
            app(MarketingApprovalService::class)->rejectRecipient($recipient->fresh(), $fixture['actor']->id, 'Do not send.');
        } elseif ($change === 'profile') {
            $otherProfile = MarketingProfile::create(['tenant_id' => $fixture['tenant']->id, 'phone' => '+15553334444', 'normalized_phone' => '+15553334444', 'accepts_sms_marketing' => true]);
            MarketingCampaignRecipient::whereKey($recipient->id)->update(['marketing_profile_id' => $otherProfile->id]);
        } else {
            MarketingCampaignRecipient::whereKey($recipient->id)->update(['status' => $change]);
        }
        $expectedState = $recipient->fresh()->only($fields);
    });

    $result = app(MarketingSmsExecutionService::class)->sendRecipient($recipient);

    Http::assertNothingSent();
    expect($result['outcome'])->toBe('skipped')
        ->and($result['reason'])->toBe('mms_claim_changed')
        ->and($recipient->fresh()->only($fields))->toEqual($expectedState)
        ->and($recipient->fresh()->send_attempt_count)->toBe(0)
        ->and(MarketingMessageDelivery::count())->toBe(0);
})->with(['reprepared', 'reapproved', 'rejected', 'profile', 'sending', 'sent']);

test('a second MMS worker cannot dispatch after another worker claims and completes the reviewed recipient', function () {
    $fixture = campaignMmsFixture();
    $recipient = approveCampaignMmsFixture($fixture);
    $winningResult = null;
    interruptCampaignMmsAfterValidation(function () use ($recipient, &$winningResult) {
        $winningResult = app(MarketingSmsExecutionService::class)->sendRecipient($recipient->fresh());
    });

    $losingResult = app(MarketingSmsExecutionService::class)->sendRecipient($recipient);

    expect($winningResult['outcome'])->toBe('sent')
        ->and($losingResult['reason'])->toBe('mms_claim_changed')
        ->and($recipient->fresh()->status)->toBe('sent')
        ->and($recipient->fresh()->send_attempt_count)->toBe(1)
        ->and(MarketingMessageDelivery::count())->toBe(1);
    Http::assertSentCount(1);
});

test('a photo added before a plain SMS claim cannot be silently dropped', function (bool $reapprove) {
    $fixture = campaignMmsFixture(false);
    $recipient = approveCampaignMmsFixture($fixture);
    $expectedState = null;
    $fields = ['status', 'recommendation_snapshot', 'approved_by', 'approved_at', 'send_attempt_count'];
    interruptCampaignMmsAfterValidation(function () use ($fixture, $recipient, $reapprove, $fields, &$expectedState) {
        $asset = campaignMmsAsset($fixture['campaign']);
        $fixture['campaign']->update(['target_snapshot' => ['mms_media' => campaignMmsSelection($asset)]]);
        app(MarketingCampaignAudienceBuilder::class)->prepareRecipients($fixture['campaign']);
        if ($reapprove) {
            app(MarketingApprovalService::class)->approveRecipient($recipient->fresh(), $fixture['actor']->id, 'Reviewed new photo.');
        }
        $expectedState = $recipient->fresh()->only($fields);
    });

    $result = app(MarketingSmsExecutionService::class)->sendRecipient($recipient);

    expect($result['reason'])->toBe('mms_claim_changed')
        ->and($recipient->fresh()->only($fields))->toEqual($expectedState)
        ->and(MarketingMessageDelivery::count())->toBe(0);
    Http::assertNothingSent();
})->with([false, true]);
