<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;

class MessagingCampaignProgressService
{
    /**
     * @return array{campaign_id:int,total:int,status_counts:array<string,int>,status:string}
     */
    public function refreshCampaign(MarketingCampaign|int $campaign): array
    {
        $resolvedCampaign = $campaign instanceof MarketingCampaign
            ? $campaign
            : MarketingCampaign::query()->find((int) $campaign);

        if (! $resolvedCampaign instanceof MarketingCampaign) {
            return [
                'campaign_id' => (int) ($campaign instanceof MarketingCampaign ? $campaign->id : $campaign),
                'total' => 0,
                'status_counts' => [],
                'status' => 'draft',
            ];
        }

        $rawCounts = MarketingCampaignRecipient::query()
            ->where('campaign_id', (int) $resolvedCampaign->id)
            ->selectRaw('status, COUNT(*) as aggregate_count')
            ->groupBy('status')
            ->pluck('aggregate_count', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $statusCounts = [];
        foreach ($rawCounts as $status => $count) {
            $normalized = strtolower(trim((string) $status));
            if ($normalized === '') {
                continue;
            }
            $statusCounts[$normalized] = (int) $count;
        }

        $total = array_sum($statusCounts);
        $resolvedStatus = $this->resolvedCampaignStatus($statusCounts, $total, (string) $resolvedCampaign->status);

        $resolvedCampaign->forceFill([
            'status' => $resolvedStatus,
            'status_counts' => $statusCounts,
            'completed_at' => in_array($resolvedStatus, ['completed', 'partially_failed', 'canceled'], true)
                ? ($resolvedCampaign->completed_at ?: now())
                : null,
        ])->save();

        return [
            'campaign_id' => (int) $resolvedCampaign->id,
            'total' => $total,
            'status_counts' => $statusCounts,
            'status' => $resolvedStatus,
        ];
    }

    public function markTestSent(MarketingCampaign|int $campaign): void
    {
        $resolvedCampaign = $campaign instanceof MarketingCampaign
            ? $campaign
            : MarketingCampaign::query()->find((int) $campaign);

        if (! $resolvedCampaign instanceof MarketingCampaign) {
            return;
        }

        $resolvedCampaign->forceFill([
            'status' => 'test_sent',
            'test_sent_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string,int>  $statusCounts
     */
    protected function resolvedCampaignStatus(array $statusCounts, int $total, string $existingStatus): string
    {
        if ($existingStatus === 'draft' && $total === 0) {
            return 'draft';
        }

        if ($existingStatus === 'test_sent' && $total === 0) {
            return 'test_sent';
        }

        if ($existingStatus === 'canceled' && ($total === 0 || (int) ($statusCounts['canceled'] ?? 0) > 0)) {
            return 'canceled';
        }

        if ($total === 0) {
            return $existingStatus !== '' ? strtolower(trim($existingStatus)) : 'draft';
        }

        $inFlight =
            (int) ($statusCounts['pending'] ?? 0)
            + (int) ($statusCounts['scheduled'] ?? 0)
            + (int) ($statusCounts['approved'] ?? 0)
            + (int) ($statusCounts['queued_for_approval'] ?? 0)
            + (int) ($statusCounts['sending'] ?? 0);

        if ($inFlight > 0) {
            return 'sending';
        }

        $failures =
            (int) ($statusCounts['failed'] ?? 0)
            + (int) ($statusCounts['undelivered'] ?? 0);
        $successes =
            (int) ($statusCounts['sent'] ?? 0)
            + (int) ($statusCounts['delivered'] ?? 0);

        if ($failures > 0) {
            return 'partially_failed';
        }

        if ($successes > 0 && (int) ($statusCounts['delivered'] ?? 0) === 0) {
            return 'sent';
        }

        return 'completed';
    }
}
