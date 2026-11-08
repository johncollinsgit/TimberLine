<?php

namespace App\Services\Marketing;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingEmailDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketingEmailExecutionService
{
    public function __construct(
        protected SendGridEmailService $sendGridEmailService,
        protected MarketingTemplateRenderer $templateRenderer,
        protected MarketingEmailReadiness $emailReadiness,
        protected MarketingTenantOwnershipService $ownershipService
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,int>
     */
    public function sendApprovedForCampaign(MarketingCampaign $campaign, array $options = []): array
    {
        $limit = max(1, (int) ($options['limit'] ?? 250));
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $actorId = isset($options['actor_id']) ? (int) $options['actor_id'] : null;
        $batchId = $this->nullableString($options['batch_id'] ?? null)
            ?? ('cmp-'.$campaign->id.'-'.strtolower((string) Str::ulid()));
        $sourceLabel = $this->nullableString($options['source_label'] ?? null)
            ?? $this->nullableString($campaign->source_label)
            ?? 'marketing_campaign';
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
                'unknown' => 0,
                'skipped' => 0,
                'dry_run' => 0,
            ];
        }

        if ($strict && $tenantId !== null && ! $this->ownershipService->campaignOwnedByTenant((int) $campaign->id, $tenantId)) {
            return [
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
                'unknown' => 0,
                'skipped' => 0,
                'dry_run' => 0,
            ];
        }

        $query = MarketingCampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('channel', 'email')
            ->whereIn('status', ['approved', 'scheduled'])
            ->orderBy('scheduled_for')
            ->orderBy('id');

        if ($strict && $tenantId !== null) {
            $query->whereHas('profile', fn ($profileQuery) => $profileQuery->forTenantId($tenantId));
        }

        if ($recipientIds !== []) {
            $query->whereIn('id', $recipientIds);
        }

