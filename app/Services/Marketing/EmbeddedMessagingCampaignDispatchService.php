<?php

namespace App\Services\Marketing;

use App\Jobs\DispatchMessagingCampaignBatch;
use App\Jobs\PrepareMessagingCampaignRecipientsJob;
use App\Jobs\ProcessMessagingCampaignRecipientJob;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageJob;
use App\Models\MarketingProfile;
use App\Models\MarketingTemplateDefinition;
use App\Models\MarketingTemplateInstance;
use App\Services\Marketing\Sms\SmsLinkShorteningService;
use App\Services\Shopify\ShopifyEmbeddedEmailComposerService;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmbeddedMessagingCampaignDispatchService
{
    public function __construct(
        protected TwilioSmsService $twilioSmsService,
        protected SendGridEmailService $sendGridEmailService,
        protected MarketingDeliveryTrackingService $deliveryTrackingService,
        protected MessageClickTrackingService $messageClickTrackingService,
        protected EmailLinkAttributionService $emailLinkAttributionService,
        protected MessagingCampaignProgressService $progressService,
        protected MessagingRetryPolicy $retryPolicy,
        protected MarketingIdentityNormalizer $identityNormalizer,
        protected SmsLinkShorteningService $smsLinkShorteningService,
        protected ShopifyEmbeddedEmailComposerService $emailComposerService,
        protected SmsMessageSafetyService $smsMessageSafetyService,
        protected MessagingEmailReplyAddressService $emailReplyAddressService
    ) {
    }

    /**
     * @param  array<int,array{profile_id:?int,name:?string,email:?string,phone:?string,normalized_phone:?string,source_type:string}>  $recipients
     * @param  array<string,mixed>  $target
     * @param  array<int,int>  $forceSendProfileIds
     * @param  array<string,mixed>|null  $emailTemplate
     * @return array{campaign:array<string,mixed>,summary:array<string,mixed>}
     */
    public function queueCampaign(
        ?int $tenantId,
        ?string $storeKey,
        string $channel,
        array $target,
        array $recipients,
        string $body,
        ?string $subject = null,
        ?string $senderKey = null,
        ?int $actorId = null,
        ?string $sourceLabel = null,
        array $forceSendProfileIds = [],
        ?array $emailTemplate = null,
        ?string $htmlBody = null,
        mixed $scheduleFor = null,
        bool $shortenLinks = false
    ): array {
        $resolvedChannel = strtolower(trim($channel));
        $resolvedStoreKey = $this->nullableString($storeKey);
        $resolvedSubject = $this->nullableString($subject);
        $resolvedSenderKey = $this->nullableString($senderKey);
        $resolvedSourceLabel = $this->nullableString($sourceLabel) ?: 'shopify_embedded_messaging_group';
        $resolvedBody = trim($body);
        $resolvedHtmlBody = $this->nullableString($htmlBody);
        $resolvedScheduleFor = $this->resolvedDate($scheduleFor);
        $now = CarbonImmutable::now();
        $scheduleAt = $resolvedScheduleFor !== null && $resolvedScheduleFor->greaterThan($now)
            ? $resolvedScheduleFor
            : $now;

        $templateInstance = null;
        $campaign = DB::transaction(function () use (
            $tenantId,
            $resolvedStoreKey,
            $resolvedChannel,
            $resolvedSourceLabel,
            $resolvedSubject,
            $resolvedBody,
            $resolvedHtmlBody,
            $target,
            $scheduleAt,
            $now,
            $emailTemplate,
            $actorId,
            &$templateInstance
        ): MarketingCampaign {
            $targetName = trim((string) data_get($target, 'name', 'Audience'));
            $campaignName = sprintf(
                '%s · %s · %s',
                strtoupper($resolvedChannel),
                $targetName !== '' ? $targetName : 'Audience',
                $now->format('M j g:i A')
            );

            $templateInstanceId = null;
            if ($resolvedChannel === 'email') {
                $templateInstance = $this->createTemplateInstance(
                    tenantId: $tenantId,
                    storeKey: $resolvedStoreKey,
                    channel: 'email',
                    campaignId: null,
                    actorId: $actorId,
                    template: is_array($emailTemplate) ? $emailTemplate : [],
                    subject: $resolvedSubject,
                    body: $resolvedBody,
                    html: $resolvedHtmlBody
                );
                $templateInstanceId = $templateInstance?->id;
            }

            $campaign = MarketingCampaign::query()->create([
                'tenant_id' => $tenantId,
                'store_key' => $resolvedStoreKey,
                'name' => Str::limit($campaignName, 120),
                'slug' => null,
                'description' => null,
                'status' => $scheduleAt->greaterThan($now) ? 'draft' : 'sending',
                'channel' => $resolvedChannel,
                'source_label' => $resolvedSourceLabel,
                'message_subject' => $resolvedSubject,
                'message_body' => $resolvedBody,
                'message_html' => $resolvedHtmlBody,
                'target_snapshot' => $target,
                'status_counts' => [
                    'scheduled' => 0,
                    'skipped' => 0,
                ],
                'queued_at' => $now,
                'scheduled_for' => $scheduleAt,
                'launched_at' => $scheduleAt->greaterThan($now) ? null : $now,
                'template_instance_id' => $templateInstanceId,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            if ($templateInstance instanceof MarketingTemplateInstance) {
                $templateInstance->forceFill([
                    'campaign_id' => (int) $campaign->id,
                ])->save();
            }

            return $campaign;
        });

        PrepareMessagingCampaignRecipientsJob::dispatch(
            campaignId: (int) $campaign->id,
            tenantId: $tenantId,
            storeKey: $resolvedStoreKey,
            channel: $resolvedChannel,
            target: $target,
            recipients: $recipients,
            body: $resolvedBody,
            subject: $resolvedSubject,
            senderKey: $resolvedSenderKey,
            actorId: $actorId,
            sourceLabel: $resolvedSourceLabel,
            forceSendProfileIds: $forceSendProfileIds,
            emailTemplate: $emailTemplate,
            htmlBody: $resolvedHtmlBody,
            scheduleFor: $scheduleAt->toIso8601String(),
            shortenLinks: $shortenLinks
        )->onQueue($this->queueName());

        $summary = [
            'processed' => count($recipients),
            'scheduled' => count($recipients),
            'skipped' => 0,
            'campaign_id' => (int) $campaign->id,
            'estimated_recipients' => count($recipients),
            'queued_jobs' => count($recipients),
            'schedule_for' => $scheduleAt->toIso8601String(),
            'preparation_status' => 'queued',
        ];

        return [
            'campaign' => [
                'id' => (int) $campaign->id,
                'status' => (string) $campaign->status,
                'channel' => (string) $campaign->channel,
                'name' => (string) $campaign->name,
                'scheduled_for' => optional($campaign->scheduled_for)->toIso8601String(),
            ],
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<int,array{profile_id:?int,name:?string,email:?string,phone:?string,normalized_phone:?string,source_type:string}>  $recipients
     * @param  array<string,mixed>  $target
     * @param  array<int,int>  $forceSendProfileIds
     * @param  array<string,mixed>|null  $emailTemplate
     * @return array{campaign:array<string,mixed>,summary:array<string,mixed>}
     */
    public function prepareCampaignRecipients(
        int $campaignId,
        ?int $tenantId,
        ?string $storeKey,
        string $channel,
        array $target,
        array $recipients,
        string $body,
        ?string $subject = null,
        ?string $senderKey = null,
        ?int $actorId = null,
        ?string $sourceLabel = null,
        array $forceSendProfileIds = [],
        ?array $emailTemplate = null,
        ?string $htmlBody = null,
        mixed $scheduleFor = null,
        bool $shortenLinks = false
    ): array {
        $campaign = MarketingCampaign::query()->findOrFail($campaignId);
        $resolvedChannel = strtolower(trim($channel));
        $resolvedStoreKey = $this->nullableString($storeKey);
        $resolvedSubject = $this->nullableString($subject);
        $resolvedSenderKey = $this->nullableString($senderKey);
        $resolvedSourceLabel = $this->nullableString($sourceLabel) ?: 'shopify_embedded_messaging_group';
        $resolvedBody = trim($body);
        $resolvedHtmlBody = $this->nullableString($htmlBody);
        $now = CarbonImmutable::now();
        $resolvedScheduleFor = $this->resolvedDate($scheduleFor);
        $scheduleAt = $resolvedScheduleFor !== null && $resolvedScheduleFor->greaterThan($now)
            ? $resolvedScheduleFor
            : $now;

        $forceSendLookup = collect($forceSendProfileIds)
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->flip();

        $profilesById = $this->profilesForRecipients($tenantId, $recipients);
        $templateInstance = $campaign->templateInstance;

        $summary = [
            'processed' => 0,
            'scheduled' => 0,
            'skipped' => 0,
            'campaign_id' => (int) $campaign->id,
            'estimated_recipients' => count($recipients),
            'queued_jobs' => 0,
            'schedule_for' => $scheduleAt->toIso8601String(),
        ];

        $campaign->forceFill([
            'status' => $scheduleAt->greaterThan($now) ? 'draft' : 'preparing',
            'queued_at' => $campaign->queued_at ?: $now,
            'scheduled_for' => $scheduleAt,
            'source_label' => $resolvedSourceLabel,
            'message_subject' => $resolvedSubject,
            'message_body' => $resolvedBody,
            'message_html' => $resolvedHtmlBody,
            'target_snapshot' => $target,
        ])->save();

        foreach ($recipients as $recipient) {
            $summary['processed']++;

            $profileId = (int) ($recipient['profile_id'] ?? 0);
            $profile = $profilesById->get($profileId);
            if (! $profile instanceof MarketingProfile) {
                $summary['skipped']++;
                continue;
            }

            $sendable = $this->sendableDestination($profile, $resolvedChannel);
            $forceSend = $forceSendLookup->has((int) $profile->id);
            $consented = $resolvedChannel === 'sms'
                ? (bool) ($profile->accepts_sms_marketing ?? false)
                : (bool) ($profile->accepts_email_marketing ?? false);

            $status = 'scheduled';
            $reasonCodes = [];
            if (! $forceSend && ! $consented) {
                $status = 'skipped';
                $reasonCodes[] = $resolvedChannel . '_not_consented';
            }

            if ($sendable === null) {
                $status = 'skipped';
                $reasonCodes[] = 'missing_sendable_contact';
            }

            $recipientModel = MarketingCampaignRecipient::query()->create([
                'campaign_id' => (int) $campaign->id,
                'marketing_profile_id' => (int) $profile->id,
                'segment_snapshot' => [
                    'source' => 'shopify_embedded_messaging',
                    'target' => $target,
                ],
                'recommendation_snapshot' => [
                    'sender_key' => $resolvedSenderKey,
                    'message_subject' => $resolvedSubject,
                    'message_body' => $resolvedBody,
                    'message_html' => $resolvedHtmlBody,
                    'source_label' => $resolvedSourceLabel,
                    'shorten_links' => $shortenLinks,
                    'force_send' => $forceSend,
                ],
                'variant_id' => null,
                'channel' => $resolvedChannel,
                'status' => $status,
                'send_attempt_count' => 0,
                'reason_codes' => $reasonCodes,
                'scheduled_for' => $scheduleAt,
                'approved_by' => $actorId,
                'approved_at' => now(),
                'last_status_note' => $status === 'scheduled'
                    ? 'Queued for dispatch.'
                    : 'Skipped before queueing.',
            ]);

            if ($status !== 'scheduled') {
                $summary['skipped']++;
                continue;
            }

            $job = MarketingMessageJob::query()->create([
                'campaign_id' => (int) $campaign->id,
                'campaign_recipient_id' => (int) $recipientModel->id,
                'marketing_profile_id' => (int) $profile->id,
                'tenant_id' => $tenantId,
                'store_key' => $resolvedStoreKey,
                'channel' => $resolvedChannel,
                'job_type' => 'send',
                'status' => 'queued',
                'attempt_count' => 0,
                'max_attempts' => max(1, (int) config('marketing.messaging.default_max_attempts', 4)),
                'priority' => 5,
                'available_at' => $scheduleAt,
                'payload' => [
                    'body' => $resolvedBody,
                    'subject' => $resolvedSubject,
                    'sender_key' => $resolvedSenderKey,
                    'source_label' => $resolvedSourceLabel,
                    'shorten_links' => $shortenLinks,
                    'force_send' => $forceSend,
                    'destination' => $sendable,
                    'target' => $target,
                    'email_template_instance_id' => $templateInstance?->id,
                    'email_template' => $emailTemplate,
                    'html_body' => $resolvedHtmlBody,
                    'created_by' => $actorId,
                ],
            ]);

            $summary['scheduled']++;
            $summary['queued_jobs']++;

            if (! $job instanceof MarketingMessageJob) {
                $summary['skipped']++;
            }
        }

        $this->progressService->refreshCampaign($campaign);

        DispatchMessagingCampaignBatch::dispatch((int) $campaign->id)
            ->onQueue($this->queueName())
            ->delay($scheduleAt->greaterThan($now) ? $scheduleAt : now());

        return [
            'campaign' => [
                'id' => (int) $campaign->id,
                'status' => (string) $campaign->status,
                'channel' => (string) $campaign->channel,
                'name' => (string) $campaign->name,
                'scheduled_for' => optional($campaign->scheduled_for)->toIso8601String(),
            ],
            'summary' => $summary,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function cancelCampaign(?int $tenantId, int $campaignId, ?int $actorId = null): array
    {
        $campaign = MarketingCampaign::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId), fn ($query) => $query->whereNull('tenant_id'))
            ->whereKey($campaignId)
            ->first();

        if (! $campaign instanceof MarketingCampaign) {
            throw ValidationException::withMessages([
                'campaign_id' => 'Campaign not found for this tenant.',
            ]);
        }

        $cancelability = $this->cancelabilityForCampaign($campaign);
        if (! (bool) ($cancelability['cancelable'] ?? false)) {
            throw ValidationException::withMessages([
                'campaign_id' => (string) ($cancelability['message'] ?? 'This campaign can no longer be canceled.'),
            ]);
        }

        $timestamp = now();

        DB::transaction(function () use ($campaign, $actorId, $timestamp): void {
            MarketingMessageJob::query()
                ->where('campaign_id', (int) $campaign->id)
                ->whereIn('status', ['queued', 'retryable', 'dispatching', 'sending'])
                ->update([
                    'status' => 'canceled',
                    'completed_at' => $timestamp,
                    'last_error_code' => 'campaign_canceled',
                    'last_error_message' => 'Campaign canceled before send.',
                ]);

            MarketingCampaignRecipient::query()
                ->where('campaign_id', (int) $campaign->id)
                ->whereIn('status', ['pending', 'scheduled', 'approved', 'queued_for_approval', 'sending'])
                ->update([
                    'status' => 'canceled',
                    'last_status_note' => 'Canceled before send.',
                ]);

            $campaign->forceFill([
                'status' => 'canceled',
                'completed_at' => $timestamp,
                'updated_by' => $actorId,
            ])->save();
        });

        $campaign = $campaign->fresh() ?? $campaign;
        $progress = $this->progressService->refreshCampaign($campaign);

        return [
            'campaign' => [
                'id' => (int) $campaign->id,
                'status' => (string) ($progress['status'] ?? $campaign->status),
                'channel' => (string) $campaign->channel,
                'name' => (string) $campaign->name,
                'scheduled_for' => optional($campaign->scheduled_for)->toIso8601String(),
            ],
            'status_counts' => (array) ($progress['status_counts'] ?? []),
        ];
    }

    /**
     * @return array{cancelable:bool,message:?string,pending_job_count:int,has_deliveries:bool}
     */
    public function cancelabilityForCampaign(MarketingCampaign|int $campaign): array
    {
        $resolvedCampaign = $campaign instanceof MarketingCampaign
            ? $campaign
            : MarketingCampaign::query()->find((int) $campaign);

        if (! $resolvedCampaign instanceof MarketingCampaign) {
            return [
                'cancelable' => false,
                'message' => 'Campaign not found.',
                'pending_job_count' => 0,
                'has_deliveries' => false,
            ];
        }

        $sourceLabel = strtolower(trim((string) ($resolvedCampaign->source_label ?? '')));
        if (! str_starts_with($sourceLabel, 'shopify_embedded_messaging')) {
            return [
                'cancelable' => false,
                'message' => 'Only embedded messaging campaigns can be canceled here.',
                'pending_job_count' => 0,
                'has_deliveries' => false,
            ];
        }

        $jobStatusCounts = MarketingMessageJob::query()
            ->where('campaign_id', (int) $resolvedCampaign->id)
            ->selectRaw('status, count(*) as aggregate_count')
            ->groupBy('status')
            ->pluck('aggregate_count', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $activeJobCount = 0;
        foreach ($jobStatusCounts as $status => $count) {
            $normalized = strtolower(trim((string) $status));
            if ($normalized === '') {
                continue;
            }

            if (in_array($normalized, ['queued', 'retryable', 'dispatching', 'sending'], true)) {
                $activeJobCount += (int) $count;
                continue;
            }
        }

        $hasDeliveries = $this->campaignHasDeliveries($resolvedCampaign);
        $campaignStatus = strtolower(trim((string) $resolvedCampaign->status));

        if ($campaignStatus === 'canceled') {
            return [
                'cancelable' => false,
                'message' => 'This campaign is already canceled.',
                'pending_job_count' => $activeJobCount,
                'has_deliveries' => $hasDeliveries,
            ];
        }

        if ($activeJobCount <= 0) {
            return [
                'cancelable' => false,
                'message' => 'There are no remaining sends left to cancel.',
                'pending_job_count' => 0,
                'has_deliveries' => $hasDeliveries,
            ];
        }

        return [
            'cancelable' => true,
            'message' => null,
            'pending_job_count' => $activeJobCount,
            'has_deliveries' => $hasDeliveries,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function dispatchPendingJobs(int $campaignId, bool $inlineProcessing = false): array
    {
        $lockKey = 'embedded_messaging:campaign_dispatch:' . $campaignId;
        if (! Cache::add($lockKey, now()->toIso8601String(), 45)) {
            return ['dispatched' => 0, 'status' => 'locked'];
        }

        try {
            $campaign = MarketingCampaign::query()->find($campaignId);
            if (! $campaign instanceof MarketingCampaign) {
                return ['dispatched' => 0, 'status' => 'missing_campaign'];
            }

            if (strtolower(trim((string) $campaign->status)) === 'canceled') {
                return ['dispatched' => 0, 'status' => 'canceled'];
            }

            $now = CarbonImmutable::now();
            $scheduledFor = $this->resolvedDate($campaign->scheduled_for);
            if ($scheduledFor !== null && $scheduledFor->greaterThan($now)) {
                DispatchMessagingCampaignBatch::dispatch((int) $campaign->id)
                    ->onQueue($this->queueName())
                    ->delay($scheduledFor);

                return ['dispatched' => 0, 'status' => 'scheduled'];
            }

            $batchSize = max(25, (int) config('marketing.messaging.dispatch_batch_size', 250));
            if ($inlineProcessing) {
                $batchSize = min($batchSize, 10);
            }
            $jobs = MarketingMessageJob::query()
                ->where('campaign_id', (int) $campaign->id)
                ->whereIn('status', ['queued', 'retryable'])
                ->where(function ($query) use ($now): void {
                    $query->whereNull('available_at')
                        ->orWhere('available_at', '<=', $now);
                })
                ->orderByDesc('priority')
                ->orderBy('id')
                ->limit($batchSize)
                ->get();

            if ($jobs->isEmpty()) {
                $this->progressService->refreshCampaign($campaign);

                $hasInflight = MarketingMessageJob::query()
                    ->where('campaign_id', (int) $campaign->id)
                    ->whereIn('status', ['dispatching', 'sending'])
                    ->exists();

                if ($hasInflight) {
                    DispatchMessagingCampaignBatch::dispatch((int) $campaign->id)
                        ->onQueue($this->queueName())
                        ->delay(now()->addSeconds(max(1, (int) config('marketing.messaging.dispatch_interval_seconds', 2))));
                }

                return ['dispatched' => 0, 'status' => 'idle'];
            }

            $campaign->forceFill([
                'status' => 'sending',
                'launched_at' => $campaign->launched_at ?: now(),
            ])->save();

            $dispatched = 0;
            $channelCounter = [];

            foreach ($jobs as $job) {
                $job->forceFill([
                    'status' => 'dispatching',
                    'dispatched_at' => now(),
                ])->save();

                $channel = strtolower(trim((string) $job->channel));
                $channelCounter[$channel] = (int) ($channelCounter[$channel] ?? 0);
                $slot = $channelCounter[$channel];
                $channelCounter[$channel]++;

                $maxPerSecond = $this->maxDispatchPerSecond($channel);
                $delaySeconds = intdiv($slot, $maxPerSecond);

                if ($inlineProcessing) {
                    $this->processJob((int) $job->id);
                } else {
                    ProcessMessagingCampaignRecipientJob::dispatch((int) $job->id)
                        ->onQueue($this->queueName())
                        ->delay(now()->addSeconds($delaySeconds));
                }

                $dispatched++;
            }

            $hasMorePending = MarketingMessageJob::query()
                ->where('campaign_id', (int) $campaign->id)
                ->whereIn('status', ['queued', 'retryable', 'dispatching', 'sending'])
                ->exists();

            if ($hasMorePending && ! $inlineProcessing) {
                DispatchMessagingCampaignBatch::dispatch((int) $campaign->id)
                    ->onQueue($this->queueName())
                    ->delay(now()->addSeconds(max(1, (int) config('marketing.messaging.dispatch_interval_seconds', 2))));
            }

            $this->progressService->refreshCampaign($campaign);

            return [
                'dispatched' => $dispatched,
                'status' => 'ok',
            ];
        } finally {
            Cache::forget($lockKey);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function processJob(int $messageJobId): array
    {
        $job = MarketingMessageJob::query()->with([
            'campaign',
            'recipient.profile',
        ])->find($messageJobId);

        if (! $job instanceof MarketingMessageJob) {
            return ['status' => 'missing_job'];
        }

        if (! in_array((string) $job->status, ['queued', 'retryable', 'dispatching', 'sending'], true)) {
            return ['status' => 'ignored'];
        }

        $availableAt = $this->resolvedDate($job->available_at);
        if ($availableAt !== null && $availableAt->isFuture()) {
            $job->forceFill(['status' => 'queued'])->save();

            return ['status' => 'not_ready'];
        }

        /** @var MarketingCampaign|null $campaign */
        $campaign = $job->campaign;
        if (! $campaign instanceof MarketingCampaign) {
            $job->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'last_error_code' => 'missing_campaign',
                'last_error_message' => 'Campaign no longer exists.',
            ])->save();

            return ['status' => 'failed', 'reason' => 'missing_campaign'];
        }

        if ($this->abortCanceledJob($job, $campaign)) {
            return ['status' => 'canceled'];
        }

        /** @var MarketingCampaignRecipient|null $recipient */
        $recipient = $job->recipient;
        $profile = $recipient?->profile;
        if (! $recipient instanceof MarketingCampaignRecipient || ! $profile instanceof MarketingProfile) {
            $job->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'last_error_code' => 'missing_recipient',
                'last_error_message' => 'Recipient or profile no longer exists.',
            ])->save();

            $this->progressService->refreshCampaign($campaign);

            return ['status' => 'failed', 'reason' => 'missing_recipient'];
        }

        $job->forceFill([
            'status' => 'sending',
            'started_at' => now(),
            'attempt_count' => ((int) $job->attempt_count) + 1,
        ])->save();

        if ($this->abortCanceledJob($job, $campaign)) {
            return ['status' => 'canceled'];
        }

        $result = strtolower(trim((string) $job->channel)) === 'email'
            ? $this->processEmailJob($job, $campaign, $recipient, $profile)
            : $this->processSmsJob($job, $campaign, $recipient, $profile);

        $this->progressService->refreshCampaign($campaign);

        return $result;
    }

    public function handleTwilioDeliveryCallback(
        MarketingMessageDelivery $delivery,
        string $providerStatus,
        ?string $errorCode = null,
        ?string $errorMessage = null
    ): void {
        $jobId = (int) data_get((array) $delivery->provider_payload, 'message_job_id', 0);
        if ($jobId <= 0) {
            return;
        }

        $job = MarketingMessageJob::query()->with('recipient', 'campaign')->find($jobId);
        if (! $job instanceof MarketingMessageJob) {
            return;
        }

        $status = strtolower(trim($providerStatus));
        $failureStatus = in_array($status, ['failed', 'undelivered', 'canceled'], true);

        if ($failureStatus) {
            if ($this->shouldRetryJob($job, 'sms', $errorCode, $status)) {
                $this->scheduleRetry(
                    job: $job,
                    channel: 'sms',
                    errorCode: $errorCode,
                    errorMessage: $errorMessage ?: $delivery->error_message,
                    note: 'Retry scheduled after Twilio callback failure.'
                );
            } else {
                $job->forceFill([
                    'status' => 'failed',
                    'failed_at' => now(),
                    'last_error_code' => $this->nullableString($errorCode),
                    'last_error_message' => $this->nullableString($errorMessage) ?: $this->nullableString($delivery->error_message),
                ])->save();
            }

            if ($job->recipient instanceof MarketingCampaignRecipient) {
                $job->recipient->forceFill([
                    'status' => $job->status === 'retryable' ? 'scheduled' : 'failed',
                    'last_status_note' => $job->status === 'retryable'
                        ? 'Retry scheduled after callback failure.'
                        : ($this->nullableString($errorMessage) ?: 'Twilio callback marked delivery failed.'),
                ])->save();
            }
        } else {
            if ((string) $job->status !== 'completed') {
                $job->forceFill([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'provider_message_id' => $delivery->provider_message_id,
                    'delivery_id' => (int) $delivery->id,
                ])->save();
            }
        }

        if ($job->campaign instanceof MarketingCampaign) {
            $this->progressService->refreshCampaign($job->campaign);
        }
    }

    protected function processSmsJob(
        MarketingMessageJob $job,
        MarketingCampaign $campaign,
        MarketingCampaignRecipient $recipient,
        MarketingProfile $profile
    ): array {
        if ($this->abortCanceledJob($job, $campaign, $recipient)) {
            return ['status' => 'canceled'];
        }

        $payload = (array) ($job->payload ?? []);
        $sourceLabel = $this->nullableString($payload['source_label'] ?? null) ?: 'shopify_embedded_messaging_group';
        $senderKey = $this->nullableString($payload['sender_key'] ?? null);
        $shortenLinksRequested = (bool) ($payload['shorten_links'] ?? false);
        $forceSend = (bool) ($payload['force_send'] ?? false);

        $toPhone = $this->identityNormalizer->toE164((string) ($profile->normalized_phone ?: $profile->phone));
        if (! $forceSend && ! (bool) ($profile->accepts_sms_marketing ?? false)) {
            $recipient->forceFill([
                'status' => 'skipped',
                'last_status_note' => 'SMS consent is no longer active.',
                'reason_codes' => array_values(array_unique(array_merge((array) $recipient->reason_codes, ['sms_not_consented']))),
            ])->save();

            $job->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'last_error_code' => 'sms_not_consented',
                'last_error_message' => 'SMS consent is no longer active for this recipient.',
            ])->save();

            return ['status' => 'failed', 'reason' => 'sms_not_consented'];
        }

        if ($toPhone === null) {
            $recipient->forceFill([
                'status' => 'skipped',
                'last_status_note' => 'Recipient has no sendable phone number.',
                'reason_codes' => array_values(array_unique(array_merge((array) $recipient->reason_codes, ['missing_phone']))),
            ])->save();

            $job->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'last_error_code' => 'missing_phone',
                'last_error_message' => 'Recipient has no sendable phone number.',
            ])->save();

            return ['status' => 'failed', 'reason' => 'missing_phone'];
        }

        $message = trim((string) ($payload['body'] ?? $campaign->message_body ?? ''));
        if ($message === '') {
            $recipient->forceFill([
                'status' => 'failed',
                'last_status_note' => 'Message body is empty.',
            ])->save();

            $job->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'last_error_code' => 'missing_message',
                'last_error_message' => 'Message body is empty.',
            ])->save();

            return ['status' => 'failed', 'reason' => 'missing_message'];
        }

        $smsPlan = $this->smsMessageSafetyService->analyzeRecipient($message, $toPhone);
        $message = (string) ($smsPlan['normalized_body'] ?? $message);
        $deliveryMode = (string) ($smsPlan['recommended_channel'] ?? 'sms');

        $delivery = MarketingMessageDelivery::query()->create([
            'campaign_id' => (int) $campaign->id,
            'campaign_recipient_id' => (int) $recipient->id,
            'marketing_profile_id' => (int) $profile->id,
            'tenant_id' => $campaign->tenant_id,
            'store_key' => $campaign->store_key,
            'batch_id' => (string) Str::uuid(),
            'source_label' => $sourceLabel,
            'message_subject' => Str::limit($message, 160),
            'channel' => 'sms',
            'provider' => 'twilio',
            'to_phone' => $toPhone,
            'variant_id' => $recipient->variant_id,
            'attempt_number' => (int) $job->attempt_count,
            'rendered_message' => $message,
            'send_status' => 'sending',
            'created_by' => (int) ($payload['created_by'] ?? 0) ?: null,
            'provider_payload' => [
                'source_label' => $sourceLabel,
                'sender_key' => $senderKey,
                'message_job_id' => (int) $job->id,
                'campaign_id' => (int) $campaign->id,
                'campaign_recipient_id' => (int) $recipient->id,
                'sms_plan' => $smsPlan,
            ],
        ]);

        $trackedMessage = $this->messageClickTrackingService->decorateSmsMessageForDelivery(
            delivery: $delivery,
            message: $message,
            createdBy: (int) ($payload['created_by'] ?? 0) ?: null
        );

        $resolvedMessage = trim((string) ($trackedMessage['message'] ?? $message));
        if ($resolvedMessage === '') {
            $resolvedMessage = $message;
        }

        $linkPrepared = $this->smsLinkShorteningService->prepareMessage($resolvedMessage, [
            'enabled' => $shortenLinksRequested,
        ]);

        $resolvedMessage = trim((string) ($linkPrepared['message'] ?? $resolvedMessage));
        if ($resolvedMessage === '') {
            $resolvedMessage = $message;
        }

        $delivery->forceFill([
            'message_subject' => Str::limit($resolvedMessage, 160),
            'rendered_message' => $resolvedMessage,
            'provider_payload' => [
                ...((array) $delivery->provider_payload),
                'tracked_links' => (array) ($trackedMessage['links'] ?? []),
                'shorten_links_requested' => $shortenLinksRequested,
                'link_shortening_provider' => (string) ($linkPrepared['provider'] ?? 'none'),
            ],
        ])->save();

        if ($this->abortCanceledJob($job, $campaign, $recipient)) {
            $delivery->forceFill([
                'send_status' => 'canceled',
                'error_code' => 'campaign_canceled',
                'error_message' => 'Campaign canceled before send.',
                'failed_at' => now(),
            ])->save();

            return ['status' => 'canceled'];
        }

        $sendResult = $this->twilioSmsService->sendSms($toPhone, $resolvedMessage, [
            'sender_key' => $senderKey,
            'status_callback_url' => $this->statusCallbackUrl(),
            'shorten_urls' => (bool) ($linkPrepared['twilio_shorten_urls'] ?? false),
            'send_as_mms' => $deliveryMode === 'mms',
        ]);

        $providerStatus = $this->deliveryTrackingService->mapProviderStatus($sendResult['status'] ?? null);
        $success = (bool) ($sendResult['success'] ?? false);

        $delivery->forceFill([
            'provider_message_id' => $sendResult['provider_message_id'] ?? null,
            'from_identifier' => $sendResult['from_identifier'] ?? null,
            'send_status' => $success ? $providerStatus : 'failed',
            'error_code' => $sendResult['error_code'] ?? null,
            'error_message' => $sendResult['error_message'] ?? null,
            'provider_payload' => [
                ...((array) $delivery->provider_payload),
                'sender_key' => $sendResult['sender_key'] ?? $senderKey,
                'sender_label' => $sendResult['sender_label'] ?? null,
                'delivery_mode' => $sendResult['delivery_mode'] ?? $deliveryMode,
                'requested_delivery_mode' => $sendResult['requested_delivery_mode'] ?? $deliveryMode,
                'twilio_response' => (array) ($sendResult['payload'] ?? []),
            ],
            'sent_at' => $success && in_array($providerStatus, ['queued', 'sending', 'sent', 'delivered', 'undelivered'], true)
                ? now()
                : null,
            'delivered_at' => $success && $providerStatus === 'delivered' ? now() : null,
            'failed_at' => ! $success || in_array($providerStatus, ['failed', 'undelivered', 'canceled'], true)
                ? now()
                : null,
        ])->save();

        $recipientStatus = $success
            ? $this->recipientStatusFromProviderStatus($providerStatus)
            : 'failed';

        $recipient->forceFill([
            'status' => $recipientStatus,
            'last_status_note' => $success
                ? null
                : ($this->nullableString($sendResult['error_message'] ?? null) ?: 'Twilio send failed.'),
            'send_attempt_count' => max((int) $recipient->send_attempt_count, (int) $job->attempt_count),
            'last_send_attempt_at' => now(),
            'sent_at' => in_array($recipientStatus, ['sent', 'delivered', 'undelivered', 'failed'], true)
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
            eventStatus: $success ? $providerStatus : 'failed',
            payload: [
                'result' => $sendResult,
                'campaign_id' => (int) $campaign->id,
                'campaign_recipient_id' => (int) $recipient->id,
            ],
            occurredAt: now()
        );

        $job->forceFill([
            'delivery_id' => (int) $delivery->id,
            'provider_message_id' => $delivery->provider_message_id,
        ])->save();

        if ($success) {
            $job->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            return [
                'status' => 'completed',
                'provider_status' => $providerStatus,
            ];
        }

        if ($this->shouldRetryJob($job, 'sms', $sendResult['error_code'] ?? null, $providerStatus)) {
            $this->scheduleRetry(
                job: $job,
                channel: 'sms',
                errorCode: $sendResult['error_code'] ?? null,
                errorMessage: $sendResult['error_message'] ?? null,
                note: 'Retry scheduled after Twilio send failure.'
            );

            $recipient->forceFill([
                'status' => 'scheduled',
                'last_status_note' => 'Retry scheduled after Twilio send failure.',
            ])->save();

            return [
                'status' => 'retryable',
                'provider_status' => $providerStatus,
            ];
        }

        $job->forceFill([
            'status' => 'failed',
            'failed_at' => now(),
            'last_error_code' => $this->nullableString($sendResult['error_code'] ?? null),
            'last_error_message' => $this->nullableString($sendResult['error_message'] ?? null),
        ])->save();

        return [
            'status' => 'failed',
            'provider_status' => $providerStatus,
        ];
    }

    protected function processEmailJob(
        MarketingMessageJob $job,
        MarketingCampaign $campaign,
        MarketingCampaignRecipient $recipient,
        MarketingProfile $profile
    ): array {
        if ($this->abortCanceledJob($job, $campaign, $recipient)) {
            return ['status' => 'canceled'];
        }

        $payload = (array) ($job->payload ?? []);
        $sourceLabel = $this->nullableString($payload['source_label'] ?? null) ?: 'shopify_embedded_messaging_group';
        $subject = $this->nullableString($payload['subject'] ?? $campaign->message_subject ?? null) ?: 'Message from Backstage';
        $body = trim((string) ($payload['body'] ?? $campaign->message_body ?? ''));
        $htmlBody = $this->nullableString($payload['html_body'] ?? $campaign->message_html ?? null);

        $toEmail = $this->identityNormalizer->normalizeEmail((string) ($profile->normalized_email ?: $profile->email));
        if ($toEmail === null) {
            $recipient->forceFill([
                'status' => 'skipped',
                'last_status_note' => 'Recipient has no sendable email.',
                'reason_codes' => array_values(array_unique(array_merge((array) $recipient->reason_codes, ['missing_email']))),
            ])->save();

            $job->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'last_error_code' => 'missing_email',
                'last_error_message' => 'Recipient has no sendable email.',
            ])->save();

            return ['status' => 'failed', 'reason' => 'missing_email'];
        }

        if (! (bool) ($profile->accepts_email_marketing ?? false) && ! (bool) ($payload['force_send'] ?? false)) {
            $recipient->forceFill([
                'status' => 'skipped',
                'last_status_note' => 'Email consent is no longer active.',
                'reason_codes' => array_values(array_unique(array_merge((array) $recipient->reason_codes, ['email_not_consented']))),
            ])->save();

            $job->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'last_error_code' => 'email_not_consented',
                'last_error_message' => 'Email consent is no longer active for this recipient.',
            ])->save();

            return ['status' => 'failed', 'reason' => 'email_not_consented'];
        }

        $templateInstanceId = (int) ($payload['email_template_instance_id'] ?? 0);
        $templateInstance = $templateInstanceId > 0
            ? MarketingTemplateInstance::query()->find($templateInstanceId)
            : null;

        $delivery = MarketingEmailDelivery::query()->create([
            'marketing_campaign_recipient_id' => (int) $recipient->id,
            'marketing_profile_id' => (int) $profile->id,
            'tenant_id' => $campaign->tenant_id,
            'store_key' => $campaign->store_key,
            'batch_id' => (string) Str::uuid(),
            'source_label' => $sourceLabel,
            'message_subject' => $subject,
            'provider' => 'sendgrid',
            'campaign_type' => 'direct_message',
            'template_key' => 'embedded_messaging',
            'email' => $toEmail,
            'status' => 'sending',
            'raw_payload' => [
                'source_label' => $sourceLabel,
                'message_job_id' => (int) $job->id,
                'campaign_id' => (int) $campaign->id,
                'campaign_recipient_id' => (int) $recipient->id,
            ],
            'metadata' => [
                'subject' => $subject,
                'source_label' => $sourceLabel,
                'template_instance_id' => $templateInstance?->id,
                'template_definition_id' => $templateInstance?->template_definition_id,
            ],
        ]);
        $emailTemplate = is_array($payload['email_template'] ?? null) ? $payload['email_template'] : [];
        $attributionContext = [
            'subject' => $subject,
            'source_label' => $sourceLabel,
            'template_key' => $this->nullableString(data_get($emailTemplate, 'template_key')) ?? 'embedded_messaging',
            'campaign_id' => (int) $campaign->id,
            'delivery_id' => (int) $delivery->id,
            'profile_id' => (int) $profile->id,
            'campaign_recipient_id' => (int) $recipient->id,
        ];

        $resolvedBody = $this->emailLinkAttributionService->decorateText($body, $attributionContext);
        $resolvedHtmlBody = $htmlBody !== null
            ? $this->emailLinkAttributionService->decorateHtml($htmlBody, [
                ...$attributionContext,
                'module_type' => 'legacy_html',
                'module_position' => 1,
            ])
            : null;
        $resolvedTemplate = $emailTemplate;

        if ($emailTemplate !== []) {
            $decoratedSections = $this->emailLinkAttributionService->decorateSections(
                is_array($emailTemplate['sections'] ?? null) ? $emailTemplate['sections'] : [],
                $attributionContext
            );

            $composed = $this->emailComposerService->compose(
                subject: $subject,
                body: $resolvedBody,
                mode: $this->nullableString($emailTemplate['mode'] ?? null),
                sections: $decoratedSections,
                legacyHtml: $this->nullableString($emailTemplate['legacy_html'] ?? null) ?? $resolvedHtmlBody
            );

            $resolvedHtmlBody = $this->nullableString($composed['html'] ?? null) ?? $resolvedHtmlBody;
            $resolvedTemplate = [
                ...$emailTemplate,
                'sections' => is_array($composed['sections'] ?? null) ? $composed['sections'] : $decoratedSections,
                'legacy_html' => $this->nullableString($composed['legacy_html'] ?? null) ?? $this->nullableString($emailTemplate['legacy_html'] ?? null),
            ];
        }

        if ($this->abortCanceledJob($job, $campaign, $recipient)) {
            $delivery->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'raw_payload' => [
                    ...((array) $delivery->raw_payload),
                    'canceled_before_send' => true,
                ],
            ])->save();

            return ['status' => 'canceled'];
        }

        $sendResult = $this->sendGridEmailService->sendEmail($toEmail, $subject, $resolvedBody, [
            'tenant_id' => $campaign->tenant_id,
            'campaign_type' => 'direct_message',
            'template_key' => 'embedded_messaging',
            'customer_id' => (int) $profile->id,
            'reply_to_email' => $this->emailReplyAddressService->replyAddressForDelivery((int) $campaign->tenant_id, (int) $delivery->id),
            'metadata' => [
                'subject' => $subject,
                'source_label' => $sourceLabel,
                'message_job_id' => (int) $job->id,
                'campaign_id' => (int) $campaign->id,
            ],
            'html_body' => $resolvedHtmlBody,
            'categories' => [
                'embedded-messaging',
                'shopify',
            ],
            'custom_args' => [
                'marketing_email_delivery_id' => (string) $delivery->id,
                'marketing_profile_id' => (string) $profile->id,
                'campaign_id' => (string) $campaign->id,
                'campaign_recipient_id' => (string) $recipient->id,
                'template_key' => (string) ($this->nullableString(data_get($resolvedTemplate, 'template_key')) ?? 'embedded_messaging'),
            ],
        ]);

        $success = (bool) ($sendResult['success'] ?? false);

        $delivery->forceFill([
            'provider' => (string) ($sendResult['provider'] ?? 'sendgrid'),
            'provider_message_id' => $sendResult['message_id'] ?? null,
            'sendgrid_message_id' => (string) ($sendResult['provider'] ?? 'sendgrid') === 'sendgrid'
                ? ($sendResult['message_id'] ?? null)
                : null,
            'status' => $success ? 'sent' : 'failed',
            'raw_payload' => [
                ...((array) $delivery->raw_payload),
                'provider_result' => $sendResult,
            ],
            'metadata' => [
                ...((array) ($delivery->metadata ?? [])),
                'template_sections' => is_array(data_get($resolvedTemplate, 'sections'))
                    ? data_get($resolvedTemplate, 'sections')
                    : [],
            ],
            'sent_at' => $success ? now() : null,
            'failed_at' => $success ? null : now(),
        ])->save();

        $recipient->forceFill([
            'status' => $success ? 'sent' : 'failed',
            'last_status_note' => $success ? null : ($this->nullableString($sendResult['error_message'] ?? null) ?: 'Email provider failure.'),
            'send_attempt_count' => max((int) $recipient->send_attempt_count, (int) $job->attempt_count),
            'last_send_attempt_at' => now(),
            'sent_at' => $success ? ($recipient->sent_at ?: now()) : $recipient->sent_at,
            'failed_at' => $success ? $recipient->failed_at : ($recipient->failed_at ?: now()),
        ])->save();

        if ($success) {
            $job->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            return ['status' => 'completed'];
        }

        if ($this->shouldRetryJob($job, 'email', $sendResult['error_code'] ?? null, (string) ($sendResult['status'] ?? 'failed'))) {
            $this->scheduleRetry(
                job: $job,
                channel: 'email',
                errorCode: $sendResult['error_code'] ?? null,
                errorMessage: $sendResult['error_message'] ?? null,
                note: 'Retry scheduled after email provider failure.'
            );

            $recipient->forceFill([
                'status' => 'scheduled',
                'last_status_note' => 'Retry scheduled after email provider failure.',
            ])->save();

            return ['status' => 'retryable'];
        }

        $job->forceFill([
            'status' => 'failed',
            'failed_at' => now(),
            'last_error_code' => $this->nullableString($sendResult['error_code'] ?? null),
            'last_error_message' => $this->nullableString($sendResult['error_message'] ?? null),
        ])->save();

        return ['status' => 'failed'];
    }

    protected function shouldRetryJob(
        MarketingMessageJob $job,
        string $channel,
        ?string $errorCode,
        ?string $providerStatus
    ): bool {
        if ((int) $job->attempt_count >= (int) $job->max_attempts) {
            return false;
        }

        return $this->retryPolicy->isRetryable($channel, $errorCode, $providerStatus);
    }

    protected function scheduleRetry(
        MarketingMessageJob $job,
        string $channel,
        ?string $errorCode,
        ?string $errorMessage,
        string $note
    ): void {
        $backoffSeconds = $this->retryPolicy->nextBackoffSeconds($channel, (int) $job->attempt_count);
        $availableAt = now()->addSeconds($backoffSeconds);

        $job->forceFill([
            'status' => 'retryable',
            'available_at' => $availableAt,
            'failed_at' => null,
            'completed_at' => null,
            'last_error_code' => $this->nullableString($errorCode),
            'last_error_message' => $this->nullableString($errorMessage) ?: $note,
        ])->save();

        ProcessMessagingCampaignRecipientJob::dispatch((int) $job->id)
            ->onQueue($this->queueName())
            ->delay($availableAt);
    }

    /**
     * @param  array<int,array{profile_id:?int,name:?string,email:?string,phone:?string,normalized_phone:?string,source_type:string}>  $recipients
     * @return Collection<int,MarketingProfile>
     */
    protected function profilesForRecipients(?int $tenantId, array $recipients): Collection
    {
        $profileIds = collect($recipients)
            ->map(fn (array $recipient): int => (int) ($recipient['profile_id'] ?? 0))
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();

        if ($profileIds === []) {
            return collect();
        }

        return MarketingProfile::query()
            ->whereIn('id', $profileIds)
            ->when($tenantId !== null, fn ($query) => $query->forTenantId($tenantId))
            ->get([
                'id',
                'tenant_id',
                'first_name',
                'last_name',
                'email',
                'normalized_email',
                'phone',
                'normalized_phone',
                'accepts_sms_marketing',
                'accepts_email_marketing',
            ])
            ->keyBy(fn (MarketingProfile $profile): int => (int) $profile->id);
    }

    protected function sendableDestination(MarketingProfile $profile, string $channel): ?string
    {
        $resolvedChannel = strtolower(trim($channel));

        if ($resolvedChannel === 'email') {
            return $this->identityNormalizer->normalizeEmail((string) ($profile->normalized_email ?: $profile->email));
        }

        return $this->identityNormalizer->toE164((string) ($profile->normalized_phone ?: $profile->phone));
    }

    protected function statusCallbackUrl(): ?string
    {
        $configured = trim((string) config('marketing.twilio.status_callback_url', ''));
        if ($configured !== '') {
            return $configured;
        }

        try {
            return route('marketing.webhooks.twilio-status');
        } catch (\Throwable) {
            return null;
        }
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

    /**
     * @param  array<string,mixed>  $template
     */
    protected function createTemplateInstance(
        ?int $tenantId,
        ?string $storeKey,
        string $channel,
        ?int $campaignId,
        ?int $actorId,
        array $template,
        ?string $subject,
        ?string $body,
        ?string $html
    ): ?MarketingTemplateInstance {
        $definitionKey = $this->nullableString($template['template_key'] ?? null);
        $definition = $definitionKey !== null
            ? MarketingTemplateDefinition::query()
                ->where('template_key', $definitionKey)
                ->where('channel', $channel)
                ->where('is_active', true)
                ->first()
            : null;

        return MarketingTemplateInstance::query()->create([
            'template_definition_id' => $definition?->id,
            'campaign_id' => $campaignId,
            'tenant_id' => $tenantId,
            'store_key' => $storeKey,
            'channel' => $channel,
            'name' => $this->nullableString($template['name'] ?? $definition?->name),
            'subject' => $this->nullableString($subject),
            'body' => $this->nullableString($body),
            'sections' => is_array($template['sections'] ?? null) ? $template['sections'] : null,
            'advanced_html' => $this->nullableString($template['legacy_html'] ?? null),
            'rendered_html' => $this->nullableString($html),
            'metadata' => [
                'mode' => $this->nullableString($template['mode'] ?? null),
                'template_key' => $definition?->template_key,
            ],
            'created_by' => $actorId,
        ]);
    }

    protected function abortCanceledJob(
        MarketingMessageJob $job,
        MarketingCampaign $campaign,
        ?MarketingCampaignRecipient $recipient = null
    ): bool {
        $campaignStatus = strtolower(trim((string) ($campaign->fresh()?->status ?? $campaign->status ?? '')));
        $jobStatus = strtolower(trim((string) (MarketingMessageJob::query()->whereKey($job->id)->value('status') ?? $job->status ?? '')));

        if ($campaignStatus !== 'canceled' && $jobStatus !== 'canceled') {
            return false;
        }

        $job->forceFill([
            'status' => 'canceled',
            'completed_at' => now(),
            'failed_at' => null,
            'last_error_code' => 'campaign_canceled',
            'last_error_message' => 'Campaign canceled before send.',
        ])->save();

        if ($recipient instanceof MarketingCampaignRecipient) {
            $recipient->forceFill([
                'status' => 'canceled',
                'last_status_note' => 'Canceled before send.',
            ])->save();
        }

        return true;
    }

    protected function campaignHasDeliveries(MarketingCampaign $campaign): bool
    {
        $messageDeliveriesExist = MarketingMessageDelivery::query()
            ->where('campaign_id', (int) $campaign->id)
            ->exists();

        if ($messageDeliveriesExist) {
            return true;
        }

        return MarketingEmailDelivery::query()
            ->whereIn('marketing_campaign_recipient_id', function ($query) use ($campaign): void {
                $query->select('id')
                    ->from('marketing_campaign_recipients')
                    ->where('campaign_id', (int) $campaign->id);
            })
            ->exists();
    }

    protected function maxDispatchPerSecond(string $channel): int
    {
        $resolvedChannel = strtolower(trim($channel));

        return $resolvedChannel === 'email'
            ? max(1, (int) config('marketing.messaging.email.max_dispatch_per_second', 40))
            : max(1, (int) config('marketing.messaging.sms.max_dispatch_per_second', 18));
    }

    protected function queueName(): string
    {
        return (string) config('marketing.messaging.queue', 'marketing-messaging');
    }

    protected function resolvedDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance(\DateTime::createFromInterface($value));
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($string);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function nullableString(mixed $value): ?string
    {
        $resolved = trim((string) $value);

        return $resolved !== '' ? $resolved : null;
    }
}
