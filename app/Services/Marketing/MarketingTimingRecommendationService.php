<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingTimingInsight;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MarketingTimingRecommendationService
{
    /**
     * @param array<string,mixed> $options
     * @return array{
     *  processed:int,
     *  created:int,
     *  updated:int,
     *  skipped:int,
     *  insights:array<int,array<string,mixed>>
     * }
     */
    public function generateInsights(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $campaignId = isset($options['campaign_id']) ? (int) $options['campaign_id'] : null;

        $campaigns = MarketingCampaign::query()
            ->with('segment:id,slug,name')
            ->when($campaignId !== null && $campaignId > 0, fn ($query) => $query->where('id', $campaignId))
            ->whereIn('channel', ['sms', 'email'])
            ->orderBy('id')
            ->get();

        $summary = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'insights' => [],
        ];

        foreach ($campaigns as $campaign) {
            $signal = $campaign->channel === 'sms'
                ? $this->smsSignalForCampaign($campaign)
                : $this->emailSignalForCampaign($campaign);

            if (! $signal['ok']) {
                $summary['skipped']++;
                continue;
            }

            $segmentKey = trim((string) ($campaign->segment?->slug ?: ''));
            if ($segmentKey === '') {
                $segmentKey = $campaign->segment?->name ? Str::slug((string) $campaign->segment->name) : null;
            }

            $payload = [
                'channel' => (string) $campaign->channel,
                'objective' => $campaign->objective ? (string) $campaign->objective : null,
                'segment_key' => $segmentKey,
                'event_context' => null,
                'recommended_hour' => (int) $signal['hour'],
                'recommended_daypart' => (string) $this->daypart((int) $signal['hour']),
                'confidence' => (float) $signal['confidence'],
                'reasons_json' => [
                    'campaign_id' => (int) $campaign->id,
                    'campaign_name' => (string) $campaign->name,
                    'sample_size' => (int) $signal['sample_size'],
                    'success_metric' => (string) $signal['metric_name'],
                    'success_rate' => (float) $signal['success_rate'],
                    'hour_breakdown' => $signal['hour_breakdown'],
                ],
            ];

            $summary['processed']++;
            $summary['insights'][] = $payload;

            if ($dryRun) {
                continue;
            }

            $existing = MarketingTimingInsight::query()
                ->where('channel', $payload['channel'])
                ->where('objective', $payload['objective'])
                ->where('segment_key', $payload['segment_key'])
                ->where('event_context', $payload['event_context'])
                ->first();

            MarketingTimingInsight::query()->updateOrCreate(
                [
                    'channel' => $payload['channel'],
                    'objective' => $payload['objective'],
                    'segment_key' => $payload['segment_key'],
                    'event_context' => $payload['event_context'],
                ],
                [
                    'recommended_hour' => $payload['recommended_hour'],
                    'recommended_daypart' => $payload['recommended_daypart'],
                    'confidence' => $payload['confidence'],
                    'reasons_json' => $payload['reasons_json'],
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

    public function bestInsightForCampaign(MarketingCampaign $campaign): ?MarketingTimingInsight
    {
        $segmentKey = trim((string) ($campaign->segment?->slug ?? ''));
        if ($segmentKey === '' && $campaign->segment?->name) {
            $segmentKey = Str::slug((string) $campaign->segment->name);
        }

        $query = MarketingTimingInsight::query()
            ->where('channel', $campaign->channel)
            ->orderByDesc('confidence')
            ->orderByDesc('updated_at');

        if ($campaign->objective) {
            $query->where(function ($nested) use ($campaign): void {
                $nested->where('objective', $campaign->objective)
                    ->orWhereNull('objective');
            });
        }

        if ($segmentKey !== '') {
            $query->where(function ($nested) use ($segmentKey): void {
                $nested->where('segment_key', $segmentKey)
                    ->orWhereNull('segment_key');
            });
        }

        return $query->first();
    }

    /**
     * @return array{ok:bool,hour:int,sample_size:int,confidence:float,success_rate:float,metric_name:string,hour_breakdown:array<int,array<string,mixed>>}
     */
    protected function smsSignalForCampaign(MarketingCampaign $campaign): array
    {
        $recipients = MarketingCampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('channel', 'sms')
            ->whereNotNull('sent_at')
            ->whereIn('status', ['sent', 'delivered', 'undelivered', 'converted'])
            ->get(['id', 'status', 'sent_at']);

        if ($recipients->count() < 5) {
            return [
                'ok' => false,
                'hour' => 13,
                'sample_size' => $recipients->count(),
                'confidence' => 0.0,
                'success_rate' => 0.0,
                'metric_name' => 'conversion_rate',
                'hour_breakdown' => [],
            ];
        }

        $hourBuckets = [];
        foreach ($recipients as $recipient) {
            $hour = (int) optional($recipient->sent_at)->format('G');
            if (! isset($hourBuckets[$hour])) {
                $hourBuckets[$hour] = ['sent' => 0, 'converted' => 0];
            }
            $hourBuckets[$hour]['sent']++;
            if ((string) $recipient->status === 'converted') {
                $hourBuckets[$hour]['converted']++;
            }
        }

        $best = ['hour' => 13, 'rate' => 0.0, 'sent' => 0];
        foreach ($hourBuckets as $hour => $bucket) {
            $sent = (int) ($bucket['sent'] ?? 0);
            if ($sent < 2) {
                continue;
            }
            $rate = $sent > 0 ? ((int) ($bucket['converted'] ?? 0) / $sent) : 0.0;
            if ($rate > $best['rate']) {
                $best = ['hour' => (int) $hour, 'rate' => $rate, 'sent' => $sent];
            }
        }

        if ($best['sent'] < 2) {
            return [
                'ok' => false,
                'hour' => 13,
                'sample_size' => $recipients->count(),
                'confidence' => 0.0,
                'success_rate' => 0.0,
                'metric_name' => 'conversion_rate',
                'hour_breakdown' => [],
            ];
        }

        return [
            'ok' => true,
            'hour' => (int) $best['hour'],
            'sample_size' => $recipients->count(),
            'confidence' => $this->confidence((int) $recipients->count(), (float) $best['rate']),
            'success_rate' => round((float) $best['rate'], 4),
            'metric_name' => 'conversion_rate',
            'hour_breakdown' => $this->hourBreakdown($hourBuckets),
        ];
    }

    /**
     * @return array{ok:bool,hour:int,sample_size:int,confidence:float,success_rate:float,metric_name:string,hour_breakdown:array<int,array<string,mixed>>}
     */
    protected function emailSignalForCampaign(MarketingCampaign $campaign): array
    {
        $deliveries = MarketingEmailDelivery::query()
            ->whereHas('recipient', fn ($query) => $query->where('campaign_id', $campaign->id))
            ->whereNotNull('sent_at')
            ->get(['id', 'sent_at', 'opened_at', 'clicked_at']);

        if ($deliveries->count() < 5) {
            return [
                'ok' => false,
                'hour' => 14,
                'sample_size' => $deliveries->count(),
                'confidence' => 0.0,
                'success_rate' => 0.0,
                'metric_name' => 'open_rate',
                'hour_breakdown' => [],
            ];
        }

        $hourBuckets = [];
        foreach ($deliveries as $delivery) {
            $hour = (int) optional($delivery->sent_at)->format('G');
            if (! isset($hourBuckets[$hour])) {
                $hourBuckets[$hour] = ['sent' => 0, 'opened' => 0, 'clicked' => 0];
            }
            $hourBuckets[$hour]['sent']++;
            if ($delivery->opened_at) {
                $hourBuckets[$hour]['opened']++;
            }
            if ($delivery->clicked_at) {
                $hourBuckets[$hour]['clicked']++;
            }
        }

        $best = ['hour' => 14, 'rate' => 0.0, 'sent' => 0];
        foreach ($hourBuckets as $hour => $bucket) {
            $sent = (int) ($bucket['sent'] ?? 0);
            if ($sent < 2) {
                continue;
            }
            $clickRate = $sent > 0 ? ((int) ($bucket['clicked'] ?? 0) / $sent) : 0.0;
            $openRate = $sent > 0 ? ((int) ($bucket['opened'] ?? 0) / $sent) : 0.0;
            $score = ($clickRate * 0.7) + ($openRate * 0.3);
            if ($score > $best['rate']) {
                $best = ['hour' => (int) $hour, 'rate' => $score, 'sent' => $sent];
            }
        }

        if ($best['sent'] < 2) {
            return [
                'ok' => false,
                'hour' => 14,
                'sample_size' => $deliveries->count(),
                'confidence' => 0.0,
                'success_rate' => 0.0,
                'metric_name' => 'open_click_score',
                'hour_breakdown' => [],
            ];
        }

        return [
            'ok' => true,
            'hour' => (int) $best['hour'],
            'sample_size' => $deliveries->count(),
            'confidence' => $this->confidence((int) $deliveries->count(), (float) $best['rate']),
            'success_rate' => round((float) $best['rate'], 4),
            'metric_name' => 'open_click_score',
            'hour_breakdown' => $this->hourBreakdown($hourBuckets),
        ];
    }

    protected function daypart(int $hour): string
    {
        return match (true) {
            $hour < 6 => 'overnight',
            $hour < 12 => 'morning',
            $hour < 17 => 'afternoon',
            $hour < 21 => 'evening',
            default => 'night',
        };
    }

    protected function confidence(int $sampleSize, float $rate): float
    {
        $sampleComponent = min(0.6, $sampleSize / 250);
        $rateComponent = min(0.3, max(0.0, $rate));

        return round(min(0.95, 0.1 + $sampleComponent + $rateComponent), 2);
    }

    /**
     * @param array<int,array<string,int>> $hourBuckets
     * @return array<int,array<string,mixed>>
     */
    protected function hourBreakdown(array $hourBuckets): array
    {
        return collect($hourBuckets)
            ->map(function (array $bucket, int|string $hour): array {
                $sent = (int) ($bucket['sent'] ?? 0);

                return [
                    'hour' => (int) $hour,
                    'sent' => $sent,
                    'converted' => (int) ($bucket['converted'] ?? 0),
                    'opened' => (int) ($bucket['opened'] ?? 0),
                    'clicked' => (int) ($bucket['clicked'] ?? 0),
                ];
            })
            ->sortBy('hour')
            ->values()
            ->all();
    }
}

