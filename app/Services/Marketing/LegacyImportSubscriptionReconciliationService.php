<?php

namespace App\Services\Marketing;

use App\Models\MarketingConsentEvent;
use App\Models\MarketingProfile;
use Illuminate\Database\Eloquent\Builder;

class LegacyImportSubscriptionReconciliationService
{
    /**
     * @var array<int,string>
     */
    protected const LEGACY_SOURCE_TYPES = [
        'yotpo_contacts_import',
        'square_marketing_import',
        'square_customer_sync',
    ];

    public function __construct(
        protected MarketingConsentService $consentService
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,int>
     */
    public function reconcile(array $options = []): array
    {
        $tenantId = $this->resolveTenantId($options['tenant_id'] ?? null);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = $this->resolveLimit($options['limit'] ?? null);

        $summary = [
            'scanned_profiles' => 0,
            'candidates' => 0,
            'reconciled_profiles' => 0,
            'reconciled_email' => 0,
            'reconciled_sms' => 0,
            'dry_run_candidates' => 0,
            'skipped_no_legacy_signal' => 0,
            'skipped_recent_opt_out' => 0,
            'skipped_no_changes' => 0,
            'reward_paths_suppressed' => 0,
        ];

        $query = MarketingProfile::query()
            ->select([
                'id',
                'tenant_id',
                'accepts_email_marketing',
                'accepts_sms_marketing',
                'email_opted_out_at',
                'sms_opted_out_at',
            ])
            ->when($tenantId !== null, fn (Builder $builder) => $builder->forTenantId($tenantId))
            ->where(function (Builder $builder): void {
                $builder
                    ->where('accepts_email_marketing', false)
                    ->orWhere('accepts_sms_marketing', false);
            })
            ->orderBy('id');

        foreach ($query->lazyById(250) as $profile) {
            if ($limit !== null && $summary['scanned_profiles'] >= $limit) {
                break;
            }

            $summary['scanned_profiles']++;

            $plan = $this->reconciliationPlanForProfile($profile);
            if (! $plan['has_legacy_signal']) {
                $summary['skipped_no_legacy_signal']++;

                continue;
            }

            $blockedChannels = array_values((array) ($plan['blocked_channels'] ?? []));
            if ($blockedChannels !== []) {
                $summary['skipped_recent_opt_out']++;
            }

            $channels = (array) ($plan['channels'] ?? []);
            if ($channels === []) {
                $summary['skipped_no_changes']++;

                continue;
            }

            $summary['candidates']++;

            if ($dryRun) {
                $summary['dry_run_candidates']++;
                $summary['reconciled_email'] += in_array('email', $channels, true) ? 1 : 0;
                $summary['reconciled_sms'] += in_array('sms', $channels, true) ? 1 : 0;
                $summary['reward_paths_suppressed']++;

                continue;
            }

            $sourceRefs = is_array($plan['source_refs'] ?? null) ? $plan['source_refs'] : [];
            $sourceIds = [];
            foreach ($sourceRefs as $channel => $ref) {
                if (! is_array($ref)) {
                    continue;
                }

                $sourceType = trim((string) ($ref['source_type'] ?? ''));
                $sourceId = trim((string) ($ref['source_id'] ?? ''));
                if ($sourceType === '' && $sourceId === '') {
                    continue;
                }

                $sourceIds[] = trim((string) $channel) . ':' . $sourceType . ':' . $sourceId;
            }

            $incoming = [
                'accepts_email_marketing' => in_array('email', $channels, true) ? true : null,
                'accepts_sms_marketing' => in_array('sms', $channels, true) ? true : null,
            ];

            $changed = $this->consentService->applyToProfile($profile, $incoming, [
                'tenant_id' => $this->resolveTenantId($profile->tenant_id),
                'source_type' => 'legacy_import_reconciliation',
                'source_id' => $sourceIds !== [] ? implode('|', $sourceIds) : ('profile:' . (int) $profile->id),
                'legacy_import_reconciliation' => true,
                'suppress_subscription_rewards' => true,
                'do_not_issue_candle_cash' => true,
                'do_not_enqueue_new_subscriber_tasks' => true,
                'details' => [
                    'channels' => $channels,
                    'legacy_sources' => array_values(array_unique(array_filter(array_map(
                        static fn (array $ref): ?string => trim((string) ($ref['source_type'] ?? '')) ?: null,
                        array_values(array_filter($sourceRefs, 'is_array'))
                    )))),
                    'legacy_source_ids' => $sourceIds,
                ],
            ]);

            if (! $changed) {
                $summary['skipped_no_changes']++;

                continue;
            }

            $summary['reconciled_profiles']++;
            $summary['reconciled_email'] += in_array('email', $channels, true) ? 1 : 0;
            $summary['reconciled_sms'] += in_array('sms', $channels, true) ? 1 : 0;
            $summary['reward_paths_suppressed']++;
        }

        return $summary;
    }

    /**
     * @return array{
     *   has_legacy_signal:bool,
     *   channels:array<int,string>,
     *   blocked_channels:array<int,string>,
     *   source_refs:array<string,array<string,mixed>>
     * }
     */
    protected function reconciliationPlanForProfile(MarketingProfile $profile): array
    {
        $hasLegacySignal = false;
        $channels = [];
        $blockedChannels = [];
        $sourceRefs = [];

        foreach (['email', 'sms'] as $channel) {
            $channelPlan = $this->channelReconciliationPlan($profile, $channel);
            $hasLegacySignal = $hasLegacySignal || (bool) $channelPlan['has_legacy_signal'];
            if ((bool) $channelPlan['blocked_by_recent_opt_out']) {
                $blockedChannels[] = $channel;
            }
            if ((bool) $channelPlan['should_enable']) {
                $channels[] = $channel;
                $sourceRefs[$channel] = (array) ($channelPlan['source_ref'] ?? []);
            }
        }

        return [
            'has_legacy_signal' => $hasLegacySignal,
            'channels' => $channels,
            'blocked_channels' => $blockedChannels,
            'source_refs' => $sourceRefs,
        ];
    }

    /**
     * @return array{
     *   has_legacy_signal:bool,
     *   blocked_by_recent_opt_out:bool,
     *   should_enable:bool,
     *   source_ref:array<string,mixed>
     * }
     */
    protected function channelReconciliationPlan(MarketingProfile $profile, string $channel): array
    {
        $alreadyConsented = $channel === 'email'
            ? (bool) $profile->accepts_email_marketing
            : (bool) $profile->accepts_sms_marketing;

        if ($alreadyConsented) {
            return [
                'has_legacy_signal' => false,
                'blocked_by_recent_opt_out' => false,
                'should_enable' => false,
                'source_ref' => [],
            ];
        }

        $legacyImported = MarketingConsentEvent::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('channel', $channel)
            ->where('event_type', 'imported')
            ->whereIn('source_type', self::LEGACY_SOURCE_TYPES)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        if (! $legacyImported) {
            return [
                'has_legacy_signal' => false,
                'blocked_by_recent_opt_out' => false,
                'should_enable' => false,
                'source_ref' => [],
            ];
        }

        $latest = MarketingConsentEvent::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('channel', $channel)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        $blockedByRecentOptOut = $latest !== null
            && in_array((string) $latest->event_type, ['opted_out', 'revoked'], true);

        return [
            'has_legacy_signal' => true,
            'blocked_by_recent_opt_out' => $blockedByRecentOptOut,
            'should_enable' => ! $blockedByRecentOptOut,
            'source_ref' => [
                'event_id' => (int) $legacyImported->id,
                'source_type' => (string) ($legacyImported->source_type ?? ''),
                'source_id' => (string) ($legacyImported->source_id ?? ''),
                'occurred_at' => $legacyImported->occurred_at?->toIso8601String(),
            ],
        ];
    }

    protected function resolveTenantId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $tenantId = (int) $value;

        return $tenantId > 0 ? $tenantId : null;
    }

    protected function resolveLimit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $limit = (int) $value;

        return $limit > 0 ? $limit : null;
    }
}
