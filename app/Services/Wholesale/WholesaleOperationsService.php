<?php

namespace App\Services\Wholesale;

use App\Models\WholesaleFollowUp;
use App\Models\WholesaleSuggestion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WholesaleOperationsService
{
    public function __construct(protected WholesaleQualifiedOrderScope $orders) {}

    /** @return array<string,mixed> */
    public function overview(int $tenantId): array
    {
        $now = CarbonImmutable::now();
        $orders = $this->orders->query($tenantId)
            ->orderBy('ordered_at')
            ->orderBy('id')
            ->get();
        $customers = $this->customersFromOrders($tenantId, $orders);
        $thisMonth = $orders->filter(fn ($order): bool => $order->ordered_at?->gte($now->startOfMonth()) ?? false);
        $thisYear = $orders->filter(fn ($order): bool => $order->ordered_at?->gte($now->startOfYear()) ?? false);
        $trailingYear = $orders->filter(fn ($order): bool => $order->ordered_at?->gte($now->subYear()) ?? false);
        $repeatCustomers = $customers->filter(fn (array $customer): bool => $customer['order_count'] > 1)->count();

        return [
            'metrics' => [
                'revenue_month' => $this->revenue($thisMonth),
                'revenue_year' => $this->revenue($thisYear),
                'revenue_trailing_12' => $this->revenue($trailingYear),
                'order_count' => $orders->count(),
                'active_customers' => $customers->filter(fn (array $customer): bool => $customer['last_order_at']?->gte($now->subYear()) ?? false)->count(),
                'new_customers' => $customers->filter(fn (array $customer): bool => $customer['first_order_at']?->gte($now->startOfMonth()) ?? false)->count(),
                'repeat_order_rate' => $customers->isEmpty() ? 0.0 : round(($repeatCustomers / $customers->count()) * 100, 1),
                'average_order_value' => $orders->isEmpty() ? 0.0 : round($this->revenue($orders) / $orders->count(), 2),
            ],
            'attention' => [
                'due_for_reorder' => $customers->where('timing_state', 'due')->count(),
                'at_risk' => $customers->where('timing_state', 'at_risk')->count(),
                'lapsed' => $customers->where('timing_state', 'lapsed')->count(),
                'ambiguous_legacy' => $this->orders->ambiguousLegacyCount($tenantId),
            ],
            'recent_orders' => $orders->sortByDesc(fn ($order) => $order->ordered_at?->timestamp ?? 0)->take(8)->values(),
            'customers' => $customers->sortByDesc('lifetime_revenue')->take(8)->values(),
            'scope_label' => 'Qualified wholesale orders only',
            'as_of' => $now,
        ];
    }

    /** @return Collection<int,array<string,mixed>> */
    public function customers(int $tenantId): Collection
    {
        return $this->customersFromOrders(
            $tenantId,
            $this->orders->query($tenantId)->orderBy('ordered_at')->orderBy('id')->get()
        )->sortByDesc('last_order_at')->values();
    }

    /** @return array<string,mixed>|null */
    public function customer(int $tenantId, string $publicKey): ?array
    {
        $customer = $this->customers($tenantId)->first(
            fn (array $customer): bool => hash_equals($customer['public_key'], $publicKey)
        );
        if (! is_array($customer)) {
            return null;
        }

        return $this->operationalCustomerDetail($tenantId, $customer);
    }

    public function recentOrders(int $tenantId, int $limit = 100): Collection
    {
        return $this->orders->query($tenantId)
            ->with('lines')
            ->orderByDesc('ordered_at')
            ->orderByDesc('id')
            ->limit(max(1, min(250, $limit)))
            ->get();
    }

    /** @param Collection<int,mixed> $orders */
    protected function customersFromOrders(int $tenantId, Collection $orders): Collection
    {
        return $orders
            ->groupBy(fn ($order): string => $this->identityKey($order))
            ->map(function (Collection $customerOrders, string $identity) use ($tenantId): array {
                $sorted = $customerOrders->sortBy(fn ($order) => $order->ordered_at?->timestamp ?? 0)->values();
                $first = $sorted->first();
                $last = $sorted->last();
                $intervals = $sorted->pluck('ordered_at')->filter()->values()->map(
                    fn ($date, int $index): ?int => $index === 0 ? null : (int) round($date->diffInDays($sorted[$index - 1]->ordered_at))
                )->filter()->sort()->values();
                $medianInterval = $this->median($intervals);
                $daysSince = $last?->ordered_at ? (int) round($last->ordered_at->diffInDays(now())) : null;
                $timingState = $this->timingState($daysSince, $medianInterval, $sorted->count());
                $storeKeys = $sorted->map(fn ($order): string => $this->sourceStore($order))->unique()->values();

                return [
                    'public_key' => hash_hmac('sha256', $tenantId.'|'.$identity, (string) config('app.key')),
                    'identity_key' => $identity,
                    'company' => $last?->shipping_company ?: $last?->billing_company ?: $last?->customer_name ?: $last?->display_name ?: 'Wholesale account',
                    'primary_buyer' => $last?->customer_name ?: trim((string) $last?->first_name.' '.(string) $last?->last_name) ?: null,
                    'email' => $last?->customer_email ?: $last?->email ?: $last?->shipping_email ?: $last?->billing_email,
                    'phone' => $last?->customer_phone ?: $last?->phone ?: $last?->shipping_phone ?: $last?->billing_phone,
                    'source_stores' => $storeKeys,
                    'first_order_at' => $first?->ordered_at,
                    'last_order_at' => $last?->ordered_at,
                    'days_since_last_order' => $daysSince,
                    'median_reorder_days' => $medianInterval,
                    'order_count' => $sorted->count(),
                    'lifetime_revenue' => $this->revenue($sorted),
                    'trailing_12_revenue' => $this->revenue($sorted->filter(fn ($order): bool => $order->ordered_at?->gte(now()->subYear()) ?? false)),
                    'average_order_value' => $sorted->isEmpty() ? 0.0 : round($this->revenue($sorted) / $sorted->count(), 2),
                    'revenue_this_year' => $this->revenue($sorted->filter(fn ($order): bool => $order->ordered_at?->gte(now()->startOfYear()) ?? false)),
                    'refund_total' => round((float) $sorted->sum(fn ($order): float => (float) ($order->refund_total ?? 0)), 2),
                    'average_reorder_days' => $intervals->isEmpty() ? null : (int) round($intervals->average()),
                    'predicted_reorder_at' => $last?->ordered_at?->copy()->addDays($medianInterval ?? ($sorted->count() === 1 ? 120 : 90)),
                    'timing_state' => $timingState,
                    'risk_level' => match ($timingState) {
                        'lapsed' => 'high',
                        'at_risk' => 'medium',
                        default => 'low',
                    },
                    'orders' => $sorted->sortByDesc(fn ($order) => $order->ordered_at?->timestamp ?? 0)->values(),
                ];
            })
            ->values();
    }

    /** @param array<string,mixed> $customer @return array<string,mixed> */
    protected function operationalCustomerDetail(int $tenantId, array $customer): array
    {
        $orders = $customer['orders'];
        $orders->loadMissing('lines');
        $lines = $orders->flatMap(fn ($order) => $order->lines->map(fn ($line): array => ['line' => $line, 'order' => $order]));
        $lineSummary = function (Collection $rows, callable $key): Collection {
            return $rows->groupBy($key)->map(function (Collection $group, string $label): array {
                return [
                    'label' => $label !== '' ? $label : 'Unclassified',
                    'units' => (int) $group->sum(fn (array $row): int => (int) ($row['line']->quantity ?: $row['line']->ordered_qty ?: 0)),
                    'revenue' => round((float) $group->sum(function (array $row): float {
                        $line = $row['line'];
                        $quantity = (int) ($line->quantity ?: $line->ordered_qty ?: 0);

                        return (float) ($line->line_total ?? ((float) $line->unit_price * $quantity));
                    }), 2),
                    'order_count' => $group->pluck('order.id')->unique()->count(),
                ];
            })->sortByDesc('units')->values();
        };
        $now = CarbonImmutable::now();
        $priorStart = $now->subYears(2);
        $priorEnd = $now->subYear();
        $priorRevenue = $this->revenue($orders->filter(fn ($order): bool => $order->ordered_at?->between($priorStart, $priorEnd) ?? false));
        $currentRevenue = (float) $customer['trailing_12_revenue'];
        $predicted = $customer['predicted_reorder_at'];

        $customer['prior_trailing_12_revenue'] = $priorRevenue;
        $customer['revenue_change_percent'] = $priorRevenue > 0 ? round((($currentRevenue - $priorRevenue) / $priorRevenue) * 100, 1) : null;
        $customer['days_relative_to_reorder'] = $predicted ? (int) round($predicted->diffInDays($now, false)) : null;
        $customer['products'] = $lineSummary($lines, fn (array $row): string => trim((string) ($row['line']->raw_title ?: $row['line']->sku ?: 'Unclassified product')));
        $customer['scents'] = $lineSummary($lines, fn (array $row): string => trim((string) ($row['line']->scent_name ?: 'Unclassified scent')));
        $customer['suggestions'] = WholesaleSuggestion::query()->forAllTenants()
            ->with('decisions')->where('tenant_id', $tenantId)->where('target_type', 'customer')->where('target_key', $customer['public_key'])->orderByDesc('id')->get();
        $customer['follow_ups'] = WholesaleFollowUp::query()->forAllTenants()
            ->where('tenant_id', $tenantId)->where('target_type', 'customer')->where('target_key', $customer['public_key'])->orderByDesc('id')->get();
        $customer['timeline'] = collect()
            ->concat($orders->map(fn ($order): array => ['at' => $order->ordered_at, 'type' => 'wholesale_order', 'summary' => ($order->order_number ?: $order->shopify_name ?: 'Order').' · '.$this->sourceStore($order)]))
            ->concat($customer['follow_ups']->map(fn ($followUp): array => ['at' => $followUp->created_at, 'type' => 'follow_up', 'summary' => $followUp->title.' · '.$followUp->status]))
            ->concat($customer['suggestions']->flatMap(fn ($suggestion) => $suggestion->decisions->map(fn ($decision): array => ['at' => $decision->decided_at, 'type' => 'suggestion_decision', 'summary' => $suggestion->title.' · '.$decision->action])))
            ->sortByDesc(fn (array $row): int => $row['at']?->timestamp ?? 0)->values();

        return $customer;
    }

    protected function identityKey($order): string
    {
        $store = $this->sourceStore($order);
        $customerId = trim((string) $order->shopify_customer_id);
        if ($customerId !== '') {
            return $store.'|shopify|'.$customerId;
        }

        $email = Str::lower(trim((string) ($order->customer_email ?: $order->email ?: $order->shipping_email ?: $order->billing_email)));
        if ($email !== '') {
            return 'email|'.$email;
        }

        return $store.'|order-owner|'.Str::lower(trim((string) ($order->customer_name ?: $order->display_name ?: $order->id)));
    }

    protected function sourceStore($order): string
    {
        $store = Str::lower(trim((string) ($order->shopify_store_key ?: $order->shopify_store)));

        return $store !== '' ? $store : (Str::lower((string) $order->source) === 'shopify_wholesale' ? 'wholesale' : 'legacy');
    }

    /** @param Collection<int,mixed> $orders */
    protected function revenue(Collection $orders): float
    {
        return round((float) $orders->sum(function ($order): float {
            $total = (float) ($order->total_price ?? 0);
            $refund = (float) ($order->refund_total ?? 0);

            return max(0, $total - $refund);
        }), 2);
    }

    /** @param Collection<int,int> $values */
    protected function median(Collection $values): ?int
    {
        if ($values->isEmpty()) {
            return null;
        }

        $middle = intdiv($values->count(), 2);
        if ($values->count() % 2 === 1) {
            return (int) $values[$middle];
        }

        return (int) round(((int) $values[$middle - 1] + (int) $values[$middle]) / 2);
    }

    protected function timingState(?int $daysSince, ?int $medianInterval, int $orderCount): string
    {
        if ($daysSince === null) {
            return 'unknown';
        }

        $expected = $medianInterval ?? ($orderCount === 1 ? 120 : 90);
        if ($daysSince >= max(180, (int) round($expected * 2))) {
            return 'lapsed';
        }
        if ($daysSince >= (int) round($expected * 1.25)) {
            return 'at_risk';
        }
        if ($daysSince >= (int) round($expected * .9)) {
            return 'due';
        }

        return 'active';
    }
}
