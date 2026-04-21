<?php

namespace App\Console\Commands;

use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MarketingDiagnoseStorefrontTracking extends Command
{
    protected $signature = 'marketing:diagnose-storefront-tracking
        {--tenant-id= : Restrict diagnostics to one tenant id (required)}
        {--store= : Restrict diagnostics to one Shopify store key}
        {--days=30 : Lookback window in days when --since is not provided}
        {--since= : Include events/orders on or after this datetime}
        {--until= : Include events/orders on or before this datetime}
        {--json : Emit JSON only output for automation}';

    protected $description = 'Diagnose storefront funnel continuity, identifier coverage, and order linkage health for one tenant/store window.';

    /**
     * @var array<int,string>
     */
    protected array $identifierKeys = [
        'checkout_token',
        'cart_token',
        'session_key',
        'client_id',
        'fbclid',
        'fbc',
        'fbp',
    ];

    public function handle(): int
    {
        $tenantId = is_numeric($this->option('tenant-id')) ? (int) $this->option('tenant-id') : null;
        if ($tenantId === null || $tenantId <= 0) {
            $this->error('Missing required --tenant-id. Storefront diagnostics are tenant-scoped.');

            return self::FAILURE;
        }

        $storeKey = $this->nullableString($this->option('store'));
        [$since, $until] = $this->resolveWindow();

        $funnelQuery = MarketingStorefrontEvent::query()
            ->forTenantId($tenantId)
            ->where('source_type', 'shopify_storefront_funnel')
            ->whereBetween('occurred_at', [$since, $until]);
        $purchaseEventQuery = MarketingStorefrontEvent::query()
            ->forTenantId($tenantId)
            ->where('source_type', 'shopify_storefront_purchase')
            ->whereBetween('occurred_at', [$since, $until]);
        $verificationFailuresQuery = MarketingStorefrontEvent::query()
            ->forTenantId($tenantId)
            ->where('event_type', 'widget_verification_failed')
            ->whereBetween('occurred_at', [$since, $until])
            ->where('endpoint', 'like', '%funnel/event%');
        $ordersQuery = Order::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('ordered_at', [$since, $until]);

        if ($storeKey !== null) {
            $this->applyStoreScope($funnelQuery, $storeKey);
            $this->applyStoreScope($purchaseEventQuery, $storeKey);
            $this->applyStoreScope($verificationFailuresQuery, $storeKey);
            $ordersQuery->where('shopify_store_key', $storeKey);
        }

        $funnelRows = $funnelQuery->get(['id', 'event_type', 'status', 'issue_type', 'meta', 'occurred_at']);
        $purchaseRows = $purchaseEventQuery->get(['id', 'event_type', 'status', 'issue_type', 'meta', 'occurred_at']);
        $verificationRows = $verificationFailuresQuery->get(['id', 'issue_type', 'meta', 'endpoint', 'occurred_at']);

        $funnelByType = [];
        $funnelByTracker = [];
        $funnelIdentifierPresence = $this->emptyPresenceTemplate();

        foreach ($funnelRows as $row) {
            $eventType = (string) $row->event_type;
            $funnelByType[$eventType] = (int) ($funnelByType[$eventType] ?? 0) + 1;

            $meta = is_array($row->meta ?? null) ? $row->meta : [];
            $tracker = $this->nullableString($meta['tracker'] ?? null) ?? 'unknown';
            $funnelByTracker[$tracker] = (int) ($funnelByTracker[$tracker] ?? 0) + 1;

            foreach ($this->identifierKeys as $key) {
                if ($this->metaHasValue($meta, $key)) {
                    $funnelIdentifierPresence[$key]['count']++;
                }
            }
        }

        $purchaseByType = [];
        $purchaseIdentifierPresence = $this->emptyPresenceTemplate();
        foreach ($purchaseRows as $row) {
            $eventType = (string) $row->event_type;
            $purchaseByType[$eventType] = (int) ($purchaseByType[$eventType] ?? 0) + 1;

            $meta = is_array($row->meta ?? null) ? $row->meta : [];
            foreach ($this->identifierKeys as $key) {
                if ($this->metaHasValue($meta, $key)) {
                    $purchaseIdentifierPresence[$key]['count']++;
                }
            }
            if ($this->metaHasValue($meta, 'linked_storefront_event_id')) {
                $purchaseIdentifierPresence['linked_storefront_event_id']['count']++;
            }
        }

        $funnelTotal = $funnelRows->count();
        $purchaseEventTotal = $purchaseRows->count();
        $ordersTotal = (clone $ordersQuery)->count();
        $checkoutStartedTotal = (int) ($funnelByType['checkout_started'] ?? 0);

        $orderLinkage = $this->orderLinkageSnapshot($ordersQuery, $ordersTotal);
        $verificationBreakdown = $this->verificationBreakdown($verificationRows);
        $nonOkBreakdown = $this->nonOkBreakdown($tenantId, $storeKey, $since, $until);

        $report = [
            'generated_at' => now()->toIso8601String(),
            'scope' => [
                'tenant_id' => $tenantId,
                'store_key' => $storeKey,
                'since' => $since->toIso8601String(),
                'until' => $until->toIso8601String(),
                'window_days' => $since->diffInDays($until),
            ],
            'counts' => [
                'orders' => $ordersTotal,
                'funnel_events' => $funnelTotal,
                'checkout_started' => $checkoutStartedTotal,
                'purchase_events' => $purchaseEventTotal,
                'verification_failures' => $verificationRows->count(),
            ],
            'funnel' => [
                'by_event_type' => $this->sortedCounts($funnelByType),
                'by_tracker' => $this->sortedCounts($funnelByTracker),
            ],
            'purchase_events' => [
                'by_event_type' => $this->sortedCounts($purchaseByType),
            ],
            'ratios' => [
                'funnel_events_per_order' => $this->rate($funnelTotal, $ordersTotal),
                'checkout_started_per_order' => $this->rate($checkoutStartedTotal, $ordersTotal),
                'purchase_events_per_order' => $this->rate($purchaseEventTotal, $ordersTotal),
                'order_linkage_match_rate' => (float) ($orderLinkage['linked_order_rate'] ?? 0.0),
            ],
            'identifier_presence' => [
                'funnel_events' => $this->finalizePresenceTemplate($funnelIdentifierPresence, $funnelTotal),
                'purchase_events' => $this->finalizePresenceTemplate($purchaseIdentifierPresence, $purchaseEventTotal),
                'orders' => (array) ($orderLinkage['identifier_presence'] ?? []),
            ],
            'order_linkage' => [
                'linked_orders' => (int) ($orderLinkage['linked_orders'] ?? 0),
                'linked_order_rate' => (float) ($orderLinkage['linked_order_rate'] ?? 0.0),
                'confidence_distribution' => (array) ($orderLinkage['confidence_distribution'] ?? []),
                'link_method_distribution' => (array) ($orderLinkage['link_method_distribution'] ?? []),
            ],
            'drop_or_reject_diagnostics' => [
                'verification_failures' => $verificationBreakdown,
                'non_ok_storefront_events' => $nonOkBreakdown,
            ],
        ];

        if (! (bool) $this->option('json')) {
            $this->line(sprintf(
                'tenant_id=%d store=%s orders=%d funnel_events=%d checkout_started=%d purchase_events=%d linked_orders=%d',
                $tenantId,
                $storeKey ?? 'any',
                $ordersTotal,
                $funnelTotal,
                $checkoutStartedTotal,
                $purchaseEventTotal,
                (int) ($orderLinkage['linked_orders'] ?? 0)
            ));
        }

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    protected function applyStoreScope(Builder $query, string $storeKey): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $query->whereRaw("meta->>'store_key' = ?", [$storeKey]);

            return;
        }

        if ($driver === 'sqlite') {
            $query->whereRaw("json_extract(meta, '$.store_key') = ?", [$storeKey]);

            return;
        }

        $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.store_key')) = ?", [$storeKey]);
    }

    protected function resolveWindow(): array
    {
        $since = $this->parseDateOption('since');
        $until = $this->parseDateOption('until') ?? now()->toImmutable();

        if (! $since instanceof CarbonImmutable) {
            $days = max(1, (int) $this->option('days'));
            $since = $until->subDays($days);
        }

        if ($since->greaterThan($until)) {
            [$since, $until] = [$until, $since];
        }

        return [$since, $until];
    }

    protected function parseDateOption(string $key): ?CarbonImmutable
    {
        $value = trim((string) $this->option($key));
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            $this->warn("Invalid --{$key} value '{$value}', ignoring.");

            return null;
        }
    }

    protected function orderLinkageSnapshot(Builder $ordersQuery, int $ordersTotal): array
    {
        $hasLinkedEventId = Schema::hasColumn('orders', 'storefront_linked_event_id');
        $hasLinkConfidence = Schema::hasColumn('orders', 'storefront_link_confidence');
        $hasLinkMethod = Schema::hasColumn('orders', 'storefront_link_method');

        $linkedOrders = 0;
        if ($hasLinkedEventId) {
            $linkedOrders = (clone $ordersQuery)->whereNotNull('storefront_linked_event_id')->count();
        } elseif ($hasLinkConfidence) {
            $linkedOrders = (clone $ordersQuery)->whereNotNull('storefront_link_confidence')->where('storefront_link_confidence', '>', 0)->count();
        }

        $identifierPresence = [];
        foreach ([
            'storefront_checkout_token' => 'checkout_token',
            'storefront_cart_token' => 'cart_token',
            'storefront_session_key' => 'session_key',
            'storefront_client_id' => 'client_id',
        ] as $column => $key) {
            if (! Schema::hasColumn('orders', $column)) {
                $identifierPresence[$key] = [
                    'count' => 0,
                    'rate' => 0.0,
                    'column_present' => false,
                ];
                continue;
            }

            $count = (clone $ordersQuery)->whereNotNull($column)->where($column, '!=', '')->count();
            $identifierPresence[$key] = [
                'count' => $count,
                'rate' => $this->rate($count, $ordersTotal),
                'column_present' => true,
            ];
        }

        $confidenceDistribution = [
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'unlinked' => $ordersTotal,
        ];
        if ($hasLinkConfidence) {
            $high = (clone $ordersQuery)->where('storefront_link_confidence', '>=', 0.85)->count();
            $medium = (clone $ordersQuery)->where('storefront_link_confidence', '>=', 0.6)->where('storefront_link_confidence', '<', 0.85)->count();
            $low = (clone $ordersQuery)->where('storefront_link_confidence', '>', 0)->where('storefront_link_confidence', '<', 0.6)->count();
            $confidenceDistribution = [
                'high' => $high,
                'medium' => $medium,
                'low' => $low,
                'unlinked' => max(0, $ordersTotal - ($high + $medium + $low)),
            ];
        }

        $linkMethodDistribution = [];
        if ($hasLinkMethod) {
            $methods = (clone $ordersQuery)
                ->selectRaw('COALESCE(NULLIF(storefront_link_method, \'\'), \'unlinked\') as link_method, count(*) as c')
                ->groupBy('link_method')
                ->pluck('c', 'link_method')
                ->all();
            $linkMethodDistribution = $this->sortedCounts($methods);
        }

        return [
            'linked_orders' => $linkedOrders,
            'linked_order_rate' => $this->rate($linkedOrders, $ordersTotal),
            'confidence_distribution' => $confidenceDistribution,
            'link_method_distribution' => $linkMethodDistribution,
            'identifier_presence' => $identifierPresence,
        ];
    }

    protected function verificationBreakdown($rows): array
    {
        $summary = [];
        foreach ($rows as $row) {
            $meta = is_array($row->meta ?? null) ? $row->meta : [];
            $reason = $this->nullableString($meta['reason'] ?? null) ?? 'unknown';
            $key = sprintf(
                '%s|%s|%s',
                $this->nullableString($row->issue_type ?? null) ?? 'unknown',
                $reason,
                $this->nullableString($row->endpoint ?? null) ?? 'unknown'
            );
            $summary[$key] = (int) ($summary[$key] ?? 0) + 1;
        }

        $rows = [];
        foreach ($this->sortedCounts($summary) as $key => $count) {
            [$issueType, $reason, $endpoint] = array_pad(explode('|', (string) $key), 3, 'unknown');
            $rows[] = [
                'issue_type' => $issueType,
                'reason' => $reason,
                'endpoint' => $endpoint,
                'count' => $count,
            ];
        }

        return $rows;
    }

    protected function nonOkBreakdown(int $tenantId, ?string $storeKey, CarbonImmutable $since, CarbonImmutable $until): array
    {
        $query = MarketingStorefrontEvent::query()
            ->forTenantId($tenantId)
            ->whereIn('source_type', ['shopify_storefront_funnel', 'shopify_storefront_purchase'])
            ->whereBetween('occurred_at', [$since, $until])
            ->where(function (Builder $builder): void {
                $builder->where('status', '!=', 'ok')
                    ->orWhereNotNull('issue_type');
            });

        if ($storeKey !== null) {
            $this->applyStoreScope($query, $storeKey);
        }

        $rows = $query
            ->selectRaw('event_type, status, issue_type, count(*) as c')
            ->groupBy('event_type', 'status', 'issue_type')
            ->orderByDesc('c')
            ->get();

        return $rows->map(fn ($row): array => [
            'event_type' => (string) ($row->event_type ?? ''),
            'status' => (string) ($row->status ?? ''),
            'issue_type' => $this->nullableString($row->issue_type ?? null),
            'count' => (int) ($row->c ?? 0),
        ])->values()->all();
    }

    protected function emptyPresenceTemplate(): array
    {
        $template = [];
        foreach ($this->identifierKeys as $key) {
            $template[$key] = ['count' => 0, 'rate' => 0.0];
        }
        $template['linked_storefront_event_id'] = ['count' => 0, 'rate' => 0.0];

        return $template;
    }

    protected function finalizePresenceTemplate(array $presence, int $total): array
    {
        $final = [];
        foreach ($presence as $key => $row) {
            $count = (int) ($row['count'] ?? 0);
            $final[$key] = [
                'count' => $count,
                'rate' => $this->rate($count, $total),
            ];
        }

        return $final;
    }

    protected function metaHasValue(array $meta, string $key): bool
    {
        $value = $meta[$key] ?? null;
        if (is_numeric($value)) {
            return (float) $value !== 0.0;
        }

        return $this->nullableString($value) !== null;
    }

    protected function rate(int|float $part, int|float $whole): float
    {
        if ($whole <= 0) {
            return 0.0;
        }

        return round(((float) $part / (float) $whole) * 100, 1);
    }

    /**
     * @param  array<string,int>  $counts
     * @return array<string,int>
     */
    protected function sortedCounts(array $counts): array
    {
        arsort($counts);

        return $counts;
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $resolved = trim((string) $value);

        return $resolved !== '' ? $resolved : null;
    }
}
