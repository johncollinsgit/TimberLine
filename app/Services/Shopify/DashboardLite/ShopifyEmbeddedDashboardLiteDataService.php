<?php

namespace App\Services\Shopify\DashboardLite;

use App\Services\Marketing\CandleCashService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShopifyEmbeddedDashboardLiteDataService
{
    public function __construct(
        protected CandleCashService $candleCashService
    ) {
    }

    /**
     * @param  array{
     *   tenant_id:?int,
     *   range?:string,
     *   section?:string,
     *   limit?:int
     * }  $input
     * @return array<string,mixed>
     */
    public function payload(array $input): array
    {
        $tenantId = isset($input['tenant_id']) && is_numeric($input['tenant_id'])
            ? (int) $input['tenant_id']
            : null;
        $range = $this->normalizeRange((string) ($input['range'] ?? '7d'));
        $section = strtolower(trim((string) ($input['section'] ?? 'summary'))) ?: 'summary';
        $includeActivity = in_array($section, ['activity', 'all'], true);
        $limit = isset($input['limit']) && is_numeric($input['limit'])
            ? max(5, min(40, (int) $input['limit']))
            : 20;

        $window = $this->windowForRange($range);
        $ttlSeconds = $this->ttlSecondsForRange($range, $window);

        $summaryKey = $this->cacheKey($tenantId, $range, 'summary');
        $activityKey = $this->cacheKey($tenantId, $range, 'activity:' . $limit);

        $summaryCacheHit = false;
        $activityCacheHit = false;

        $summary = Cache::get($summaryKey);
        if (is_array($summary)) {
            $summaryCacheHit = true;
        } else {
            $summary = $this->buildSummary($tenantId, $window);
            Cache::put($summaryKey, $summary, now()->addSeconds($ttlSeconds));
        }

        $activity = null;
        if ($includeActivity) {
            $activity = Cache::get($activityKey);
            if (is_array($activity)) {
                $activityCacheHit = true;
            } else {
                $activity = $this->buildActivity($tenantId, $window, $limit);
                Cache::put($activityKey, $activity, now()->addSeconds($ttlSeconds));
            }
        }

        $meta = [
            'generatedAt' => now()->toIso8601String(),
            'cacheTtlSeconds' => $ttlSeconds,
            'cache' => [
                'summary' => ['hit' => $summaryCacheHit, 'key' => $summaryKey],
                'activity' => ['hit' => $activityCacheHit, 'key' => $includeActivity ? $activityKey : null],
            ],
        ];

        $payload = [
            'meta' => $meta,
            'query' => [
                'range' => $range,
                'from' => $window['from']->toIso8601String(),
                'to' => $window['to']->toIso8601String(),
                'timezone' => (string) config('app.timezone', 'UTC'),
            ],
            'summary' => $summary,
        ];

        if ($includeActivity) {
            $payload['activity'] = $activity ?? [
                'rows' => [],
                'count' => 0,
            ];
        }

        return $payload;
    }

    protected function normalizeRange(string $raw): string
    {
        $raw = strtolower(trim($raw));

        return match ($raw) {
            'today', '1d' => 'today',
            '30d', 'last_30_days' => '30d',
            default => '7d',
        };
    }

    /**
     * @return array{from:CarbonImmutable,to:CarbonImmutable}
     */
    protected function windowForRange(string $range): array
    {
        $now = now()->toImmutable();

        return match ($range) {
            'today' => [
                'from' => $now->startOfDay(),
                'to' => $now,
            ],
            '30d' => [
                'from' => $now->subDays(30)->startOfDay(),
                'to' => $now,
            ],
            default => [
                'from' => $now->subDays(7)->startOfDay(),
                'to' => $now,
            ],
        };
    }

    protected function ttlSecondsForRange(string $range, array $window): int
    {
        if ($range === 'today') {
            return 30;
        }

        $touchesToday = ($window['to'] ?? null) instanceof CarbonImmutable
            && $window['to']->greaterThanOrEqualTo(now()->startOfDay()->toImmutable());

        return $touchesToday ? 60 : 180;
    }

    protected function cacheKey(?int $tenantId, string $range, string $section): string
    {
        return 'shopify:dashboard-lite:' . sha1(json_encode([
            'tenant_id' => $tenantId,
            'range' => $range,
            'section' => $section,
        ]));
    }

    /**
     * @return array<string,mixed>
     */
    protected function buildSummary(?int $tenantId, array $window): array
    {
        if (
            $tenantId === null
            || ! Schema::hasTable('marketing_profile_links')
            || ! Schema::hasTable('orders')
        ) {
            return $this->emptySummary();
        }

        $purchaseStats = $this->purchaseStats($tenantId, $window['from'], $window['to']);
        $candleCashStats = $this->candleCashStats($tenantId, $window['from'], $window['to']);

        return [
            'kpis' => [
                'customersPurchased' => (int) ($purchaseStats['customersPurchased'] ?? 0),
                'purchaseCount' => (int) ($purchaseStats['purchaseCount'] ?? 0),
                'returningCustomers' => (int) ($purchaseStats['returningCustomers'] ?? 0),
                'returningRatePct' => (float) ($purchaseStats['returningRatePct'] ?? 0.0),
                'candleCashEarned' => $candleCashStats['earned'] ?? $this->emptyCandleCashMetric(),
                'candleCashRedeemed' => $candleCashStats['redeemed'] ?? $this->emptyCandleCashMetric(),
                // "Outstanding expiring" is approximated as open (issued, unredeemed) reward codes since
                // that is a fast and explicit expiring surface in our current schema.
                'openRewardCodes' => $candleCashStats['openRewardCodes'] ?? $this->emptyCandleCashMetric(withCount: true),
                'outstandingBalance' => $candleCashStats['outstandingBalance'] ?? $this->emptyCandleCashMetric(),
            ],
            'movement' => [
                'earned' => $candleCashStats['earned'] ?? $this->emptyCandleCashMetric(),
                'redeemed' => $candleCashStats['redeemed'] ?? $this->emptyCandleCashMetric(),
                'net' => $this->netMovement(
                    (float) data_get($candleCashStats, 'earned.points', 0),
                    (float) data_get($candleCashStats, 'redeemed.points', 0)
                ),
            ],
        ];
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,count:int}
     */
    protected function buildActivity(?int $tenantId, array $window, int $limit): array
    {
        if (
            $tenantId === null
            || ! Schema::hasTable('marketing_profile_links')
            || ! Schema::hasTable('marketing_profiles')
            || ! Schema::hasTable('orders')
        ) {
            return [
                'rows' => [],
                'count' => 0,
            ];
        }

        $from = $window['from'];
        $to = $window['to'];

        $earnedByProfile = null;
        if (Schema::hasTable('candle_cash_transactions')) {
            $earnedByProfile = DB::table('candle_cash_transactions as tx')
                ->join('marketing_profiles as mp', 'mp.id', '=', 'tx.marketing_profile_id')
                ->when($tenantId === null, fn ($q) => $q->whereNull('mp.tenant_id'), fn ($q) => $q->where('mp.tenant_id', $tenantId))
                ->where('tx.type', 'earn')
                ->where('tx.candle_cash_delta', '>', 0)
                ->whereBetween('tx.created_at', [$from, $to])
                ->groupBy('tx.marketing_profile_id')
                ->selectRaw('tx.marketing_profile_id as marketing_profile_id')
                ->selectRaw('sum(tx.candle_cash_delta) as earned_points')
                ->selectRaw('count(*) as earned_count');
        }

        $redeemedByProfile = null;
        $redeemedByOrder = null;
        if (Schema::hasTable('candle_cash_redemptions')) {
            $redeemedByProfile = DB::table('candle_cash_redemptions as r')
                ->join('marketing_profiles as mp', 'mp.id', '=', 'r.marketing_profile_id')
                ->when($tenantId === null, fn ($q) => $q->whereNull('mp.tenant_id'), fn ($q) => $q->where('mp.tenant_id', $tenantId))
                ->where('r.status', 'redeemed')
                ->whereBetween('r.redeemed_at', [$from, $to])
                ->groupBy('r.marketing_profile_id')
                ->selectRaw('r.marketing_profile_id as marketing_profile_id')
                ->selectRaw('sum(r.candle_cash_spent) as redeemed_points')
                ->selectRaw('count(*) as redeemed_count');

            $castOrderId = $this->castToIntegerExpression('r.external_order_id');
            $redeemedByOrder = DB::table('candle_cash_redemptions as r')
                ->join('marketing_profiles as mp', 'mp.id', '=', 'r.marketing_profile_id')
                ->when($tenantId === null, fn ($q) => $q->whereNull('mp.tenant_id'), fn ($q) => $q->where('mp.tenant_id', $tenantId))
                ->where('r.status', 'redeemed')
                ->where('r.external_order_source', 'order')
                ->whereNotNull('r.external_order_id')
                ->where('r.external_order_id', '!=', '')
                ->whereBetween('r.redeemed_at', [$from, $to])
                ->groupBy(DB::raw($castOrderId))
                ->selectRaw($castOrderId . ' as order_id')
                ->selectRaw('sum(r.candle_cash_spent) as redeemed_order_points');
        }

        $balanceByProfile = null;
        if (Schema::hasTable('candle_cash_balances')) {
            $balanceByProfile = DB::table('candle_cash_balances as b')
                ->selectRaw('b.marketing_profile_id as marketing_profile_id')
                ->selectRaw('b.balance as balance_points');
        }

        $orderIdJoinExpr = $this->castToIntegerExpression('mpl.source_id');

        $query = DB::table('marketing_profile_links as mpl')
            ->join('orders as o', function ($join) use ($orderIdJoinExpr): void {
                $join->on(DB::raw($orderIdJoinExpr), '=', 'o.id');
            })
            ->join('marketing_profiles as mp', 'mp.id', '=', 'mpl.marketing_profile_id')
            ->when($tenantId === null, fn ($q) => $q->whereNull('mpl.tenant_id'), fn ($q) => $q->where('mpl.tenant_id', $tenantId))
            ->where('mpl.source_type', 'order')
            ->whereBetween(DB::raw('coalesce(o.ordered_at, o.created_at)'), [$from, $to])
            ->orderByDesc(DB::raw('coalesce(o.ordered_at, o.created_at)'))
            ->limit($limit)
            ->select([
                'o.id as order_id',
                'o.shopify_name',
                'o.order_number',
                'o.ordered_at',
                'o.total_price',
                'o.currency_code',
                'mp.id as marketing_profile_id',
                'mp.first_name',
                'mp.last_name',
                'mp.email',
            ]);

        if ($earnedByProfile) {
            $query->leftJoinSub($earnedByProfile, 'earned', function ($join): void {
                $join->on('earned.marketing_profile_id', '=', 'mp.id');
            });
            $query->addSelect([
                DB::raw('coalesce(earned.earned_points, 0) as earned_points'),
                DB::raw('coalesce(earned.earned_count, 0) as earned_count'),
            ]);
        } else {
            $query->addSelect([
                DB::raw('0 as earned_points'),
                DB::raw('0 as earned_count'),
            ]);
        }

        if ($redeemedByProfile) {
            $query->leftJoinSub($redeemedByProfile, 'redeemed', function ($join): void {
                $join->on('redeemed.marketing_profile_id', '=', 'mp.id');
            });
            $query->addSelect([
                DB::raw('coalesce(redeemed.redeemed_points, 0) as redeemed_points'),
                DB::raw('coalesce(redeemed.redeemed_count, 0) as redeemed_count'),
            ]);
        } else {
            $query->addSelect([
                DB::raw('0 as redeemed_points'),
                DB::raw('0 as redeemed_count'),
            ]);
        }

        if ($redeemedByOrder) {
            $query->leftJoinSub($redeemedByOrder, 'redeemed_order', function ($join): void {
                $join->on('redeemed_order.order_id', '=', 'o.id');
            });
            $query->addSelect([
                DB::raw('coalesce(redeemed_order.redeemed_order_points, 0) as redeemed_order_points'),
            ]);
        } else {
            $query->addSelect([
                DB::raw('0 as redeemed_order_points'),
            ]);
        }

        if ($balanceByProfile) {
            $query->leftJoinSub($balanceByProfile, 'balances', function ($join): void {
                $join->on('balances.marketing_profile_id', '=', 'mp.id');
            });
            $query->addSelect([
                DB::raw('coalesce(balances.balance_points, 0) as balance_points'),
            ]);
        } else {
            $query->addSelect([
                DB::raw('0 as balance_points'),
            ]);
        }

        $rows = $query->get()->map(function ($row): array {
            $first = trim((string) ($row->first_name ?? ''));
            $last = trim((string) ($row->last_name ?? ''));
            $name = trim($first . ' ' . $last);
            $email = trim((string) ($row->email ?? ''));

            $earnedPoints = (float) ($row->earned_points ?? 0);
            $redeemedOrderPoints = (float) ($row->redeemed_order_points ?? 0);
            $balancePoints = (float) ($row->balance_points ?? 0);

            $orderTotal = $row->total_price !== null ? (float) $row->total_price : null;
            $currencyCode = trim((string) ($row->currency_code ?? '')) ?: 'USD';

            return [
                'order' => [
                    'id' => (int) ($row->order_id ?? 0),
                    'label' => trim((string) ($row->shopify_name ?? $row->order_number ?? '')) ?: ('Order #' . (int) ($row->order_id ?? 0)),
                    'orderedAt' => $row->ordered_at ? (string) CarbonImmutable::parse($row->ordered_at)->toIso8601String() : null,
                    'total' => [
                        'amount' => $orderTotal,
                        'currencyCode' => $currencyCode,
                    ],
                ],
                'customer' => [
                    'id' => (int) ($row->marketing_profile_id ?? 0),
                    'name' => $name !== '' ? $name : null,
                    'email' => $email !== '' ? $email : null,
                ],
                'candleCash' => [
                    'earnedWindow' => $this->formatCandleCashMetric($earnedPoints),
                    'redeemedThisOrder' => $this->formatCandleCashMetric($redeemedOrderPoints),
                    'balance' => $this->formatCandleCashMetric($balancePoints),
                ],
            ];
        })->values()->all();

        return [
            'rows' => $rows,
            'count' => count($rows),
        ];
    }

    /**
     * @return array{customersPurchased:int,purchaseCount:int,returningCustomers:int,returningRatePct:float}
     */
    protected function purchaseStats(?int $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $orderIdJoinExpr = $this->castToIntegerExpression('mpl.source_id');

        $base = DB::table('marketing_profile_links as mpl')
            ->join('orders as o', function ($join) use ($orderIdJoinExpr): void {
                $join->on(DB::raw($orderIdJoinExpr), '=', 'o.id');
            })
            ->when($tenantId === null, fn ($q) => $q->whereNull('mpl.tenant_id'), fn ($q) => $q->where('mpl.tenant_id', $tenantId))
            ->where('mpl.source_type', 'order')
            ->whereBetween(DB::raw('coalesce(o.ordered_at, o.created_at)'), [$from, $to]);

        $customersPurchased = (int) (clone $base)
            ->distinct('mpl.marketing_profile_id')
            ->count('mpl.marketing_profile_id');

        $purchaseCount = (int) (clone $base)
            ->distinct('o.id')
            ->count('o.id');

        $returningCustomers = (int) (clone $base)
            ->select('mpl.marketing_profile_id')
            ->groupBy('mpl.marketing_profile_id')
            ->havingRaw('count(distinct o.id) >= 2')
            ->get()
            ->count();

        $returningRatePct = $customersPurchased > 0
            ? round(($returningCustomers / $customersPurchased) * 100, 1)
            : 0.0;

        return [
            'customersPurchased' => $customersPurchased,
            'purchaseCount' => $purchaseCount,
            'returningCustomers' => $returningCustomers,
            'returningRatePct' => $returningRatePct,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function candleCashStats(?int $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('marketing_profiles')) {
            return [];
        }

        $earnedPoints = 0.0;
        if (Schema::hasTable('candle_cash_transactions')) {
            $earnedPoints = (float) DB::table('candle_cash_transactions as tx')
                ->join('marketing_profiles as mp', 'mp.id', '=', 'tx.marketing_profile_id')
                ->when($tenantId === null, fn ($q) => $q->whereNull('mp.tenant_id'), fn ($q) => $q->where('mp.tenant_id', $tenantId))
                ->where('tx.type', 'earn')
                ->where('tx.candle_cash_delta', '>', 0)
                ->whereBetween('tx.created_at', [$from, $to])
                ->sum('tx.candle_cash_delta');
        }

        $redeemedPoints = 0.0;
        if (Schema::hasTable('candle_cash_redemptions')) {
            $redeemedPoints = (float) DB::table('candle_cash_redemptions as r')
                ->join('marketing_profiles as mp', 'mp.id', '=', 'r.marketing_profile_id')
                ->when($tenantId === null, fn ($q) => $q->whereNull('mp.tenant_id'), fn ($q) => $q->where('mp.tenant_id', $tenantId))
                ->where('r.status', 'redeemed')
                ->whereBetween('r.redeemed_at', [$from, $to])
                ->sum('r.candle_cash_spent');
        }

        $openCodePoints = 0.0;
        $openCodeCount = 0;
        if (Schema::hasTable('candle_cash_redemptions')) {
            $openCodeQuery = DB::table('candle_cash_redemptions as r')
                ->join('marketing_profiles as mp', 'mp.id', '=', 'r.marketing_profile_id')
                ->when($tenantId === null, fn ($q) => $q->whereNull('mp.tenant_id'), fn ($q) => $q->where('mp.tenant_id', $tenantId))
                ->where('r.status', 'issued')
                ->where(function ($q): void {
                    $q->whereNull('r.expires_at')->orWhere('r.expires_at', '>', now());
                });
            $openCodePoints = (float) (clone $openCodeQuery)->sum('r.candle_cash_spent');
            $openCodeCount = (int) (clone $openCodeQuery)->count();
        }

        $outstandingPoints = 0.0;
        if (Schema::hasTable('candle_cash_balances')) {
            $outstandingPoints = (float) DB::table('candle_cash_balances as b')
                ->join('marketing_profiles as mp', 'mp.id', '=', 'b.marketing_profile_id')
                ->when($tenantId === null, fn ($q) => $q->whereNull('mp.tenant_id'), fn ($q) => $q->where('mp.tenant_id', $tenantId))
                ->sum('b.balance');
        }

        return [
            'earned' => $this->formatCandleCashMetric($earnedPoints),
            'redeemed' => $this->formatCandleCashMetric($redeemedPoints),
            'openRewardCodes' => $this->formatCandleCashMetric($openCodePoints, $openCodeCount),
            'outstandingBalance' => $this->formatCandleCashMetric($outstandingPoints),
        ];
    }

    /**
     * @return array{points:float,amount:float,formatted:string,count?:int|null}
     */
    protected function formatCandleCashMetric(float $points, ?int $count = null): array
    {
        $points = round($points, 3);
        $amount = round((float) $this->candleCashService->amountFromPoints($points), 2);

        $metric = [
            'points' => $points,
            'amount' => $amount,
            'formatted' => '$' . number_format($amount, 2),
        ];

        if ($count !== null) {
            $metric['count'] = $count;
        }

        return $metric;
    }

    /**
     * @return array{points:float,amount:float,formatted:string}
     */
    protected function netMovement(float $earnedPoints, float $redeemedPoints): array
    {
        $netPoints = round($earnedPoints - $redeemedPoints, 3);
        $netAmount = round((float) $this->candleCashService->amountFromPoints($netPoints), 2);
        $prefix = $netAmount >= 0 ? '+' : '-';

        return [
            'points' => $netPoints,
            'amount' => $netAmount,
            'formatted' => $prefix . '$' . number_format(abs($netAmount), 2),
        ];
    }

    /**
     * @return array{points:float,amount:float,formatted:string,count?:int|null}
     */
    protected function emptyCandleCashMetric(bool $withCount = false): array
    {
        $base = [
            'points' => 0.0,
            'amount' => 0.0,
            'formatted' => '$0.00',
        ];

        if ($withCount) {
            $base['count'] = 0;
        }

        return $base;
    }

    /**
     * @return array<string,mixed>
     */
    protected function emptySummary(): array
    {
        return [
            'kpis' => [
                'customersPurchased' => 0,
                'purchaseCount' => 0,
                'returningCustomers' => 0,
                'returningRatePct' => 0.0,
                'candleCashEarned' => $this->emptyCandleCashMetric(),
                'candleCashRedeemed' => $this->emptyCandleCashMetric(),
                'openRewardCodes' => $this->emptyCandleCashMetric(withCount: true),
                'outstandingBalance' => $this->emptyCandleCashMetric(),
            ],
            'movement' => [
                'earned' => $this->emptyCandleCashMetric(),
                'redeemed' => $this->emptyCandleCashMetric(),
                'net' => $this->netMovement(0, 0),
            ],
        ];
    }

    protected function castToIntegerExpression(string $columnExpression): string
    {
        $driver = (string) DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return 'cast(' . $columnExpression . ' as integer)';
        }

        return 'cast(' . $columnExpression . ' as unsigned)';
    }
}

