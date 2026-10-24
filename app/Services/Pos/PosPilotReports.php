<?php

declare(strict_types=1);

namespace App\Services\Pos;

use Carbon\CarbonImmutable;

/** Read-only normalization of the operational journal. Never imports or charges. */
final class PosPilotReports
{
    public function __construct(private PosStore $store, private PosPilotAccess $access) {}

    public function activity(array $input, bool $export = false): array
    {
        $ctx = $this->access->context();
        $tenant = $ctx['tenant_id'];
        $scope = $input['scope'] ?? 'device';
        $period = $input['period'] ?? 'day';
        if (! in_array($scope, ['device', 'user', 'company'], true) || ! in_array($period, ['day', 'month', 'year'], true)) {
            throw new PosException('invalid_report', 422);
        }
        if (($scope === 'company' && $ctx['role'] !== 'admin') || ($export && $ctx['role'] === 'member')) {
            throw new PosException('report_scope_denied', 403);
        }
        $timezone = $this->store->rows('company_settings', $tenant)->value('reporting_timezone');
        if (! $timezone) {
            throw new PosException('reporting_timezone_review_required');
        }
        $date = $input['date'] ?? CarbonImmutable::now($timezone)->format(match ($period) {
            'day' => 'Y-m-d', 'month' => 'Y-m', 'year' => 'Y'
        });
        $full = $date.match ($period) {
            'day' => '', 'month' => '-01', 'year' => '-01-01'
        };
        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $full, $timezone);
        } catch (\Throwable) {
            throw new PosException('invalid_report_date', 422);
        }
        if (! $start || $start->format('Y-m-d') !== $full || $start->year < 2000 || $start->year > 2100) {
            throw new PosException('invalid_report_date', 422);
        }
        $end = match ($period) {
            'day' => $start->addDay(), 'month' => $start->addMonth(), 'year' => $start->addYear()
        };
        $base = $this->store->rows('sales', $tenant);
        if ($ctx['role'] !== 'admin') {
            $ids = $this->store->rows('event_staff', $tenant)->where('operator_id', $ctx['operator_id'])->where('active', true)->pluck('event_id');
            $base->whereIn('event_id', $ids);
        }
        if (! empty($input['event_id'])) {
            $base->where('event_id', $input['event_id']);
        }
        if ($scope === 'device') {
            $base->where('device_record_id', $ctx['device']->id);
        }
        if ($scope === 'user') {
            $base->where('operator_id', $ctx['operator_id']);
        }
        $rows = (clone $base)->where('state', 'succeeded')->where('confirmed_at', '>=', $start->utc())->where('confirmed_at', '<', $end->utc())->orderBy('confirmed_at')->limit(50001)->get();
        $refundRows = $this->store->rows('refund_details', $tenant)->whereIn('sale_id', (clone $base)->select('id'))
            ->whereNotNull('operation_id')->where('confirmed_at', '>=', $start->utc())->where('confirmed_at', '<', $end->utc())->orderBy('confirmed_at')->limit(50001)->get();
        if ($rows->count() > 50000 || $refundRows->count() > 50000) {
            throw new PosException('report_range_too_large');
        }
        $length = match ($period) {
            'day' => 24, 'month' => $start->daysInMonth, 'year' => 12
        };
        $buckets = [];
        for ($i = 0; $i < $length; $i++) {
            $buckets[] = ['key' => $i, 'label' => match ($period) {
                'day' => sprintf('%02d:00', $i), 'month' => (string) ($i + 1), 'year' => $start->month($i + 1)->format('M')
            }, 'total_minor' => 0, 'count' => 0];
        }
        $totals = ['subtotal_minor' => 0, 'tax_minor' => 0, 'collected_minor' => 0, 'refund_minor' => 0, 'net_minor' => 0, 'count' => $rows->count(), 'average_sale_minor' => 0];
        $transactions = [];
        $products = [];
        $staff = [];
        $events = [];
        $bucketFor = fn ($time) => match ($period) {
            'day' => CarbonImmutable::parse($time, 'UTC')->setTimezone($timezone)->hour,
            'month' => CarbonImmutable::parse($time, 'UTC')->setTimezone($timezone)->day - 1, 'year' => CarbonImmutable::parse($time, 'UTC')->setTimezone($timezone)->month - 1
        };
        foreach ($rows as $sale) {
            $snapshot = app(PosService::class)->snapshot($sale);
            $tax = (int) ($snapshot['terms']['tax_minor'] ?? array_sum(array_column($snapshot['sale']['lines'], 'tax_amount_minor')));
            $totals['tax_minor'] += $tax;
            $totals['subtotal_minor'] += (int) $sale->amount_minor - $tax;
            $totals['collected_minor'] += (int) $sale->amount_minor;
            $bucket = $bucketFor($sale->confirmed_at);
            $buckets[$bucket]['total_minor'] += (int) $sale->amount_minor;
            $buckets[$bucket]['count']++;
            foreach ($snapshot['terms']['display'] as $line) {
                $key = ($line['sku'] ?? '').'|'.$line['title'];
                $products[$key] ??= ['title' => $line['title'], 'quantity' => 0, 'subtotal_minor' => 0];
                $products[$key]['quantity'] += $line['quantity'];
                $products[$key]['subtotal_minor'] += $line['subtotal_minor'];
            }
            foreach (['staff' => $sale->operator_id, 'events' => $sale->event_id] as $kind => $id) {
                if ($kind === 'staff') {
                    $staff[$id] ??= ['id' => $id, 'name' => $snapshot['pilot']['operator_name'] ?? 'Unassigned', 'collected_minor' => 0, 'count' => 0];
                    $staff[$id]['collected_minor'] += (int) $sale->amount_minor;
                    $staff[$id]['count']++;
                } else {
                    $events[$id] ??= ['id' => $id, 'name' => $snapshot['pilot']['event_name'] ?? 'Unassigned', 'collected_minor' => 0, 'count' => 0];
                    $events[$id]['collected_minor'] += (int) $sale->amount_minor;
                    $events[$id]['count']++;
                }
            }
            $transactions[] = ['id' => $sale->id, 'register_id' => $sale->register_id, 'kind' => 'sale', 'at' => CarbonImmutable::parse($sale->confirmed_at, 'UTC')->toIso8601String(), 'bucket' => $bucket,
                'amount_minor' => (int) $sale->amount_minor, 'staff' => $snapshot['pilot']['operator_name'] ?? 'Unassigned', 'event' => $snapshot['pilot']['event_name'] ?? 'Unassigned', 'sync_pending' => $sale->stock_state !== 'committed'];
        }
        foreach ($refundRows as $refund) {
            $sale = $this->store->rows('sales', $tenant)->where('id', $refund->sale_id)->firstOrFail();
            $snapshot = app(PosService::class)->snapshot($sale);
            $totals['refund_minor'] += (int) $refund->amount_minor;
            $transactions[] = ['id' => $sale->id, 'register_id' => $sale->register_id, 'kind' => 'refund', 'at' => CarbonImmutable::parse($refund->confirmed_at, 'UTC')->toIso8601String(), 'bucket' => $bucketFor($refund->confirmed_at),
                'amount_minor' => -(int) $refund->amount_minor, 'staff' => $snapshot['pilot']['operator_name'] ?? 'Unassigned', 'event' => $snapshot['pilot']['event_name'] ?? 'Unassigned', 'sync_pending' => ! $refund->restocked_at];
        }
        usort($transactions, fn ($a, $b) => strcmp($b['at'], $a['at']));
        usort($products, fn ($a, $b) => $b['quantity'] <=> $a['quantity']);
        $totals['net_minor'] = $totals['collected_minor'] - $totals['refund_minor'];
        $totals['average_sale_minor'] = $totals['count'] ? intdiv($totals['collected_minor'], $totals['count']) : 0;
        if (isset($input['bucket'])) {
            if ((int) $input['bucket'] >= count($buckets)) {
                throw new PosException('invalid_chart_bucket', 422);
            }
            $transactions = array_values(array_filter($transactions, fn ($r) => $r['bucket'] === (int) $input['bucket']));
        }
        $offset = max(0, (int) ($input['offset'] ?? 0));

        return ['period' => $period, 'date' => $date, 'scope' => $scope, 'timezone' => $timezone, 'totals' => $totals, 'buckets' => $buckets,
            'transactions' => $export ? $transactions : array_slice($transactions, $offset, 100), 'next_offset' => count($transactions) > $offset + 100 ? $offset + 100 : null,
            'products' => array_slice(array_values($products), 0, 20), 'staff' => array_values($staff), 'events' => array_values($events),
            'historical_unassigned' => $ctx['role'] === 'admin' ? $this->store->rows('sales', $tenant)->whereNull('event_id')->count() : null];
    }

    public function countStock(string $eventId, string $register, string $stage, array $counts): array
    {
        $ctx = $this->access->register($register, 'catalog');
        if ($ctx['role'] === 'member') {
            throw new PosException('manager_required', 403);
        }
        if (! $this->store->rows('event_staff', $ctx['tenant_id'])->where('event_id', $eventId)->where('operator_id', $ctx['operator_id'])->where('active', true)->exists()) {
            throw new PosException('event_assignment_required', 403);
        }
        if (! $this->store->rows('event_registers', $ctx['tenant_id'])->where('event_id', $eventId)->where('register_id', $register)->where('active', true)->exists()) {
            throw new PosException('event_register_mismatch');
        }
        $event = $this->store->rows('trade_events', $ctx['tenant_id'])->where('id', $eventId)->firstOrFail();
        if (($stage === 'opening' && $event->state !== 'planned') || ($stage === 'closing' && ! in_array($event->state, ['closing', 'closed'], true))) {
            throw new PosException('count_stage_invalid');
        }
        $contextHash = $this->stockContext($ctx['tenant_id'], $eventId);
        $catalog = array_column(app(PosCatalog::class)->listing($ctx), null, 'item_id');
        $result = [];
        $expectedIds = array_keys(array_filter($catalog, fn ($i) => $i['available_quantity'] !== null));
        $actualIds = array_column($counts, 'item_id');
        sort($expectedIds);
        sort($actualIds);
        if ($expectedIds !== $actualIds) {
            throw new PosException('complete_event_count_required');
        }
        foreach ($counts as $line) {
            $item = $catalog[$line['item_id']] ?? null;
            if (! $item || $item['available_quantity'] === null || ! is_int($line['quantity'])) {
                throw new PosException('count_item_invalid');
            }
            $result[] = ['item_id' => $line['item_id'], 'title' => $item['title'], 'expected' => $item['available_quantity'], 'actual' => $line['quantity'], 'variance' => $line['quantity'] - $item['available_quantity']];
        }
        $id = bin2hex(random_bytes(16));
        $this->store->db->transaction(function () use ($ctx, $eventId, $register, $stage, $result, $id, $contextHash): void {
            $event = $this->store->rows('trade_events', $ctx['tenant_id'])->where('id', $eventId)->lockForUpdate()->firstOrFail();
            if (($stage === 'opening' && $event->state !== 'planned') || ($stage === 'closing' && ! in_array($event->state, ['closing', 'closed'], true))) {
                throw new PosException('count_stage_invalid');
            }
            if (! hash_equals($contextHash, $this->stockContext($ctx['tenant_id'], $eventId))) {
                throw new PosException('stock_changed_during_count');
            }
            $sequence = 1 + (int) $this->store->rows('stock_counts', $ctx['tenant_id'])->where('event_id', $eventId)->max('sequence');
            $this->store->rows('stock_counts', $ctx['tenant_id'])->insert($this->store->values($ctx['tenant_id'], ['id' => $id, 'event_id' => $eventId, 'register_id' => $register,
                'operator_id' => $ctx['operator_id'], 'stage' => $stage, 'sequence' => $sequence, 'context_hash' => $contextHash, 'counts' => $this->store->encrypt($result), 'created_at' => now()]));
        });

        return ['count_id' => $id, 'counts' => $result, 'inventory_mutated' => false];
    }

    public function eventState(string $id, string $action): array
    {
        $ctx = $this->access->context();
        $tenant = $ctx['tenant_id'];
        if ($ctx['role'] === 'member' || ! $this->store->rows('event_staff', $tenant)->where('event_id', $id)->where('operator_id', $ctx['operator_id'])->where('active', true)->exists()) {
            throw new PosException('event_manager_required', 403);
        }

        return $this->store->db->transaction(function () use ($tenant, $id, $action, $ctx): array {
            $event = $this->store->rows('trade_events', $tenant)->where('id', $id)->lockForUpdate()->firstOrFail();
            $sales = $this->store->rows('sales', $tenant)->where('event_id', $id)->get();
            $unresolved = $sales->filter(fn ($s) => ! in_array($s->state, ['quoted', 'failed', 'cancelled', 'succeeded'], true) || ($s->state === 'succeeded' && $s->stock_state !== 'committed') || in_array($s->stock_state, ['held'], true) || ($s->source_reference && $s->stock_state === 'unprepared'))->count();
            $unresolved += $this->store->rows('refund_details', $tenant)->whereIn('sale_id', $sales->pluck('id'))->whereNull('confirmed_at')->where('state', '!=', 'not_dispatched')->where(function ($q) {
                $q->whereNull('operation_id')->orWhereIn('operation_id', $this->store->db->table('payment_core_operations')->whereNotIn('state', ['failed', 'cancelled'])->select('id'));
            })->count();
            $effects = $this->store->rows('outbox', $tenant)->whereIn('sale_id', $sales->pluck('id'))->where('state', '!=', 'completed')->count();
            $counts = $this->store->rows('stock_counts', $tenant)->where('event_id', $id)->orderBy('sequence')->get();
            $closing = $counts->where('stage', 'closing')->last();
            $variance = $closing ? collect($this->store->decrypt($closing->counts))->contains(fn ($l) => $l['variance'] !== 0) : true;
            if ($action === 'open') {
                if ($event->state !== 'planned' || ! $counts->contains('stage', 'opening')) {
                    throw new PosException('opening_count_required');
                }
                $this->store->rows('trade_events', $tenant)->where('id', $id)->update(['state' => 'open']);
            } elseif ($action === 'close') {
                $this->store->rows('trade_events', $tenant)->where('id', $id)->where('state', 'open')->update(['state' => 'closing']);
            } elseif ($action === 'finalize') {
                if (! in_array($event->state, ['closing', 'closed'], true) || $unresolved || $effects || $variance || ! $closing?->context_hash || ! hash_equals($closing->context_hash, $this->stockContext($tenant, $id))) {
                    throw new PosException('closeout_provisional');
                }
                $this->store->rows('trade_events', $tenant)->where('id', $id)->update(['state' => 'closed']);
                $this->store->rows('shifts', $tenant)->where('event_id', $id)->where('state', 'open')->update(['state' => 'closed', 'closed_at' => now()]);
            } elseif ($action !== 'inspect') {
                throw new PosException('invalid_event_action');
            }
            $state = $this->store->rows('trade_events', $tenant)->where('id', $id)->value('state');
            if ($action !== 'inspect') {
                $this->store->event($tenant, str_repeat('0', 32), 'event_'.$action, ['event_id' => $id, 'operator_id' => $ctx['operator_id']]);
            }
            $paid = $sales->where('state', 'succeeded')->whereNotNull('confirmed_at');
            $collected = (int) $paid->sum('amount_minor');
            $tax = (int) $paid->sum(function ($s) {
                $snapshot = app(PosService::class)->snapshot($s);

                return (int) ($snapshot['terms']['tax_minor'] ?? array_sum(array_column($snapshot['sale']['lines'], 'tax_amount_minor')));
            });
            $refunded = (int) $this->store->rows('refund_details', $tenant)->whereIn('sale_id', $sales->pluck('id'))->whereNotNull('confirmed_at')->sum('amount_minor');

            return ['event_id' => $id, 'state' => $state, 'unresolved_payments' => $unresolved, 'pending_effects' => $effects,
                'provisional' => $state !== 'closed' || $unresolved > 0 || $effects > 0 || $variance || ! $closing?->context_hash || ! hash_equals($closing->context_hash, $this->stockContext($tenant, $id)), 'collected_minor' => $collected, 'subtotal_minor' => $collected - $tax,
                'tax_minor' => $tax, 'refund_minor' => $refunded, 'net_minor' => $collected - $refunded,
                'counts' => $counts->map(fn ($c) => ['stage' => $c->stage, 'at' => $c->created_at, 'lines' => $this->store->decrypt($c->counts)])->all(), 'settlement_status' => 'provider_reconciliation_required'];
        });
    }

    private function stockContext(int $tenant, string $event): string
    {
        $sales = $this->store->rows('sales', $tenant)->where('event_id', $event);

        return \App\Services\PaymentsCore\Contract::hash([
            'sales' => (clone $sales)->orderBy('id')->get(['id', 'state', 'stock_state', 'confirmed_at'])->map(fn ($r) => (array) $r)->all(),
            'refunds' => $this->store->rows('refund_details', $tenant)->whereIn('sale_id', (clone $sales)->select('id'))->orderBy('id')->get(['id', 'state', 'confirmed_at', 'restocked_at'])->map(fn ($r) => (array) $r)->all(),
            'effects' => $this->store->rows('outbox', $tenant)->whereIn('sale_id', (clone $sales)->select('id'))->orderBy('id')->get(['id', 'state'])->map(fn ($r) => (array) $r)->all(),
        ]);
    }
}
