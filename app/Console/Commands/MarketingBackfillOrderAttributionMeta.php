<?php

namespace App\Console\Commands;

use App\Models\BirthdayRewardIssuance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReferral;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Services\Marketing\MarketingAttributionSourceMetaBuilder;
use Illuminate\Console\Command;

class MarketingBackfillOrderAttributionMeta extends Command
{
    protected $signature = 'marketing:backfill-order-attribution-meta
        {--dry-run : Report what would change without writing updates}
        {--chunk=200 : Number of orders to inspect per batch}';

    protected $description = 'Backfill durable attribution_meta on orders from linked attribution records.';

    public function handle(MarketingAttributionSourceMetaBuilder $builder): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(25, (int) $this->option('chunk'));

        $summary = [
            'examined' => 0,
            'enriched' => 0,
            'skipped' => 0,
            'unable' => 0,
        ];

        Order::query()
            ->orderBy('id')
            ->chunkById($chunk, function ($orders) use (&$summary, $dryRun, $builder): void {
                foreach ($orders as $order) {
                    $summary['examined']++;

                    $result = $this->processOrder($order, $builder, $dryRun);
                    if ($result === 'enriched') {
                        $summary['enriched']++;
                    } elseif ($result === 'unable') {
                        $summary['unable']++;
                    } else {
                        $summary['skipped']++;
                    }
                }
            });

        $this->info(sprintf(
            'examined=%d enriched=%d skipped=%d unable=%d dry_run=%s',
            $summary['examined'],
            $summary['enriched'],
            $summary['skipped'],
            $summary['unable'],
            $dryRun ? 'yes' : 'no'
        ));

        return self::SUCCESS;
    }

    protected function processOrder(Order $order, MarketingAttributionSourceMetaBuilder $builder, bool $dryRun): string
    {
        [$links, $referrals, $issuances, $redemptions] = $this->relatedRecords($order);

        $existing = is_array($order->attribution_meta ?? null) ? $order->attribution_meta : [];
        $candidate = $existing;

        foreach ($links as $link) {
            $candidate = $builder->mergeSourceMeta($candidate, is_array($link->source_meta ?? null) ? $link->source_meta : []);
        }

        foreach ($referrals as $referral) {
            $candidate = $builder->mergeSourceMeta($candidate, is_array($referral->metadata ?? null) ? $referral->metadata : []);
        }

        foreach ($issuances as $issuance) {
            $candidate = $builder->mergeSourceMeta($candidate, is_array($issuance->metadata ?? null) ? $issuance->metadata : []);
        }

        foreach ($redemptions as $redemption) {
            $candidate = $builder->mergeSourceMeta(
                $candidate,
                is_array($redemption->redemption_context['attribution_meta'] ?? null)
                    ? $redemption->redemption_context['attribution_meta']
                    : []
            );
        }

        if ($candidate === []) {
            return 'unable';
        }

        if ($candidate === $existing) {
            return 'skipped';
        }

        if (! $dryRun) {
            $order->forceFill(['attribution_meta' => $candidate])->save();
        }

        return 'enriched';
    }

    /**
     * @return array{0:\Illuminate\Support\Collection<int,MarketingProfileLink>,1:\Illuminate\Support\Collection<int,CandleCashReferral>,2:\Illuminate\Support\Collection<int,BirthdayRewardIssuance>,3:\Illuminate\Support\Collection<int,CandleCashRedemption>}
     */
    protected function relatedRecords(Order $order): array
    {
        $shopifySourceId = $order->shopify_order_id
            ? (string) ($order->shopify_store_key ?: $order->shopify_store ?: 'unknown') . ':' . $order->shopify_order_id
            : null;
        $shopifyCustomerSourceId = $order->shopify_customer_id
            ? (string) ($order->shopify_store_key ?: $order->shopify_store ?: 'unknown') . ':' . $order->shopify_customer_id
            : null;

        $links = MarketingProfileLink::query()
            ->where(function ($query) use ($order, $shopifySourceId, $shopifyCustomerSourceId): void {
                $query->where(function ($nested) use ($order): void {
                    $nested->where('source_type', 'order')->where('source_id', (string) $order->id);
                });

                if ($shopifySourceId) {
                    $query->orWhere(function ($nested) use ($shopifySourceId): void {
                        $nested->where('source_type', 'shopify_order')->where('source_id', $shopifySourceId);
                    });
                }

                if ($shopifyCustomerSourceId) {
                    $query->orWhere(function ($nested) use ($shopifyCustomerSourceId): void {
                        $nested->where('source_type', 'shopify_customer')->where('source_id', $shopifyCustomerSourceId);
                    });
                }
            })
            ->get();

        $referrals = CandleCashReferral::query()
            ->where('qualifying_order_id', (string) $order->id)
            ->get();

        $issuances = BirthdayRewardIssuance::query()
            ->where('order_id', $order->id)
            ->get();

        $redemptions = CandleCashRedemption::query()
            ->where('external_order_source', 'order')
            ->where('external_order_id', (string) $order->id)
            ->get();

        return [$links, $referrals, $issuances, $redemptions];
    }
}