        $summary = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'unknown' => 0,
            'skipped' => 0,
            'dry_run' => 0,
        ];

        foreach ($query->limit($limit)->with(['campaign', 'profile', 'variant'])->get() as $recipient) {
            $result = $this->sendRecipient($recipient, [
                'dry_run' => $dryRun,
                'actor_id' => $actorId,
                'tenant_id' => $tenantId,
                'batch_id' => $batchId,
                'source_label' => $sourceLabel,
            ]);
            $summary['processed']++;
            if (($result['outcome'] ?? '') === 'sent') {
                $summary['sent']++;
            } elseif (($result['outcome'] ?? '') === 'failed') {
                $summary['failed']++;
            } elseif (($result['outcome'] ?? '') === 'unknown') {
                $summary['unknown']++;
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
     * @param  array<string,mixed>  $options
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
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function sendRecipient(MarketingCampaignRecipient $recipient, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $forceRetry = (bool) ($options['force_retry'] ?? false);
        $actorId = isset($options['actor_id']) ? (int) $options['actor_id'] : null;
        $tenantId = $this->positiveInt($options['tenant_id'] ?? null);
        $batchId = $this->nullableString($options['batch_id'] ?? null);
        $sourceLabel = $this->nullableString($options['source_label'] ?? null);
        $strict = $this->ownershipService->strictModeEnabled();
        $dispatchContext = null;

        $recipient->loadMissing(['campaign', 'profile', 'variant']);
        $campaign = $recipient->campaign;
        $profile = $recipient->profile;

        if (! $campaign || ! $profile) {
            return ['outcome' => 'failed', 'reason' => 'missing_campaign_or_profile', 'dry_run' => $dryRun];
        }

        $batchId = $batchId
            ?? ('cmp-'.$campaign->id.'-'.strtolower((string) Str::ulid()));
        $sourceLabel = $sourceLabel
            ?? $this->nullableString($campaign->source_label)
            ?? 'marketing_campaign';

        if ($strict && $tenantId === null) {
            $dispatchContext = $this->resolveProfileDispatchContext($profile);
            $tenantId = $this->positiveInt($dispatchContext['tenant_id'] ?? null)
                ?? $this->positiveInt($profile->tenant_id)
                ?? $this->ownershipService->campaignOwnerTenantId((int) $campaign->id);
        }

        if ($strict && $tenantId === null) {
            return [
                'outcome' => 'skipped',
                'reason' => 'tenant_context_required',
                'dry_run' => $dryRun,
            ];
        }

        $profileOwnerTenantId = $this->positiveInt($profile->tenant_id);
        if ($profileOwnerTenantId === null && $tenantId !== null) {
            $dispatchContext = is_array($dispatchContext) ? $dispatchContext : $this->resolveProfileDispatchContext($profile);
            $profileOwnerTenantId = $this->positiveInt($dispatchContext['tenant_id'] ?? null);
        }

        if (
            $strict
            && $tenantId !== null
            && $profileOwnerTenantId !== $tenantId
        ) {
            return [
                'outcome' => 'skipped',
                'reason' => 'foreign_tenant_recipient',
                'dry_run' => $dryRun,
            ];
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
        $profileHtml = null;
        if ($campaign->source_label === 'customer_profile') {
            $prepared = app(CustomerOutreachDraft::class)->emailContent($campaign, $profile, $messageText);
            if (! $prepared) {
                return ['outcome' => 'failed', 'reason' => 'customer_profile_draft_changed', 'dry_run' => $dryRun];
            }
            $subject = $prepared['subject'];
            $rendered = $prepared['body'];
            $profileHtml = $prepared['html'];
        }
        $dispatchContext = is_array($dispatchContext) ? $dispatchContext : $this->resolveProfileDispatchContext($profile);
        $dispatchTenantId = $dispatchContext['tenant_id'];
        $storeKey = $dispatchContext['store_key'];
        $storeContext = $dispatchContext['store_context'];
        $providerContext = $this->emailReadiness->providerContextForDelivery($dispatchTenantId, [
            'store_context' => $storeContext,
            'store_key' => $storeKey,
        ]);
        $dispatchTenantId = $dispatchTenantId ?? $this->positiveInt($providerContext['tenant_id'] ?? null);
        if ($dispatchTenantId !== null) {
            $storeContext['tenant_id'] = $dispatchTenantId;
        }
        $resolvedProvider = trim((string) ($providerContext['provider'] ?? 'sendgrid')) ?: 'sendgrid';

        if ($subject === '') {
            return ['outcome' => 'failed', 'reason' => 'missing_subject', 'dry_run' => $dryRun];
        }

        $blocked = [];
        $delivery = DB::transaction(function () use ($recipient, $profile, $email, $actorId, $campaign, $resolvedProvider, $providerContext, $dispatchTenantId, $storeKey, $batchId, $sourceLabel, $subject, $messageText, $rendered, $profileHtml, $dryRun, &$blocked): ?MarketingEmailDelivery {
            $decisionAudit = null;
            if ($campaign->source_label === 'customer_profile') {
                $scope = (int) $campaign->tenant_id;
                app(CustomerAccountFence::class)->lock($scope);
                \App\Models\Tenant::whereKey($scope)->lockForUpdate()->firstOrFail();
                $freshCampaign = MarketingCampaign::forTenantId($scope)->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
                $freshProfile = \App\Models\MarketingProfile::forTenantId($scope)->whereKey($profile->id)->lockForUpdate()->firstOrFail();
                $freshRecipient = MarketingCampaignRecipient::whereKey($recipient->id)->where('campaign_id', $freshCampaign->id)->where('marketing_profile_id', $freshProfile->id)->lockForUpdate()->firstOrFail();
                $decision = app(CustomerOutreachExplanation::class)->dispatchDecision($freshCampaign, $freshProfile);
                if (! in_array($freshRecipient->status, ['approved', 'scheduled'], true) || $freshRecipient->channel !== 'email'
                    || in_array($freshCampaign->status, ['paused', 'completed', 'archived'], true)
                    || ($freshRecipient->scheduled_for && $freshRecipient->scheduled_for->isFuture())) {
                    $decision['reason_codes'][] = 'outreach_recipient_not_sendable';
                }
                if (trim((string) ($freshProfile->normalized_email ?: $freshProfile->email)) !== $email
                    || (int) $dispatchTenantId !== $scope) {
                    $decision['reason_codes'][] = 'outreach_customer_contact_changed';
                }
                $currentBody = trim((string) $freshRecipient->variant?->message_text);
                $currentPrepared = app(CustomerOutreachDraft::class)->emailContent($freshCampaign, $freshProfile, $currentBody);
                if ($currentBody !== $messageText || ! $currentPrepared || $currentPrepared['html'] !== $profileHtml
                    || $currentPrepared['body'] !== $rendered || $currentPrepared['subject'] !== $subject) {
                    $decision['reason_codes'][] = 'outreach_prepared_content_changed';
                }
                $decision['reason_codes'] = array_values(array_unique($decision['reason_codes']));
                $decision['allowed'] = $decision['reason_codes'] === [];
                $decisionAudit = app(CustomerOutreachExplanation::class)->recordDispatchDecision($freshCampaign, $freshProfile, $decision, $actorId);
                if (! $decision['allowed']) {
                    $blocked = $decision['reason_codes'];

                    return null;
                }
                $recipient->setRawAttributes($freshRecipient->getAttributes(), true);
            }
            $recipient->forceFill([
                'status' => 'sending',
                'send_attempt_count' => ((int) $recipient->send_attempt_count) + 1,
                'last_send_attempt_at' => now(),
            ])->save();

            return MarketingEmailDelivery::query()->create([
                'marketing_campaign_recipient_id' => $recipient->id,
                'marketing_profile_id' => $profile->id,
                'tenant_id' => $dispatchTenantId,
                'store_key' => $storeKey,
                'batch_id' => $batchId,
                'source_label' => $sourceLabel,
                'message_subject' => $subject,
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
                    ...($campaign->source_label === 'customer_profile' ? ['purpose' => 'promotional', 'dry_run' => $dryRun, 'outreach_decision_audit_id' => $decisionAudit] : []),
                    'tenant_id' => $dispatchTenantId,
                    'customer_id' => $profile->id,
                    'store_key' => $storeKey,
                    'batch_id' => $batchId,
                    'source_label' => $sourceLabel,
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
        }, 3);
        if (! $delivery) {
            return ['outcome' => 'skipped', 'reason' => 'customer_outreach_policy_blocked', 'reason_codes' => $blocked, 'dry_run' => $dryRun];
        }

        $send = $this->sendGridEmailService->sendEmail($email, $subject, $rendered, [
            ...($profileHtml !== null ? ['html_body' => $profileHtml] : []),
            'dry_run' => $dryRun,
            'tenant_id' => $dispatchTenantId,
            'delivery_id' => $delivery->id,
            'idempotency_key' => 'marketing-email-delivery:'.$delivery->id,
            'ledger_source_type' => 'marketing_email_delivery',
            'source_id' => $delivery->id,
            'store_context' => $storeContext,
            'store_key' => $storeKey,
            'is_marketing' => true,
            'campaign_type' => trim((string) ($campaign->objective ?: 'campaign')) ?: 'campaign',
            'template_key' => $recipient->variant?->variant_key,
            'customer_id' => $profile->id,
            'coupon_code' => data_get($recipient->recommendation_snapshot, 'coupon_code'),
            'metadata' => [
                'campaign_id' => $campaign->id,
                'campaign_recipient_id' => $recipient->id,
                'batch_id' => $batchId,
                'source_label' => $sourceLabel,
                'shopify_store_key' => $storeKey,
                'provider_resolution_source' => (string) ($providerContext['resolution_source'] ?? 'none'),
                'provider_readiness_status' => (string) ($providerContext['readiness_status'] ?? 'error'),
            ],
            'categories' => [
                'marketing-campaign',
                'campaign-'.$campaign->id,
            ],
            'custom_args' => [
                'marketing_email_delivery_id' => (string) $delivery->id,
                'marketing_campaign_recipient_id' => (string) $recipient->id,
            ],
        ]);

        $success = (bool) ($send['success'] ?? false);
        $simulation = $dryRun || (bool) ($send['dry_run'] ?? false);
        if ($campaign->source_label === 'customer_profile' && $simulation) {
            $delivery->forceFill(['metadata' => [...((array) $delivery->metadata), 'dry_run' => true]])->save();
        }
        if ($campaign->source_label === 'customer_profile' && ! $simulation
            && (($success && ! filled($send['message_id'] ?? null))
                || (! $success && (($send['retryable'] ?? false) || ($send['status'] ?? '') === 'unknown'
                    || preg_match('/exception|timeout|transport|connection/i', (string) ($send['error_code'] ?? '')))))) {
            $delivery->forceFill(['status' => 'unknown', 'raw_payload' => [...((array) $delivery->raw_payload), 'provider_result' => $send]])->save();
            $recipient->forceFill(['status' => 'sending', 'last_status_note' => 'Provider acceptance is unconfirmed. Reconcile this original delivery before another attempt.'])->save();

            return ['outcome' => 'unknown', 'reason' => 'provider_outcome_unconfirmed', 'delivery_id' => $delivery->id, 'dry_run' => false];
        }
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

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
