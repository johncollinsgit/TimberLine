<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingSetting;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MarketingSmsExecutionService
{
    public function __construct(
        protected TwilioSmsService $twilioSmsService,
        protected MarketingTemplateRenderer $templateRenderer,
        protected MarketingDeliveryTrackingService $deliveryTrackingService,
        protected MarketingIdentityNormalizer $normalizer,
        protected MarketingTenantOwnershipService $ownershipService,
        protected MessageClickTrackingService $messageClickTrackingService
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,int>
     */
    public function sendApprovedForCampaign(MarketingCampaign $campaign, array $options = []): array
    {
        $limit = max(1, (int) ($options['limit'] ?? 250));
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $actorId = isset($options['actor_id']) ? (int) $options['actor_id'] : null;
        $senderKey = $this->nullableString($options['sender_key'] ?? null);
        $requestedTenantId = $this->positiveInt($options['tenant_id'] ?? null);
        $strict = $this->ownershipService->strictModeEnabled();
        $tenantId = $requestedTenantId ?? ($strict ? $this->ownershipService->campaignOwnerTenantId((int) $campaign->id) : null);
        $recipientIds = collect((array) ($options['recipient_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($strict && $tenantId === null) {
            return [
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'dry_run' => 0,
            ];
        }

        if ($strict && $tenantId !== null && ! $this->ownershipService->campaignOwnedByTenant((int) $campaign->id, $tenantId)) {
            return [
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'dry_run' => 0,
            ];
        }

        $query = MarketingCampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('channel', 'sms')
            ->whereIn('status', ['approved', 'scheduled'])
            ->orderBy('scheduled_for')
            ->orderBy('id');

        if ($strict && $tenantId !== null) {
            $query->whereHas('profile', fn ($profileQuery) => $profileQuery->forTenantId($tenantId));
        }

        if ($recipientIds !== []) {
            $query->whereIn('id', $recipientIds);
        }

        $recipients = $query
            ->limit($limit)
            ->with(['campaign', 'profile', 'variant'])
            ->get();

        $summary = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'dry_run' => 0,
        ];

        foreach ($recipients as $recipient) {
            $result = $this->sendRecipient($recipient, [
                'dry_run' => $dryRun,
                'actor_id' => $actorId,
                'sender_key' => $senderKey,
                'tenant_id' => $tenantId,
            ]);
            $summary['processed']++;

            $outcome = (string) ($result['outcome'] ?? 'skipped');
            if ($outcome === 'sent') {
                $summary['sent']++;
            } elseif ($outcome === 'failed') {
                $summary['failed']++;
            } else {
                $summary['skipped']++;
            }

            if ((bool) ($result['dry_run'] ?? false)) {
                $summary['dry_run']++;
            }
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function retryRecipient(MarketingCampaignRecipient $recipient, array $options = []): array
    {
        return $this->sendRecipient($recipient, [
            ...$options,
            'force_retry' => true,
        ]);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function sendRecipient(MarketingCampaignRecipient $recipient, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $forceRetry = (bool) ($options['force_retry'] ?? false);
        $overrideWindows = (bool) ($options['override_windows'] ?? false);
        $actorId = isset($options['actor_id']) ? (int) $options['actor_id'] : null;
        $senderKey = $this->nullableString($options['sender_key'] ?? null);
        $tenantId = $this->positiveInt($options['tenant_id'] ?? null);
        $strict = $this->ownershipService->strictModeEnabled();

        $recipient->loadMissing(['campaign', 'profile', 'variant']);
        $campaign = $recipient->campaign;
        $profile = $recipient->profile;

        if (! $campaign || ! $profile) {
            return [
                'outcome' => 'failed',
                'reason' => 'missing_campaign_or_profile',
                'recipient_id' => $recipient->id,
                'dry_run' => $dryRun,
            ];
        }

        if ($strict && $tenantId === null) {
            $tenantId = $this->positiveInt($profile->tenant_id)
                ?? $this->ownershipService->campaignOwnerTenantId((int) $campaign->id);
        }

        if ($strict && $tenantId === null) {
            return [
                'outcome' => 'skipped',
                'reason' => 'tenant_context_required',
                'recipient_id' => $recipient->id,
                'status' => $recipient->status,
                'dry_run' => $dryRun,
            ];
        }

        if (
            $strict
            && $tenantId !== null
            && (int) ($profile->tenant_id ?? 0) !== $tenantId
        ) {
            return [
                'outcome' => 'skipped',
                'reason' => 'foreign_tenant_recipient',
                'recipient_id' => $recipient->id,
                'status' => $recipient->status,
                'dry_run' => $dryRun,
            ];
        }

        if ($recipient->channel !== 'sms' || strtolower((string) $campaign->channel) !== 'sms') {
            return $this->skipRecipient($recipient, 'non_sms_channel', 'Recipient/campaign channel is not SMS.');
        }

        $allowedStatuses = $forceRetry
            ? ['approved', 'scheduled', 'failed', 'undelivered']
            : ['approved', 'scheduled'];

        if (! in_array((string) $recipient->status, $allowedStatuses, true)) {
            return [
                'outcome' => 'skipped',
                'reason' => 'status_not_sendable',
                'recipient_id' => $recipient->id,
                'status' => $recipient->status,
                'dry_run' => $dryRun,
            ];
        }

        if (! (bool) $profile->accepts_sms_marketing) {
            return $this->skipRecipient($recipient, 'sms_not_consented', 'Recipient no longer has SMS consent.');
        }

        $toPhone = $this->normalizer->toE164((string) ($profile->normalized_phone ?: $profile->phone));
        if ($toPhone === null || $toPhone === '') {
            return $this->skipRecipient($recipient, 'missing_phone', 'Recipient has no sendable phone number.');
        }

        if (! $overrideWindows) {
            $windowCheck = $this->passesSendTimeGuards($campaign);
            if (! $windowCheck['allowed']) {
                return $this->skipRecipient(
                    $recipient,
                    (string) $windowCheck['reason'],
                    (string) ($windowCheck['note'] ?? 'Outside allowed send window.')
                );
            }
        }

        $messageText = $this->resolveMessageText($campaign, $recipient);
        if ($messageText === null) {
            return $this->markFailedWithoutProvider(
                $recipient,
                'missing_message',
                'No message text is available from variant/template snapshot.',
                $actorId
            );
        }

        $renderedMessage = trim($this->templateRenderer->renderCampaignMessage($campaign, $messageText, $profile));
        if ($renderedMessage === '') {
            return $this->markFailedWithoutProvider(
                $recipient,
                'empty_rendered_message',
                'Rendered message is empty after template variable substitution.',
                $actorId
            );
        }

        $attemptNumber = ((int) $recipient->send_attempt_count) + 1;
        $delivery = DB::transaction(function () use ($recipient, $campaign, $profile, $toPhone, $renderedMessage, $attemptNumber, $actorId): MarketingMessageDelivery {
            $recipient->refresh();

            $recipient->forceFill([
                'status' => 'sending',
                'send_attempt_count' => $attemptNumber,
                'last_send_attempt_at' => now(),
            ])->save();

            return MarketingMessageDelivery::query()->create([
                'campaign_id' => $campaign->id,
                'campaign_recipient_id' => $recipient->id,
                'marketing_profile_id' => $profile->id,
                'channel' => 'sms',
                'provider' => 'twilio',
                'to_phone' => $toPhone,
                'variant_id' => $recipient->variant_id,
                'attempt_number' => $attemptNumber,
                'rendered_message' => $renderedMessage,
                'send_status' => 'sending',
                'created_by' => $actorId,
            ]);
        });

        $trackedMessage = $this->messageClickTrackingService->decorateSmsMessageForDelivery(
            delivery: $delivery,
            message: $renderedMessage,
            createdBy: $actorId
        );
        $resolvedMessage = trim((string) ($trackedMessage['message'] ?? $renderedMessage));
        if ($resolvedMessage === '') {
            $resolvedMessage = $renderedMessage;
        }

        $delivery->forceFill([
            'rendered_message' => $resolvedMessage,
            'provider_payload' => [
                ...((array) $delivery->provider_payload),
                'tracked_links' => (array) ($trackedMessage['links'] ?? []),
            ],
        ])->save();

        $sendResult = $this->twilioSmsService->sendSms($toPhone, $resolvedMessage, [
            'dry_run' => $dryRun,
            'sender_key' => $senderKey ?: $this->senderKeyFromRecipient($recipient),
            'status_callback_url' => $this->statusCallbackUrl(),
        ]);

        $providerStatus = strtolower(trim((string) ($sendResult['status'] ?? 'failed')));
        $success = (bool) ($sendResult['success'] ?? false);

        $delivery->forceFill([
            'provider_message_id' => $sendResult['provider_message_id'] ?? null,
            'from_identifier' => $sendResult['from_identifier'] ?? null,
            'send_status' => $success ? $providerStatus : 'failed',
            'error_code' => $sendResult['error_code'] ?? null,
            'error_message' => $sendResult['error_message'] ?? null,
            'provider_payload' => [
                ...((array) $delivery->provider_payload),
                ...(is_array($sendResult['payload'] ?? null)
                    ? $sendResult['payload']
                    : ['raw' => $sendResult['payload'] ?? null]),
                'sender_key' => $sendResult['sender_key'] ?? ($senderKey ?: $this->senderKeyFromRecipient($recipient)),
                'sender_label' => $sendResult['sender_label'] ?? null,
            ],
            'sent_at' => $success && in_array($providerStatus, ['queued', 'sending', 'sent', 'delivered', 'undelivered'], true)
                ? now()
                : null,
            'delivered_at' => $success && $providerStatus === 'delivered' ? now() : null,
            'failed_at' => ! $success || in_array($providerStatus, ['failed', 'undelivered', 'canceled'], true) ? now() : null,
        ])->save();

        $recipientStatus = $success
            ? $this->recipientStatusFromProviderStatus($providerStatus)
            : 'failed';

        $recipient->forceFill([
            'status' => $recipientStatus,
            'last_status_note' => $success
                ? ($dryRun ? 'Dry-run send simulated.' : null)
                : (string) ($sendResult['error_message'] ?? 'Twilio send failed.'),
            'sent_at' => in_array($recipientStatus, ['sent', 'delivered', 'undelivered'], true)
                ? ($recipient->sent_at ?: now())
                : $recipient->sent_at,
            'delivered_at' => $recipientStatus === 'delivered'
                ? ($recipient->delivered_at ?: now())
                : $recipient->delivered_at,
            'failed_at' => in_array($recipientStatus, ['failed', 'undelivered'], true)
                ? ($recipient->failed_at ?: now())
                : $recipient->failed_at,
        ])->save();

        $this->deliveryTrackingService->appendEvent(
            delivery: $delivery,
            provider: 'twilio',
            providerMessageId: $delivery->provider_message_id,
            eventType: 'status_updated',
            eventStatus: $delivery->send_status,
            payload: [
                ...$sendResult,
                'campaign_recipient_id' => $recipient->id,
            ],
            occurredAt: now()
        );

        return [
            'outcome' => $success ? 'sent' : 'failed',
            'reason' => $success ? $delivery->send_status : 'provider_failure',
            'recipient_id' => $recipient->id,
            'delivery_id' => $delivery->id,
            'provider_message_id' => $delivery->provider_message_id,
            'status' => $recipient->status,
            'dry_run' => (bool) ($sendResult['dry_run'] ?? false),
        ];
    }

    /**
     * @return array{allowed:bool,reason:?string,note:?string}
     */
    protected function passesSendTimeGuards(MarketingCampaign $campaign): array
    {
        if (app()->environment('testing') && ! (bool) config('marketing.sms.enforce_send_windows_in_tests', false)) {
            return ['allowed' => true, 'reason' => null, 'note' => null];
        }

        $sendWindow = $this->resolveSendWindow($campaign);
        $quietHours = $this->resolveQuietHours($campaign);
        $timezone = (string) ($sendWindow['timezone'] ?? $quietHours['timezone'] ?? config('app.timezone', 'America/New_York'));

        $now = CarbonImmutable::now($timezone);
        $current = $now->format('H:i');

        if ($this->hasRange($sendWindow) && ! $this->isTimeInRange($current, (string) $sendWindow['start'], (string) $sendWindow['end'])) {
            return [
                'allowed' => false,
                'reason' => 'outside_send_window',
                'note' => "Current local time {$current} is outside configured send window.",
            ];
        }

        if ($this->hasRange($quietHours) && $this->isTimeInRange($current, (string) $quietHours['start'], (string) $quietHours['end'])) {
            return [
                'allowed' => false,
                'reason' => 'quiet_hours',
                'note' => "Current local time {$current} is inside configured quiet hours.",
            ];
        }

        return ['allowed' => true, 'reason' => null, 'note' => null];
    }

    /**
     * @return array<string,mixed>
     */
    protected function resolveSendWindow(MarketingCampaign $campaign): array
    {
        $campaignWindow = is_array($campaign->send_window_json) ? $campaign->send_window_json : [];
        if ($this->hasRange($campaignWindow)) {
            return [
                'start' => $campaignWindow['start'] ?? null,
                'end' => $campaignWindow['end'] ?? null,
                'timezone' => $campaignWindow['timezone'] ?? $this->defaultWindowTimezone(),
            ];
        }

        $setting = MarketingSetting::query()->where('key', 'sms_default_send_window')->first();
        $settingValue = is_array($setting?->value) ? $setting->value : [];
        if ($this->hasRange($settingValue)) {
            return [
                'start' => $settingValue['start'] ?? null,
                'end' => $settingValue['end'] ?? null,
                'timezone' => $settingValue['timezone'] ?? $this->defaultWindowTimezone(),
            ];
        }

        return (array) config('marketing.sms.send_window_fallback', []);
    }

    /**
     * @return array<string,mixed>
     */
    protected function resolveQuietHours(MarketingCampaign $campaign): array
    {
        $campaignQuiet = is_array($campaign->quiet_hours_override_json) ? $campaign->quiet_hours_override_json : [];
        if ($this->hasRange($campaignQuiet)) {
            return [
                'start' => $campaignQuiet['start'] ?? null,
                'end' => $campaignQuiet['end'] ?? null,
                'timezone' => $campaignQuiet['timezone'] ?? $this->defaultWindowTimezone(),
            ];
        }

        $setting = MarketingSetting::query()->where('key', 'sms_quiet_hours')->first();
        $settingValue = is_array($setting?->value) ? $setting->value : [];
        if ($this->hasRange($settingValue)) {
            return [
                'start' => $settingValue['start'] ?? null,
                'end' => $settingValue['end'] ?? null,
                'timezone' => $settingValue['timezone'] ?? $this->defaultWindowTimezone(),
            ];
        }

        return (array) config('marketing.sms.quiet_hours_fallback', []);
    }

    protected function defaultWindowTimezone(): string
    {
        return (string) config('app.timezone', 'America/New_York');
    }

    /**
     * @param array<string,mixed> $range
     */
    protected function hasRange(array $range): bool
    {
        $start = trim((string) ($range['start'] ?? ''));
        $end = trim((string) ($range['end'] ?? ''));

        return $start !== '' && $end !== '';
    }

    protected function isTimeInRange(string $current, string $start, string $end): bool
    {
        $currentMins = $this->toMinutes($current);
        $startMins = $this->toMinutes($start);
        $endMins = $this->toMinutes($end);
        if ($currentMins === null || $startMins === null || $endMins === null) {
            return true;
        }

        if ($startMins <= $endMins) {
            return $currentMins >= $startMins && $currentMins <= $endMins;
        }

        return $currentMins >= $startMins || $currentMins <= $endMins;
    }

    protected function toMinutes(string $value): ?int
    {
        $parts = explode(':', trim($value));
        if (count($parts) !== 2) {
            return null;
        }

        if (! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return null;
        }

        $hours = (int) $parts[0];
        $minutes = (int) $parts[1];
        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        return ($hours * 60) + $minutes;
    }

    protected function resolveMessageText(MarketingCampaign $campaign, MarketingCampaignRecipient $recipient): ?string
    {
        $direct = trim((string) ($recipient->variant?->message_text ?? ''));
        if ($direct !== '') {
            return $direct;
        }

        $snapshotMessage = trim((string) data_get($recipient->recommendation_snapshot, 'suggested_message', ''));
        if ($snapshotMessage !== '') {
            return $snapshotMessage;
        }

        $fallbackVariant = $campaign->variants()
            ->whereIn('status', ['active', 'draft'])
            ->orderByDesc('is_control')
            ->orderByDesc('weight')
            ->orderBy('id')
            ->first(['message_text']);

        $fallback = trim((string) ($fallbackVariant?->message_text ?? ''));

        return $fallback !== '' ? $fallback : null;
    }

    /**
     * @return array<string,mixed>
     */
    protected function markFailedWithoutProvider(
        MarketingCampaignRecipient $recipient,
        string $errorCode,
        string $errorMessage,
        ?int $actorId = null
    ): array {
        $recipient->forceFill([
            'status' => 'failed',
            'last_status_note' => $errorMessage,
            'failed_at' => $recipient->failed_at ?: now(),
        ])->save();

        $delivery = MarketingMessageDelivery::query()->create([
            'campaign_id' => $recipient->campaign_id,
            'campaign_recipient_id' => $recipient->id,
            'marketing_profile_id' => $recipient->marketing_profile_id,
            'channel' => 'sms',
            'provider' => 'twilio',
            'to_phone' => $recipient->profile?->normalized_phone ?: $recipient->profile?->phone,
            'variant_id' => $recipient->variant_id,
            'attempt_number' => max(1, (int) $recipient->send_attempt_count),
            'rendered_message' => (string) ($recipient->variant?->message_text ?: ''),
            'send_status' => 'failed',
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'failed_at' => now(),
            'created_by' => $actorId,
        ]);

        $this->deliveryTrackingService->appendEvent(
            delivery: $delivery,
            provider: 'twilio',
            providerMessageId: null,
            eventType: 'status_updated',
            eventStatus: 'failed',
            payload: [
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ],
            occurredAt: now()
        );

        return [
            'outcome' => 'failed',
            'reason' => $errorCode,
            'recipient_id' => $recipient->id,
            'delivery_id' => $delivery->id,
            'status' => $recipient->status,
            'dry_run' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function skipRecipient(MarketingCampaignRecipient $recipient, string $reason, string $note): array
    {
        $reasons = collect((array) $recipient->reason_codes)
            ->push($reason)
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->unique()
            ->values()
            ->all();

        $recipient->forceFill([
            'status' => 'skipped',
            'reason_codes' => $reasons,
            'last_status_note' => $note,
        ])->save();

        return [
            'outcome' => 'skipped',
            'reason' => $reason,
            'recipient_id' => $recipient->id,
            'status' => $recipient->status,
            'dry_run' => false,
        ];
    }

    protected function recipientStatusFromProviderStatus(string $providerStatus): string
    {
        return match ($providerStatus) {
            'queued', 'sending' => 'sending',
            'sent' => 'sent',
            'delivered' => 'delivered',
            'undelivered' => 'undelivered',
            default => 'failed',
        };
    }

    protected function statusCallbackUrl(): ?string
    {
        $configured = trim((string) config('marketing.twilio.status_callback_url', ''));
        if ($configured !== '') {
            return $configured;
        }

        try {
            $path = route('marketing.webhooks.twilio-status', [], false);
            $canonical = app(\App\Support\Tenancy\TenantHostBuilder::class)
                ->canonicalLandlordUrlForPath($path);

            return is_string($canonical) && $canonical !== ''
                ? $canonical
                : route('marketing.webhooks.twilio-status');
        } catch (\Throwable) {
            return null;
        }
    }

    protected function senderKeyFromRecipient(MarketingCampaignRecipient $recipient): ?string
    {
        return $this->nullableString(data_get($recipient->recommendation_snapshot, 'sender_key'));
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }
}
