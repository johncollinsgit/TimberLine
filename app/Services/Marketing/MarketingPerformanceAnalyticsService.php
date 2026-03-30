<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignConversion;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingVariantPerformanceSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class MarketingPerformanceAnalyticsService
{
    /**
     * @param array<string,mixed> $options
     * @return array{
     *  processed:int,
     *  created:int,
     *  updated:int,
     *  skipped:int,
     *  rows:array<int,array<string,mixed>>,
     *  window_start:?string,
     *  window_end:?string
     * }
     */
    public function snapshotVariantPerformance(array $options = []): array
    {
        $campaignId = isset($options['campaign_id']) ? (int) $options['campaign_id'] : null;
        $tenantId = isset($options['tenant_id']) && is_numeric($options['tenant_id'])
            ? (int) $options['tenant_id']
            : null;
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $windowStart = $this->asDate($options['window_start'] ?? null);
        $windowEnd = $this->asDate($options['window_end'] ?? null);

        if ($windowStart && ! $windowEnd) {
            $windowEnd = now()->toImmutable();
        }
        if ($windowEnd && ! $windowStart) {
            $windowStart = $windowEnd->subDays(90);
        }

        $recipientGroups = MarketingCampaignRecipient::query()
            ->selectRaw('campaign_id, variant_id, channel, count(*) as recipients_count')
            ->when($campaignId !== null && $campaignId > 0, fn ($query) => $query->where('campaign_id', $campaignId))
            ->when($tenantId !== null, fn ($query) => $query->whereHas('profile', fn ($profileQuery) => $profileQuery->forTenantId($tenantId)))
            ->groupBy('campaign_id', 'variant_id', 'channel')
            ->get();

        $summary = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'rows' => [],
            'window_start' => $windowStart?->toIso8601String(),
            'window_end' => $windowEnd?->toIso8601String(),
        ];

        foreach ($recipientGroups as $group) {
            $campaignIdValue = (int) $group->campaign_id;
            $variantIdValue = $group->variant_id !== null ? (int) $group->variant_id : null;
            $channel = strtolower(trim((string) $group->channel));
            $recipientsCount = (int) $group->recipients_count;

            if ($campaignIdValue <= 0 || ! in_array($channel, ['sms', 'email'], true)) {
                $summary['skipped']++;
                continue;
            }

            $metrics = $channel === 'sms'
                ? $this->smsMetrics($campaignIdValue, $variantIdValue, $windowStart, $windowEnd, $tenantId)
                : $this->emailMetrics($campaignIdValue, $variantIdValue, $windowStart, $windowEnd, $tenantId);

            $conversion = $this->conversionMetrics($campaignIdValue, $variantIdValue, $windowStart, $windowEnd, $tenantId);

            $row = [
                'campaign_id' => $campaignIdValue,
                'variant_id' => $variantIdValue,
                'channel' => $channel,
                'window_start' => $windowStart,
                'window_end' => $windowEnd,
                'recipients_count' => $recipientsCount,
                'sent_count' => (int) $metrics['sent_count'],
                'delivered_count' => (int) $metrics['delivered_count'],
                'opened_count' => (int) $metrics['opened_count'],
                'clicked_count' => (int) $metrics['clicked_count'],
                'converted_count' => (int) $conversion['converted_count'],
                'attributed_revenue' => $conversion['attributed_revenue'],
                'snapshot_meta' => [
                    'failed_count' => (int) $metrics['failed_count'],
                    'conversion_rate' => $this->ratio(
                        (int) $conversion['converted_count'],
                        max(1, (int) $metrics['sent_count'])
                    ),
                    'delivered_rate' => $this->ratio(
                        (int) $metrics['delivered_count'],
                        max(1, (int) $metrics['sent_count'])
                    ),
                    'opened_rate' => $this->ratio(
                        (int) $metrics['opened_count'],
                        max(1, (int) $metrics['delivered_count'])
                    ),
                    'clicked_rate' => $this->ratio(
                        (int) $metrics['clicked_count'],
                        max(1, (int) $metrics['opened_count'])
                    ),
                ],
            ];

            $summary['processed']++;
            $summary['rows'][] = $this->exportRow($row);

            if ($dryRun) {
                continue;
            }

            $existing = MarketingVariantPerformanceSnapshot::query()
                ->where('campaign_id', $campaignIdValue)
                ->where('variant_id', $variantIdValue)
                ->where('channel', $channel)
                ->where('window_start', $windowStart)
                ->where('window_end', $windowEnd)
                ->first();

            MarketingVariantPerformanceSnapshot::query()->updateOrCreate(
                [
                    'campaign_id' => $campaignIdValue,
                    'variant_id' => $variantIdValue,
                    'channel' => $channel,
                    'window_start' => $windowStart,
                    'window_end' => $windowEnd,
                ],
                [
                    'recipients_count' => $recipientsCount,
                    'sent_count' => (int) $metrics['sent_count'],
                    'delivered_count' => (int) $metrics['delivered_count'],
                    'opened_count' => (int) $metrics['opened_count'],
                    'clicked_count' => (int) $metrics['clicked_count'],
                    'converted_count' => (int) $conversion['converted_count'],
                    'attributed_revenue' => $conversion['attributed_revenue'],
                    'snapshot_meta' => $row['snapshot_meta'],
                ]
            );

            if ($existing) {
                $summary['updated']++;
            } else {
                $summary['created']++;
            }
        }

        return $summary;
    }

    /**
     * @return array{
     *  recipients:int,
     *  sent:int,
     *  delivered:int,
     *  opened:int,
     *  clicked:int,
     *  converted:int,
     *  revenue:float,
     *  variant_rows:array<int,array<string,mixed>>,
     *  top_variant:?array<string,mixed>
     * }
     */
    public function campaignSummary(MarketingCampaign $campaign, int $windowDays = 120, ?int $tenantId = null): array
    {
        $windowEnd = now()->toImmutable();
        $windowStart = $windowEnd->subDays(max(7, $windowDays));
        $snapshot = $this->snapshotVariantPerformance([
            'campaign_id' => (int) $campaign->id,
            'tenant_id' => $tenantId,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'dry_run' => true,
        ]);

        $rows = collect((array) $snapshot['rows']);

        $variantRows = $rows->map(function (array $row): array {
            $sent = (int) ($row['sent_count'] ?? 0);
            $delivered = (int) ($row['delivered_count'] ?? 0);
            $opened = (int) ($row['opened_count'] ?? 0);
            $clicked = (int) ($row['clicked_count'] ?? 0);
            $converted = (int) ($row['converted_count'] ?? 0);

            return [
                ...$row,
                'conversion_rate' => $this->ratio($converted, max(1, $sent)),
                'delivery_rate' => $this->ratio($delivered, max(1, $sent)),
                'open_rate' => $this->ratio($opened, max(1, $delivered)),
                'click_rate' => $this->ratio($clicked, max(1, $opened)),
            ];
        })->values();

        $topVariant = $variantRows
            ->filter(fn (array $row): bool => (int) ($row['sent_count'] ?? 0) >= 3)
            ->sortByDesc('conversion_rate')
            ->first();

        return [
            'recipients' => (int) $rows->sum('recipients_count'),
            'sent' => (int) $rows->sum('sent_count'),
            'delivered' => (int) $rows->sum('delivered_count'),
            'opened' => (int) $rows->sum('opened_count'),
            'clicked' => (int) $rows->sum('clicked_count'),
            'converted' => (int) $rows->sum('converted_count'),
            'revenue' => (float) $rows->sum('attributed_revenue'),
            'variant_rows' => $variantRows->all(),
            'top_variant' => is_array($topVariant) ? $topVariant : null,
        ];
    }

    /**
     * @return array{sent_count:int,delivered_count:int,opened_count:int,clicked_count:int,failed_count:int}
     */
    protected function smsMetrics(
        int $campaignId,
        ?int $variantId,
        ?CarbonImmutable $windowStart,
        ?CarbonImmutable $windowEnd,
        ?int $tenantId = null
    ): array {
        $query = MarketingMessageDelivery::query()
            ->where('campaign_id', $campaignId)
            ->where('channel', 'sms');

        if ($tenantId !== null) {
            $query->whereHas('profile', fn ($profileQuery) => $profileQuery->forTenantId($tenantId));
        }

        $query = $variantId === null
            ? $query->whereNull('variant_id')
            : $query->where('variant_id', $variantId);

        $query = $this->applyWindow($query, $windowStart, $windowEnd, 'created_at');

        return [
            'sent_count' => (int) (clone $query)->whereIn('send_status', ['sent', 'delivered', 'undelivered'])->count(),
            'delivered_count' => (int) (clone $query)->where('send_status', 'delivered')->count(),
            'opened_count' => 0,
            'clicked_count' => 0,
            'failed_count' => (int) (clone $query)->whereIn('send_status', ['failed', 'undelivered', 'canceled'])->count(),
        ];
    }

    /**
     * @return array{sent_count:int,delivered_count:int,opened_count:int,clicked_count:int,failed_count:int}
     */
    protected function emailMetrics(
        int $campaignId,
        ?int $variantId,
        ?CarbonImmutable $windowStart,
        ?CarbonImmutable $windowEnd,
        ?int $tenantId = null
    ): array {
        $query = MarketingEmailDelivery::query()
            ->whereHas('recipient', function ($recipientQuery) use ($campaignId, $variantId, $tenantId): void {
                $recipientQuery->where('campaign_id', $campaignId);
                if ($variantId === null) {
                    $recipientQuery->whereNull('variant_id');
                } else {
                    $recipientQuery->where('variant_id', $variantId);
                }

                if ($tenantId !== null) {
                    $recipientQuery->whereHas('profile', fn ($profileQuery) => $profileQuery->forTenantId($tenantId));
                }
            });

        $query = $this->applyWindow($query, $windowStart, $windowEnd, 'created_at');

        return [
            'sent_count' => (int) (clone $query)->whereIn('status', ['sent', 'delivered', 'opened', 'clicked'])->count(),
            'delivered_count' => (int) (clone $query)->whereIn('status', ['delivered', 'opened', 'clicked'])->count(),
            'opened_count' => (int) (clone $query)->whereNotNull('opened_at')->count(),
            'clicked_count' => (int) (clone $query)->whereNotNull('clicked_at')->count(),
            'failed_count' => (int) (clone $query)->where('status', 'failed')->count(),
        ];
    }

    /**
     * @return array{converted_count:int,attributed_revenue:float}
     */
    protected function conversionMetrics(
        int $campaignId,
        ?int $variantId,
        ?CarbonImmutable $windowStart,
        ?CarbonImmutable $windowEnd,
        ?int $tenantId = null
    ): array {
        $query = MarketingCampaignConversion::query()
            ->where('campaign_id', $campaignId);

        if ($tenantId !== null) {
            $query->whereHas('profile', fn ($profileQuery) => $profileQuery->forTenantId($tenantId));
        }

        if ($variantId === null) {
            $query->where(function ($nested): void {
                $nested->whereNull('campaign_recipient_id')
                    ->orWhereHas('recipient', fn ($recipientQuery) => $recipientQuery->whereNull('variant_id'));
            });
        } else {
            $query->whereHas('recipient', fn ($recipientQuery) => $recipientQuery->where('variant_id', $variantId));
        }

        $query = $this->applyWindow($query, $windowStart, $windowEnd, 'converted_at');

        return [
            'converted_count' => (int) (clone $query)->count(),
            'attributed_revenue' => round((float) ((clone $query)->sum('order_total') ?: 0), 2),
        ];
    }

    protected function ratio(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round($numerator / $denominator, 4);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|mixed $query
     */
    protected function applyWindow(
        mixed $query,
        ?CarbonImmutable $windowStart,
        ?CarbonImmutable $windowEnd,
        string $column
    ): mixed {
        if (! $windowStart || ! $windowEnd) {
            return $query;
        }

        return $query->whereBetween($column, [$windowStart, $windowEnd]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    protected function exportRow(array $row): array
    {
        return [
            'campaign_id' => (int) $row['campaign_id'],
            'variant_id' => $row['variant_id'] !== null ? (int) $row['variant_id'] : null,
            'channel' => (string) $row['channel'],
            'window_start' => $row['window_start'] instanceof CarbonImmutable ? $row['window_start']->toIso8601String() : null,
            'window_end' => $row['window_end'] instanceof CarbonImmutable ? $row['window_end']->toIso8601String() : null,
            'recipients_count' => (int) $row['recipients_count'],
            'sent_count' => (int) $row['sent_count'],
            'delivered_count' => (int) $row['delivered_count'],
            'opened_count' => (int) $row['opened_count'],
            'clicked_count' => (int) $row['clicked_count'],
            'converted_count' => (int) $row['converted_count'],
            'attributed_revenue' => round((float) ($row['attributed_revenue'] ?? 0), 2),
            'snapshot_meta' => is_array($row['snapshot_meta'] ?? null) ? $row['snapshot_meta'] : [],
        ];
    }

    protected function asDate(mixed $value): ?CarbonImmutable
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
}
