<?php

namespace App\Services\Marketing;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingEmailDelivery;
use Illuminate\Support\Facades\DB;

class MarketingEmailExecutionService
{
    public function __construct(
        protected SendGridEmailService $sendGridEmailService,
        protected MarketingTemplateRenderer $templateRenderer,
        protected MarketingEmailReadiness $emailReadiness
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
        $recipientIds = collect((array) ($options['recipient_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $query = MarketingCampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('channel', 'email')
            ->whereIn('status', ['approved', 'scheduled'])
            ->orderBy('scheduled_for')
            ->orderBy('id');

        if ($recipientIds !== []) {
            $query->whereIn('id', $recipientIds);
        }

        $summary = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'dry_run' => 0,
        ];

        foreach ($query->limit($limit)->with(['campaign', 'profile', 'variant'])->get() as $recipient) {
            $result = $this->sendRecipient($recipient, [
                'dry_run' => $dryRun,
                'actor_id' => $actorId,
            ]);
            $summary['processed']++;
            if (($result['outcome'] ?? '') === 'sent') {
                $summary['sent']++;
            } elseif (($result['outcome'] ?? '') === 'failed') {
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
        $actorId = isset($options['actor_id']) ? (int) $options['actor_id'] : null;

        $recipient->loadMissing(['campaign', 'profile', 'variant']);
        $campaign = $recipient->campaign;
        $profile = $recipient->profile;

        if (! $campaign || ! $profile) {
            return ['outcome' => 'failed', 'reason' => 'missing_campaign_or_profile', 'dry_run' => $dryRun];
        }

        if ($recipient->channel !== 'email' || strtolower((string) $campaign->channel) !== 'email') {
            return $this->skipRecipient($recipient, 'non_email_channel', 'Recipient/campaign channel is not email.');
        }

        $allowed = $forceRetry ? ['approved', 'scheduled', 'failed', 'undelivered'] : ['approved', 'scheduled'];
        if (! in_array((string) $recipient->status, $allowed, true)) {
            return ['outcome' => 'skipped', 'reason' => 'status_not_sendable', 'dry_run' => $dryRun];
        }

        if (! (bool) $profile->accepts_email_marketing) {
            return $this->skipRecipient($recipient, 'email_not_consented', 'Profile no longer has email consent.');
        }

        $email = trim((string) ($profile->normalized_email ?: $profile->email));
        if ($email === '') {
            return $this->skipRecipient($recipient, 'missing_email', 'Profile has no deliverable email address.');
        }

        $messageText = trim((string) ($recipient->variant?->message_text ?: data_get($recipient->recommendation_snapshot, 'suggested_message', '')));
        if ($messageText === '') {
            $fallbackVariant = $campaign->variants()
                ->whereIn('status', ['active', 'draft'])
                ->orderByDesc('is_control')
                ->orderByDesc('weight')
                ->orderBy('id')
                ->first(['message_text']);
            $messageText = trim((string) ($fallbackVariant?->message_text ?? ''));
        }
        if ($messageText === '') {
            return ['outcome' => 'failed', 'reason' => 'missing_message', 'dry_run' => $dryRun];
        }

        $subjectTemplate = trim((string) (data_get($recipient->recommendation_snapshot, 'email_subject') ?: $campaign->name ?: 'Timberline Update'));
        $subject = trim($this->templateRenderer->renderCampaignMessage($campaign, $subjectTemplate, $profile));
        $rendered = $this->templateRenderer->renderCampaignMessage($campaign, $messageText, $profile);
        $dispatchContext = $this->resolveProfileDispatchContext($profile);
        $tenantId = $dispatchContext['tenant_id'];
        $storeKey = $dispatchContext['store_key'];
        $storeContext = $dispatchContext['store_context'];
        $providerContext = $this->emailReadiness->providerContextForDelivery($tenantId, [
            'store_context' => $storeContext,
            'store_key' => $storeKey,
        ]);
        $tenantId = $tenantId ?? $this->positiveInt($providerContext['tenant_id'] ?? null);
        if ($tenantId !== null) {
            $storeContext['tenant_id'] = $tenantId;
        }
        $resolvedProvider = trim((string) ($providerContext['provider'] ?? 'sendgrid')) ?: 'sendgrid';

        if ($subject === '') {
            return ['outcome' => 'failed', 'reason' => 'missing_subject', 'dry_run' => $dryRun];
        }

        $delivery = DB::transaction(function () use ($recipient, $profile, $email, $actorId, $campaign, $resolvedProvider, $providerContext, $tenantId, $storeKey): MarketingEmailDelivery {
            $recipient->forceFill([
                'status' => 'sending',
                'send_attempt_count' => ((int) $recipient->send_attempt_count) + 1,
                'last_send_attempt_at' => now(),
            ])->save();

            return MarketingEmailDelivery::query()->create([
                'marketing_campaign_recipient_id' => $recipient->id,
                'marketing_profile_id' => $profile->id,
                'tenant_id' => $tenantId,
                'provider' => $resolvedProvider,
                'campaign_type' => trim((string) ($campaign->objective ?: 'campaign')) ?: 'campaign',
                'template_key' => $recipient->variant?->variant_key,
                'email' => $email,
                'status' => 'sending',
                'raw_payload' => [
                    'actor_id' => $actorId,
                    'provider_resolution' => $providerContext,
                ],
                'metadata' => [
                    'tenant_id' => $tenantId,
                    'customer_id' => $profile->id,
                    'campaign_type' => trim((string) ($campaign->objective ?: 'campaign')) ?: 'campaign',
                    'template_key' => $recipient->variant?->variant_key,
                    'coupon_code' => data_get($recipient->recommendation_snapshot, 'coupon_code'),
                    'shopify_store_key' => $storeKey,
                    'provider' => $resolvedProvider,
                    'provider_resolution_source' => (string) ($providerContext['resolution_source'] ?? 'none'),
                    'provider_readiness_status' => (string) ($providerContext['readiness_status'] ?? 'error'),
                    'provider_config_status' => (string) ($providerContext['config_status'] ?? 'error'),
                    'provider_using_fallback_config' => (bool) ($providerContext['using_fallback_config'] ?? false),
                ],
            ]);
        });

        $send = $this->sendGridEmailService->sendEmail($email, $subject, $rendered, [
            'dry_run' => $dryRun,
            'tenant_id' => $tenantId,
            'store_context' => $storeContext,
            'store_key' => $storeKey,
            'campaign_type' => trim((string) ($campaign->objective ?: 'campaign')) ?: 'campaign',
            'template_key' => $recipient->variant?->variant_key,
            'customer_id' => $profile->id,
            'coupon_code' => data_get($recipient->recommendation_snapshot, 'coupon_code'),
            'metadata' => [
                'campaign_id' => $campaign->id,
                'campaign_recipient_id' => $recipient->id,
                'shopify_store_key' => $storeKey,
                'provider_resolution_source' => (string) ($providerContext['resolution_source'] ?? 'none'),
                'provider_readiness_status' => (string) ($providerContext['readiness_status'] ?? 'error'),
            ],
            'categories' => [
                'marketing-campaign',
                'campaign-' . $campaign->id,
            ],
            'custom_args' => [
                'marketing_email_delivery_id' => (string) $delivery->id,
                'marketing_campaign_recipient_id' => (string) $recipient->id,
            ],
        ]);

        $success = (bool) ($send['success'] ?? false);
        $provider = trim((string) ($send['provider'] ?? 'sendgrid')) ?: 'sendgrid';
        $delivery->forceFill([
            'provider' => $provider,
            'provider_message_id' => $send['message_id'] ?? null,
            'sendgrid_message_id' => $provider === 'sendgrid' ? ($send['message_id'] ?? null) : null,
            'status' => $success ? 'sent' : 'failed',
            'sent_at' => $success ? now() : null,
            'failed_at' => $success ? null : now(),
            'raw_payload' => [
                ...((array) ($delivery->raw_payload ?? [])),
                'provider' => $provider,
                'payload' => is_array($send['payload'] ?? null) ? $send['payload'] : ['raw' => $send],
                'provider_result' => [
                    'status' => $send['status'] ?? null,
                    'error_code' => $send['error_code'] ?? null,
                    'error_message' => $send['error_message'] ?? null,
                    'retryable' => (bool) ($send['retryable'] ?? false),
                ],
            ],
            'metadata' => [
                ...((array) ($delivery->metadata ?? [])),
                'provider' => $provider,
                'provider_resolution_source' => (string) ($providerContext['resolution_source'] ?? 'none'),
                'provider_readiness_status' => (string) ($providerContext['readiness_status'] ?? 'error'),
                'provider_config_status' => (string) ($providerContext['config_status'] ?? 'error'),
                'provider_using_fallback_config' => (bool) ($providerContext['using_fallback_config'] ?? false),
                'error_code' => $send['error_code'] ?? null,
            ],
        ])->save();

        $recipient->forceFill([
            'status' => $success ? 'sent' : 'failed',
            'sent_at' => $success ? ($recipient->sent_at ?: now()) : $recipient->sent_at,
            'failed_at' => ! $success ? ($recipient->failed_at ?: now()) : $recipient->failed_at,
            'last_status_note' => $success ? null : (string) ($send['error_message'] ?? 'SendGrid send failed.'),
        ])->save();

        return [
            'outcome' => $success ? 'sent' : 'failed',
            'reason' => $success ? 'sent' : 'provider_failure',
            'delivery_id' => $delivery->id,
            'dry_run' => (bool) ($send['dry_run'] ?? false),
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

        return ['outcome' => 'skipped', 'reason' => $reason, 'dry_run' => false];
    }

    /**
     * @return array{tenant_id:?int,store_key:?string,store_context:array<string,mixed>}
     */
    protected function resolveProfileDispatchContext(\App\Models\MarketingProfile $profile): array
    {
        $tenantId = $this->positiveInt($profile->tenant_id);
        $storeKey = null;

        $external = CustomerExternalProfile::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('provider', 'shopify')
            ->orderByDesc('synced_at')
            ->orderByDesc('id')
            ->first();

        if (! $external) {
            $external = CustomerExternalProfile::query()
                ->where('marketing_profile_id', $profile->id)
                ->orderByDesc('synced_at')
                ->orderByDesc('id')
                ->first();
        }

        if ($external instanceof CustomerExternalProfile) {
            if ($tenantId === null) {
                $tenantId = $this->positiveInt($external->tenant_id);
            }
            $storeKey = $this->normalizeStoreKey($external->store_key);
        }

        $storeContext = [];
        if ($storeKey !== null) {
            $storeContext['key'] = $storeKey;
        }
        if ($tenantId !== null) {
            $storeContext['tenant_id'] = $tenantId;
        }

        return [
            'tenant_id' => $tenantId,
            'store_key' => $storeKey,
            'store_context' => $storeContext,
        ];
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }

    protected function normalizeStoreKey(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }
}
