<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketingSourceOverlapReportService
{
    /**
     * @return array<int,array{value:string,label:string}>
     */
    public function filterOptions(): array
    {
        return [
            ['value' => 'all', 'label' => 'All Profiles'],
            ['value' => 'square_only_missing_contact', 'label' => 'Square-only + Missing Contact'],
            ['value' => 'shopify_without_growave', 'label' => 'Shopify Without Growave'],
            ['value' => 'growave_without_square', 'label' => 'Growave Without Square'],
            ['value' => 'all_three', 'label' => 'All 3 Sources'],
            ['value' => 'bucket:shopify_only', 'label' => 'Shopify Only'],
            ['value' => 'bucket:square_only', 'label' => 'Square Only'],
            ['value' => 'bucket:growave_only', 'label' => 'Growave Only'],
            ['value' => 'bucket:shopify_square', 'label' => 'Shopify + Square'],
            ['value' => 'bucket:shopify_growave', 'label' => 'Shopify + Growave'],
            ['value' => 'bucket:square_growave', 'label' => 'Square + Growave'],
            ['value' => 'bucket:shopify_square_growave', 'label' => 'Shopify + Square + Growave'],
            ['value' => 'bucket:unlinked_or_other', 'label' => 'Unlinked / Other'],
        ];
    }

    public function normalizeFilter(string $filter): string
    {
        $allowedFilters = collect($this->filterOptions())->pluck('value')->all();

        return in_array($filter, $allowedFilters, true) ? $filter : 'all';
    }

    /**
     * @return array<string,array{label:string,description:string,shopify:bool,square:bool,growave:bool}>
     */
    public function bucketDefinitions(): array
    {
        return [
            'shopify_only' => [
                'label' => 'Shopify Only',
                'description' => 'Online/customer records without Square or Growave linkage.',
                'shopify' => true,
                'square' => false,
                'growave' => false,
            ],
            'square_only' => [
                'label' => 'Square Only',
                'description' => 'POS/event buyers that only exist in Square-linked identity.',
                'shopify' => false,
                'square' => true,
                'growave' => false,
            ],
            'growave_only' => [
                'label' => 'Growave Only',
                'description' => 'Loyalty/review records with no Shopify or Square link.',
                'shopify' => false,
                'square' => false,
                'growave' => true,
            ],
            'shopify_square' => [
                'label' => 'Shopify + Square',
                'description' => 'Cross-channel ecommerce and POS customers without Growave.',
                'shopify' => true,
                'square' => true,
                'growave' => false,
            ],
            'shopify_growave' => [
                'label' => 'Shopify + Growave',
                'description' => 'Online customers linked into loyalty/review history but not Square.',
                'shopify' => true,
                'square' => false,
                'growave' => true,
            ],
            'square_growave' => [
                'label' => 'Square + Growave',
                'description' => 'POS + loyalty overlap without Shopify order/customer linkage.',
                'shopify' => false,
                'square' => true,
                'growave' => true,
            ],
            'shopify_square_growave' => [
                'label' => 'Shopify + Square + Growave',
                'description' => 'True multi-channel core customers touching all three source systems.',
                'shopify' => true,
                'square' => true,
                'growave' => true,
            ],
            'unlinked_or_other' => [
                'label' => 'Unlinked / Other',
                'description' => 'Canonical profiles with none of the three source links.',
                'shopify' => false,
                'square' => false,
                'growave' => false,
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function summary(): array
    {
        $definitions = $this->bucketDefinitions();
        $totalProfiles = MarketingProfile::query()->count();
        $bucketCase = $this->bucketCaseExpression('source_link_flags');
        $squareCustomerMetrics = $this->squareCustomerMetricsSubquery();
        $shopifyOrderMetrics = $this->shopifyOrderMetricsSubquery();
        $reviewMetrics = $this->profileReviewMetricsSubquery();
        $candleCashBalanceMetrics = $this->profileCandleCashBalanceSubquery();

        $trackedSpendExpression = '(coalesce(square_customer_metrics.square_order_spend_cents, 0) + coalesce(square_customer_metrics.square_payment_spend_cents, 0)';
        if ($shopifyOrderMetrics !== null) {
            $trackedSpendExpression .= ' + coalesce(shopify_order_metrics.shopify_order_spend_cents, 0)';
        }
        $trackedSpendExpression .= ')';

        $query = MarketingProfile::query()
            ->toBase()
            ->leftJoinSub($this->sourceLinkFlagsSubquery(), 'source_link_flags', function ($join): void {
                $join->on('source_link_flags.marketing_profile_id', '=', 'marketing_profiles.id');
            })
            ->leftJoinSub($squareCustomerMetrics, 'square_customer_metrics', function ($join): void {
                $join->on('square_customer_metrics.marketing_profile_id', '=', 'marketing_profiles.id');
            });

        if ($shopifyOrderMetrics !== null) {
            $query->leftJoinSub($shopifyOrderMetrics, 'shopify_order_metrics', function ($join): void {
                $join->on('shopify_order_metrics.marketing_profile_id', '=', 'marketing_profiles.id');
            });
        }

        if ($reviewMetrics !== null) {
            $query->leftJoinSub($reviewMetrics, 'review_summary_metrics', function ($join): void {
                $join->on('review_summary_metrics.marketing_profile_id', '=', 'marketing_profiles.id');
            });
        }

        if ($candleCashBalanceMetrics !== null) {
            $query->leftJoinSub($candleCashBalanceMetrics, 'candle_cash_balance_metrics', function ($join): void {
                $join->on('candle_cash_balance_metrics.marketing_profile_id', '=', 'marketing_profiles.id');
            });
        }

        $rows = $query
            ->selectRaw($bucketCase . ' as overlap_bucket')
            ->selectRaw('count(*) as profile_count')
            ->selectRaw("sum(case when marketing_profiles.email is null or marketing_profiles.email = '' then 1 else 0 end) as missing_email_count")
            ->selectRaw("sum(case when marketing_profiles.phone is null or marketing_profiles.phone = '' then 1 else 0 end) as missing_phone_count")
            ->selectRaw("sum(case when (marketing_profiles.email is null or marketing_profiles.email = '') and (marketing_profiles.phone is null or marketing_profiles.phone = '') then 1 else 0 end) as missing_both_count")
            ->selectRaw('sum(' . $trackedSpendExpression . ') as total_tracked_spend_cents')
            ->selectRaw(($candleCashBalanceMetrics !== null
                ? 'sum(coalesce(candle_cash_balance_metrics.balance, 0))'
                : '0') . ' as total_candle_cash_balance')
            ->selectRaw(($reviewMetrics !== null
                ? 'sum(coalesce(review_summary_metrics.has_review_summary, 0))'
                : '0') . ' as review_summary_profile_count')
            ->selectRaw(($reviewMetrics !== null
                ? 'sum(coalesce(review_summary_metrics.review_count, 0))'
                : '0') . ' as total_review_count')
            ->groupBy(DB::raw($bucketCase))
            ->get()
            ->keyBy('overlap_bucket');

        $summary = [];

        foreach ($definitions as $key => $definition) {
            /** @var object|null $row */
            $row = $rows->get($key);
            $profileCount = (int) ($row->profile_count ?? 0);

            $summary[$key] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'profile_count' => $profileCount,
                'percent_of_total' => $totalProfiles > 0 ? round(($profileCount / $totalProfiles) * 100, 1) : 0.0,
                'missing_email_count' => (int) ($row->missing_email_count ?? 0),
                'missing_phone_count' => (int) ($row->missing_phone_count ?? 0),
                'missing_both_count' => (int) ($row->missing_both_count ?? 0),
                'total_tracked_spend_cents' => (int) ($row->total_tracked_spend_cents ?? 0),
                'total_candle_cash_balance' => (int) ($row->total_candle_cash_balance ?? 0),
                'review_summary_profile_count' => (int) ($row->review_summary_profile_count ?? 0),
                'total_review_count' => (int) ($row->total_review_count ?? 0),
            ];
        }

        return $summary;
    }

    public function profilesQuery(string $filter, string $search): QueryBuilder
    {
        $bucketCase = $this->bucketCaseExpression('source_link_flags');
        $squareCustomerMetrics = $this->squareCustomerMetricsSubquery();
        $shopifyOrderMetrics = $this->shopifyOrderMetricsSubquery();
        $reviewMetrics = $this->profileReviewMetricsSubquery();
        $candleCashBalanceMetrics = $this->profileCandleCashBalanceSubquery();

        $trackedSpendExpression = '(coalesce(square_customer_metrics.square_order_spend_cents, 0) + coalesce(square_customer_metrics.square_payment_spend_cents, 0)';
        if ($shopifyOrderMetrics !== null) {
            $trackedSpendExpression .= ' + coalesce(shopify_order_metrics.shopify_order_spend_cents, 0)';
        }
        $trackedSpendExpression .= ')';

        $query = MarketingProfile::query()
            ->toBase()
            ->leftJoinSub($this->sourceLinkFlagsSubquery(), 'source_link_flags', function ($join): void {
                $join->on('source_link_flags.marketing_profile_id', '=', 'marketing_profiles.id');
            })
            ->leftJoinSub($squareCustomerMetrics, 'square_customer_metrics', function ($join): void {
                $join->on('square_customer_metrics.marketing_profile_id', '=', 'marketing_profiles.id');
            });

        if ($shopifyOrderMetrics !== null) {
            $query->leftJoinSub($shopifyOrderMetrics, 'shopify_order_metrics', function ($join): void {
                $join->on('shopify_order_metrics.marketing_profile_id', '=', 'marketing_profiles.id');
            });
        }

        if ($reviewMetrics !== null) {
            $query->leftJoinSub($reviewMetrics, 'review_summary_metrics', function ($join): void {
                $join->on('review_summary_metrics.marketing_profile_id', '=', 'marketing_profiles.id');
            });
        }

        if ($candleCashBalanceMetrics !== null) {
            $query->leftJoinSub($candleCashBalanceMetrics, 'candle_cash_balance_metrics', function ($join): void {
                $join->on('candle_cash_balance_metrics.marketing_profile_id', '=', 'marketing_profiles.id');
            });
        }

        $query->select([
            'marketing_profiles.id',
            'marketing_profiles.first_name',
            'marketing_profiles.last_name',
            'marketing_profiles.email',
            'marketing_profiles.phone',
            'marketing_profiles.source_channels',
            'marketing_profiles.updated_at',
            DB::raw('coalesce(source_link_flags.has_shopify_link, 0) as has_shopify_link'),
            DB::raw('coalesce(source_link_flags.has_square_link, 0) as has_square_link'),
            DB::raw('coalesce(source_link_flags.has_growave_link, 0) as has_growave_link'),
            DB::raw('coalesce(source_link_flags.has_square_customer_link, 0) as has_square_customer_link'),
            DB::raw('coalesce(source_link_flags.has_square_order_link, 0) as has_square_order_link'),
            DB::raw('coalesce(source_link_flags.has_square_payment_link, 0) as has_square_payment_link'),
            DB::raw('coalesce(square_customer_metrics.square_customer_link_count, 0) as square_customer_link_count'),
            DB::raw('coalesce(square_customer_metrics.square_order_count, 0) as square_order_count'),
            DB::raw('coalesce(square_customer_metrics.square_payment_count, 0) as square_payment_count'),
            DB::raw(($shopifyOrderMetrics !== null
                ? 'coalesce(shopify_order_metrics.shopify_order_link_count, 0)'
                : '0') . ' as shopify_order_link_count'),
            DB::raw($trackedSpendExpression . ' as tracked_spend_cents'),
            DB::raw(($candleCashBalanceMetrics !== null
                ? 'coalesce(candle_cash_balance_metrics.balance, 0)'
                : '0') . ' as candle_cash_balance'),
            DB::raw(($reviewMetrics !== null
                ? 'coalesce(review_summary_metrics.has_review_summary, 0)'
                : '0') . ' as has_review_summary'),
            DB::raw(($reviewMetrics !== null
                ? 'coalesce(review_summary_metrics.review_count, 0)'
                : '0') . ' as review_count'),
            DB::raw($bucketCase . ' as overlap_bucket'),
        ]);

        $this->applyFilter($query, $filter);

        if ($search !== '') {
            $query->where(function ($nested) use ($search): void {
                $nested->where('marketing_profiles.first_name', 'like', '%' . $search . '%')
                    ->orWhere('marketing_profiles.last_name', 'like', '%' . $search . '%')
                    ->orWhere('marketing_profiles.email', 'like', '%' . $search . '%')
                    ->orWhere('marketing_profiles.phone', 'like', '%' . $search . '%');
            });
        }

        return $query
            ->orderByRaw($trackedSpendExpression . ' desc')
            ->orderByDesc('marketing_profiles.updated_at');
    }

    protected function applyFilter(QueryBuilder $query, string $filter): void
    {
        $filter = $this->normalizeFilter($filter);

        if ($filter === 'all') {
            return;
        }

        if ($filter === 'square_only_missing_contact') {
            $this->applyBucketFilter($query, 'square_only');
            $this->applyMissingContactFilter($query);

            return;
        }

        if ($filter === 'shopify_without_growave') {
            $query->whereRaw('coalesce(source_link_flags.has_shopify_link, 0) = 1')
                ->whereRaw('coalesce(source_link_flags.has_growave_link, 0) = 0');

            return;
        }

        if ($filter === 'growave_without_square') {
            $query->whereRaw('coalesce(source_link_flags.has_growave_link, 0) = 1')
                ->whereRaw('coalesce(source_link_flags.has_square_link, 0) = 0');

            return;
        }

        if ($filter === 'all_three') {
            $this->applyBucketFilter($query, 'shopify_square_growave');

            return;
        }

        if (str_starts_with($filter, 'bucket:')) {
            $this->applyBucketFilter($query, substr($filter, 7));
        }
    }

    protected function applyBucketFilter(QueryBuilder $query, string $bucket): void
    {
        $definition = $this->bucketDefinitions()[$bucket] ?? null;

        if ($definition === null) {
            return;
        }

        $query->whereRaw('coalesce(source_link_flags.has_shopify_link, 0) = ?', [$definition['shopify'] ? 1 : 0])
            ->whereRaw('coalesce(source_link_flags.has_square_link, 0) = ?', [$definition['square'] ? 1 : 0])
            ->whereRaw('coalesce(source_link_flags.has_growave_link, 0) = ?', [$definition['growave'] ? 1 : 0]);
    }

    protected function applyMissingContactFilter(QueryBuilder $query): void
    {
        $query->where(function ($email): void {
            $email->whereNull('marketing_profiles.email')
                ->orWhere('marketing_profiles.email', '');
        })->where(function ($phone): void {
            $phone->whereNull('marketing_profiles.phone')
                ->orWhere('marketing_profiles.phone', '');
        });
    }

    protected function sourceLinkFlagsSubquery(): QueryBuilder
    {
        return MarketingProfileLink::query()
            ->toBase()
            ->select('marketing_profile_id')
            ->whereIn('source_type', [
                'square_customer',
                'square_order',
                'square_payment',
                'shopify_customer',
                'shopify_order',
                'growave_customer',
            ])
            ->groupBy('marketing_profile_id')
            ->selectRaw("max(case when source_type = 'square_customer' then 1 else 0 end) as has_square_customer_link")
            ->selectRaw("max(case when source_type = 'square_order' then 1 else 0 end) as has_square_order_link")
            ->selectRaw("max(case when source_type = 'square_payment' then 1 else 0 end) as has_square_payment_link")
            ->selectRaw("max(case when source_type in ('square_customer', 'square_order', 'square_payment') then 1 else 0 end) as has_square_link")
            ->selectRaw("max(case when source_type in ('shopify_customer', 'shopify_order') then 1 else 0 end) as has_shopify_link")
            ->selectRaw("max(case when source_type = 'growave_customer' then 1 else 0 end) as has_growave_link");
    }

    protected function profileReviewMetricsSubquery(): ?QueryBuilder
    {
        if (! Schema::hasTable('marketing_review_summaries')) {
            return null;
        }

        return DB::table('marketing_review_summaries')
            ->select('marketing_profile_id')
            ->whereNotNull('marketing_profile_id')
            ->groupBy('marketing_profile_id')
            ->selectRaw('max(1) as has_review_summary')
            ->selectRaw('max(coalesce(review_count, 0)) as review_count');
    }

    protected function profileCandleCashBalanceSubquery(): ?QueryBuilder
    {
        if (! Schema::hasTable('candle_cash_balances')) {
            return null;
        }

        return DB::table('candle_cash_balances')
            ->select('marketing_profile_id', 'balance');
    }

    protected function shopifyOrderMetricsSubquery(): ?QueryBuilder
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'shopify_order_id')) {
            return null;
        }

        $amountColumn = $this->detectShopifyOrderAmountColumn();

        if ($amountColumn === null) {
            return null;
        }

        $orderRows = DB::table('orders')
            ->selectRaw($this->shopifyOrderSourceIdExpression() . ' as shopify_source_id')
            ->whereNotNull('shopify_order_id')
            ->selectRaw('round(coalesce(' . $amountColumn . ', 0) * 100, 0) as spend_cents');

        return DB::table('marketing_profile_links as shopify_links')
            ->leftJoinSub($orderRows, 'shopify_order_rows', function ($join): void {
                $join->on('shopify_order_rows.shopify_source_id', '=', 'shopify_links.source_id');
            })
            ->where('shopify_links.source_type', 'shopify_order')
            ->groupBy('shopify_links.marketing_profile_id')
            ->selectRaw('shopify_links.marketing_profile_id')
            ->selectRaw('count(distinct shopify_links.source_id) as shopify_order_link_count')
            ->selectRaw('coalesce(sum(coalesce(shopify_order_rows.spend_cents, 0)), 0) as shopify_order_spend_cents');
    }

    protected function shopifyOrderSourceIdExpression(): string
    {
        $driver = DB::connection()->getDriverName();
        $storeExpression = match (true) {
            Schema::hasColumn('orders', 'shopify_store_key') && Schema::hasColumn('orders', 'shopify_store') => "coalesce(shopify_store_key, shopify_store, 'unknown')",
            Schema::hasColumn('orders', 'shopify_store_key') => "coalesce(shopify_store_key, 'unknown')",
            Schema::hasColumn('orders', 'shopify_store') => "coalesce(shopify_store, 'unknown')",
            default => "'unknown'",
        };

        if ($driver === 'sqlite') {
            return $storeExpression . " || ':' || cast(shopify_order_id as text)";
        }

        return "concat(" . $storeExpression . ", ':', cast(shopify_order_id as char))";
    }

    protected function detectShopifyOrderAmountColumn(): ?string
    {
        foreach (['total_price', 'total', 'grand_total', 'order_total', 'subtotal_price'] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                return $column;
            }
        }

        return null;
    }

    protected function squareCustomerMetricsSubquery(): QueryBuilder
    {
        $orderMetrics = SquareOrder::query()
            ->toBase()
            ->select('square_customer_id')
            ->whereNotNull('square_customer_id')
            ->where('square_customer_id', '<>', '')
            ->groupBy('square_customer_id')
            ->selectRaw('count(*) as order_count')
            ->selectRaw('coalesce(sum(total_money_amount), 0) as order_spend_cents')
            ->selectRaw('max(closed_at) as last_square_order_at');

        $paymentMetrics = SquarePayment::query()
            ->toBase()
            ->select('square_customer_id')
            ->whereNotNull('square_customer_id')
            ->where('square_customer_id', '<>', '')
            ->groupBy('square_customer_id')
            ->selectRaw('count(*) as payment_count')
            ->selectRaw('coalesce(sum(amount_money), 0) as payment_spend_cents')
            ->selectRaw('max(created_at_source) as last_square_payment_at');

        return MarketingProfileLink::query()
            ->toBase()
            ->from('marketing_profile_links as square_links')
            ->leftJoinSub($orderMetrics, 'square_order_metrics', function ($join): void {
                $join->on('square_order_metrics.square_customer_id', '=', 'square_links.source_id');
            })
            ->leftJoinSub($paymentMetrics, 'square_payment_metrics', function ($join): void {
                $join->on('square_payment_metrics.square_customer_id', '=', 'square_links.source_id');
            })
            ->where('square_links.source_type', 'square_customer')
            ->groupBy('square_links.marketing_profile_id')
            ->selectRaw('square_links.marketing_profile_id')
            ->selectRaw('count(distinct square_links.source_id) as square_customer_link_count')
            ->selectRaw('min(square_links.source_id) as sample_square_customer_id')
            ->selectRaw('coalesce(sum(coalesce(square_order_metrics.order_count, 0)), 0) as square_order_count')
            ->selectRaw('coalesce(sum(coalesce(square_order_metrics.order_spend_cents, 0)), 0) as square_order_spend_cents')
            ->selectRaw('max(square_order_metrics.last_square_order_at) as last_square_order_at')
            ->selectRaw('coalesce(sum(coalesce(square_payment_metrics.payment_count, 0)), 0) as square_payment_count')
            ->selectRaw('coalesce(sum(coalesce(square_payment_metrics.payment_spend_cents, 0)), 0) as square_payment_spend_cents')
            ->selectRaw('max(square_payment_metrics.last_square_payment_at) as last_square_payment_at');
    }

    protected function bucketCaseExpression(string $alias = 'source_link_flags'): string
    {
        return sprintf(
            "case
                when coalesce(%s.has_shopify_link, 0) = 1 and coalesce(%s.has_square_link, 0) = 0 and coalesce(%s.has_growave_link, 0) = 0 then 'shopify_only'
                when coalesce(%s.has_shopify_link, 0) = 0 and coalesce(%s.has_square_link, 0) = 1 and coalesce(%s.has_growave_link, 0) = 0 then 'square_only'
                when coalesce(%s.has_shopify_link, 0) = 0 and coalesce(%s.has_square_link, 0) = 0 and coalesce(%s.has_growave_link, 0) = 1 then 'growave_only'
                when coalesce(%s.has_shopify_link, 0) = 1 and coalesce(%s.has_square_link, 0) = 1 and coalesce(%s.has_growave_link, 0) = 0 then 'shopify_square'
                when coalesce(%s.has_shopify_link, 0) = 1 and coalesce(%s.has_square_link, 0) = 0 and coalesce(%s.has_growave_link, 0) = 1 then 'shopify_growave'
                when coalesce(%s.has_shopify_link, 0) = 0 and coalesce(%s.has_square_link, 0) = 1 and coalesce(%s.has_growave_link, 0) = 1 then 'square_growave'
                when coalesce(%s.has_shopify_link, 0) = 1 and coalesce(%s.has_square_link, 0) = 1 and coalesce(%s.has_growave_link, 0) = 1 then 'shopify_square_growave'
                else 'unlinked_or_other'
            end",
            $alias, $alias, $alias,
            $alias, $alias, $alias,
            $alias, $alias, $alias,
            $alias, $alias, $alias,
            $alias, $alias, $alias,
            $alias, $alias, $alias,
            $alias, $alias, $alias
        );
    }
}
