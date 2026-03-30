<?php

namespace App\Console\Commands;

use App\Models\BirthdayRewardIssuance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReferral;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Services\Marketing\MarketingAttributionSourceMetaBuilder;
use Illuminate\Console\Command;

class MarketingBackfillAttributionSourceMeta extends Command
{
    protected $signature = 'marketing:backfill-attribution-source-meta
        {--tenant-id= : Restrict execution to a tenant id (required)}
        {--dry-run : Report what would change without writing updates}
        {--chunk=200 : Number of orders to inspect per batch}';

    protected $description = 'Backfill and propagate attribution source_meta across order-linked marketing records.';

    public function handle(MarketingAttributionSourceMetaBuilder $builder): int
    {
        $tenantId = is_numeric($this->option('tenant-id')) ? (int) $this->option('tenant-id') : null;
        if ($tenantId === null || $tenantId <= 0) {
            $this->error('Missing required --tenant-id. Attribution source-meta backfill is tenant-scoped in MT-2C.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(25, (int) $this->option('chunk'));

        $summary = [
            'examined' => 0,
            'enriched' => 0,
            'skipped' => 0,
            'unable' => 0,
        ];

        Order::query()
            ->forTenantId($tenantId)
            ->orderBy('id')
            ->chunkById($chunk, function ($orders) use (&$summary, $dryRun, $builder, $tenantId): void {
                foreach ($orders as $order) {
                    $summary['examined']++;

                    $result = $this->processOrder($order, $builder, $dryRun, $tenantId);
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
            'tenant_id=%d examined=%d enriched=%d skipped=%d unable=%d dry_run=%s',
            $tenantId,
            $summary['examined'],
            $summary['enriched'],
            $summary['skipped'],
            $summary['unable'],
            $dryRun ? 'yes' : 'no'
        ));

        return self::SUCCESS;
    }

    protected function processOrder(Order $order, MarketingAttributionSourceMetaBuilder $builder, bool $dryRun, int $tenantId): string
    {
        [$links, $referrals, $issuances, $redemptions] = $this->relatedRecords($order, $tenantId);

        if ($links->isEmpty() && $referrals->isEmpty() && $issuances->isEmpty() && $redemptions->isEmpty()) {
            return 'skipped';
        }

        $candidate = is_array($order->attribution_meta ?? null) ? $order->attribution_meta : [];

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

        $changed = false;

        foreach ($links as $link) {
            $merged = $builder->mergeSourceMeta(is_array($link->source_meta ?? null) ? $link->source_meta : [], $candidate);
            if ($merged !== (is_array($link->source_meta ?? null) ? $link->source_meta : [])) {
                $changed = true;
                if (! $dryRun) {
                    $link->forceFill(['source_meta' => $merged])->save();
                }
            }
        }

        foreach ($referrals as $referral) {
            $merged = $builder->mergeSourceMeta(is_array($referral->metadata ?? null) ? $referral->metadata : [], $candidate);
            if ($merged !== (is_array($referral->metadata ?? null) ? $referral->metadata : [])) {
                $changed = true;
                if (! $dryRun) {
                    $referral->forceFill(['metadata' => $merged])->save();
                }
            }
        }

        foreach ($issuances as $issuance) {
            $merged = $builder->mergeSourceMeta(is_array($issuance->metadata ?? null) ? $issuance->metadata : [], $candidate);
            if ($merged !== (is_array($issuance->metadata ?? null) ? $issuance->metadata : [])) {
                $changed = true;
                if (! $dryRun) {
                    $issuance->forceFill(['metadata' => $merged])->save();
                }
            }
        }

        foreach ($redemptions as $redemption) {
            $existing = is_array($redemption->redemption_context['attribution_meta'] ?? null)
                ? $redemption->redemption_context['attribution_meta']
                : [];
            $merged = $builder->mergeSourceMeta($existing, $candidate);
            if ($merged !== $existing) {
                $changed = true;
                if (! $dryRun) {
                    $context = is_array($redemption->redemption_context ?? null) ? $redemption->redemption_context : [];
                    $context['attribution_meta'] = $merged;
                    $redemption->forceFill(['redemption_context' => $context])->save();
                }
            }
        }

        return $changed ? 'enriched' : 'skipped';
    }

    /**
     * @return array{0:\Illuminate\Support\Collection<int,MarketingProfileLink>,1:\Illuminate\Support\Collection<int,CandleCashReferral>,2:\Illuminate\Support\Collection<int,BirthdayRewardIssuance>,3:\Illuminate\Support\Collection<int,CandleCashRedemption>}
     */
    protected function relatedRecords(Order $order, int $tenantId): array
    {
        $shopifySourceId = $order->shopify_order_id
            ? (string) ($order->shopify_store_key ?: $order->shopify_store ?: 'unknown') . ':' . $order->shopify_order_id
            : null;
        $shopifyCustomerSourceId = $order->shopify_customer_id
            ? (string) ($order->shopify_store_key ?: $order->shopify_store ?: 'unknown') . ':' . $order->shopify_customer_id
            : null;

        $links = MarketingProfileLink::query()
            ->forTenantId($tenantId)
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
            ->whereHas('referrer', fn ($query) => $query->where('tenant_id', $tenantId))
            ->get();

        $issuances = BirthdayRewardIssuance::query()
            ->where('order_id', $order->id)
            ->whereHas('marketingProfile', fn ($query) => $query->where('tenant_id', $tenantId))
            ->get();

        $redemptions = CandleCashRedemption::query()
            ->where('external_order_source', 'order')
            ->where('external_order_id', (string) $order->id)
            ->whereHas('profile', fn ($query) => $query->where('tenant_id', $tenantId))
            ->get();

        return [$links, $referrals, $issuances, $redemptions];
    }
}
