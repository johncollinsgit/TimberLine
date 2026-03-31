<?php

namespace App\Services\Marketing;

use App\Models\MarketingAutomationEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

class TenantRewardsReminderLogService
{
    public const TRIGGER_KEY = 'tenant_rewards_expiration_reminder';

    /**
     * @param  array<string,mixed>  $entry
     */
    public function record(array $entry): ?MarketingAutomationEvent
    {
        if (! Schema::hasTable('marketing_automation_events')) {
            return null;
        }

        $tenantId = $this->positiveInt($entry['tenant_id'] ?? null);
        $marketingProfileId = $this->positiveInt($entry['marketing_profile_id'] ?? null);
        $channel = $this->nullableString($entry['channel'] ?? null);
        $status = $this->normalizeStatus($entry['status'] ?? null);
        $occurredAt = $this->asDate($entry['occurred_at'] ?? null) ?? now()->toImmutable();
        $processedAt = $this->asDate($entry['processed_at'] ?? null);
        $rewardIdentifier = $this->stringOrDefault($entry['reward_identifier'] ?? null, 'reward');
        $timingDays = max(0, (int) ($entry['timing_days_before_expiration'] ?? 0));
        $policyVersion = max(0, (int) ($entry['policy_version'] ?? 0));
        $reminderKey = $this->stringOrDefault(
            $entry['reminder_key'] ?? null,
            $this->reminderKey($rewardIdentifier, $channel, $timingDays, $policyVersion)
        );

        $context = [
            'reward_identifier' => $rewardIdentifier,
            'reward_code' => $this->nullableString($entry['reward_code'] ?? null),
            'reward_source_key' => $this->nullableString($entry['reward_source_key'] ?? null),
            'reward_source_label' => $this->nullableString($entry['reward_source_label'] ?? null),
            'timing_days_before_expiration' => $timingDays,
            'scheduled_at' => $this->isoString($entry['scheduled_at'] ?? null),
            'attempted_at' => $this->isoString($entry['attempted_at'] ?? null),
            'sent_at' => $this->isoString($entry['sent_at'] ?? null),
            'failed_at' => $this->isoString($entry['failed_at'] ?? null),
            'skipped_at' => $this->isoString($entry['skipped_at'] ?? null),
            'skip_reason' => $this->nullableString($entry['skip_reason'] ?? null),
            'policy_version' => $policyVersion,
            'reminder_key' => $reminderKey,
            'earned_at' => $this->isoString($entry['earned_at'] ?? null),
            'expires_at' => $this->isoString($entry['expires_at'] ?? null),
            'delivery_reference' => $this->nullableString($entry['delivery_reference'] ?? null),
            'notes' => $this->nullableString($entry['notes'] ?? null),
        ];

        $recent = MarketingAutomationEvent::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($marketingProfileId !== null, fn ($query) => $query->where('marketing_profile_id', $marketingProfileId))
            ->where('trigger_key', self::TRIGGER_KEY)
            ->when($channel !== null, fn ($query) => $query->where('channel', $channel))
            ->where('occurred_at', '>=', $occurredAt->subDays(365))
            ->orderByDesc('id')
            ->get();

        $allowDuplicate = (bool) ($entry['allow_duplicate'] ?? false);

        $existing = $allowDuplicate ? null : $recent->first(function (MarketingAutomationEvent $event) use ($reminderKey, $status): bool {
            $context = is_array($event->context) ? $event->context : [];

            return trim((string) ($context['reminder_key'] ?? '')) === $reminderKey
                && trim((string) $event->status) === $status;
        });

        if ($existing) {
            return $existing;
        }

        return MarketingAutomationEvent::query()->create([
            'tenant_id' => $tenantId,
            'marketing_profile_id' => $marketingProfileId,
            'trigger_key' => self::TRIGGER_KEY,
            'channel' => $channel,
            'status' => $status,
            'store_key' => null,
            'reason' => $this->nullableString($entry['reason'] ?? null)
                ?? $this->nullableString($entry['skip_reason'] ?? null),
            'context' => $context,
            'occurred_at' => $occurredAt,
            'processed_at' => $processedAt,
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recentForTenant(int $tenantId, int $limit = 20): array
    {
        if (! Schema::hasTable('marketing_automation_events')) {
            return [];
        }

        $limit = max(1, min(100, $limit));

        return MarketingAutomationEvent::query()
            ->forTenantId($tenantId)
            ->where('trigger_key', self::TRIGGER_KEY)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (MarketingAutomationEvent $event): array => $this->mapEvent($event))
            ->all();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    public function filteredForTenant(int $tenantId, array $filters = [], int $limit = 500): array
    {
        if (! Schema::hasTable('marketing_automation_events')) {
            return [];
        }

        $limit = max(1, min(5000, $limit));
        $channel = $this->nullableString($filters['channel'] ?? null);
        $status = $this->nullableString($filters['status'] ?? null);
        $rewardIdentifier = $this->nullableString($filters['reward_identifier'] ?? null);
        $marketingProfileId = $this->positiveInt($filters['marketing_profile_id'] ?? $filters['profile_id'] ?? null);
        $dateFrom = $this->asDate($filters['date_from'] ?? null)?->startOfDay();
        $dateTo = $this->asDate($filters['date_to'] ?? null)?->endOfDay();

        $rows = MarketingAutomationEvent::query()
            ->forTenantId($tenantId)
            ->where('trigger_key', self::TRIGGER_KEY)
            ->when($marketingProfileId !== null, fn ($query) => $query->where('marketing_profile_id', $marketingProfileId))
            ->when($channel !== null, fn ($query) => $query->where('channel', strtolower($channel)))
            ->when($status !== null, fn ($query) => $query->where('status', strtolower($status)))
            ->when($dateFrom !== null, fn ($query) => $query->where('occurred_at', '>=', $dateFrom))
            ->when($dateTo !== null, fn ($query) => $query->where('occurred_at', '<=', $dateTo))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $rows
            ->map(fn (MarketingAutomationEvent $event): array => $this->mapEvent($event))
            ->filter(function (array $row) use ($rewardIdentifier): bool {
                if ($rewardIdentifier === null) {
                    return true;
                }

                return strtolower(trim((string) ($row['reward_identifier'] ?? ''))) === strtolower($rewardIdentifier);
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    public function historyForCustomer(int $tenantId, int $marketingProfileId, array $filters = [], int $limit = 200): array
    {
        return $this->filteredForTenant($tenantId, [
            ...$filters,
            'marketing_profile_id' => $marketingProfileId,
        ], $limit);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function historyForReward(
        ?int $tenantId,
        ?int $marketingProfileId,
        string $rewardIdentifier,
        int $limit = 50
    ): array {
        if (! Schema::hasTable('marketing_automation_events')) {
            return [];
        }

        $rewardIdentifier = trim($rewardIdentifier);
        if ($rewardIdentifier === '') {
            return [];
        }

        $limit = max(1, min(200, $limit));

        return MarketingAutomationEvent::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($marketingProfileId !== null, fn ($query) => $query->where('marketing_profile_id', $marketingProfileId))
            ->where('trigger_key', self::TRIGGER_KEY)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->filter(function (MarketingAutomationEvent $event) use ($rewardIdentifier): bool {
                $context = is_array($event->context) ? $event->context : [];

                return trim((string) ($context['reward_identifier'] ?? '')) === $rewardIdentifier;
            })
            ->map(fn (MarketingAutomationEvent $event): array => $this->mapEvent($event))
            ->values()
            ->all();
    }

    public function reminderKey(string $rewardIdentifier, ?string $channel, int $timingDays, int $policyVersion = 0): string
    {
        $base = strtolower(trim($rewardIdentifier)).'|'.strtolower(trim((string) $channel)).'|'.$timingDays;

        return $policyVersion > 0 ? $base.'|v'.$policyVersion : $base;
    }

    /**
     * @return array<string,mixed>
     */
    protected function mapEvent(MarketingAutomationEvent $event): array
    {
        $context = is_array($event->context) ? $event->context : [];

        return [
            'id' => (int) $event->id,
            'tenant_id' => $this->positiveInt($event->tenant_id),
            'marketing_profile_id' => $this->positiveInt($event->marketing_profile_id),
            'channel' => $this->nullableString($event->channel),
            'status' => trim((string) $event->status),
            'reward_identifier' => $this->nullableString($context['reward_identifier'] ?? null),
            'reward_code' => $this->nullableString($context['reward_code'] ?? null),
            'reward_source_key' => $this->nullableString($context['reward_source_key'] ?? null),
            'reward_source_label' => $this->nullableString($context['reward_source_label'] ?? null),
            'timing_days_before_expiration' => max(0, (int) ($context['timing_days_before_expiration'] ?? 0)),
            'scheduled_at' => $this->isoString($context['scheduled_at'] ?? null),
            'attempted_at' => $this->isoString($context['attempted_at'] ?? null),
            'sent_at' => $this->isoString($context['sent_at'] ?? null),
            'failed_at' => $this->isoString($context['failed_at'] ?? null),
            'skipped_at' => $this->isoString($context['skipped_at'] ?? null),
            'skip_reason' => $this->nullableString($context['skip_reason'] ?? null),
            'policy_version' => max(0, (int) ($context['policy_version'] ?? 0)),
            'reminder_key' => $this->nullableString($context['reminder_key'] ?? null),
            'earned_at' => $this->isoString($context['earned_at'] ?? null),
            'expires_at' => $this->isoString($context['expires_at'] ?? null),
            'delivery_reference' => $this->nullableString($context['delivery_reference'] ?? null),
            'occurred_at' => optional($event->occurred_at)?->toIso8601String(),
            'processed_at' => optional($event->processed_at)?->toIso8601String(),
            'reason' => $this->nullableString($event->reason),
        ];
    }

    protected function normalizeStatus(mixed $value): string
    {
        $status = strtolower(trim((string) $value));

        return in_array($status, ['scheduled', 'attempted', 'sent', 'skipped', 'failed'], true)
            ? $status
            : 'scheduled';
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

    protected function isoString(mixed $value): ?string
    {
        return $this->asDate($value)?->toIso8601String();
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
