<?php

namespace App\Services\Marketing;

use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageOrderAttribution;
use App\Models\Order;
use App\Models\ShopifyStore;
use App\Models\TenantMessagingLedgerEntry;
use Carbon\CarbonImmutable;

class MarketingResultsService
{
    /** @return array<string,mixed> */
    public function report(int $tenantId, ?string $storeKey = null, mixed $dateFrom = null, mixed $dateTo = null): array
    {
        $from = $this->date($dateFrom) ?? CarbonImmutable::now()->subDays(29)->startOfDay();
        $to = ($this->date($dateTo) ?? CarbonImmutable::now())->endOfDay();
        $query = MarketingMessageOrderAttribution::query()
            ->where('tenant_id', $tenantId)
            ->when($storeKey !== null, fn ($builder) => $builder->where('store_key', $storeKey))
            ->whereBetween('order_occurred_at', [$from, $to]);
        $rows = $query->get();

        $deliveries = MarketingEmailDelivery::query()->forTenantId($tenantId)
            ->when($storeKey !== null, fn ($builder) => $builder->where('store_key', $storeKey))
            ->whereBetween('created_at', [$from, $to])->count()
            + MarketingMessageDelivery::query()->forTenantId($tenantId)
                ->when($storeKey !== null, fn ($builder) => $builder->where('store_key', $storeKey))
                ->whereBetween('created_at', [$from, $to])->count();
        $ledger = TenantMessagingLedgerEntry::query()->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->where('entry_type', 'usage_settlement')
            ->whereBetween('occurred_at', [$from, $to])
            ->get();
        $buyerSpendMicros = (int) $ledger->sum('amount_micros');
        $providerCostMicros = (int) $ledger->sum('provider_cost_micros');

        $currencies = $rows->groupBy(fn ($row): string => strtoupper((string) ($row->currency_code ?: 'USD')))
            ->map(function ($currencyRows, string $currency) use ($buyerSpendMicros, $providerCostMicros): array {
                $netCents = (int) $currencyRows->sum('net_revenue_cents');
                $currencySpendMicros = $currency === 'USD' ? $buyerSpendMicros : 0;
                $currencyProviderMicros = $currency === 'USD' ? $providerCostMicros : 0;
                $spendCents = (int) round($currencySpendMicros / 10000);

                return [
                    'currency' => $currency,
                    'cost_currency_compatible' => $currency === 'USD',
                    'attributed_gross_cents' => (int) $currencyRows->sum('gross_revenue_cents'),
                    'refund_cents' => (int) $currencyRows->sum('refund_cents'),
                    'attributed_net_cents' => $netCents,
                    'attributed_orders' => $currencyRows->pluck('order_id')->unique()->count(),
                    'direct_orders' => $currencyRows->where('attribution_type', 'direct')->pluck('order_id')->unique()->count(),
                    'assisted_orders' => $currencyRows->where('attribution_type', 'assisted')->pluck('order_id')->unique()->count(),
                    'buyer_spend_micros' => $currencySpendMicros,
                    'provider_cost_micros' => $currencyProviderMicros,
                    'net_marketing_return_cents' => $netCents - $spendCents,
                    'roas' => $spendCents > 0 ? round($netCents / $spendCents, 2) : null,
                ];
            })->values()->all();

        return [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'store_key' => $storeKey,
            'attribution' => [
                'label' => 'Everbranch-attributed',
                'model' => 'Last eligible touch',
                'window_days' => max(1, (int) config('marketing.message_analytics.attribution_window_days', 7)),
            ],
            'has_sales_source' => $this->hasSalesSource($tenantId),
            'has_attributed_results' => $rows->isNotEmpty(),
            'delivery_count' => $deliveries,
            'attributed_order_count' => $rows->pluck('order_id')->unique()->count(),
            'conversion_rate' => $deliveries > 0 ? round(($rows->pluck('order_id')->unique()->count() / $deliveries) * 100, 2) : null,
            'buyer_spend_micros' => $buyerSpendMicros,
            'provider_cost_micros' => $providerCostMicros,
            'currencies' => $currencies,
            'by_channel' => $this->breakdown($rows, 'channel'),
            'by_campaign' => $this->breakdown($rows, 'source_campaign_label'),
            'by_module' => $this->breakdown($rows, 'source_module_key'),
            'by_store' => $this->breakdown($rows, 'store_key'),
            'by_attribution_type' => $this->breakdown($rows, 'attribution_type'),
        ];
    }

    protected function breakdown($rows, string $field): array
    {
        return $rows->groupBy(fn ($row): string => trim((string) ($row->{$field} ?? '')) ?: 'Unspecified')
            ->flatMap(function ($group, string $label): array {
                return $group->groupBy(fn ($row): string => strtoupper((string) ($row->currency_code ?: 'USD')))
                    ->map(fn ($currencyRows, string $currency): array => [
                        'label' => str($label)->headline()->toString(),
                        'currency' => $currency,
                        'orders' => $currencyRows->pluck('order_id')->unique()->count(),
                        'net_revenue_cents' => (int) $currencyRows->sum('net_revenue_cents'),
                    ])->values()->all();
            })->values()->all();
    }

    protected function hasSalesSource(int $tenantId): bool
    {
        return Order::query()->forTenantId($tenantId)->exists()
            || ShopifyStore::query()->where('tenant_id', $tenantId)->exists();
    }

    protected function date(mixed $value): ?CarbonImmutable
    {
        try {
            return filled($value) ? CarbonImmutable::parse($value)->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
