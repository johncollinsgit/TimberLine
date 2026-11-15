<?php

namespace App\Services\Team\Studio;

use App\Models\MarketingImportRun;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use App\Services\Pos\PosEventSales;
use App\Services\Pos\PosException;
use App\Services\Reporting\ReviewedSquareEventPurchases;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/** Shared sales-only read. Source ledgers and the payroll/profitability contract retain authority. */
class EventSalesHistory
{
    public function __construct(private EventSalesAccess $access) {}

    public function read(Tenant $tenant, User $user, TeamOperationRecord $event): array
    {
        $this->access->event($tenant, $user, $event);
        $links = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $event->area_id, 'kind' => 'event_link', 'status' => 'accepted'])->get();
        $link = $links->first(fn ($item) => ($item->data['occurrence_id'] ?? null) === $event->id);
        $current = $this->summarizeOccurrence($tenant, $user, $event, $link);
        $history = [];
        $issues = [];
        if ($link) {
            foreach ($links as $other) {
                if (($other->data['series_id'] ?? null) !== $link->data['series_id'] || ($other->data['occurrence_id'] ?? null) === $event->id) {
                    continue;
                }
                $record = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $event->area_id])->whereIn('kind', ['event', 'financial'])->find($other->data['occurrence_id'] ?? '');
                if (! $record || ! $this->access->exported($record, $other)) {
                    $issues[] = 'Some historical source access or reviewed mappings need attention.';

                    continue;
                }
                $history[] = $this->summarizeOccurrence($tenant, $user, $record, $other);
            }
        } else {
            $issues[] = 'Review this event’s recurring series to compare prior years.';
        }
        usort($history, fn ($a, $b) => strcmp($b['starts_on'] ?? '', $a['starts_on'] ?? ''));
        $year = (int) substr($current['starts_on'] ?? now()->toDateString(), 0, 4);
        $eligible = array_values(array_filter($history, fn ($row) => $row['complete'] && ! $row['partial'] && $row['net_sales_minor'] !== null && (int) substr($row['starts_on'] ?? '', 0, 4) < $year));
        $total = array_sum(array_column($eligible, 'net_sales_minor'));
        $dailyEligible = array_values(array_filter($eligible, fn ($row) => $row['selling_days'] > 0));
        $days = array_sum(array_column($dailyEligible, 'selling_days'));
        $previous = array_values(array_filter($eligible, fn ($row) => (int) substr($row['starts_on'], 0, 4) === $year - 1));

        return ['contract_version' => 1, 'can_manage' => $this->access->manager($tenant, $user), 'event_id' => $event->id, 'event_name' => $event->title, 'series_id' => $link?->data['series_id'] ?? null,
            'timezone' => $current['timezone'], 'current' => $current, 'history' => $history,
            'benchmarks' => ['average_event_net_sales_minor' => $eligible ? (int) round($total / count($eligible)) : null,
                'average_day_net_sales_minor' => $days ? (int) round(array_sum(array_column($dailyEligible, 'net_sales_minor')) / $days) : null,
                'occurrence_count' => count($eligible), 'selling_day_count' => $days,
                'previous_year_net_sales_minor' => count($previous) === 1 ? $previous[0]['net_sales_minor'] : null,
                'years' => array_values(array_unique(array_map(fn ($row) => (int) substr($row['starts_on'], 0, 4), $eligible)))],
            'comparisons' => ['event_day' => $this->compare($current, $eligible, 'event_day'), 'weekday' => $this->compare($current, $eligible, 'weekday'),
                'intraday' => $this->intraday($current, $eligible),
                'live_basis' => 'Partial selling days are shown beside completed historical days; no intraday pace is inferred from daily totals.'],
            'review_items' => array_values(array_unique([...$issues, ...$current['review_items']])), 'as_of' => now()->toIso8601String()];
    }

    /** Background workers may build the same compact rows after their current manager admission. */
    public function summarizeOccurrence(Tenant $tenant, User $user, TeamOperationRecord $event, ?TeamOperationRecord $link = null): array
    {
        abort_unless((int) $event->tenant_id === (int) $tenant->id && in_array($event->kind, ['event', 'financial'], true), 404);
        if ($link === null) {
            $link = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $event->area_id, 'kind' => 'event_link', 'status' => 'accepted'])->get()
                ->first(fn ($row) => ($row->data['occurrence_id'] ?? null) === $event->id);
        }
        $map = $link?->data ?? [];
        $timezone = $map['timezone'] ?? $event->data['timezone'] ?? 'UTC';
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }
        $start = $map['starts_on'] ?? $event->starts_on?->toDateString();
        $end = $map['ends_on'] ?? $event->ends_on?->toDateString();
        $issues = [];
        $valid = $link && $this->access->exported($event, $link);
        if ($link && (! $valid || $start !== $event->starts_on?->toDateString() || $end !== $event->ends_on?->toDateString())) {
            $valid = false;
            $issues[] = 'The reviewed occurrence or its source access changed; review its mapping.';
        }
        $staff = [];
        $reported = $valid ? $this->reported($tenant, $event, $link, $staff, $issues) : null;
        $sales = ['net_sales_minor' => null, 'known_net_sales_minor' => 0, 'sale_count' => 0, 'daily' => [], 'operators' => [], 'complete' => true, 'source_coverage_complete' => false];
        $commerce = null;
        if ($event->kind === 'event' && Schema::hasTable('commerce_stock_event_links')) {
            $commerce = app(EventCommerceReport::class)->commerceId($event);
            if ($commerce && Schema::hasTable('pos_live_sales')) {
                $sales = app(PosEventSales::class)->read($tenant->id, $commerce, $timezone);
            }
        }
        $selected = $event->data['_teams_enrichment']['square_orders'] ?? [];
        $scopes = $valid ? ($map['square_scopes'] ?? []) : [];
        $resolved = $this->resolveScopes($tenant, $event, $link, $timezone, $scopes, $commerce);
        $coverage = $resolved['coverage'];
        $issues = [...$issues, ...$resolved['review_items']];
        $allDates = [];
        $squareNet = 0;
        $squareKnown = true;
        foreach ($resolved['chunks'] as $allocated) {
            $squareKnown = $squareKnown && ! $allocated['review_items'];
            foreach ($allocated['purchases'] as $purchase) {
                $squareNet += $purchase['net_sales_minor'] ?? 0;
                if ($purchase['net_sales_minor'] === null) {
                    $squareKnown = false;
                }
                // The current Pay report already includes its selected external orders.
                if ($commerce !== null && in_array($purchase['source_id'], $selected['ids'] ?? [], true)) {
                    continue;
                }
                $sales['sale_count']++;
                $sales['known_net_sales_minor'] += $purchase['net_sales_minor'] ?? 0;
                $timestamp = CarbonImmutable::parse($purchase['occurred_at'])->setTimezone($timezone);
                $date = $timestamp->toDateString();
                $allDates[$date] ??= ['date' => $date, 'sale_count' => 0, 'net_sales_minor' => 0, 'minute_sales' => [], 'complete' => true];
                $allDates[$date]['sale_count']++;
                $allDates[$date]['net_sales_minor'] += $purchase['net_sales_minor'] ?? 0;
                $allDates[$date]['complete'] = $allDates[$date]['complete'] && $purchase['net_sales_minor'] !== null;
                if ($purchase['net_sales_minor'] !== null) {
                    $minute = $timestamp->format('H:i');
                    $allDates[$date]['minute_sales'][$minute] = ($allDates[$date]['minute_sales'][$minute] ?? 0) + $purchase['net_sales_minor'];
                }
            }
        }
        $daily = [];
        foreach ([...($sales['daily'] ?? []), ...array_values($allDates)] as $day) {
            $date = $day['date'];
            $daily[$date] ??= ['date' => $date, 'sale_count' => 0, 'net_sales_minor' => 0, 'minute_sales' => [], 'timestamps_complete' => true, 'complete' => true];
            $daily[$date]['sale_count'] += $day['sale_count'] ?? 0;
            $daily[$date]['net_sales_minor'] += $day['net_sales_minor'] ?? 0;
            $daily[$date]['complete'] = $daily[$date]['complete'] && ($day['complete'] ?? false) && $day['net_sales_minor'] !== null;
            $daily[$date]['timestamps_complete'] = $daily[$date]['timestamps_complete'] && array_key_exists('minute_sales', $day);
            foreach ($day['minute_sales'] ?? [] as $minute => $amount) {
                $daily[$date]['minute_sales'][$minute] = ($daily[$date]['minute_sales'][$minute] ?? 0) + $amount;
            }
        }
        $sellingDates = $map['calendar']['selling_dates'] ?? array_column($map['daily_hours'] ?? [], 'date');
        if (! $sellingDates && $start && $end && (int) ($map['verified_selling_days'] ?? 0) === (int) CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) + 1) {
            for ($date = CarbonImmutable::parse($start); $date->toDateString() <= $end; $date = $date->addDay()) {
                $sellingDates[] = $date->toDateString();
            }
        }
        $sellingDates = array_values(array_unique($sellingDates));
        sort($sellingDates);
        // A proof for one payroll segment cannot establish coverage of an entire show.
        $datesToCover = $sellingDates;
        if (! $datesToCover && $start && $end) {
            for ($date = CarbonImmutable::parse($start); $date->toDateString() <= $end; $date = $date->addDay()) {
                $datesToCover[] = $date->toDateString();
            }
        }
        foreach ($datesToCover as $date) {
            if (! collect($scopes)->contains(fn ($scope) => $scope['starts_on'] <= $date && $scope['ends_on'] >= $date)) {
                $coverage = false;
            }
        }
        if ($coverage && $valid) {
            foreach ($sellingDates as $date) {
                $daily[$date] ??= ['date' => $date, 'sale_count' => 0, 'net_sales_minor' => 0, 'minute_sales' => [], 'timestamps_complete' => true, 'complete' => true];
            }
        }
        ksort($daily);
        foreach ($daily as $date => &$day) {
            $position = array_search($date, $sellingDates, true);
            $day['event_day'] = $position === false ? null : $position + 1;
            $day['weekday'] = CarbonImmutable::parse($date, $timezone)->format('D');
            $day['partial'] = $date >= now($timezone)->toDateString();
            if (! $day['complete']) {
                $day['net_sales_minor'] = null;
            }
        }
        unset($day);
        $partial = ! $end || $end >= now($timezone)->toDateString();
        $coverage = $coverage && $valid;
        $known = (int) ($sales['known_net_sales_minor'] ?? 0);
        $moneyKnown = ($sales['complete'] ?? false) && $squareKnown;
        $net = $coverage && $moneyKnown ? $known : null;
        $days = $map['verified_selling_days'] ?? null;
        $operators = $sales['operators'] ?? [];
        $operatorNames = User::whereIn('id', array_column($operators, 'user_id'))->whereHas('tenants', fn ($query) => $query->where('tenants.id', $tenant->id))->pluck('name', 'id');
        $operators = array_map(fn ($operator) => $operator + ['name' => $operatorNames[$operator['user_id']] ?? null], $operators);
        $summary = ['event_id' => $event->id, 'title' => $event->title, 'starts_on' => $start, 'ends_on' => $end, 'timezone' => $timezone,
            'staff' => $staff, 'net_sales_minor' => $net, 'known_net_sales_minor' => $known, 'reported_net_sales_minor' => $reported,
            'selling_days' => $days, 'daily' => array_values($daily), 'sale_count' => (int) $sales['sale_count'], 'operators' => $operators,
            'source_coverage_complete' => $coverage, 'complete' => $coverage && $moneyKnown, 'partial' => $partial,
            'basis' => $net !== null ? 'verified_square_and_confirmed_pay' : ($known > 0 ? 'partial_confirmed_purchases' : ($reported !== null ? 'workbook_reported_only' : 'unknown')),
            'review_items' => array_values(array_unique([...$issues, ...($sales['review_items'] ?? [])])), 'as_of' => $sales['as_of'] ?? now()->toIso8601String()];
        if ($this->access->manager($tenant, $user)) {
            $summary['reconciliation'] = ['reported_net_sales_minor' => $reported, 'square_net_sales_minor' => $coverage && $squareKnown ? $squareNet : null,
                'difference_minor' => $reported !== null && $coverage && $squareKnown ? $squareNet - $reported : null];
        }

        return $summary;
    }

    /** Worker-only evidence read; no HTTP sales summary includes customer identities. */
    public function scopePurchases(Tenant $tenant, TeamOperationRecord $event, ?TeamOperationRecord $link): array
    {
        abort_unless((int) $event->tenant_id === (int) $tenant->id && in_array($event->kind, ['event', 'financial'], true), 404);
        if (! $link || $link->kind !== 'event_link' || $link->status !== 'accepted'
            || ($link->data['occurrence_id'] ?? null) !== $event->id || ! $this->access->exported($event, $link)
            || ($link->data['starts_on'] ?? null) !== $event->starts_on?->toDateString()
            || ($link->data['ends_on'] ?? null) !== $event->ends_on?->toDateString()) {
            return ['purchases' => [], 'review_items' => ['Review this occurrence before reading its linked customer purchases.'], 'customer_review_items' => []];
        }
        $timezone = $link->data['timezone'] ?? 'UTC';
        abort_unless(in_array($timezone, timezone_identifiers_list(), true), 422);
        $commerce = $event->kind === 'event' && Schema::hasTable('commerce_stock_event_links') ? app(EventCommerceReport::class)->commerceId($event) : null;
        $resolved = $this->resolveScopes($tenant, $event, $link, $timezone, $link->data['square_scopes'] ?? [], $commerce);
        $purchases = [];
        $customerIssues = [];
        foreach ($resolved['chunks'] as $chunk) {
            $customerIssues = [...$customerIssues, ...$chunk['customer_review_items']];
            foreach ($chunk['purchases'] as $purchase) {
                $purchases[] = array_intersect_key($purchase, array_flip(['source', 'source_id', 'customer_id', 'occurred_at', 'amount_minor', 'refunded_minor', 'net_sales_minor'])) + ['currency' => 'USD'];
            }
        }

        return ['purchases' => $purchases, 'review_items' => $resolved['review_items'], 'customer_review_items' => array_values(array_unique($customerIssues))];
    }

    /** One reviewed rule resolver for event money and worker customer facts. */
    private function resolveScopes(Tenant $tenant, TeamOperationRecord $event, ?TeamOperationRecord $link, string $timezone, array $scopes, ?int $commerce): array
    {
        $result = ['coverage' => $scopes !== [], 'chunks' => [], 'review_items' => []];
        $seen = [];
        foreach ($scopes as $scope) {
            $proven = app(EventSquareCoverage::class)->proven($tenant->id, $scope, $timezone);
            $result['coverage'] = $result['coverage'] && $proven;
            if (! $proven) {
                $result['review_items'][] = 'Square source coverage is incomplete or its original connection changed.';
            }
            if (! Schema::hasTable('square_orders') || ! Schema::hasTable('square_payments')) {
                $result['coverage'] = false;

                continue;
            }
            $binding = MarketingImportRun::forTenantId($tenant->id)->find($scope['orders_run_id'] ?? 0)?->summary['source_binding'] ?? null;
            if (! $binding) {
                $result['coverage'] = false;

                continue;
            }
            // Incomplete retrieval may show known purchases; revoked original ownership may not.
            try {
                app(\App\Services\Marketing\SquareConnectionContext::class)->assertCurrent($tenant->id, $binding);
            } catch (\Throwable) {
                $result['coverage'] = false;

                continue;
            }
            $from = CarbonImmutable::parse($scope['starts_on'], $timezone)->startOfDay()->utc();
            $through = CarbonImmutable::parse($scope['ends_on'], $timezone)->addDay()->startOfDay()->utc();
            $orders = SquareOrder::forTenantId($tenant->id)->where('location_id', $scope['location_id'])->where('closed_at', '>=', $from)->where('closed_at', '<', $through->addHours(72))->get();
            $payments = SquarePayment::forTenantId($tenant->id)->whereIn('square_order_id', $orders->pluck('square_order_id'))->where('status', 'COMPLETED')->get()->groupBy('square_order_id');
            $timestamps = [];
            foreach ($orders as $order) {
                $timestamp = $this->occurredAt($order, $payments->get($order->square_order_id, collect()));
                if ($timestamp === null) {
                    $result['review_items'][] = 'An offline purchase needs its original selling timestamp.';
                    $result['coverage'] = false;

                    continue;
                }
                if ($timestamp->lt($from) || $timestamp->gte($through)) {
                    continue;
                }
                if (isset($seen[$order->square_order_id])) {
                    $result['review_items'][] = 'Overlapping source scopes repeat a Square order.';
                    $result['coverage'] = false;

                    continue;
                }
                $seen[$order->square_order_id] = true;
                $timestamps[$order->square_order_id] = $timestamp;
            }
            $map = $link->data;
            $chunk = app(ReviewedSquareEventPurchases::class)->forOccurrence($tenant->id, $event, $commerce, ['ids' => array_keys($timestamps), 'reviewed_by' => $map['reviewed_by'], 'reviewed_at' => $map['reviewed_at'], 'source_binding' => $binding]);
            foreach ($chunk['purchases'] as &$purchase) {
                $purchase['occurred_at'] = $timestamps[$purchase['source_id']]->toIso8601String();
            }
            unset($purchase);
            $result['review_items'] = [...$result['review_items'], ...$chunk['review_items']];
            $result['chunks'][] = $chunk;
        }
        $result['review_items'] = array_values(array_unique($result['review_items']));

        return $result;
    }

    public function forRegister(array $scope, User $user): array
    {
        $settings = app(\App\Services\Pos\StockPosCommerce::class)->settings($scope);
        $pool = app(StockLedger::class)->rows('pools', $scope['tenant_id'])->where('id', $settings['inventory_pool_id'] ?? '')->first();
        if (! $pool || ! preg_match('/^event:([1-9][0-9]*)$/D', (string) $pool->event_key, $match)) {
            throw new PosException('event_register_required', 422);
        }
        $event = app(EventCommerceReport::class)->candidate($scope['tenant_id'], (int) $match[1]);
        if (! $event) {
            throw new PosException('event_cost_mapping_required', 422);
        }

        return $this->read(Tenant::findOrFail($scope['tenant_id']), $user, $event);
    }

    private function reported(Tenant $tenant, TeamOperationRecord $event, TeamOperationRecord $link, array &$staff, array &$issues): ?int
    {
        $map = $link->data;
        $sources = $map['financial_sources'] ?? (! empty($map['financial_id']) ? [['record_id' => $map['financial_id'], 'treatment' => 'add', 'version' => $map['financial_version'] ?? null]] : []);
        $refs = array_values(array_unique([...array_column($sources, 'record_id'), ...($map['staff_ids'] ?? [])]));
        $staffRefs = ($map['staff_ids'] ?? []) ?: $refs;
        $records = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $event->area_id])->whereIn('kind', ['financial', 'staff'])->whereIn('id', $refs)->get()->keyBy('id');
        $net = 0;
        $known = $sources !== [];
        foreach ($refs as $id) {
            $record = $records[$id] ?? null;
            if (! $record || ! $this->access->exported($record, $link) || isset($map['reviewed_source_versions'][$id]) && $map['reviewed_source_versions'][$id] !== $record->version) {
                $issues[] = 'Some reported sales or staff source access needs review.';
                $known = false;

                continue;
            }
            $name = trim((string) ($record->data['person_name'] ?? ($record->kind === 'staff' ? $record->title : '')));
            if ($name !== '' && in_array($id, $staffRefs, true)) {
                $stable = ! empty($record->data['person_id']) || $record->kind === 'staff';
                $person = (string) ($record->data['person_id'] ?? $id);
                $staff[$person] = ['id' => $stable ? $person : 'unlinked:'.$person, 'name' => $name, 'identity_review_required' => ! $stable];
            }
        }
        foreach ($sources as $source) {
            $record = $records[$source['record_id']] ?? null;
            if (($source['treatment'] ?? 'add') !== 'add') {
                continue;
            }
            if (! $record || ! $this->access->exported($record, $link) || ($source['version'] ?? null) !== $record->version || ! is_int($record->data['net_sales_cents'] ?? null)) {
                $known = false;
                $issues[] = 'A reported sales row changed after mapping review.';

                continue;
            }
            $net += $record->data['net_sales_cents'];
        }
        $staff = array_values($staff);
        if (! $staff) {
            $ids = array_filter([$event->assigned_user_id, ...(is_array($event->data['staff_ids'] ?? null) ? $event->data['staff_ids'] : [])], fn ($id) => is_scalar($id) && ctype_digit((string) $id));
            foreach (User::whereIn('id', $ids)->whereHas('tenants', fn ($q) => $q->where('tenants.id', $tenant->id))->get() as $person) {
                $staff[] = ['id' => 'user:'.$person->id, 'name' => $person->name];
            }
        }

        return $known ? $net : null;
    }

    private function occurredAt(SquareOrder $order, \Illuminate\Support\Collection $payments): ?CarbonImmutable
    {
        $offline = $payments->filter(fn ($payment) => ($payment->raw_payload['is_offline_payment'] ?? false) === true);
        if ($offline->isEmpty()) {
            return $order->closed_at ? CarbonImmutable::instance($order->closed_at) : null;
        }
        $times = $offline->map(fn ($payment) => data_get($payment->raw_payload, 'offline_payment_details.client_created_at'));
        if ($times->contains(fn ($time) => ! is_string($time) || strtotime($time) === false)) {
            return null;
        }
        $dates = $times->map(fn ($time) => CarbonImmutable::parse($time)->utc());

        return $dates->sort()->first();
    }

    private function compare(array $current, array $history, string $key): array
    {
        $groups = [];
        foreach ($history as $row) {
            foreach ($row['daily'] as $day) {
                if ($day[$key] !== null && $day['complete'] && ! $day['partial'] && $day['net_sales_minor'] !== null) {
                    $groups[$day[$key]]['past'][] = $day['net_sales_minor'];
                }
            }
        }
        foreach ($current['daily'] as $day) {
            if ($day[$key] !== null) {
                $groups[$day[$key]]['current'][] = $day['net_sales_minor'];
                $groups[$day[$key]]['partial'] = ($groups[$day[$key]]['partial'] ?? false) || $day['partial'];
            }
        }
        uksort($groups, $key === 'event_day' ? fn ($a, $b) => $a <=> $b : fn ($a, $b) => array_search($a, ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']) <=> array_search($b, ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']));
        $result = [];
        foreach ($groups as $label => $group) {
            $past = $group['past'] ?? [];
            $present = $group['current'] ?? [];
            $result[] = ['label' => $key === 'event_day' ? 'Day '.$label : $label, 'current_net_sales_minor' => $present && ! in_array(null, $present, true) ? (int) round(array_sum($present) / count($present)) : null,
                'historical_average_net_sales_minor' => $past ? (int) round(array_sum($past) / count($past)) : null, 'sample_count' => count($past), 'partial' => $group['partial'] ?? false];
        }

        return $result;
    }

    private function intraday(array $current, array $history): array
    {
        $today = now($current['timezone']);
        $through = $today->format('H:i');
        $present = collect($current['daily'])->firstWhere('date', $today->toDateString());
        $result = ['available' => false, 'current_net_sales_minor' => null, 'historical_average_net_sales_minor' => null,
            'through_local_time' => $through, 'sample_count' => 0, 'event_day' => $present['event_day'] ?? null,
            'current_source_coverage_complete' => $current['source_coverage_complete']];
        if (! $present || ! $present['complete'] || ! ($present['timestamps_complete'] ?? false) || ! $present['event_day']) {
            return $result;
        }
        $sum = static fn ($day) => array_sum(array_filter($day['minute_sales'] ?? [], fn ($amount, $minute) => strcmp((string) $minute, $through) <= 0, ARRAY_FILTER_USE_BOTH));
        $values = [];
        foreach ($history as $occurrence) {
            $day = collect($occurrence['daily'])->firstWhere('event_day', $present['event_day']);
            if ($day && $day['complete'] && ($day['timestamps_complete'] ?? false)) {
                $values[] = $sum($day);
            }
        }
        if (! $values) {
            return $result;
        }

        return array_replace($result, ['available' => true, 'current_net_sales_minor' => $sum($present),
            'historical_average_net_sales_minor' => (int) round(array_sum($values) / count($values)), 'sample_count' => count($values)]);
    }
}
