<?php

namespace App\Services\Marketing;

use App\Jobs\DispatchTenantRewardsReminderJob;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use Carbon\CarbonImmutable;

class TenantRewardsReminderDispatchService
{
    public function __construct(
        protected CandleCashEarnedAnalyticsService $analyticsService,
        protected TenantRewardsReminderScheduleService $scheduleService,
        protected TenantRewardsPolicyMessagePreviewService $previewService,
        protected MarketingEmailReadiness $emailReadiness,
        protected SendGridEmailService $sendGridEmailService,
        protected TwilioSenderConfigService $twilioSenderConfigService,
        protected TwilioSmsService $twilioSmsService,
        protected MarketingDeliveryTrackingService $deliveryTrackingService
    ) {
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function processTenant(int $tenantId, array $policy, array $options = []): array
    {
        $now = $this->asDate($options['now'] ?? null) ?? now()->toImmutable();
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $force = (bool) ($options['force'] ?? false);
        $limit = max(1, min(500, (int) ($options['limit'] ?? 200)));
        $rewardIdentifierFilter = $this->nullableString($options['reward_identifier'] ?? null);
        $marketingProfileId = $this->positiveInt($options['marketing_profile_id'] ?? $options['profile_id'] ?? null);
        $channelFilter = $this->nullableString($options['channel'] ?? null);
        $timingDaysFilter = $this->nonNegativeInt($options['timing_days_before_expiration'] ?? $options['timing_days'] ?? null);
        $manualSkipReason = $this->nullableString($options['mark_skipped'] ?? null);
        $includeContent = $dryRun || (bool) ($options['include_content'] ?? false);

        $policyVersion = max(
            0,
            (int) ($options['policy_version'] ?? data_get($policy, 'access_state.policy_version', data_get($policy, 'versioning.current_version', 0)))
        );
        $readiness = $this->deliveryReadiness($tenantId, $policy, $now);

        $rewards = collect($this->analyticsService->outstandingRewardBuckets($tenantId))
            ->filter(function (array $reward) use ($rewardIdentifierFilter, $marketingProfileId): bool {
                if ($rewardIdentifierFilter !== null
                    && strtolower(trim((string) ($reward['reward_identifier'] ?? ''))) !== strtolower($rewardIdentifierFilter)) {
                    return false;
                }

                if ($marketingProfileId !== null && (int) ($reward['marketing_profile_id'] ?? 0) !== $marketingProfileId) {
                    return false;
                }

                return true;
            })
            ->take($limit)
            ->values();

        $summary = [
            'rewards_considered' => $rewards->count(),
            'due_count' => 0,
            'upcoming_count' => 0,
            'schedule_skip_count' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
            'email_sent_count' => 0,
            'sms_sent_count' => 0,
            'email_failed_count' => 0,
            'sms_failed_count' => 0,
            'email_skipped_count' => 0,
            'sms_skipped_count' => 0,
            'policy_version' => $policyVersion,
            'dry_run' => $dryRun,
        ];

        $dueItems = [];
        $upcomingItems = [];
        $scheduleSkippedItems = [];
        $processedItems = [];
        $skipReasonCounts = [];

        foreach ($rewards as $reward) {
            $schedule = $this->scheduleService->evaluate($policy, [
                ...$reward,
                'tenant_id' => $tenantId,
                'policy_version' => $policyVersion,
            ], [
                'tenant_id' => $tenantId,
                'policy_version' => $policyVersion,
                'now' => $now->toIso8601String(),
                'ignore_existing_history' => $force,
            ]);

            $rewardSkipped = collect((array) ($schedule['skipped'] ?? []))
                ->filter(function (array $entry) use ($channelFilter, $timingDaysFilter): bool {
                    return ($channelFilter === null || strtolower(trim((string) ($entry['channel'] ?? ''))) === $channelFilter)
                        && $this->matchesTimingFilter($entry, $timingDaysFilter);
                })
                ->values()
                ->all();

            foreach ($rewardSkipped as $entry) {
                $entry = $this->withRewardContext($entry, $reward);
                $scheduleSkippedItems[] = $this->presentScheduleRow($reward, $entry);
                $summary['schedule_skip_count']++;
                $this->incrementSkipReason($skipReasonCounts, (string) ($entry['skip_reason'] ?? 'skipped'));

                if (! $dryRun && ! $force && $this->shouldPersistScheduleSkip((string) ($entry['skip_reason'] ?? ''))) {
                    $this->scheduleService->recordSkipped($entry, (string) ($entry['skip_reason'] ?? 'skipped'), (string) ($entry['reason'] ?? 'Reminder was skipped.'));
                }
            }

            $rewardUpcoming = collect((array) ($schedule['upcoming'] ?? []))
                ->filter(function (array $entry) use ($channelFilter, $timingDaysFilter): bool {
                    return ($channelFilter === null || strtolower(trim((string) ($entry['channel'] ?? ''))) === $channelFilter)
                        && $this->matchesTimingFilter($entry, $timingDaysFilter);
                })
                ->map(fn (array $entry): array => $this->presentScheduleRow($reward, $entry))
                ->values()
                ->all();
            $upcomingItems = [...$upcomingItems, ...$rewardUpcoming];
            $summary['upcoming_count'] += count($rewardUpcoming);

            $rewardDue = collect((array) ($schedule['should_send'] ?? []))
                ->filter(function (array $entry) use ($channelFilter, $timingDaysFilter): bool {
                    return ($channelFilter === null || strtolower(trim((string) ($entry['channel'] ?? ''))) === $channelFilter)
                        && $this->matchesTimingFilter($entry, $timingDaysFilter);
                })
                ->values();

            foreach ($rewardDue as $entry) {
                $entry = $this->withRewardContext($entry, $reward);
                $summary['due_count']++;
                $presented = $this->presentScheduleRow($reward, $entry, $includeContent ? $this->previewForChannel($policy, $reward, $entry) : null);
                $dueItems[] = $presented;

                if ($manualSkipReason !== null) {
                    $processed = $this->markSkipped($entry, $manualSkipReason, $dryRun, $force);
                    $processedItems[] = $this->presentProcessedRow($reward, $processed, $includeContent ? $this->previewForChannel($policy, $reward, $entry) : null);
                    $this->applyProcessedSummary($summary, $processed);
                    $this->incrementSkipReason($skipReasonCounts, (string) ($processed['skip_reason'] ?? 'manual_skip'));
                    continue;
                }

                $launchGate = $this->launchGate($policy, $now);
                if (! $launchGate['allowed']) {
                    $processed = $this->markSkipped($entry, $launchGate['message'], $dryRun, $force, $launchGate['code']);
                    $processedItems[] = $this->presentProcessedRow($reward, $processed, $includeContent ? $this->previewForChannel($policy, $reward, $entry) : null);
                    $this->applyProcessedSummary($summary, $processed);
                    $this->incrementSkipReason($skipReasonCounts, (string) ($processed['skip_reason'] ?? 'launch_not_ready'));
                    continue;
                }

                $processed = $this->dispatchDueReminder(
                    tenantId: $tenantId,
                    policy: $policy,
                    reward: $reward,
                    entry: $entry,
                    readiness: $readiness,
                    dryRun: $dryRun,
                    force: $force,
                    includeContent: $includeContent
                );

                $processedItems[] = $this->presentProcessedRow($reward, $processed, $includeContent ? ($processed['preview'] ?? $this->previewForChannel($policy, $reward, $entry)) : null);
                $this->applyProcessedSummary($summary, $processed);

                if (($processed['status'] ?? null) === 'skipped') {
                    $this->incrementSkipReason($skipReasonCounts, (string) ($processed['skip_reason'] ?? 'skipped'));
                }
            }
        }

        $dueItems = collect($dueItems)->sortBy(['scheduled_at', 'channel', 'reward_identifier'])->values()->all();
        $upcomingItems = collect($upcomingItems)->sortBy(['scheduled_at', 'channel', 'reward_identifier'])->values()->all();
        $scheduleSkippedItems = collect($scheduleSkippedItems)->sortBy(['scheduled_at', 'channel', 'reward_identifier'])->values()->all();
        $processedItems = collect($processedItems)->sortBy(['scheduled_at', 'channel', 'reward_identifier'])->values()->all();

        return [
            'tenant_id' => $tenantId,
            'status' => 'ok',
            'policy_version' => $policyVersion,
            'dry_run' => $dryRun,
            'readiness' => $readiness,
            'summary' => $summary,
            'due_items' => $dueItems,
            'upcoming_items' => $upcomingItems,
            'schedule_skipped_items' => $scheduleSkippedItems,
            'processed_items' => $processedItems,
            'skip_reasons' => collect($skipReasonCounts)
                ->map(fn (int $count, string $code): array => ['code' => $code, 'count' => $count])
                ->sortByDesc('count')
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function queueDueReminders(int $tenantId, array $policy, array $options = []): array
    {
        $batchSize = max(1, min(500, (int) ($options['batch_size'] ?? 50)));
        $preview = $this->processTenant($tenantId, $policy, [
            ...$options,
            'dry_run' => true,
            'include_content' => false,
        ]);

        $dueItems = collect((array) ($preview['due_items'] ?? []))
            ->take($batchSize)
            ->values();

        $queued = $dueItems->map(function (array $row) use ($tenantId, $options): array {
            DispatchTenantRewardsReminderJob::dispatch(
                tenantId: $tenantId,
                rewardIdentifier: (string) ($row['reward_identifier'] ?? ''),
                channel: (string) ($row['channel'] ?? 'email'),
                timingDaysBeforeExpiration: max(0, (int) ($row['timing_days_before_expiration'] ?? 0)),
                policyVersion: max(0, (int) ($row['policy_version'] ?? 0)),
                context: [
                    'reason' => $this->nullableString($options['reason'] ?? null),
                    'actor_user_id' => $this->positiveInt($options['actor_user_id'] ?? null),
                    'shopify_admin_user_id' => $this->nullableString($options['shopify_admin_user_id'] ?? null),
                    'shopify_admin_session_id' => $this->nullableString($options['shopify_admin_session_id'] ?? null),
                ]
            );

            return [
                'reward_identifier' => $row['reward_identifier'] ?? null,
                'channel' => $row['channel'] ?? null,
                'timing_days_before_expiration' => $row['timing_days_before_expiration'] ?? null,
                'policy_version' => $row['policy_version'] ?? null,
                'customer_name' => $row['customer_name'] ?? null,
            ];
        })->all();

        return [
            'status' => 'queued',
            'tenant_id' => $tenantId,
            'policy_version' => max(0, (int) ($options['policy_version'] ?? data_get($policy, 'versioning.current_version', 0))),
            'queued_count' => count($queued),
            'remaining_due_count' => max(0, collect((array) ($preview['due_items'] ?? []))->count() - count($queued)),
            'items' => $queued,
            'preview' => [
                'summary' => (array) ($preview['summary'] ?? []),
                'due_count' => collect((array) ($preview['due_items'] ?? []))->count(),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function dispatchQueuedReminder(int $tenantId, array $policy, array $options = []): array
    {
        return $this->processTenant($tenantId, $policy, [
            'reward_identifier' => $this->nullableString($options['reward_identifier'] ?? null),
            'marketing_profile_id' => $this->positiveInt($options['marketing_profile_id'] ?? $options['profile_id'] ?? null),
            'channel' => $this->nullableString($options['channel'] ?? null),
            'timing_days_before_expiration' => $this->nonNegativeInt($options['timing_days_before_expiration'] ?? $options['timing_days'] ?? null),
            'limit' => 1,
            'policy_version' => max(0, (int) ($options['policy_version'] ?? data_get($policy, 'versioning.current_version', 0))),
            'include_content' => false,
            'force' => (bool) ($options['force'] ?? false),
            'now' => $options['now'] ?? null,
        ]);
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function explainReward(int $tenantId, array $policy, array $options = []): array
    {
        $rewardIdentifier = $this->nullableString($options['reward_identifier'] ?? null);
        $marketingProfileId = $this->positiveInt($options['marketing_profile_id'] ?? $options['profile_id'] ?? null);
        $channel = $this->nullableString($options['channel'] ?? null);
        $timingDaysFilter = $this->nonNegativeInt($options['timing_days_before_expiration'] ?? $options['timing_days'] ?? null);
        $now = $this->asDate($options['now'] ?? null) ?? now()->toImmutable();
        $policyVersion = max(
            0,
            (int) ($options['policy_version'] ?? data_get($policy, 'versioning.current_version', data_get($policy, 'access_state.policy_version', 0)))
        );

        $rewards = collect($this->analyticsService->outstandingRewardBuckets($tenantId))
            ->filter(function (array $reward) use ($rewardIdentifier, $marketingProfileId): bool {
                if ($rewardIdentifier !== null
                    && strtolower(trim((string) ($reward['reward_identifier'] ?? ''))) !== strtolower($rewardIdentifier)) {
                    return false;
                }

                if ($marketingProfileId !== null && (int) ($reward['marketing_profile_id'] ?? 0) !== $marketingProfileId) {
                    return false;
                }

                return true;
            })
            ->values();

        if ($rewards->isEmpty()) {
            return [
                'tenant_id' => $tenantId,
                'status' => 'reward_not_found',
                'message' => 'No current reward balance matched that reminder lookup.',
                'policy_version' => $policyVersion,
                'items' => [],
            ];
        }

        $readiness = $this->deliveryReadiness($tenantId, $policy, $now);
        $launchGate = $this->launchGate($policy, $now);
        $dispatchPreview = $this->processTenant($tenantId, $policy, [
            'dry_run' => true,
            'include_content' => true,
            'now' => $now->toIso8601String(),
            'reward_identifier' => $rewardIdentifier,
            'marketing_profile_id' => $marketingProfileId,
            'channel' => $channel,
            'policy_version' => $policyVersion,
            'limit' => max(1, min(25, (int) ($options['limit'] ?? 10))),
        ]);

        $items = $rewards->map(function (array $reward) use ($policy, $options, $policyVersion, $channel, $timingDaysFilter, $dispatchPreview): array {
            $scheduleExplanation = $this->scheduleService->explain($policy, [
                ...$reward,
                'policy_version' => $policyVersion,
            ], [
                ...$options,
                'tenant_id' => $reward['tenant_id'] ?? null,
                'policy_version' => $policyVersion,
                'channel' => $channel,
            ]);

            $processedItems = collect((array) ($dispatchPreview['processed_items'] ?? []))
                ->filter(function (array $row) use ($reward, $channel, $timingDaysFilter): bool {
                    if (strtolower(trim((string) ($row['reward_identifier'] ?? ''))) !== strtolower(trim((string) ($reward['reward_identifier'] ?? '')))) {
                        return false;
                    }

                    if ($channel !== null && strtolower(trim((string) ($row['channel'] ?? ''))) !== strtolower($channel)) {
                        return false;
                    }

                    if ($timingDaysFilter !== null && max(0, (int) ($row['timing_days_before_expiration'] ?? -1)) !== $timingDaysFilter) {
                        return false;
                    }

                    return true;
                })
                ->values()
                ->all();

            return [
                'reward_identifier' => $reward['reward_identifier'] ?? null,
                'marketing_profile_id' => $reward['marketing_profile_id'] ?? null,
                'customer_name' => $reward['customer_name'] ?? null,
                'reward_source_key' => $reward['source_key'] ?? null,
                'reward_source_label' => $reward['source_label'] ?? null,
                'schedule_explanation' => $scheduleExplanation,
                'dispatch_preview' => [
                    'due_items' => collect((array) ($dispatchPreview['due_items'] ?? []))
                        ->where('reward_identifier', $reward['reward_identifier'] ?? null)
                        ->when($timingDaysFilter !== null, fn ($collection) => $collection->where('timing_days_before_expiration', $timingDaysFilter))
                        ->values()
                        ->all(),
                    'processed_items' => $processedItems,
                    'schedule_skipped_items' => collect((array) ($dispatchPreview['schedule_skipped_items'] ?? []))
                        ->where('reward_identifier', $reward['reward_identifier'] ?? null)
                        ->when($timingDaysFilter !== null, fn ($collection) => $collection->where('timing_days_before_expiration', $timingDaysFilter))
                        ->values()
                        ->all(),
                ],
                'suppression_reasons' => collect([
                    ...((array) data_get($scheduleExplanation, 'suppression_reasons', [])),
                    ...collect($processedItems)->pluck('skip_reason')->filter()->all(),
                ])->unique()->values()->all(),
            ];
        })->all();

        return [
            'tenant_id' => $tenantId,
            'status' => 'ok',
            'policy_version' => $policyVersion,
            'evaluated_at' => $now->toIso8601String(),
            'delivery_readiness' => $readiness,
            'launch_gate' => $launchGate,
            'items' => $items,
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $reward
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>  $readiness
     * @return array<string,mixed>
     */
    protected function dispatchDueReminder(
        int $tenantId,
        array $policy,
        array $reward,
        array $entry,
        array $readiness,
        bool $dryRun,
        bool $force,
        bool $includeContent
    ): array {
        $channel = strtolower(trim((string) ($entry['channel'] ?? 'email')));
        $preview = $this->previewForChannel($policy, $reward, $entry);

        if ($channel === 'email') {
            if (! (bool) data_get($readiness, 'email.live_ready', false)) {
                return $this->markSkipped(
                    $entry,
                    (string) data_get($readiness, 'email.message', 'Reminder emails are not ready for live sending.'),
                    $dryRun,
                    $force,
                    'email_not_ready',
                    $preview
                );
            }

            return $this->dispatchEmailReminder($tenantId, $reward, $entry, $preview, $dryRun, $force, $includeContent);
        }

        if (! (bool) data_get($readiness, 'sms.live_ready', false)) {
            return $this->markSkipped(
                $entry,
                (string) data_get($readiness, 'sms.message', 'Text reminders are not ready for live sending.'),
                $dryRun,
                $force,
                'sms_not_ready',
                $preview
            );
        }

        return $this->dispatchSmsReminder($tenantId, $reward, $entry, $preview, $dryRun, $force, $includeContent);
    }

    /**
     * @param  array<string,mixed>  $reward
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>  $preview
     * @return array<string,mixed>
     */
    protected function dispatchEmailReminder(
        int $tenantId,
        array $reward,
        array $entry,
        array $preview,
        bool $dryRun,
        bool $force,
        bool $includeContent
    ): array {
        $email = $this->nullableString($reward['email'] ?? null);
        if ($email === null) {
            return $this->markSkipped($entry, 'Customer email is missing for this reminder.', $dryRun, $force, 'missing_email', $preview);
        }

        if ($dryRun) {
            return [
                ...$entry,
                'status' => 'preview_ready',
                'delivery_channel' => 'email',
                'delivery_reference' => null,
                'reason' => 'Email reminder is ready to send through the current delivery path.',
                'preview' => $includeContent ? $preview : null,
            ];
        }

        $delivery = MarketingEmailDelivery::query()->create([
            'marketing_campaign_recipient_id' => null,
            'marketing_profile_id' => $this->positiveInt($entry['marketing_profile_id'] ?? null),
            'tenant_id' => $tenantId,
            'provider' => 'sendgrid',
            'campaign_type' => 'tenant_rewards_reminder',
            'template_key' => 'tenant_rewards_expiration_reminder',
            'email' => $email,
            'status' => 'sending',
            'raw_payload' => [
                'reward_identifier' => $entry['reward_identifier'] ?? null,
                'reward_code' => $entry['reward_code'] ?? null,
                'reminder_key' => $entry['reminder_key'] ?? null,
                'timing_days_before_expiration' => $entry['timing_days_before_expiration'] ?? null,
                'scheduled_at' => $entry['scheduled_at'] ?? null,
                'policy_version' => $entry['policy_version'] ?? null,
                'rendered_subject' => $preview['subject'] ?? null,
                'rendered_preview_text' => $preview['preview_text'] ?? null,
                'rendered_body' => $preview['body'] ?? null,
                'rendered_cta' => $preview['cta'] ?? null,
            ],
            'metadata' => [
                'reward_identifier' => $entry['reward_identifier'] ?? null,
                'reminder_key' => $entry['reminder_key'] ?? null,
                'policy_version' => $entry['policy_version'] ?? null,
                'delivery_kind' => 'tenant_rewards_expiration_reminder',
                'channel' => 'email',
            ],
        ]);

        $attemptedAt = now()->toIso8601String();
        $this->scheduleService->recordAttempted([
            ...$entry,
            'attempted_at' => $attemptedAt,
            'delivery_reference' => 'marketing_email_delivery:'.$delivery->id,
            'allow_duplicate' => $force,
        ]);

        $send = $this->sendGridEmailService->sendEmail(
            $email,
            (string) ($preview['subject'] ?? 'Your rewards expire soon'),
            (string) ($preview['body'] ?? ''),
            [
                'tenant_id' => $tenantId,
                'campaign_type' => 'tenant_rewards_reminder',
                'template_key' => 'tenant_rewards_expiration_reminder',
                'customer_id' => $this->positiveInt($entry['marketing_profile_id'] ?? null),
                'metadata' => [
                    'reward_identifier' => $entry['reward_identifier'] ?? null,
                    'policy_version' => $entry['policy_version'] ?? null,
                    'reminder_key' => $entry['reminder_key'] ?? null,
                    'timing_days_before_expiration' => $entry['timing_days_before_expiration'] ?? null,
                ],
                'categories' => ['tenant-rewards-reminder', 'reward-expiration'],
                'custom_args' => [
                    'marketing_email_delivery_id' => (string) $delivery->id,
                    'reward_identifier' => (string) ($entry['reward_identifier'] ?? ''),
                    'reminder_key' => (string) ($entry['reminder_key'] ?? ''),
                ],
            ]
        );

        $success = (bool) ($send['success'] ?? false);
        $timestamp = now();
        $delivery->forceFill([
            'provider' => (string) ($send['provider'] ?? 'sendgrid'),
            'provider_message_id' => $send['message_id'] ?? null,
            'sendgrid_message_id' => $send['message_id'] ?? null,
            'status' => $success ? 'sent' : 'failed',
            'sent_at' => $success ? $timestamp : null,
            'failed_at' => $success ? null : $timestamp,
            'raw_payload' => [
                ...((array) ($delivery->raw_payload ?? [])),
                'provider_status' => $send['status'] ?? null,
                'provider_payload' => is_array($send['payload'] ?? null) ? $send['payload'] : [],
                'error_code' => $send['error_code'] ?? null,
                'error_message' => $send['error_message'] ?? null,
                'dry_run' => (bool) ($send['dry_run'] ?? false),
            ],
            'metadata' => [
                ...((array) ($delivery->metadata ?? [])),
                'provider' => $send['provider'] ?? 'sendgrid',
                'retryable' => (bool) ($send['retryable'] ?? false),
            ],
        ])->save();

        if ($success) {
            $this->scheduleService->recordSent([
                ...$entry,
                'sent_at' => $timestamp->toIso8601String(),
                'delivery_reference' => 'marketing_email_delivery:'.$delivery->id,
                'allow_duplicate' => $force,
            ]);

            return [
                ...$entry,
                'status' => 'sent',
                'delivery_channel' => 'email',
                'delivery_reference' => 'marketing_email_delivery:'.$delivery->id,
                'provider_message_id' => $send['message_id'] ?? null,
                'preview' => $includeContent ? $preview : null,
            ];
        }

        $reason = $this->nullableString($send['error_message'] ?? null) ?? 'Email reminder send failed.';
        $this->scheduleService->recordFailed([
            ...$entry,
            'failed_at' => $timestamp->toIso8601String(),
            'delivery_reference' => 'marketing_email_delivery:'.$delivery->id,
            'notes' => $reason,
            'allow_duplicate' => $force,
        ], $reason);

        return [
            ...$entry,
            'status' => 'failed',
            'delivery_channel' => 'email',
            'delivery_reference' => 'marketing_email_delivery:'.$delivery->id,
            'error_code' => $send['error_code'] ?? null,
            'reason' => $reason,
            'preview' => $includeContent ? $preview : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $reward
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>  $preview
     * @return array<string,mixed>
     */
    protected function dispatchSmsReminder(
        int $tenantId,
        array $reward,
        array $entry,
        array $preview,
        bool $dryRun,
        bool $force,
        bool $includeContent
    ): array {
        $toPhone = $this->nullableString($reward['phone'] ?? null);
        if ($toPhone === null) {
            return $this->markSkipped($entry, 'Customer phone number is missing for this reminder.', $dryRun, $force, 'missing_phone', $preview);
        }

        if ($dryRun) {
            return [
                ...$entry,
                'status' => 'preview_ready',
                'delivery_channel' => 'sms',
                'delivery_reference' => null,
                'reason' => 'Text reminder is ready to send through the current delivery path.',
                'preview' => $includeContent ? $preview : null,
            ];
        }

        $delivery = MarketingMessageDelivery::query()->create([
            'campaign_id' => null,
            'campaign_recipient_id' => null,
            'marketing_profile_id' => $this->positiveInt($entry['marketing_profile_id'] ?? null),
            'channel' => 'sms',
            'provider' => 'twilio',
            'to_phone' => $toPhone,
            'variant_id' => null,
            'attempt_number' => 1,
            'rendered_message' => (string) ($preview['body'] ?? ''),
            'send_status' => 'sending',
            'created_by' => null,
            'provider_payload' => [
                'reward_identifier' => $entry['reward_identifier'] ?? null,
                'reward_code' => $entry['reward_code'] ?? null,
                'reminder_key' => $entry['reminder_key'] ?? null,
                'policy_version' => $entry['policy_version'] ?? null,
                'timing_days_before_expiration' => $entry['timing_days_before_expiration'] ?? null,
                'scheduled_at' => $entry['scheduled_at'] ?? null,
                'delivery_kind' => 'tenant_rewards_expiration_reminder',
            ],
        ]);

        $attemptedAt = now()->toIso8601String();
        $this->scheduleService->recordAttempted([
            ...$entry,
            'attempted_at' => $attemptedAt,
            'delivery_reference' => 'marketing_message_delivery:'.$delivery->id,
            'allow_duplicate' => $force,
        ]);

        $send = $this->twilioSmsService->sendSms($toPhone, (string) ($preview['body'] ?? ''), [
            'status_callback_url' => $this->statusCallbackUrl(),
        ]);

        $success = (bool) ($send['success'] ?? false);
        $providerStatus = $this->deliveryTrackingService->mapProviderStatus($send['status'] ?? null);
        $timestamp = now();

        $delivery->forceFill([
            'provider_message_id' => $send['provider_message_id'] ?? null,
            'from_identifier' => $send['from_identifier'] ?? null,
            'send_status' => $success ? $providerStatus : 'failed',
            'error_code' => $send['error_code'] ?? null,
            'error_message' => $send['error_message'] ?? null,
            'provider_payload' => [
                ...((array) ($delivery->provider_payload ?? [])),
                'sender_key' => $send['sender_key'] ?? null,
                'sender_label' => $send['sender_label'] ?? null,
                'provider_status' => $send['status'] ?? null,
                'twilio_response' => is_array($send['payload'] ?? null) ? $send['payload'] : [],
            ],
            'sent_at' => $success && in_array($providerStatus, ['queued', 'sending', 'sent', 'delivered', 'undelivered'], true)
                ? $timestamp
                : null,
            'delivered_at' => $success && $providerStatus === 'delivered' ? $timestamp : null,
            'failed_at' => ! $success || in_array($providerStatus, ['failed', 'undelivered', 'canceled'], true) ? $timestamp : null,
        ])->save();

        $this->deliveryTrackingService->appendEvent(
            delivery: $delivery,
            provider: 'twilio',
            providerMessageId: $delivery->provider_message_id,
            eventType: 'status_updated',
            eventStatus: $success ? $providerStatus : 'failed',
            payload: [
                'result' => $send,
                'reward_identifier' => $entry['reward_identifier'] ?? null,
                'policy_version' => $entry['policy_version'] ?? null,
            ],
            occurredAt: $timestamp
        );

        if ($success) {
            $this->scheduleService->recordSent([
                ...$entry,
                'sent_at' => $timestamp->toIso8601String(),
                'delivery_reference' => 'marketing_message_delivery:'.$delivery->id,
                'allow_duplicate' => $force,
            ]);

            return [
                ...$entry,
                'status' => 'sent',
                'delivery_channel' => 'sms',
                'delivery_reference' => 'marketing_message_delivery:'.$delivery->id,
                'provider_message_id' => $send['provider_message_id'] ?? null,
                'preview' => $includeContent ? $preview : null,
            ];
        }

        $reason = $this->nullableString($send['error_message'] ?? null) ?? 'Text reminder send failed.';
        $this->scheduleService->recordFailed([
            ...$entry,
            'failed_at' => $timestamp->toIso8601String(),
            'delivery_reference' => 'marketing_message_delivery:'.$delivery->id,
            'notes' => $reason,
            'allow_duplicate' => $force,
        ], $reason);

        return [
            ...$entry,
            'status' => 'failed',
            'delivery_channel' => 'sms',
            'delivery_reference' => 'marketing_message_delivery:'.$delivery->id,
            'error_code' => $send['error_code'] ?? null,
            'reason' => $reason,
            'preview' => $includeContent ? $preview : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>|null  $preview
     * @return array<string,mixed>
     */
    protected function markSkipped(
        array $entry,
        string $reason,
        bool $dryRun,
        bool $force,
        string $skipReason = 'manual_skip',
        ?array $preview = null
    ): array {
        if (! $dryRun) {
            $this->scheduleService->recordSkipped([
                ...$entry,
                'delivery_reference' => null,
                'allow_duplicate' => $force,
            ], $skipReason, $reason);
        }

        return [
            ...$entry,
            'status' => 'skipped',
            'skip_reason' => $skipReason,
            'reason' => $reason,
            'delivery_reference' => null,
            'preview' => $preview,
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $reward
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    protected function previewForChannel(array $policy, array $reward, array $entry): array
    {
        $context = [
            'expires_at' => $entry['expires_at'] ?? null,
            'rewards_url' => $this->rewardsUrl(),
            'timing_days_before_expiration' => $entry['timing_days_before_expiration'] ?? null,
        ];

        return strtolower(trim((string) ($entry['channel'] ?? 'email'))) === 'sms'
            ? $this->previewService->smsReminder($policy, $reward, $context)
            : $this->previewService->emailReminder($policy, $reward, $context);
    }

    /**
     * @param  array<string,mixed>  $reward
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>|null  $preview
     * @return array<string,mixed>
     */
    protected function presentScheduleRow(array $reward, array $entry, ?array $preview = null): array
    {
        return [
            'reward_identifier' => $entry['reward_identifier'] ?? null,
            'reward_code' => $entry['reward_code'] ?? null,
            'reward_source_key' => $entry['reward_source_key'] ?? ($reward['source_key'] ?? null),
            'reward_source_label' => $entry['reward_source_label'] ?? ($reward['source_label'] ?? null),
            'channel' => $entry['channel'] ?? null,
            'status' => $entry['status'] ?? null,
            'scheduled_at' => $entry['scheduled_at'] ?? null,
            'timing_days_before_expiration' => $entry['timing_days_before_expiration'] ?? null,
            'policy_version' => $entry['policy_version'] ?? null,
            'marketing_profile_id' => $reward['marketing_profile_id'] ?? null,
            'customer_name' => $reward['customer_name'] ?? trim(((string) ($reward['first_name'] ?? '')).' '.((string) ($reward['last_name'] ?? ''))),
            'remaining_amount' => $reward['remaining_amount'] ?? $reward['remaining_candle_cash'] ?? null,
            'formatted_remaining_amount' => $reward['formatted_remaining_amount'] ?? null,
            'earned_at' => $reward['earned_at'] ?? $entry['earned_at'] ?? null,
            'expires_at' => $entry['expires_at'] ?? null,
            'reason' => $entry['reason'] ?? null,
            'skip_reason' => $entry['skip_reason'] ?? null,
            'preview' => $preview,
        ];
    }

    /**
     * @param  array<string,mixed>  $reward
     * @param  array<string,mixed>  $processed
     * @param  array<string,mixed>|null  $preview
     * @return array<string,mixed>
     */
    protected function presentProcessedRow(array $reward, array $processed, ?array $preview = null): array
    {
        return [
            ...$this->presentScheduleRow($reward, $processed, $preview),
            'delivery_reference' => $processed['delivery_reference'] ?? null,
            'delivery_channel' => $processed['delivery_channel'] ?? ($processed['channel'] ?? null),
            'provider_message_id' => $processed['provider_message_id'] ?? null,
            'error_code' => $processed['error_code'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>  $reward
     * @return array<string,mixed>
     */
    protected function withRewardContext(array $entry, array $reward): array
    {
        return [
            ...$entry,
            'reward_source_key' => $reward['source_key'] ?? ($entry['reward_source_key'] ?? null),
            'reward_source_label' => $reward['source_label'] ?? ($entry['reward_source_label'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @param  array<string,mixed>  $processed
     */
    protected function applyProcessedSummary(array &$summary, array $processed): void
    {
        $status = strtolower(trim((string) ($processed['status'] ?? '')));
        $channel = strtolower(trim((string) ($processed['channel'] ?? $processed['delivery_channel'] ?? '')));

        if ($status === 'sent') {
            $summary['sent_count']++;
            if ($channel === 'email') {
                $summary['email_sent_count']++;
            }
            if ($channel === 'sms') {
                $summary['sms_sent_count']++;
            }

            return;
        }

        if ($status === 'failed') {
            $summary['failed_count']++;
            if ($channel === 'email') {
                $summary['email_failed_count']++;
            }
            if ($channel === 'sms') {
                $summary['sms_failed_count']++;
            }

            return;
        }

        if ($status === 'skipped') {
            $summary['skipped_count']++;
            if ($channel === 'email') {
                $summary['email_skipped_count']++;
            }
            if ($channel === 'sms') {
                $summary['sms_skipped_count']++;
            }
        }
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    protected function deliveryReadiness(int $tenantId, array $policy, CarbonImmutable $now): array
    {
        $expiration = (array) ($policy['expiration_and_reminders'] ?? []);
        $emailContext = $this->emailReadiness->providerContextForDelivery($tenantId);
        $emailEnabled = (bool) ($expiration['email_enabled'] ?? true);
        $emailLiveReady = ! $emailEnabled || (bool) ($emailContext['can_send_live'] ?? false);

        $smsEnabled = (bool) ($expiration['sms_enabled'] ?? false);
        $smsSender = $this->twilioSenderConfigService->defaultSender();
        $smsLiveReady = ! $smsEnabled || (
            (bool) config('marketing.sms.enabled')
            && (bool) config('marketing.twilio.enabled')
            && ! (bool) config('marketing.sms.dry_run')
            && $smsSender !== null
        );

        return [
            'evaluated_at' => $now->toIso8601String(),
            'email' => [
                'enabled' => $emailEnabled,
                'live_ready' => $emailLiveReady,
                'message' => $emailEnabled
                    ? ($emailLiveReady
                        ? 'Reminder emails are ready for live sending.'
                        : $this->firstNonEmpty(
                            (array) ($emailContext['notes'] ?? []),
                            (array) ($emailContext['missing_requirements'] ?? []),
                            'Reminder emails need live email setup before launch.'
                        ))
                    : 'Reminder emails are turned off.',
                'provider_context' => $emailContext,
            ],
            'sms' => [
                'enabled' => $smsEnabled,
                'live_ready' => $smsLiveReady,
                'sender' => $smsSender,
                'message' => $smsEnabled
                    ? ($smsLiveReady
                        ? 'Text reminders are ready for live sending.'
                        : ((bool) config('marketing.sms.dry_run')
                            ? 'Text reminders are still in dry-run mode.'
                            : 'Text reminders need a live SMS sender before launch.'))
                    : 'Text reminders are turned off.',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array{allowed:bool,code:string,message:string}
     */
    protected function launchGate(array $policy, CarbonImmutable $now): array
    {
        $access = (array) ($policy['access_state'] ?? []);
        $launchState = strtolower(trim((string) ($access['launch_state'] ?? 'published')));
        $scheduledLaunchAt = $this->asDate($access['scheduled_launch_at'] ?? null);

        if ((bool) ($access['test_mode'] ?? false)) {
            return [
                'allowed' => false,
                'code' => 'test_mode_enabled',
                'message' => 'Program settings are in test mode, so live reminders are paused.',
            ];
        }

        if ($launchState === 'draft') {
            return [
                'allowed' => false,
                'code' => 'program_in_draft',
                'message' => 'Program settings are still in draft, so reminders are not live yet.',
            ];
        }

        if ($launchState === 'scheduled' && $scheduledLaunchAt instanceof CarbonImmutable && $scheduledLaunchAt->greaterThan($now)) {
            return [
                'allowed' => false,
                'code' => 'launch_scheduled_for_future',
                'message' => 'Program launch is scheduled for later, so reminders are waiting for the live date.',
            ];
        }

        return [
            'allowed' => true,
            'code' => 'live',
            'message' => 'Program is live for customer reminders.',
        ];
    }

    protected function shouldPersistScheduleSkip(string $skipReason): bool
    {
        return ! in_array(strtolower(trim($skipReason)), ['duplicate_prevented'], true);
    }

    /**
     * @param  array<string,int>  $counts
     */
    protected function incrementSkipReason(array &$counts, string $code): void
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            $code = 'skipped';
        }

        $counts[$code] = (int) ($counts[$code] ?? 0) + 1;
    }

    protected function rewardsUrl(): string
    {
        return rtrim((string) config('marketing.candle_cash.storefront_base_url', 'https://theforestrystudio.com'), '/').'/pages/rewards';
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

    protected function asDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    protected function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    protected function nonNegativeInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    protected function matchesTimingFilter(array $entry, ?int $timingDaysFilter): bool
    {
        if ($timingDaysFilter === null) {
            return true;
        }

        return max(0, (int) ($entry['timing_days_before_expiration'] ?? -1)) === $timingDaysFilter;
    }

    protected function firstNonEmpty(mixed ...$lists): string
    {
        foreach ($lists as $list) {
            if (is_string($list)) {
                $normalized = trim($list);
                if ($normalized !== '') {
                    return $normalized;
                }

                continue;
            }

            if (! is_array($list)) {
                continue;
            }

            foreach ($list as $item) {
                $normalized = trim((string) $item);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        return '';
    }
}
