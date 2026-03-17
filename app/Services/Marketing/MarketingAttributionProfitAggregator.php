<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaignConversion;
use App\Models\Order;
use Carbon\CarbonInterface;

class MarketingAttributionProfitAggregator
{
    public function __construct(
        protected OrderProfitCalculator $orderProfitCalculator
    ) {
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function aggregate(CarbonInterface $from, CarbonInterface $to, array $options = []): array
    {
        $groupBy = strtolower(trim((string) ($options['group_by'] ?? 'channel')));

        $query = MarketingCampaignConversion::query()
            ->with('campaign:id,channel,name')
            ->whereBetween('converted_at', [$from, $to]);

        if (! empty($options['campaign_channel'])) {
            $campaignChannel = strtolower(trim((string) $options['campaign_channel']));
            $query->whereHas('campaign', fn ($campaignQuery) => $campaignQuery->where('channel', $campaignChannel));
        }

        $conversions = $query->get(['id', 'campaign_id', 'source_type', 'source_id', 'attribution_snapshot', 'converted_at']);

        $orderIds = $conversions
            ->filter(fn (MarketingCampaignConversion $conversion) => $conversion->source_type === 'order' && is_numeric($conversion->source_id))
            ->map(fn (MarketingCampaignConversion $conversion) => (int) $conversion->source_id)
            ->unique()
            ->values();

        $orders = Order::query()
            ->with(['lines.size'])
            ->whereIn('id', $orderIds)
            ->get()
            ->keyBy('id');

        $profitCache = [];
        $groups = [];
        $skipped = [
            'non_order_conversions' => 0,
            'missing_orders' => 0,
        ];

        foreach ($conversions as $conversion) {
            if ($conversion->source_type !== 'order' || ! is_numeric($conversion->source_id)) {
                $skipped['non_order_conversions']++;
                continue;
            }

            $orderId = (int) $conversion->source_id;
            /** @var Order|null $order */
            $order = $orders->get($orderId);
            if (! $order) {
                $skipped['missing_orders']++;
                continue;
            }

            if (! array_key_exists($orderId, $profitCache)) {
                $profitCache[$orderId] = $this->orderProfitCalculator->calculate($order);
            }

            $profit = $profitCache[$orderId];
            $snapshot = is_array($conversion->attribution_snapshot ?? null) ? $conversion->attribution_snapshot : [];
            $group = $this->groupForConversion($conversion, $snapshot, $groupBy);
            $groupKey = $group['key'];

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'key' => $groupKey,
                    'label' => $group['label'],
                    'group_by' => $groupBy,
                    'conversion_count' => 0,
                    'unique_order_ids' => [],
                    'revenue' => 0.0,
                    'product_cost_total' => 0.0,
                    'discount_total' => 0.0,
                    'refund_total' => 0.0,
                    'shipping_revenue' => 0.0,
                    'shipping_cost' => 0.0,
                    'payment_fee' => 0.0,
                    'candle_cash_cost' => 0.0,
                    'net_profit' => 0.0,
                    'confidence_mix' => [
                        'high' => 0,
                        'medium' => 0,
                        'low' => 0,
                    ],
                ];
            }

            $groups[$groupKey]['conversion_count']++;
            $groups[$groupKey]['unique_order_ids'][$orderId] = true;
            $groups[$groupKey]['revenue'] += (float) $profit['revenue'];
            $groups[$groupKey]['product_cost_total'] += (float) $profit['product_cost_total'];
            $groups[$groupKey]['discount_total'] += (float) $profit['discount_total'];
            $groups[$groupKey]['refund_total'] += (float) $profit['refund_total'];
            $groups[$groupKey]['shipping_revenue'] += (float) $profit['shipping_revenue'];
            $groups[$groupKey]['shipping_cost'] += (float) $profit['shipping_cost'];
            $groups[$groupKey]['payment_fee'] += (float) $profit['payment_fee'];
            $groups[$groupKey]['candle_cash_cost'] += (float) $profit['candle_cash_cost'];
            $groups[$groupKey]['net_profit'] += (float) $profit['net_profit'];
            $groups[$groupKey]['confidence_mix'][$profit['confidence_level']]++;
        }

        foreach ($groups as &$group) {
            $group['unique_order_count'] = count($group['unique_order_ids']);
            unset($group['unique_order_ids']);

            foreach ([
                'revenue',
                'product_cost_total',
                'discount_total',
                'refund_total',
                'shipping_revenue',
                'shipping_cost',
                'payment_fee',
                'candle_cash_cost',
                'net_profit',
            ] as $field) {
                $group[$field] = round((float) $group[$field], 2);
            }
        }
        unset($group);

        return [
            'range' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'group_by' => $groupBy,
            'groups' => array_values($groups),
            'totals' => [
                'conversion_count' => array_sum(array_map(fn ($group) => (int) $group['conversion_count'], $groups)),
                'group_count' => count($groups),
            ],
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array{key:string,label:string}
     */
    protected function groupForConversion(MarketingCampaignConversion $conversion, array $snapshot, string $groupBy): array
    {
        if ($groupBy === 'campaign') {
            $key = 'campaign:' . (string) $conversion->campaign_id;
            $label = trim((string) ($conversion->campaign?->name ?? 'Campaign ' . $conversion->campaign_id));

            return ['key' => $key, 'label' => $label];
        }

        $channel = strtolower(trim((string) ($snapshot['channel'] ?? 'unknown'))) ?: 'unknown';

        return [
            'key' => 'channel:' . $channel,
            'label' => $channel,
        ];
    }
}
