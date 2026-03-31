<?php

namespace App\Services\Marketing;

use Carbon\CarbonImmutable;

class TenantRewardsPolicyReadinessService
{
    public function __construct(
        protected MarketingEmailReadiness $emailReadiness,
        protected TwilioSenderConfigService $twilioSenderConfigService,
        protected TenantRewardsReminderScheduleService $reminderScheduleService
    ) {
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(?int $tenantId, array $policy, array $options = []): array
    {
        $expiration = (array) ($policy['expiration_and_reminders'] ?? []);
        $access = (array) ($policy['access_state'] ?? []);
        $versioning = is_array($options['versioning'] ?? null) ? (array) $options['versioning'] : [];
        $messages = is_array($options['messages'] ?? null) ? (array) $options['messages'] : [];
        $summary = trim((string) ($options['summary'] ?? ''));
        $smsChannelEnabled = (bool) ($options['sms_channel_enabled'] ?? true);
        $editable = (bool) ($options['editable'] ?? true);
        $currentVersion = max(0, (int) ($versioning['current_version'] ?? 0));
        $now = $this->asDate($options['now'] ?? null) ?? now()->toImmutable();

        $emailEnabled = (bool) ($expiration['email_enabled'] ?? true);
        $smsEnabled = (bool) ($expiration['sms_enabled'] ?? false);
        $testMode = (bool) ($access['test_mode'] ?? false);
        $launchState = (string) ($access['launch_state'] ?? 'published');
        $scheduledLaunchAt = $this->asDate($access['scheduled_launch_at'] ?? null);

        $emailReadiness = $this->emailReadiness->summary($tenantId);
        $emailReady = ! $emailEnabled || (bool) ($emailReadiness['can_send_live'] ?? false);

        $smsDefaultSender = $this->twilioSenderConfigService->defaultSender();
        $smsReady = ! $smsEnabled || (
            $smsChannelEnabled
            && (bool) config('marketing.sms.enabled')
            && (bool) config('marketing.twilio.enabled')
            && ! (bool) config('marketing.sms.dry_run')
            && $smsDefaultSender !== null
        );

        $schedulePreview = $this->reminderScheduleService->previewPolicy($policy, [
            'tenant_id' => $tenantId,
            'policy_version' => $currentVersion,
            'email_contactable' => true,
            'sms_contactable' => true,
        ]);
        $scheduleSummary = (array) ($schedulePreview['summary'] ?? []);

        $emailOffsets = array_values(array_map(
            'intval',
            (array) ($scheduleSummary['email_offsets_days'] ?? [])
        ));
        $smsOffsets = array_values(array_map(
            'intval',
            (array) ($scheduleSummary['sms_offsets_days'] ?? [])
        ));

        $scheduleValid = ! $emailEnabled || $emailOffsets !== [];
        if ($smsEnabled) {
            $scheduleValid = $scheduleValid && $smsOffsets !== [];
        }

        $warningCount = count((array) ($messages['warnings'] ?? []));
        $errorCount = count((array) ($messages['errors'] ?? []));
        $infoCount = count((array) ($messages['info'] ?? []));

        $alphaReference = is_array($options['alpha_reference'] ?? null) ? (array) $options['alpha_reference'] : [];
        $alphaMatches = $alphaReference !== [] ? $this->matchesAlphaPreset($policy, $alphaReference) : false;

        $programConfigured = trim((string) data_get($policy, 'program_identity.program_name', '')) !== ''
            && (float) data_get($policy, 'earning_rules.second_order_reward_amount', 0) > 0
            && (float) data_get($policy, 'value_model.max_redeemable_per_order_dollars', 0) > 0;
        $remindersConfigured = $scheduleValid && (! $emailEnabled || $emailOffsets !== []) && (! $smsEnabled || $smsOffsets !== []);
        $exclusionsReviewed = $this->hasMeaningfulExclusions((array) data_get($policy, 'redemption_rules.exclusions', []));
        $publishedLive = $launchState === 'published' && ! $testMode;
        $scheduledPending = $launchState === 'scheduled' && $scheduledLaunchAt instanceof CarbonImmutable && $scheduledLaunchAt->greaterThan($now);
        $scheduledNow = $launchState === 'scheduled' && $scheduledLaunchAt instanceof CarbonImmutable && $scheduledLaunchAt->lessThanOrEqualTo($now);
        $liveVersionPublished = ($publishedLive || $scheduledNow) && $currentVersion > 0;

        $status = 'ready';
        if (! $editable) {
            $status = 'read_only';
        } elseif ($launchState === 'draft') {
            $status = 'draft';
        } elseif ($scheduledPending) {
            $status = 'scheduled';
        }

        if ($errorCount > 0 || ! $scheduleValid || ! $emailReady || ! $smsReady || $testMode) {
            $status = 'needs_attention';
        }

        $headline = match (true) {
            $testMode => 'Program is still in test mode, so live reminders are paused.',
            ! $scheduleValid => 'Reminder timing needs attention before launch.',
            $smsEnabled && ! $smsReady => 'Text reminders need one more setting before launch.',
            $emailEnabled && ! $emailReady => 'Email reminders need one more setting before launch.',
            $status === 'draft' => 'Program settings are saved as draft.',
            $status === 'scheduled' => 'Program changes are scheduled for a future launch.',
            $status === 'read_only' => 'Program settings are unavailable until plan access is enabled.',
            default => 'Your rewards reminder schedule is ready.',
        };

        $messagesList = [];
        if ($emailEnabled) {
            $messagesList[] = $emailReady
                ? 'Reminder emails are ready to send with the current live email setup.'
                : $this->firstNonEmpty(
                    (array) ($emailReadiness['notes'] ?? []),
                    (array) ($emailReadiness['missing_requirements'] ?? []),
                    'Reminder emails still need live email setup.'
                );
        } else {
            $messagesList[] = 'Reminder emails are turned off.';
        }

        if ($smsEnabled) {
            $messagesList[] = $smsReady
                ? 'Text reminders are ready with the current live sender.'
                : ($smsChannelEnabled
                    ? ((bool) config('marketing.sms.dry_run')
                        ? 'Text reminders are still in dry-run mode.'
                        : 'Text reminders still need a live SMS sender before launch.')
                    : 'Text reminders need SMS plan access before launch.');
        } else {
            $messagesList[] = 'Text reminders are turned off.';
        }

        if ($summary !== '') {
            $messagesList[] = $summary;
        }

        $scheduleDescription = $this->scheduleDescription($emailEnabled, $emailOffsets, $smsEnabled, $smsOffsets, (int) ($expiration['sms_max_per_reward'] ?? 0));
        $messagesList[] = $scheduleDescription;

        $checklist = [
            $this->checkItem(
                'program_configured',
                'Program settings are filled in',
                $programConfigured,
                'Program name, reward amount, and order value settings are ready.'
            ),
            $this->checkItem(
                'reminders_configured',
                'Customer reminder timing is ready',
                $remindersConfigured,
                'Choose at least one reminder timing for each enabled channel before launch.'
            ),
            $this->checkItem(
                'email_channel_ready',
                'Email channel is ready',
                ! $emailEnabled || $emailReady,
                $emailEnabled
                    ? 'Email reminders need live email sending setup.'
                    : 'Email reminders are turned off.'
            ),
            $this->checkItem(
                'sms_channel_ready',
                'Text channel is ready',
                ! $smsEnabled || $smsReady,
                $smsEnabled
                    ? 'Text reminders need a live SMS sender before launch.'
                    : 'Text reminders are turned off.'
            ),
            $this->checkItem(
                'exclusions_reviewed',
                'Exclusions have been reviewed',
                $exclusionsReviewed,
                'Review wholesale, sale, subscription, and product exclusions before launch.'
            ),
            $this->checkItem(
                'live_version_published',
                'A live version has been published',
                $liveVersionPublished,
                $scheduledPending
                    ? 'A version is saved, but the launch date is still in the future.'
                    : 'Publish the current program version before going live.'
            ),
        ];

        $nextSteps = collect($checklist)
            ->filter(fn (array $item): bool => ($item['status'] ?? 'needs_attention') !== 'ready')
            ->map(fn (array $item): string => (string) ($item['next_step'] ?? 'Review this launch item.'))
            ->take(5)
            ->values()
            ->all();

        if ($testMode) {
            array_unshift($nextSteps, 'Turn off test mode before expecting customer reminders to send live.');
        }

        return [
            'status' => $status,
            'headline' => $headline,
            'summary' => $scheduleDescription,
            'launch_summary' => $summary !== '' ? $summary : $scheduleDescription,
            'program_live' => ($publishedLive || $scheduledNow) && $status === 'ready',
            'launch_state' => $launchState,
            'policy_version' => $currentVersion,
            'last_updated_at' => $versioning['last_updated_at'] ?? null,
            'warning_count' => $warningCount,
            'error_count' => $errorCount,
            'info_count' => $infoCount,
            'schedule_valid' => $scheduleValid,
            'alpha_defaults_applied' => $alphaMatches,
            'alpha_summary' => $alphaMatches
                ? 'Alpha starter setup is active.'
                : 'Alpha starter setup has custom changes.',
            'messages' => array_values(array_unique(array_filter($messagesList, fn ($value): bool => trim((string) $value) !== ''))),
            'channels' => [
                'email' => [
                    'enabled' => $emailEnabled,
                    'ready' => $emailReady,
                    'offsets_days' => $emailOffsets,
                    'status_label' => $emailReady ? 'Ready' : 'Needs setup',
                    'live_send_ready' => $emailReady,
                ],
                'sms' => [
                    'enabled' => $smsEnabled,
                    'ready' => $smsReady,
                    'offsets_days' => $smsOffsets,
                    'status_label' => $smsReady ? 'Ready' : 'Needs setup',
                    'sender_label' => trim((string) ($smsDefaultSender['label'] ?? '')) ?: null,
                    'live_send_ready' => $smsReady,
                ],
            ],
            'checklist' => $checklist,
            'next_steps' => array_values(array_unique($nextSteps)),
            'test_mode' => $testMode,
            'scheduled_launch_at' => $scheduledLaunchAt?->toIso8601String(),
            'schedule_preview' => $schedulePreview,
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $alphaReference
     */
    public function matchesAlphaPreset(array $policy, array $alphaReference): bool
    {
        $comparisons = [
            data_get($policy, 'program_identity.program_name') === data_get($alphaReference, 'program_identity.program_name'),
            data_get($policy, 'program_identity.terminology_mode') === data_get($alphaReference, 'program_identity.terminology_mode'),
            (float) data_get($policy, 'earning_rules.second_order_reward_amount', 0) === (float) data_get($alphaReference, 'earning_rules.second_order_reward_amount', 0),
            (float) data_get($policy, 'value_model.minimum_purchase_dollars', 0) === (float) data_get($alphaReference, 'value_model.minimum_purchase_dollars', 0),
            (float) data_get($policy, 'value_model.max_redeemable_per_order_dollars', 0) === (float) data_get($alphaReference, 'value_model.max_redeemable_per_order_dollars', 0),
            (float) data_get($policy, 'value_model.redeem_increment_dollars', 0) === (float) data_get($alphaReference, 'value_model.redeem_increment_dollars', 0),
            (int) data_get($policy, 'expiration_and_reminders.expiration_days', 0) === (int) data_get($alphaReference, 'expiration_and_reminders.expiration_days', 0),
            (array) data_get($policy, 'expiration_and_reminders.email_reminder_offsets_days', []) === (array) data_get($alphaReference, 'expiration_and_reminders.email_reminder_offsets_days', []),
            (array) data_get($policy, 'expiration_and_reminders.sms_reminder_offsets_days', []) === (array) data_get($alphaReference, 'expiration_and_reminders.sms_reminder_offsets_days', []),
            (int) data_get($policy, 'expiration_and_reminders.sms_max_per_reward', 0) === (int) data_get($alphaReference, 'expiration_and_reminders.sms_max_per_reward', 0),
            data_get($policy, 'redemption_rules.stacking_mode') === data_get($alphaReference, 'redemption_rules.stacking_mode'),
            data_get($policy, 'redemption_rules.code_strategy') === data_get($alphaReference, 'redemption_rules.code_strategy'),
            (array) data_get($policy, 'redemption_rules.exclusions', []) === (array) data_get($alphaReference, 'redemption_rules.exclusions', []),
        ];

        return ! in_array(false, $comparisons, true);
    }

    /**
     * @param  array<int,int>  $emailOffsets
     * @param  array<int,int>  $smsOffsets
     */
    protected function scheduleDescription(
        bool $emailEnabled,
        array $emailOffsets,
        bool $smsEnabled,
        array $smsOffsets,
        int $smsMaxPerReward
    ): string {
        $parts = [];

        if ($emailEnabled) {
            $parts[] = $emailOffsets === []
                ? 'Reminder emails are on, but no email timing is selected yet.'
                : 'Customers will receive reminder emails '.$this->offsetSentence($emailOffsets).' before rewards expire.';
        } else {
            $parts[] = 'Reminder emails are off.';
        }

        if ($smsEnabled) {
            $timing = $smsOffsets === []
                ? 'without a text reminder date yet'
                : $this->offsetSentence($smsOffsets).' before rewards expire';

            $parts[] = sprintf(
                'Customers will receive up to %d text reminder%s %s.',
                max(0, $smsMaxPerReward),
                max(0, $smsMaxPerReward) === 1 ? '' : 's',
                $timing
            );
        } else {
            $parts[] = 'Text reminders are off.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<int,int>  $offsets
     */
    protected function offsetSentence(array $offsets): string
    {
        $values = collect($offsets)
            ->map(fn (int $offset): string => $offset.' day'.($offset === 1 ? '' : 's'))
            ->values()
            ->all();

        if ($values === []) {
            return 'without timing';
        }

        if (count($values) === 1) {
            return $values[0];
        }

        if (count($values) === 2) {
            return $values[0].' and '.$values[1];
        }

        $last = array_pop($values);

        return implode(', ', $values).', and '.$last;
    }

    /**
     * @param  array<string,mixed>  $exclusions
     */
    protected function hasMeaningfulExclusions(array $exclusions): bool
    {
        if ((bool) ($exclusions['wholesale'] ?? false)
            || (bool) ($exclusions['sale_items'] ?? false)
            || (bool) ($exclusions['subscriptions'] ?? false)
            || (bool) ($exclusions['bundles'] ?? false)
            || (bool) ($exclusions['limited_releases'] ?? false)) {
            return true;
        }

        return ((array) ($exclusions['collections'] ?? [])) !== []
            || ((array) ($exclusions['products'] ?? [])) !== []
            || ((array) ($exclusions['tags'] ?? [])) !== [];
    }

    /**
     * @return array<string,string>
     */
    protected function checkItem(string $key, string $label, bool $ready, string $nextStep): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $ready ? 'ready' : 'needs_attention',
            'next_step' => $ready ? 'No action needed.' : $nextStep,
        ];
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
}
