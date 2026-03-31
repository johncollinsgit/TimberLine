<?php

namespace App\Services\Marketing;

use Carbon\CarbonImmutable;

class TenantRewardsReminderAnalyticsService
{
    public function __construct(
        protected CandleCashEarnedAnalyticsService $earnedAnalyticsService,
        protected TenantRewardsReminderLogService $logService,
        protected TenantRewardsReminderDispatchService $dispatchService,
        protected TenantRewardsReminderScheduleService $scheduleService
    ) {
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function reportForTenant(int $tenantId, array $policy, array $options = []): array
    {
        $now = $this->asDate($options['now'] ?? null) ?? now()->toImmutable();
        $activityWindowDays = max(1, min(180, (int) ($options['activity_window_days'] ?? 30)));
        $upcomingWindowDays = max(1, min(30, (int) ($options['upcoming_window_days'] ?? 7)));
        $expiringSoonDays = max(1, min(90, (int) ($options['expiring_soon_days'] ?? 14)));
        $currentVersion = max(0, (int) ($options['policy_version'] ?? data_get($policy, 'versioning.current_version', data_get($policy, 'access_state.policy_version', 0))));
        $readiness = is_array($options['readiness'] ?? null) ? (array) $options['readiness'] : [];
        $financeSummary = is_array($options['finance_summary'] ?? null) ? (array) $options['finance_summary'] : [];
        $alertThresholds = is_array($options['alert_thresholds'] ?? null) ? (array) $options['alert_thresholds'] : [];
        $filters = $this->normalizedFilters($options, $now, $activityWindowDays);
        $rewardContextCache = [];

        $history = collect($this->logService->filteredForTenant($tenantId, $filters, 1000))
            ->map(function (array $row) use ($tenantId, &$rewardContextCache): array {
                return $this->enrichHistoryRow($tenantId, $row, $rewardContextCache);
            })
            ->filter(fn (array $row): bool => $this->matchesRewardTypeFilter($row, $filters['reward_type']))
            ->values();

        $queuePreview = $this->dispatchService->processTenant($tenantId, $policy, [
            'dry_run' => true,
            'limit' => 250,
            'now' => $now->toIso8601String(),
            'include_content' => false,
            'policy_version' => $currentVersion,
            'channel' => $filters['channel'],
        ]);

        $dueItems = collect((array) ($queuePreview['due_items'] ?? []))
            ->filter(fn (array $row): bool => $this->matchesRewardTypeFilter($row, $filters['reward_type']))
            ->values();
        $upcomingItems = collect((array) ($queuePreview['upcoming_items'] ?? []))
            ->filter(fn (array $row): bool => $this->matchesRewardTypeFilter($row, $filters['reward_type']))
            ->values();
        $processedPreview = collect((array) ($queuePreview['processed_items'] ?? []))
            ->filter(fn (array $row): bool => $this->matchesRewardTypeFilter($row, $filters['reward_type']))
            ->values();
        $scheduleSkippedItems = collect((array) ($queuePreview['schedule_skipped_items'] ?? []))
            ->filter(fn (array $row): bool => $this->matchesRewardTypeFilter($row, $filters['reward_type']))
            ->values();
        $earnedBuckets = collect($this->earnedAnalyticsService->outstandingRewardBuckets($tenantId))
            ->filter(function (array $reward) use ($filters): bool {
                return $this->matchesRewardTypeFilter([
                    'reward_source_key' => $reward['source_key'] ?? null,
                    'reward_source_label' => $reward['source_label'] ?? null,
                ], $filters['reward_type']);
            });

        $expiringSoon = $earnedBuckets
            ->map(function (array $reward) use ($policy, $currentVersion, $now): ?array {
                $rewardTenantId = (int) ($reward['tenant_id'] ?? 0);
                $schedule = $this->scheduleService->evaluate($policy, [
                    ...$reward,
                    'tenant_id' => $rewardTenantId,
                    'policy_version' => $currentVersion,
                ], [
                    'now' => $now->toIso8601String(),
                    'tenant_id' => $rewardTenantId,
                    'policy_version' => $currentVersion,
                ]);

                $expiresAt = $this->asDate(data_get($schedule, 'reward.expires_at'));
                if (! $expiresAt instanceof CarbonImmutable || $expiresAt->lessThan($now)) {
                    return null;
                }

                return [
                    'reward_identifier' => $reward['reward_identifier'] ?? null,
                    'marketing_profile_id' => $reward['marketing_profile_id'] ?? null,
                    'customer_name' => $reward['customer_name'] ?? null,
                    'reward_source_key' => $reward['source_key'] ?? null,
                    'reward_source_label' => $reward['source_label'] ?? null,
                    'remaining_amount' => $reward['remaining_amount'] ?? null,
                    'formatted_remaining_amount' => $reward['formatted_remaining_amount'] ?? null,
                    'expires_at' => $expiresAt->toIso8601String(),
                    'policy_version' => $currentVersion,
                ];
            })
            ->filter(fn ($row): bool => is_array($row))
            ->filter(fn (array $row): bool => ($this->asDate($row['expires_at'] ?? null)?->lessThanOrEqualTo($now->addDays($expiringSoonDays)) ?? false))
            ->sortBy('expires_at')
            ->values();

        $skipReasons = $history
            ->filter(fn (array $row): bool => strtolower(trim((string) ($row['status'] ?? ''))) === 'skipped')
            ->countBy(fn (array $row): string => strtolower(trim((string) ($row['skip_reason'] ?? 'other'))))
            ->sortDesc()
            ->map(fn (int $count, string $code): array => ['code' => $code, 'count' => $count])
            ->values()
            ->all();

        $channelBreakdown = [
            'email' => [
                'sent' => $history->where('channel', 'email')->where('status', 'sent')->count(),
                'failed' => $history->where('channel', 'email')->where('status', 'failed')->count(),
                'skipped' => $history->where('channel', 'email')->where('status', 'skipped')->count(),
            ],
            'sms' => [
                'sent' => $history->where('channel', 'sms')->where('status', 'sent')->count(),
                'failed' => $history->where('channel', 'sms')->where('status', 'failed')->count(),
                'skipped' => $history->where('channel', 'sms')->where('status', 'skipped')->count(),
            ],
        ];

        $expiringSoonAmount = round((float) $expiringSoon->sum(fn (array $row): float => (float) ($row['remaining_amount'] ?? 0)), 2);
        $healthSignals = $this->healthSignals(
            history: $history,
            queuePreview: $queuePreview,
            readiness: $readiness,
            financeSummary: $financeSummary,
            alertThresholds: $alertThresholds,
            expiringSoonAmount: $expiringSoonAmount,
            now: $now
        );

        $impactView = $this->impactView(
            dueItems: $dueItems,
            upcomingItems: $upcomingItems,
            expiringSoonAmount: $expiringSoonAmount,
            financeSummary: $financeSummary
        );

        return [
            'headline' => ($readiness['schedule_valid'] ?? false)
                ? 'Reminder activity and expiring rewards are ready to review.'
                : 'Reminder activity is available, but launch setup still needs attention.',
            'policy_version' => $currentVersion,
            'activity_window_days' => $activityWindowDays,
            'upcoming_window_days' => $upcomingWindowDays,
            'expiring_soon_days' => $expiringSoonDays,
            'filters' => $filters,
            'summary_cards' => [
                [
                    'label' => 'Reminders sent',
                    'value' => $history->where('status', 'sent')->count(),
                    'tone' => 'success',
                ],
                [
                    'label' => 'Reminders skipped',
                    'value' => $history->where('status', 'skipped')->count(),
                    'tone' => 'warning',
                ],
                [
                    'label' => 'Reminders failed',
                    'value' => $history->where('status', 'failed')->count(),
                    'tone' => 'error',
                ],
                [
                    'label' => 'Upcoming reminders due soon',
                    'value' => $upcomingItems->filter(fn (array $row): bool => ($this->asDate($row['scheduled_at'] ?? null)?->lessThanOrEqualTo($now->addDays($upcomingWindowDays)) ?? false))->count(),
                    'tone' => 'neutral',
                ],
                [
                    'label' => 'Rewards expiring soon',
                    'value' => $expiringSoon->count(),
                    'tone' => 'neutral',
                ],
            ],
            'readiness' => [
                'status' => $readiness['status'] ?? 'unknown',
                'headline' => $readiness['headline'] ?? null,
                'launch_state' => data_get($policy, 'access_state.launch_state', 'published'),
                'schedule_valid' => (bool) ($readiness['schedule_valid'] ?? false),
            ],
            'channel_breakdown' => $channelBreakdown,
            'top_skip_reasons' => $skipReasons,
            'queue_preview' => [
                'summary' => (array) ($queuePreview['summary'] ?? []),
                'due_now_count' => $dueItems->count(),
                'upcoming_count' => $upcomingItems->count(),
                'schedule_skip_count' => $scheduleSkippedItems->count(),
                'due_now' => $dueItems->take(10)->values()->all(),
                'upcoming_due_soon' => $upcomingItems
                    ->filter(fn (array $row): bool => ($this->asDate($row['scheduled_at'] ?? null)?->lessThanOrEqualTo($now->addDays($upcomingWindowDays)) ?? false))
                    ->take(10)
                    ->values()
                    ->all(),
                'preview_ready_count' => $processedPreview->where('status', 'preview_ready')->count(),
            ],
            'expiring_soon' => [
                'count' => $expiringSoon->count(),
                'amount' => $expiringSoonAmount,
                'items' => $expiringSoon->take(10)->all(),
            ],
            'activity_table' => [
                'count' => $history->count(),
                'items' => $history->take(100)->values()->all(),
            ],
            'recent_activity' => $history->take(25)->values()->all(),
            'health_signals' => $healthSignals,
            'impact_view' => $impactView,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    protected function normalizedFilters(array $options, CarbonImmutable $now, int $activityWindowDays): array
    {
        $rawFilters = is_array($options['filters'] ?? null) ? (array) $options['filters'] : [];
        $dateFrom = $this->asDate($rawFilters['date_from'] ?? null)?->startOfDay();
        $dateTo = $this->asDate($rawFilters['date_to'] ?? null)?->endOfDay();

        if (! $dateFrom instanceof CarbonImmutable && ! $dateTo instanceof CarbonImmutable) {
            $dateFrom = $now->subDays($activityWindowDays)->startOfDay();
            $dateTo = $now->endOfDay();
        }

        return [
            'date_from' => $dateFrom?->toDateString(),
            'date_to' => $dateTo?->toDateString(),
            'channel' => $this->nullableString($rawFilters['channel'] ?? null),
            'status' => $this->nullableString($rawFilters['status'] ?? null),
            'reward_type' => $this->nullableString($rawFilters['reward_type'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,array<string,mixed>|null>  $rewardContextCache
     * @return array<string,mixed>
     */
    protected function enrichHistoryRow(int $tenantId, array $row, array &$rewardContextCache): array
    {
        $rewardIdentifier = trim((string) ($row['reward_identifier'] ?? ''));
        if ($rewardIdentifier === '') {
            return $row;
        }

        if (! array_key_exists($rewardIdentifier, $rewardContextCache)) {
            $rewardContextCache[$rewardIdentifier] = $this->earnedAnalyticsService->rewardContext($tenantId, $rewardIdentifier);
        }

        $rewardContext = is_array($rewardContextCache[$rewardIdentifier] ?? null)
            ? (array) $rewardContextCache[$rewardIdentifier]
            : [];

        return [
            ...$row,
            'reward_source_key' => $row['reward_source_key'] ?? ($rewardContext['source_key'] ?? null),
            'reward_source_label' => $row['reward_source_label'] ?? ($rewardContext['source_label'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    protected function matchesRewardTypeFilter(array $row, ?string $rewardType): bool
    {
        if ($rewardType === null) {
            return true;
        }

        return strtolower(trim((string) ($row['reward_source_key'] ?? ''))) === strtolower($rewardType);
    }

    /**
     * @param  \Illuminate\Support\Collection<int,array<string,mixed>>  $history
     * @param  array<string,mixed>  $queuePreview
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>  $financeSummary
     * @param  array<string,mixed>  $alertThresholds
     * @return array<int,array<string,mixed>>
     */
    protected function healthSignals(
        $history,
        array $queuePreview,
        array $readiness,
        array $financeSummary,
        array $alertThresholds,
        float $expiringSoonAmount,
        CarbonImmutable $now
    ): array {
        $signals = [];
        $emailChannel = is_array($readiness['channels']['email'] ?? null) ? (array) $readiness['channels']['email'] : [];
        $smsChannel = is_array($readiness['channels']['sms'] ?? null) ? (array) $readiness['channels']['sms'] : [];
        $noSendsHours = max(1, (int) ($alertThresholds['alert_no_sends_hours'] ?? 24));
        $highSkipRatePercent = max(10, (int) ($alertThresholds['alert_high_skip_rate_percent'] ?? 50));
        $failureSpikeCount = max(1, (int) ($alertThresholds['alert_failure_spike_count'] ?? 5));
        $sentLast24Hours = $history->filter(function (array $row) use ($now, $noSendsHours): bool {
            if (strtolower(trim((string) ($row['status'] ?? ''))) !== 'sent') {
                return false;
            }

            $occurredAt = $this->asDate($row['occurred_at'] ?? $row['sent_at'] ?? null);

            return $occurredAt instanceof CarbonImmutable
                && $occurredAt->greaterThanOrEqualTo($now->subHours($noSendsHours));
        })->count();

        if ((bool) ($emailChannel['enabled'] ?? false) && ! (bool) ($emailChannel['ready'] ?? false)) {
            $signals[] = [
                'code' => 'email_not_configured',
                'level' => 'warning',
                'message' => 'Email reminders are turned on, but live email sending is not ready yet.',
            ];
        }

        if ((bool) ($smsChannel['enabled'] ?? false) && ! (bool) ($smsChannel['ready'] ?? false)) {
            $signals[] = [
                'code' => 'sms_not_configured',
                'level' => 'warning',
                'message' => 'Text reminders are turned on, but live SMS sending is not ready yet.',
            ];
        }

        if ($sentLast24Hours === 0 && ((int) data_get($queuePreview, 'summary.due_count', 0)) > 0) {
            $signals[] = [
                'code' => 'no_recent_sends',
                'level' => 'warning',
                'message' => sprintf('There were due reminders in the last %d hours, but none were sent.', $noSendsHours),
            ];
        }

        $completedCount = $history->whereIn('status', ['sent', 'skipped', 'failed'])->count();
        $skipCount = $history->where('status', 'skipped')->count();
        $skipRate = $completedCount > 0 ? ($skipCount / $completedCount) : 0.0;
        if ($completedCount >= 5 && ($skipRate * 100) >= $highSkipRatePercent) {
            $signals[] = [
                'code' => 'high_skip_rate',
                'level' => 'warning',
                'message' => sprintf('Recent reminder skips are above %d%% of completed reminder attempts.', $highSkipRatePercent),
            ];
        }

        $failedLast24Hours = $history->filter(function (array $row) use ($now): bool {
            if (strtolower(trim((string) ($row['status'] ?? ''))) !== 'failed') {
                return false;
            }

            $occurredAt = $this->asDate($row['occurred_at'] ?? $row['failed_at'] ?? null);

            return $occurredAt instanceof CarbonImmutable
                && $occurredAt->greaterThanOrEqualTo($now->subDay());
        })->count();
        if ($failedLast24Hours >= $failureSpikeCount) {
            $signals[] = [
                'code' => 'dispatch_failures_spike',
                'level' => 'warning',
                'message' => sprintf('Reminder delivery failures reached %d in the last 24 hours.', $failedLast24Hours),
            ];
        }

        $alertThreshold = (float) data_get($financeSummary, 'alert_threshold', data_get($financeSummary, 'threshold', 0));
        if ($alertThreshold <= 0) {
            $alertThreshold = (float) data_get($financeSummary, 'settings.liability_alert_threshold_dollars', 0);
        }

        if ($expiringSoonAmount >= max(100.0, $alertThreshold > 0 ? $alertThreshold * 0.35 : 0)) {
            $signals[] = [
                'code' => 'large_expiring_reward_volume',
                'level' => 'warning',
                'message' => 'A meaningful amount of reward value is scheduled to expire soon.',
            ];
        }

        if ($signals === []) {
            $signals[] = [
                'code' => 'healthy',
                'level' => 'info',
                'message' => 'Reminder delivery and expiring reward volume look healthy right now.',
            ];
        }

        return $signals;
    }

    /**
     * @param  \Illuminate\Support\Collection<int,array<string,mixed>>  $dueItems
     * @param  \Illuminate\Support\Collection<int,array<string,mixed>>  $upcomingItems
     * @param  array<string,mixed>  $financeSummary
     * @return array<string,mixed>
     */
    protected function impactView($dueItems, $upcomingItems, float $expiringSoonAmount, array $financeSummary): array
    {
        $estimatedReminderVolume = $dueItems->count() + $upcomingItems->count();
        $estimatedRedemptionExposure = max(
            0,
            round(
                (float) data_get($financeSummary, 'outstanding_liability.amount', 0)
                - (float) data_get($financeSummary, 'breakage_estimate.amount', 0),
                2
            )
        );

        return [
            'headline' => 'If these settings stay the same, this is the near-term operational picture.',
            'estimated_reminder_volume' => [
                'label' => 'Estimated reminder volume',
                'value' => $estimatedReminderVolume,
                'detail' => 'Based on current due and upcoming reminder timings across open rewards.',
            ],
            'estimated_expiring_rewards' => [
                'label' => 'Estimated expiring reward value',
                'value' => round($expiringSoonAmount, 2),
                'formatted_value' => '$'.number_format(round($expiringSoonAmount, 2), 2),
                'detail' => 'Based on open rewards that are due to expire soon under the current policy.',
            ],
            'estimated_redemption_exposure' => [
                'label' => 'Estimated redemption exposure',
                'value' => $estimatedRedemptionExposure,
                'formatted_value' => '$'.number_format($estimatedRedemptionExposure, 2),
                'detail' => 'Outstanding reward value after subtracting the current breakage estimate.',
            ],
            'messages' => [
                sprintf('Customers are on track to receive about %d reminders if open-reward behavior stays similar.', $estimatedReminderVolume),
                'Expiring reward value and open liability will move with current earning and expiration settings.',
            ],
        ];
    }

    protected function asDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim((string) $value) === '') {
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

        return $normalized !== '' ? strtolower($normalized) : null;
    }
}
