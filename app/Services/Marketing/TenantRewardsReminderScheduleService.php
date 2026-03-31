<?php

namespace App\Services\Marketing;

use Carbon\CarbonImmutable;

class TenantRewardsReminderScheduleService
{
    public function __construct(
        protected TenantRewardsReminderLogService $logService
    ) {
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $reward
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function evaluate(array $policy, array $reward, array $context = []): array
    {
        $now = $this->asDate($context['now'] ?? null) ?? now()->toImmutable();
        $expiration = (array) ($policy['expiration_and_reminders'] ?? []);

        $tenantId = $this->positiveInt($reward['tenant_id'] ?? $context['tenant_id'] ?? null);
        $marketingProfileId = $this->positiveInt($reward['marketing_profile_id'] ?? $context['marketing_profile_id'] ?? null);
        $rewardIdentifier = $this->stringOrDefault($reward['reward_identifier'] ?? null, 'reward-preview');
        $rewardCode = $this->nullableString($reward['reward_code'] ?? null);
        $earnedAt = $this->asDate($reward['earned_at'] ?? null) ?? $now;
        $expiresAt = $this->resolveExpirationDate($policy, $reward, $context, $earnedAt);
        $redeemedAt = $this->asDate($reward['redeemed_at'] ?? null);
        $canceledAt = $this->asDate($reward['canceled_at'] ?? $reward['cancelled_at'] ?? null);
        $status = strtolower(trim((string) ($reward['status'] ?? 'issued')));
        $policyVersion = max(
            0,
            (int) ($reward['policy_version'] ?? $context['policy_version'] ?? data_get($policy, 'access_state.policy_version', 0))
        );

        $history = array_values(array_filter(
            is_array($reward['history'] ?? null)
                ? (array) $reward['history']
                : ((bool) ($context['ignore_existing_history'] ?? false)
                    ? []
                    : $this->logService->historyForReward($tenantId, $marketingProfileId, $rewardIdentifier)),
            fn ($row): bool => is_array($row)
        ));
        $emailOffsets = $this->normalizedOffsets(
            $expiration['email_reminder_offsets_days'] ?? $expiration['reminder_offsets_days'] ?? []
        );
        $smsOffsets = $this->selectedSmsOffsets($expiration);

        $channels = [
            'email' => [
                'enabled' => (bool) ($expiration['email_enabled'] ?? true),
                'contactable' => (bool) ($reward['email_contactable'] ?? $context['email_contactable'] ?? true),
                'offsets' => $emailOffsets,
            ],
            'sms' => [
                'enabled' => (bool) ($expiration['sms_enabled'] ?? false),
                'contactable' => (bool) ($reward['sms_contactable'] ?? $context['sms_contactable'] ?? true),
                'offsets' => $smsOffsets,
            ],
        ];

        $due = [];
        $upcoming = [];
        $skipped = [];

        foreach ($channels as $channel => $channelConfig) {
            foreach ((array) ($channelConfig['offsets'] ?? []) as $offsetDays) {
                $offsetDays = max(0, (int) $offsetDays);
                $scheduledAt = $expiresAt?->subDays($offsetDays);
                $reminderKey = $this->logService->reminderKey($rewardIdentifier, $channel, $offsetDays, $policyVersion);
                $base = [
                    'reward_identifier' => $rewardIdentifier,
                    'reward_code' => $rewardCode,
                    'tenant_id' => $tenantId,
                    'marketing_profile_id' => $marketingProfileId,
                    'channel' => $channel,
                    'timing_days_before_expiration' => $offsetDays,
                    'scheduled_at' => $scheduledAt?->toIso8601String(),
                    'policy_version' => $policyVersion,
                    'reminder_key' => $reminderKey,
                    'earned_at' => $earnedAt->toIso8601String(),
                    'expires_at' => $expiresAt?->toIso8601String(),
                ];

                if (! ($channelConfig['enabled'] ?? false)) {
                    $skipped[] = $this->skippedItem($base, 'channel_disabled', ucfirst($channel).' reminders are turned off.');
                    continue;
                }

                if ($scheduledAt === null || $expiresAt === null) {
                    $skipped[] = $this->skippedItem($base, 'missing_expiration_date', 'A reminder schedule needs an expiration date.');
                    continue;
                }

                if ($scheduledAt->greaterThan($expiresAt)) {
                    $skipped[] = $this->skippedItem($base, 'after_expiration', 'Reminder timing falls after the reward expiration date.');
                    continue;
                }

                if (! ($channelConfig['contactable'] ?? false)) {
                    $skipped[] = $this->skippedItem($base, 'channel_not_ready', ucfirst($channel).' reminders cannot send until customer contact details are ready.');
                    continue;
                }

                if (in_array($status, ['redeemed'], true) || ($redeemedAt && $redeemedAt->lessThanOrEqualTo($now))) {
                    $skipped[] = $this->skippedItem($base, 'already_redeemed', 'This reward has already been used.');
                    continue;
                }

                if (in_array($status, ['canceled', 'cancelled'], true) || ($canceledAt && $canceledAt->lessThanOrEqualTo($now))) {
                    $skipped[] = $this->skippedItem($base, 'already_canceled', 'This reward has already been canceled.');
                    continue;
                }

                if ($expiresAt->lessThan($now)) {
                    $skipped[] = $this->skippedItem($base, 'already_expired', 'This reward has already expired.');
                    continue;
                }

                if ($this->hasRecordedReminder($history, $rewardIdentifier, $channel, $offsetDays, $policyVersion, $reminderKey)) {
                    $skipped[] = $this->skippedItem($base, 'duplicate_prevented', 'This reminder timing was already recorded for this reward.');
                    continue;
                }

                $item = [
                    ...$base,
                    'status' => $scheduledAt->lessThanOrEqualTo($now) ? 'due' : 'upcoming',
                ];

                if ($item['status'] === 'due') {
                    $due[] = $item;
                } else {
                    $upcoming[] = $item;
                }
            }
        }

        $due = collect($due)->sortBy(['scheduled_at', 'channel'])->values()->all();
        $upcoming = collect($upcoming)->sortBy(['scheduled_at', 'channel'])->values()->all();
        $skipped = collect($skipped)->sortBy(['scheduled_at', 'channel'])->values()->all();

        return [
            'reward' => [
                'reward_identifier' => $rewardIdentifier,
                'reward_code' => $rewardCode,
                'tenant_id' => $tenantId,
                'marketing_profile_id' => $marketingProfileId,
                'status' => $status !== '' ? $status : 'issued',
                'earned_at' => $earnedAt->toIso8601String(),
                'expires_at' => $expiresAt?->toIso8601String(),
                'policy_version' => $policyVersion,
            ],
            'should_send' => $due,
            'upcoming' => $upcoming,
            'skipped' => $skipped,
            'history' => $history,
            'summary' => [
                'due_count' => count($due),
                'upcoming_count' => count($upcoming),
                'skipped_count' => count($skipped),
                'email_enabled' => (bool) ($channels['email']['enabled'] ?? false),
                'sms_enabled' => (bool) ($channels['sms']['enabled'] ?? false),
                'email_offsets_days' => $emailOffsets,
                'sms_offsets_days' => $smsOffsets,
                'policy_version' => $policyVersion,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function previewPolicy(array $policy, array $context = []): array
    {
        $now = $this->asDate($context['now'] ?? null) ?? now()->toImmutable();
        $tenantId = $this->positiveInt($context['tenant_id'] ?? null);
        $policyVersion = max(0, (int) ($context['policy_version'] ?? data_get($policy, 'access_state.policy_version', 0)));

        return $this->evaluate($policy, [
            'tenant_id' => $tenantId,
            'reward_identifier' => 'policy-preview',
            'earned_at' => $now->toIso8601String(),
            'policy_version' => $policyVersion,
            'email_contactable' => true,
            'sms_contactable' => true,
        ], [
            ...$context,
            'tenant_id' => $tenantId,
            'policy_version' => $policyVersion,
            'email_contactable' => true,
            'sms_contactable' => true,
        ]);
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $reward
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function explain(array $policy, array $reward, array $context = []): array
    {
        $schedule = $this->evaluate($policy, $reward, $context);
        $expiration = (array) ($policy['expiration_and_reminders'] ?? []);
        $rewardPayload = (array) ($schedule['reward'] ?? []);
        $history = (array) ($schedule['history'] ?? []);
        $now = $this->asDate($context['now'] ?? null) ?? now()->toImmutable();

        $evaluatedTimings = collect([
            ...array_map(fn (array $row): array => [...$row, 'decision' => 'due'], (array) ($schedule['should_send'] ?? [])),
            ...array_map(fn (array $row): array => [...$row, 'decision' => 'upcoming'], (array) ($schedule['upcoming'] ?? [])),
            ...array_map(fn (array $row): array => [...$row, 'decision' => 'skipped'], (array) ($schedule['skipped'] ?? [])),
        ])
            ->sortBy(['scheduled_at', 'channel'])
            ->values()
            ->map(function (array $row) use ($expiration): array {
                $channel = strtolower(trim((string) ($row['channel'] ?? 'email')));
                $channelEnabled = $channel === 'sms'
                    ? (bool) ($expiration['sms_enabled'] ?? false)
                    : (bool) ($expiration['email_enabled'] ?? true);

                return [
                    'reward_identifier' => $row['reward_identifier'] ?? null,
                    'channel' => $channel,
                    'timing_days_before_expiration' => $row['timing_days_before_expiration'] ?? null,
                    'scheduled_at' => $row['scheduled_at'] ?? null,
                    'decision' => $row['decision'] ?? $row['status'] ?? null,
                    'status' => $row['status'] ?? null,
                    'skip_reason' => $row['skip_reason'] ?? null,
                    'reason' => $row['reason'] ?? null,
                    'policy_version' => $row['policy_version'] ?? null,
                    'channel_enabled' => $channelEnabled,
                    'contact_ready' => ! in_array((string) ($row['skip_reason'] ?? ''), ['channel_not_ready'], true),
                    'suppressed' => ($row['decision'] ?? $row['status'] ?? null) === 'skipped',
                ];
            })
            ->all();

        $emailOffsets = $this->normalizedOffsets(
            $expiration['email_reminder_offsets_days'] ?? $expiration['reminder_offsets_days'] ?? []
        );
        $smsOffsets = $this->selectedSmsOffsets($expiration);
        $expiresAt = $this->asDate($rewardPayload['expires_at'] ?? null);
        $redeemedAt = $this->asDate($reward['redeemed_at'] ?? null);
        $canceledAt = $this->asDate($reward['canceled_at'] ?? $reward['cancelled_at'] ?? null);
        $status = strtolower(trim((string) ($rewardPayload['status'] ?? 'issued')));

        return [
            'reward' => $rewardPayload,
            'policy_version' => max(0, (int) ($rewardPayload['policy_version'] ?? 0)),
            'eligibility_checks' => [
                'has_expiration_date' => $expiresAt instanceof CarbonImmutable,
                'reward_redeemed' => $status === 'redeemed' || ($redeemedAt && $redeemedAt->lessThanOrEqualTo($now)),
                'reward_canceled' => in_array($status, ['canceled', 'cancelled'], true) || ($canceledAt && $canceledAt->lessThanOrEqualTo($now)),
                'reward_expired' => $expiresAt instanceof CarbonImmutable ? $expiresAt->lessThan($now) : false,
                'history_entries' => count($history),
            ],
            'channel_eligibility' => [
                'email' => [
                    'enabled' => (bool) ($expiration['email_enabled'] ?? true),
                    'contactable' => (bool) ($reward['email_contactable'] ?? $context['email_contactable'] ?? true),
                    'offsets_days' => $emailOffsets,
                ],
                'sms' => [
                    'enabled' => (bool) ($expiration['sms_enabled'] ?? false),
                    'contactable' => (bool) ($reward['sms_contactable'] ?? $context['sms_contactable'] ?? true),
                    'offsets_days' => $smsOffsets,
                    'max_per_reward' => max(0, (int) ($expiration['sms_max_per_reward'] ?? 0)),
                    'quiet_days' => max(0, (int) ($expiration['sms_quiet_days'] ?? 0)),
                ],
            ],
            'evaluated_timings' => $evaluatedTimings,
            'suppression_reasons' => collect($evaluatedTimings)
                ->pluck('skip_reason')
                ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
                ->values()
                ->unique()
                ->all(),
            'history' => $history,
            'summary' => $schedule['summary'] ?? [],
        ];
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    public function recordScheduled(array $entry): void
    {
        $this->logService->record([
            ...$entry,
            'status' => 'scheduled',
            'occurred_at' => $entry['scheduled_at'] ?? $entry['occurred_at'] ?? null,
        ]);
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    public function recordAttempted(array $entry): void
    {
        $attemptedAt = $entry['attempted_at'] ?? now()->toIso8601String();

        $this->logService->record([
            ...$entry,
            'status' => 'attempted',
            'attempted_at' => $attemptedAt,
            'occurred_at' => $attemptedAt,
            'processed_at' => $attemptedAt,
        ]);
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    public function recordSent(array $entry): void
    {
        $sentAt = $entry['sent_at'] ?? now()->toIso8601String();

        $this->logService->record([
            ...$entry,
            'status' => 'sent',
            'sent_at' => $sentAt,
            'occurred_at' => $sentAt,
            'processed_at' => $sentAt,
        ]);
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    public function recordFailed(array $entry, ?string $reason = null): void
    {
        $failedAt = $entry['failed_at'] ?? now()->toIso8601String();

        $this->logService->record([
            ...$entry,
            'status' => 'failed',
            'reason' => $reason,
            'failed_at' => $failedAt,
            'occurred_at' => $failedAt,
            'processed_at' => $failedAt,
        ]);
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    public function recordSkipped(array $entry, string $skipReason, ?string $reason = null): void
    {
        $skippedAt = $entry['skipped_at'] ?? now()->toIso8601String();

        $this->logService->record([
            ...$entry,
            'status' => 'skipped',
            'skip_reason' => $skipReason,
            'reason' => $reason,
            'skipped_at' => $skippedAt,
            'occurred_at' => $skippedAt,
            'processed_at' => $skippedAt,
        ]);
    }

    /**
     * @param  array<string,mixed>  $expiration
     * @return array<int,int>
     */
    protected function selectedSmsOffsets(array $expiration): array
    {
        $offsets = $this->normalizedOffsets(
            $expiration['sms_reminder_offsets_days'] ?? ($expiration['sms_enabled'] ?? false ? [3] : [])
        );
        if ($offsets === []) {
            return [];
        }

        $cap = max(0, (int) ($expiration['sms_max_per_reward'] ?? count($offsets)));
        if ($cap === 0) {
            return [];
        }

        $quietDays = max(0, (int) ($expiration['sms_quiet_days'] ?? 0));
        $selected = [];
        foreach (collect($offsets)->sort()->values()->all() as $offset) {
            if (count($selected) >= $cap) {
                break;
            }

            $tooClose = collect($selected)->contains(
                fn (int $selectedOffset): bool => abs($selectedOffset - $offset) < $quietDays
            );

            if ($tooClose) {
                continue;
            }

            $selected[] = (int) $offset;
        }

        return collect($selected)
            ->sortDesc()
            ->values()
            ->all();
    }

    /**
     * @return array<int,int>
     */
    protected function normalizedOffsets(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($item): int => max(0, (int) $item))
            ->filter(fn (int $item): bool => $item >= 0)
            ->unique()
            ->sortDesc()
            ->values()
            ->take(10)
            ->all();
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $reward
     * @param  array<string,mixed>  $context
     */
    protected function resolveExpirationDate(
        array $policy,
        array $reward,
        array $context,
        CarbonImmutable $earnedAt
    ): ?CarbonImmutable {
        $explicit = $this->asDate($reward['expires_at'] ?? $context['expires_at'] ?? null);
        if ($explicit) {
            return $explicit;
        }

        $expiration = (array) ($policy['expiration_and_reminders'] ?? []);
        $mode = strtolower(trim((string) ($expiration['expiration_mode'] ?? 'days_from_issue')));
        if ($mode === 'none') {
            return null;
        }

        if ($mode === 'end_of_season') {
            return $earnedAt->endOfQuarter();
        }

        $days = max(1, (int) ($expiration['expiration_days'] ?? 30));

        return $earnedAt->addDays($days);
    }

    /**
     * @param  array<string,mixed>  $base
     * @return array<string,mixed>
     */
    protected function skippedItem(array $base, string $skipReason, string $reason): array
    {
        return [
            ...$base,
            'status' => 'skipped',
            'skip_reason' => $skipReason,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $history
     */
    protected function hasRecordedReminder(
        array $history,
        string $rewardIdentifier,
        string $channel,
        int $timingDays,
        int $policyVersion,
        string $reminderKey
    ): bool {
        $legacyKey = $this->logService->reminderKey($rewardIdentifier, $channel, $timingDays);

        return collect($history)->contains(function (array $row) use ($rewardIdentifier, $channel, $timingDays, $policyVersion, $reminderKey, $legacyKey): bool {
            $existingKey = trim((string) ($row['reminder_key'] ?? ''));
            $existingVersion = max(0, (int) ($row['policy_version'] ?? 0));
            $existingStatus = strtolower(trim((string) ($row['status'] ?? 'scheduled')));
            $sameTiming = strtolower(trim((string) ($row['reward_identifier'] ?? ''))) === strtolower($rewardIdentifier)
                && strtolower(trim((string) ($row['channel'] ?? ''))) === strtolower($channel)
                && max(0, (int) ($row['timing_days_before_expiration'] ?? -1)) === $timingDays;
            $blocksDuplicate = in_array($existingStatus, ['scheduled', 'sent', 'skipped'], true);

            if (! $blocksDuplicate) {
                return false;
            }

            if ($existingKey === $reminderKey) {
                return true;
            }

            if ($existingKey === $legacyKey && ($existingVersion === $policyVersion || $policyVersion === 0)) {
                return true;
            }

            return $sameTiming && ($existingVersion === $policyVersion || $policyVersion === 0);
        });
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

    protected function stringOrDefault(mixed $value, string $fallback): string
    {
        return $this->nullableString($value) ?? $fallback;
    }

    protected function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
