<?php

namespace App\Services\Marketing;

use App\Models\CandleCashTransaction;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CandleCashGiftReportService
{
    public function __construct(
        protected CandleCashService $candleCashService
    ) {
    }

    public function generate(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $from = $this->normalizeDate($from);
        $to = $this->normalizeDate($to);

        $totalGiftTransactions = (int) $this->baseGiftQuery($from, $to)->count();
        $totalGiftCandleCash = (int) $this->baseGiftQuery($from, $to)->sum('candle_cash_delta');

        $allGiftRecords = $this->baseGiftQuery($from, $to)
            ->with(['profile:id,email,normalized_email'])
            ->orderByDesc('id')
            ->get();

        $transactions = $allGiftRecords
            ->take(80)
            ->map(function (CandleCashTransaction $transaction): array {
                $occurred = $transaction->created_at ?: $transaction->updated_at;

                return [
                    'id' => $transaction->id,
                    'candle_cash_amount' => $this->candleCashService->amountFromPoints((int) $transaction->candle_cash_delta),
                    'description' => $transaction->description,
                    'gift_intent' => $transaction->gift_intent,
                    'gift_origin' => $transaction->gift_origin,
                    'campaign_key' => $transaction->campaign_key,
                    'notification_status' => $transaction->notification_status,
                    'notified_via' => $transaction->notified_via,
                    'created_at' => $occurred ? $occurred->format('Y-m-d H:i') : null,
                    'marketing_profile_id' => $transaction->marketing_profile_id,
                    'profile_email' => $transaction->profile?->email,
                ];
            })
            ->values()
            ->all();

        return [
            'range' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'totals' => [
                'gift_transactions' => $totalGiftTransactions,
                'gift_amount' => $this->candleCashService->amountFromPoints($totalGiftCandleCash),
            ],
            'breakdowns' => [
                'intent' => $this->breakdownByColumn($from, $to, 'gift_intent'),
                'origin' => $this->breakdownByColumn($from, $to, 'gift_origin'),
                'notification' => $this->breakdownByColumn($from, $to, 'notification_status'),
                'actor' => $this->actorBreakdown($from, $to),
            ],
            'transactions' => $transactions,
            'conversion' => $this->computeConversionMetrics($allGiftRecords, $from, $to),
        ];
    }

    protected function baseGiftQuery(?CarbonImmutable $from, ?CarbonImmutable $to): Builder
    {
        return CandleCashTransaction::query()
            ->where('type', 'gift')
            ->when($from, fn (Builder $builder) => $builder->where('created_at', '>=', $from->startOfDay()))
            ->when($to, fn (Builder $builder) => $builder->where('created_at', '<=', $to->endOfDay()));
    }

    protected function breakdownByColumn(?CarbonImmutable $from, ?CarbonImmutable $to, string $column): array
    {
        $rows = $this->baseGiftQuery($from, $to)
            ->selectRaw("$column as value")
            ->selectRaw('count(*) as count')
            ->selectRaw('coalesce(sum(candle_cash_delta), 0) as candle_cash_delta')
            ->groupBy($column)
            ->orderByDesc('count')
            ->get();

        return $rows->mapWithKeys(function ($row) {
            $value = $row->value;
            $key = $value === null || trim((string) $value) === '' ? 'unspecified' : (string) $value;
            return [$key => [
                'label' => $this->formatLabel($value, $key),
                'count' => (int) $row->count,
                'candle_cash_amount' => $this->candleCashService->amountFromPoints((int) $row->candle_cash_delta),
            ]];
        })->all();
    }

    protected function actorBreakdown(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $rows = $this->baseGiftQuery($from, $to)
            ->selectRaw('source_id')
            ->selectRaw('count(*) as count')
            ->selectRaw('coalesce(sum(candle_cash_delta), 0) as candle_cash_delta')
            ->whereNotNull('source_id')
            ->groupBy('source_id')
            ->orderByDesc('count')
            ->get();

        $userIds = $rows
            ->map(fn ($row) => is_numeric($row->source_id) ? (int) $row->source_id : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $users = $userIds === []
            ? []
            : User::query()
                ->whereIn('id', $userIds)
                ->get(['id', 'name', 'email'])
                ->mapWithKeys(fn (User $user) => [$user->id => trim((string) ($user->name ?: $user->email)) ?: 'Admin'])
                ->all();

        return $rows->mapWithKeys(function ($row) use ($users) {
            $sourceId = (string) $row->source_id;
            $label = $this->actorLabel($sourceId, $users);
            return [$sourceId => [
                'label' => $label,
                'count' => (int) $row->count,
                'candle_cash_amount' => $this->candleCashService->amountFromPoints((int) $row->candle_cash_delta),
            ]];
        })->all();
    }

    protected function computeConversionMetrics(Collection $gifts, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $earliestGiftByProfile = [];
        $profileEmails = [];

        foreach ($gifts as $gift) {
            $profileId = $gift->marketing_profile_id;
            if (! $profileId) {
                continue;
            }

            $occurred = $gift->created_at ?: $gift->updated_at;
            if (! $occurred) {
                continue;
            }

            if (! isset($earliestGiftByProfile[$profileId]) || $occurred->lt($earliestGiftByProfile[$profileId])) {
                $earliestGiftByProfile[$profileId] = $occurred;
            }

            if (! isset($profileEmails[$profileId]) && $gift->profile) {
                $profileEmails[$profileId] = trim((string) ($gift->profile->normalized_email ?: $gift->profile->email ?: ''));
            }
        }

        if ($earliestGiftByProfile === []) {
            return [
                'gifted_customers_with_orders' => 0,
                'converted_orders' => 0,
                'revenue_after_gifts' => 0.0,
            ];
        }

        $orders = $this->fetchOrdersForProfiles($earliestGiftByProfile, $profileEmails, $from, $to);
        $hasProfileColumn = Schema::hasColumn('orders', 'marketing_profile_id');

        $convertedProfiles = [];
        $revenue = 0.0;

        foreach ($orders as $order) {
            $profileId = $this->resolveOrderProfile($order, $earliestGiftByProfile, $profileEmails, $hasProfileColumn);
            if (! $profileId || ! isset($earliestGiftByProfile[$profileId])) {
                continue;
            }

            $orderDate = $order->created_at ?: $order->ordered_at;
            if (! $orderDate || $orderDate->lt($earliestGiftByProfile[$profileId])) {
                continue;
            }

            $convertedProfiles[$profileId] = true;
            $revenue += $this->orderRevenue($order);
        }

        return [
            'gifted_customers_with_orders' => count($convertedProfiles),
            'converted_orders' => $orders->count(),
            'revenue_after_gifts' => round($revenue, 2),
        ];
    }

    protected function fetchOrdersForProfiles(array $profiles, array $emails, ?CarbonImmutable $from, ?CarbonImmutable $to): Collection
    {
        $hasProfileColumn = Schema::hasColumn('orders', 'marketing_profile_id');

        $query = Order::query()->with(['lines.size']);

        if ($hasProfileColumn && $profiles !== []) {
            $query->whereIn('marketing_profile_id', array_keys($profiles));
        }

        if (! $hasProfileColumn) {
            $emailCandidates = array_filter(array_unique($emails));
            if ($emailCandidates === []) {
                return collect();
            }

            $query->where(function (Builder $builder) use ($emailCandidates) {
                foreach (['email', 'customer_email', 'shipping_email', 'billing_email'] as $column) {
                    $builder->orWhereIn($column, $emailCandidates);
                }
            });
        }

        return $query
            ->when($from, fn (Builder $builder) => $builder->where('created_at', '>=', $from->startOfDay()))
            ->when($to, fn (Builder $builder) => $builder->where('created_at', '<=', $to->endOfDay()))
            ->get();
    }

    protected function resolveOrderProfile(Order $order, array $profiles, array $emails, bool $hasProfileColumn): ?int
    {
        if ($hasProfileColumn && $order->marketing_profile_id && isset($profiles[$order->marketing_profile_id])) {
            return $order->marketing_profile_id;
        }

        foreach ($emails as $profileId => $email) {
            if (! $email) {
                continue;
            }
            foreach ([
                $order->email,
                $order->customer_email,
                $order->shipping_email,
                $order->billing_email,
            ] as $orderEmail) {
                if ($orderEmail && strcasecmp(trim((string) $orderEmail), $email) === 0) {
                    return $profileId;
                }
            }
        }

        return null;
    }

    protected function orderRevenue(Order $order): float
    {
        return (float) $order->lines->sum(function (OrderLine $line): float {
            $quantity = max(0, $line->ordered_qty ?: $line->quantity ?: 0);
            $price = (float) ($line->size?->retail_price ?? $line->size?->wholesale_price ?? 0);

            return $quantity * $price;
        });
    }

    protected function actorLabel(string $sourceId, array $userMap): string
    {
        if (is_numeric($sourceId)) {
            $id = (int) $sourceId;
            return $userMap[$id] ?? 'Admin';
        }

        $normalized = trim(str_replace('_', ' ', $sourceId));
        return $normalized !== '' ? Str::headline($normalized) : 'Admin';
    }

    protected function formatLabel(?string $value, string $fallback): string
    {
        if ($value === null || trim($value) === '') {
            return Str::headline(str_replace('_', ' ', $fallback));
        }

        return Str::headline(str_replace('_', ' ', $value));
    }

    protected function normalizeDate(?CarbonImmutable $date): ?CarbonImmutable
    {
        return $date;
    }
}
